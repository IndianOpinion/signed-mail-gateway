import { readBodyCapped } from "./body";
import { type Env, type GatewayConfig, readConfig } from "./config";
import { type LogFields, log } from "./log";
import { type OutboundMessage, validateMessage } from "./message";
import { mapProviderError } from "./provider";
import { MESSAGES, errorReply, jsonReply, messageFor, textReply } from "./reply";
import { readSignedHeaders, signingBytes, verifySignature } from "./signature";
import { VERSION } from "./version";

export { NonceStore } from "./nonce-store";
export type { Env } from "./config";

export const ROUTES = ["/v1/send", "/v1/batch", "/v1/check"] as const;
export type Route = (typeof ROUTES)[number];

/** Name of the single nonce store instance. */
export const NONCE_STORE_NAME = "nonces";

/** What a `send_email` binding must look like. */
export interface MailBinding {
  send(message: OutboundMessage): Promise<{ messageId?: unknown } | null | undefined>;
}

interface NonceStoreStub {
  claim(key: string, now: number, windowSeconds: number): Promise<boolean>;
}

export interface HandlerOptions {
  /** Clock in Unix seconds. Tests replace it. */
  now?: () => number;
  /** Name of the nonce store instance. Tests give each case its own. */
  nonceStoreName?: string;
}

/** One message's outcome, as returned alone by /v1/send or inside a batch. */
interface SendOutcome {
  status: number;
  body: Record<string, unknown>;
  outcome: "sent" | "failed" | "refused";
  code?: string;
  domain?: string;
  rcpt: number;
  dropped: number;
}

interface RequestContext {
  env: Env;
  config: GatewayConfig;
  /** Domains already reported as `misconfigured_binding` in this request. */
  reportedBindings: Set<string>;
}

function isMailBinding(value: unknown): value is MailBinding {
  return typeof value === "object" && value !== null && typeof (value as MailBinding).send === "function";
}

const decoder = new TextDecoder("utf-8", { fatal: true, ignoreBOM: true });

/** Strict UTF-8 JSON parse. A byte order mark is kept, so it fails. */
function parseJson(bytes: Uint8Array): { ok: true; value: unknown } | { ok: false } {
  try {
    return { ok: true, value: JSON.parse(decoder.decode(bytes)) };
  } catch {
    return { ok: false };
  }
}

function refused(status: number, error: string, extra: Partial<SendOutcome> = {}): SendOutcome {
  return {
    status,
    body: {
      ok: false,
      error,
      message: messageFor(error),
      ...(extra.domain !== undefined ? { domain: extra.domain } : {}),
    },
    outcome: "refused",
    code: error,
    rcpt: extra.rcpt ?? 0,
    dropped: extra.dropped ?? 0,
    ...(extra.domain !== undefined ? { domain: extra.domain } : {}),
  };
}

async function sendOne(input: unknown, rc: RequestContext): Promise<SendOutcome> {
  const v = validateMessage(input, {
    maxRecipients: rc.config.maxRecipients,
    stripHeaderPrefixes: rc.config.stripHeaderPrefixes,
  });
  if (!v.ok) {
    return refused(400, v.error);
  }
  const counts = { rcpt: v.recipients, dropped: v.dropped.length, domain: v.domain };

  const bindingName = rc.config.senders.get(v.domain);
  if (bindingName === undefined) {
    return refused(403, "domain_unknown", counts);
  }
  if (!rc.config.enabledDomains.has(v.domain)) {
    return refused(403, "domain_disabled", counts);
  }
  const binding = rc.env[bindingName];
  if (!isMailBinding(binding)) {
    if (!rc.reportedBindings.has(v.domain)) {
      rc.reportedBindings.add(v.domain);
      log({ outcome: "misconfigured_binding", domain: v.domain });
    }
    return refused(403, "domain_unknown", counts);
  }

  try {
    // Called once. Never retried here: after an ambiguous failure a retry
    // could deliver the message twice.
    const result = await binding.send(v.message);
    return {
      status: 200,
      body: {
        ok: true,
        id: typeof result?.messageId === "string" ? result.messageId : "",
        domain: v.domain,
        dropped_headers: v.dropped,
      },
      outcome: "sent",
      ...counts,
    };
  } catch (err) {
    const failure = mapProviderError(err);
    return {
      status: failure.status,
      body: {
        ok: false,
        error: failure.error,
        provider_code: failure.providerCode,
        message: failure.message,
        domain: v.domain,
      },
      outcome: "failed",
      code: failure.providerCode,
      ...counts,
    };
  }
}

