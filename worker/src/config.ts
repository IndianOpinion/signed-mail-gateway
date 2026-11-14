import { log } from "./log";

/** Everything the Worker reads from its environment, already validated. */
export interface GatewayConfig {
  /** Usable secrets in check order. Secrets under 32 bytes are left out. */
  secrets: { slot: "current" | "next"; key: Uint8Array }[];
  senders: Map<string, string>;
  enabledDomains: Set<string>;
  maxSkewSeconds: number;
  maxRecipients: number;
  maxBodyBytes: number;
  maxBatch: number;
  batchConcurrency: number;
  stripHeaderPrefixes: string[];
}

/** The environment as the Worker sees it. Send bindings are looked up by name. */
export interface Env {
  GATEWAY_SECRET?: string;
  GATEWAY_SECRET_NEXT?: string;
  NONCE_STORE?: DurableObjectNamespace;
  SENDERS?: string | Record<string, unknown>;
  ENABLED_DOMAINS?: string;
  MAX_SKEW_SECONDS?: string | number;
  MAX_RECIPIENTS?: string | number;
  MAX_BODY_BYTES?: string | number;
  MAX_BATCH?: string | number;
  BATCH_CONCURRENCY?: string | number;
  STRIP_HEADER_PREFIXES?: string;
  [binding: string]: unknown;
}

export const MIN_SECRET_BYTES = 32;

interface IntSpec {
  name: string;
  def: number;
  min: number;
  max: number;
}

export const INT_VARS = {
  MAX_SKEW_SECONDS: { name: "MAX_SKEW_SECONDS", def: 300, min: 30, max: 900 },
  MAX_RECIPIENTS: { name: "MAX_RECIPIENTS", def: 10, min: 1, max: 50 },
  MAX_BODY_BYTES: { name: "MAX_BODY_BYTES", def: 4194304, min: 1024, max: 26214400 },
  MAX_BATCH: { name: "MAX_BATCH", def: 10, min: 1, max: 50 },
  BATCH_CONCURRENCY: { name: "BATCH_CONCURRENCY", def: 4, min: 1, max: 6 },
} as const satisfies Record<string, IntSpec>;

export const DEFAULT_STRIP_PREFIXES = ["X-SES-"];

// Each fallback is logged once per isolate and per raw value, not on every
// request, so a bad variable does not flood the log.
const reported = new Set<string>();

function reportDefault(name: string, raw: unknown): void {
  const key = `${name}=${String(raw)}`;
  if (reported.has(key)) {
    return;
  }
  reported.add(key);
  log({ outcome: "config_default", var: name });
}

/** For tests: forget which fallbacks were already logged. */
export function resetConfigReports(): void {
  reported.clear();
}

/**
 * Reads an integer variable. A value that does not parse falls back to the
 * default; a value outside the range is clamped to the nearest bound. Both
 * are logged once as `config_default` with the variable name.
 */
export function readInt(raw: unknown, spec: IntSpec): number {
  if (raw === undefined || raw === null || raw === "") {
    return spec.def;
  }
  const text = typeof raw === "number" ? String(raw) : typeof raw === "string" ? raw.trim() : "";
  if (!/^\d{1,9}$/.test(text)) {
    reportDefault(spec.name, raw);
    return spec.def;
  }
  const value = Number(text);
  if (value < spec.min || value > spec.max) {
    reportDefault(spec.name, raw);
    return Math.min(spec.max, Math.max(spec.min, value));
  }
  return value;
}

function commaList(raw: unknown): string[] {
  if (typeof raw !== "string") {
    return [];
  }
  return raw
    .split(",")
    .map((part) => part.trim())
    .filter((part) => part !== "");
}

function readSenders(raw: unknown): Map<string, string> {
  const map = new Map<string, string>();
  if (raw === undefined || raw === null || raw === "") {
    return map;
  }
  let parsed: unknown = raw;
  if (typeof raw === "string") {
    try {
      parsed = JSON.parse(raw);
    } catch {
      reportDefault("SENDERS", "unparsable");
      return map;
    }
  }
  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
    reportDefault("SENDERS", "not-an-object");
    return map;
  }
  for (const [domain, binding] of Object.entries(parsed)) {
    if (typeof binding === "string" && binding !== "") {
      map.set(domain.trim().toLowerCase(), binding);
    }
  }
  return map;
}

const encoder = new TextEncoder();

/** Builds the config for one request. Cheap enough to run every time. */
export function readConfig(env: Env): GatewayConfig {
  const secrets: GatewayConfig["secrets"] = [];
  for (const [slot, raw] of [
    ["current", env.GATEWAY_SECRET],
    ["next", env.GATEWAY_SECRET_NEXT],
  ] as const) {
    if (typeof raw === "string") {
      const key = encoder.encode(raw);
      if (key.byteLength >= MIN_SECRET_BYTES) {
        secrets.push({ slot, key });
      }
    }
  }

  const stripRaw = env.STRIP_HEADER_PREFIXES;
  const stripHeaderPrefixes =
    stripRaw === undefined ? DEFAULT_STRIP_PREFIXES : commaList(stripRaw).map((p) => p.toLowerCase());

  return {
    secrets,
    senders: readSenders(env.SENDERS),
    enabledDomains: new Set(commaList(env.ENABLED_DOMAINS).map((d) => d.toLowerCase())),
    maxSkewSeconds: readInt(env.MAX_SKEW_SECONDS, INT_VARS.MAX_SKEW_SECONDS),
    maxRecipients: readInt(env.MAX_RECIPIENTS, INT_VARS.MAX_RECIPIENTS),
    maxBodyBytes: readInt(env.MAX_BODY_BYTES, INT_VARS.MAX_BODY_BYTES),
    maxBatch: readInt(env.MAX_BATCH, INT_VARS.MAX_BATCH),
    batchConcurrency: readInt(env.BATCH_CONCURRENCY, INT_VARS.BATCH_CONCURRENCY),
    stripHeaderPrefixes: stripHeaderPrefixes.map((p) => p.toLowerCase()),
  };
}
