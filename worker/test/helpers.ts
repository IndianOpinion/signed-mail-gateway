import { env as testEnv } from "cloudflare:workers";
import { createHandler, type Env } from "../src/index";
import { sign } from "../src/signature";
import vectorsJson from "../../test-vectors/v1.json";

export interface Vector {
  id: string;
  description: string;
  path: string;
  body: string;
  body_bytes: number;
  signed_with: "A" | "B";
  timestamp: string;
  nonce: string;
  signature: string;
  next_secret?: "A" | "B";
  clock_at?: number;
  expect: { status: number; error?: string; key_slot?: string; then?: { status: number; error?: string } };
}

export const vectors = vectorsJson as unknown as {
  secrets: { A: string; B: string };
  timestamp: string;
  nonce: string;
  vectors: Vector[];
};

export const SECRET_A = vectors.secrets.A;
export const SECRET_B = vectors.secrets.B;
export const T0 = Number(vectors.timestamp);
export const DOMAIN = "mail.example.org";
export const FROM = "news@mail.example.org";

export function vector(id: string): Vector {
  const found = vectors.vectors.find((v) => v.id === id);
  if (found === undefined) {
    throw new Error(`no vector ${id}`);
  }
  return found;
}

const encoder = new TextEncoder();

/** A fake send_email binding: records calls, can throw, counts concurrency. */
export class FakeBinding {
  calls: unknown[] = [];
  inFlight = 0;
  maxInFlight = 0;
  delayMs = 0;
  failWith: unknown = undefined;
  failWhen: ((message: Record<string, unknown>) => unknown) | undefined;
  private counter = 0;

  async send(message: unknown): Promise<{ messageId: string }> {
    this.calls.push(structuredClone(message));
    this.inFlight++;
    this.maxInFlight = Math.max(this.maxInFlight, this.inFlight);
    try {
      if (this.delayMs > 0) {
        await new Promise((resolve) => setTimeout(resolve, this.delayMs));
      }
      const failure =
        this.failWhen !== undefined ? this.failWhen(message as Record<string, unknown>) : this.failWith;
      if (failure !== undefined) {
        throw failure;
      }
      this.counter++;
      return { messageId: `msg-${this.counter}` };
    } finally {
      this.inFlight--;
    }
  }
}

export function providerError(code: string | undefined, message: string): Error {
  const err = new Error(message) as Error & { code?: string };
  if (code !== undefined) {
    err.code = code;
  }
  return err;
}

export interface Harness {
  env: Env;
  binding: FakeBinding;
  clock: { now: number };
  fetch(request: Request): Promise<Response>;
}

let storeCounter = 0;

/** A handler with its own clock and its own nonce store instance. */
export function harness(overrides: Partial<Env> = {}): Harness {
  const binding = new FakeBinding();
  const clock = { now: T0 };
  const env: Env = {
    NONCE_STORE: testEnv.NONCE_STORE,
    GATEWAY_SECRET: SECRET_A,
    SENDERS: JSON.stringify({ [DOMAIN]: "MAIL_SENDER" }),
    ENABLED_DOMAINS: DOMAIN,
    MAIL_SENDER: binding,
    ...overrides,
  };
  storeCounter++;
  const handler = createHandler({
    now: () => clock.now,
    nonceStoreName: `test-${String(storeCounter)}-${crypto.randomUUID()}`,
  });
  return {
    env,
    binding,
    clock,
    fetch: (request) => handler.fetch(request, env),
  };
}

export function randomNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
}

export interface SignOptions {
  path?: string;
  body?: string | Uint8Array;
  timestamp?: string;
  nonce?: string;
  secret?: string;
  signature?: string;
  method?: string;
  headers?: Record<string, string>;
}

export async function signFor(secret: string, timestamp: string, nonce: string, body: Uint8Array): Promise<string> {
  const prefix = encoder.encode(`${timestamp}.${nonce}.`);
  const data = new Uint8Array(prefix.byteLength + body.byteLength);
  data.set(prefix);
  data.set(body, prefix.byteLength);
  return sign(encoder.encode(secret), data);
}

/** Builds a signed request. Every field can be overridden to break it. */
export async function signedRequest(options: SignOptions = {}): Promise<Request> {
  const path = options.path ?? "/v1/send";
  const raw = options.body ?? "{}";
  const body = typeof raw === "string" ? encoder.encode(raw) : raw;
  const timestamp = options.timestamp ?? String(T0);
  const nonce = options.nonce ?? randomNonce();
  const signature = options.signature ?? (await signFor(options.secret ?? SECRET_A, timestamp, nonce, body));
  return new Request(`https://gateway.example${path}`, {
    method: options.method ?? "POST",
    body,
    headers: {
      "content-type": "application/json",
      "x-gateway-timestamp": timestamp,
      "x-gateway-nonce": nonce,
      "x-gateway-signature": signature,
      ...options.headers,
    },
  });
}

/** Signs and sends a message object through /v1/send. */
export async function send(h: Harness, message: unknown, options: SignOptions = {}): Promise<Response> {
  return h.fetch(await signedRequest({ ...options, path: options.path ?? "/v1/send", body: JSON.stringify(message) }));
}

export function message(extra: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    from: `Example News <${FROM}>`,
    to: ["reader@example.com"],
    subject: "Hello",
    text: "Hello, reader.",
    ...extra,
  };
}

export async function json(response: Response): Promise<Record<string, unknown>> {
  return (await response.json());
}

export function vectorRequest(v: Vector, overrides: Partial<SignOptions> = {}): Promise<Request> {
  return signedRequest({
    path: v.path,
    body: v.body,
    timestamp: v.timestamp,
    nonce: v.nonce,
    signature: v.signature,
    ...overrides,
  });
}