/** Runs `worker` over `items` with at most `limit` in flight; results keep input order. */
export async function mapBounded<T, R>(
  items: readonly T[],
  limit: number,
  worker: (item: T) => Promise<R>,
): Promise<R[]> {
  const results = new Array<R>(items.length);
  let next = 0;
  const lanes = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (next < items.length) {
      const index = next++;
      results[index] = await worker(items[index] as T);
    }
  });
  await Promise.all(lanes);
  return results;
}

async function handleSend(bytes: Uint8Array, rc: RequestContext, fields: LogFields): Promise<Response> {
  const parsed = parseJson(bytes);
  const outcome = parsed.ok ? await sendOne(parsed.value, rc) : refused(400, "bad_json");
  Object.assign(fields, {
    outcome: outcome.outcome,
    code: outcome.code,
    domain: outcome.domain,
    rcpt: outcome.rcpt,
    dropped: outcome.dropped,
  });
  return jsonReply(outcome.status, outcome.body);
}

async function handleBatch(bytes: Uint8Array, rc: RequestContext, fields: LogFields): Promise<Response> {
  const parsed = parseJson(bytes);
  const messages =
    parsed.ok && typeof parsed.value === "object" && parsed.value !== null && !Array.isArray(parsed.value)
      ? (parsed.value as Record<string, unknown>).messages
      : undefined;
  if (!parsed.ok || !Array.isArray(messages) || messages.length === 0) {
    Object.assign(fields, { outcome: "refused", code: "bad_json" });
    return errorReply(400, "bad_json", parsed.ok ? MESSAGES.bad_batch : MESSAGES.bad_json);
  }
  if (messages.length > rc.config.maxBatch) {
    Object.assign(fields, { outcome: "refused", code: "batch_too_large", n: messages.length });
    return errorReply(400, "batch_too_large", MESSAGES.batch_too_large);
  }

  const outcomes = await mapBounded(messages, rc.config.batchConcurrency, (m) => sendOne(m, rc));
  const sent = outcomes.filter((o) => o.outcome === "sent").length;
  Object.assign(fields, {
    outcome: "batch",
    n: outcomes.length,
    sent,
    rcpt: outcomes.reduce((sum, o) => sum + o.rcpt, 0),
    dropped: outcomes.reduce((sum, o) => sum + o.dropped, 0),
  });
  return jsonReply(200, {
    ok: true,
    batch: true,
    n: outcomes.length,
    sent,
    results: outcomes.map((o) => ({ ...o.body, status: o.status })),
  });
}

function handleCheck(rc: RequestContext, slot: "current" | "next", now: number, fields: LogFields): Response {
  fields.outcome = "check";
  return jsonReply(200, {
    ok: true,
    check: true,
    key_slot: slot,
    server_time: now,
    enabled_domains: [...rc.config.enabledDomains].sort(),
    max_recipients: rc.config.maxRecipients,
    batch_max: rc.config.maxBatch,
    batch_concurrency: rc.config.batchConcurrency,
    max_body_bytes: rc.config.maxBodyBytes,
    max_skew_seconds: rc.config.maxSkewSeconds,
    version: VERSION,
  });
}

/** Every check in the documented order. Each one fails closed. */
async function handleSigned(
  request: Request,
  env: Env,
  route: Route,
  clock: () => number,
  storeName: string,
  fields: LogFields,
): Promise<Response> {
  // 10. Configuration.
  const config = readConfig(env);
  if (config.secrets.length === 0 || env.NONCE_STORE === undefined) {
    Object.assign(fields, { outcome: "misconfigured", code: "misconfigured" });
    return errorReply(503, "misconfigured", MESSAGES.misconfigured);
  }

  // 11. Size, before anything is parsed.
  const body = await readBodyCapped(request, config.maxBodyBytes);
  if (!body.ok) {
    Object.assign(fields, { outcome: "refused", code: "body_too_large" });
    return errorReply(413, "body_too_large", MESSAGES.body_too_large);
  }

  // 12. Header shape.
  const signed = readSignedHeaders(request.headers);
  if (signed === null) {
    Object.assign(fields, { outcome: "refused", code: "auth_failed" });
    return errorReply(401, "auth_failed", MESSAGES.auth_malformed);
  }

  // 13. Signature over the raw bytes, current secret first.
  const data = signingBytes(signed.timestamp, signed.nonce, body.bytes);
  let slot: "current" | "next" | undefined;
  for (const secret of config.secrets) {
    if (await verifySignature(secret.key, signed.signature, data)) {
      slot = secret.slot;
      break;
    }
  }
  if (slot === undefined) {
    Object.assign(fields, { outcome: "refused", code: "auth_failed" });
    return errorReply(401, "auth_failed", MESSAGES.auth_signature);
  }
  fields.key_slot = slot;

  // 14. Clock, only after the signature so an unsigned caller learns nothing.
  const now = clock();
  if (Math.abs(now - Number(signed.timestamp)) > config.maxSkewSeconds) {
    Object.assign(fields, { outcome: "refused", code: "stale_timestamp" });
    return errorReply(401, "stale_timestamp", MESSAGES.stale_timestamp, { server_time: now });
  }

  // 15. Replay. The store being down fails closed.
  let fresh: boolean;
  try {
    const namespace = env.NONCE_STORE;
    const stub = namespace.get(namespace.idFromName(storeName)) as unknown as NonceStoreStub;
    fresh = await stub.claim(`${signed.timestamp}.${signed.nonce}`, now, config.maxSkewSeconds * 2);
  } catch {
    Object.assign(fields, { outcome: "refused", code: "nonce_store_unavailable" });
    return errorReply(503, "nonce_store_unavailable", MESSAGES.nonce_store_unavailable);
  }
  if (!fresh) {
    Object.assign(fields, { outcome: "refused", code: "replay" });
    return errorReply(409, "replay", MESSAGES.replay);
  }

  // 16. Only now is the body parsed.
  const rc: RequestContext = { env, config, reportedBindings: new Set() };
  switch (route) {
    case "/v1/send":
      return handleSend(body.bytes, rc, fields);
    case "/v1/batch":
      return handleBatch(body.bytes, rc, fields);
    case "/v1/check":
      return handleCheck(rc, slot, now, fields);
  }
}

function isRoute(path: string): path is Route {
  return (ROUTES as readonly string[]).includes(path);
}

/** Builds the fetch handler. The default export uses the real clock. */
export function createHandler(options: HandlerOptions = {}): ExportedHandler<Env> & {
  fetch(request: Request, env: Env, ctx?: ExecutionContext): Promise<Response>;
} {
  const clock = options.now ?? (() => Math.floor(Date.now() / 1000));
  const storeName = options.nonceStoreName ?? NONCE_STORE_NAME;
  return {
    async fetch(request: Request, env: Env): Promise<Response> {
      const path = new URL(request.url).pathname;
      // 9. Path and method.
      if (!isRoute(path)) {
        return textReply(404, "Not found\n");
      }
      if (request.method !== "POST") {
        return textReply(405, "Method not allowed\n", { allow: "POST" });
      }

      const started = Date.now();
      const fields: LogFields = { route: path, outcome: "refused" };
      let response: Response;
      try {
        response = await handleSigned(request, env, path, clock, storeName, fields);
      } catch (err) {
        // Name only: the message could carry request data.
        Object.assign(fields, {
          outcome: "error",
          code: "internal_error",
          error_name: err instanceof Error ? err.name : typeof err,
        });
        response = errorReply(500, "internal_error", MESSAGES.internal_error);
      }
      fields.ms = Date.now() - started;
      log(fields);
      return response;
    },
  };
}

export default createHandler();
