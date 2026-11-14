/**
 * Request signing: HMAC-SHA256 over `<timestamp>.<nonce>.<raw body>`.
 *
 * The body is never decoded before verification. The comparison is done by
 * Web Crypto `verify`, which is constant time.
 */

export const TIMESTAMP_HEADER = "X-Gateway-Timestamp";
export const NONCE_HEADER = "X-Gateway-Nonce";
export const SIGNATURE_HEADER = "X-Gateway-Signature";

const TIMESTAMP_RE = /^[0-9]{10}$/;
const NONCE_RE = /^[0-9a-f]{32,64}$/;
const SIGNATURE_RE = /^[0-9A-Fa-f]{64}$/;

export interface SignedHeaders {
  timestamp: string;
  nonce: string;
  signature: Uint8Array;
}

/**
 * Checks the shape of the three headers. Returns null when any is missing or
 * malformed. The nonce must already be lower case: it is part of the signed
 * string, so it is never lowered here.
 */
export function readSignedHeaders(headers: Headers): SignedHeaders | null {
  const timestamp = headers.get(TIMESTAMP_HEADER);
  const nonce = headers.get(NONCE_HEADER);
  const signature = headers.get(SIGNATURE_HEADER);
  if (timestamp === null || nonce === null || signature === null) {
    return null;
  }
  if (!TIMESTAMP_RE.test(timestamp) || !NONCE_RE.test(nonce) || !SIGNATURE_RE.test(signature)) {
    return null;
  }
  return { timestamp, nonce, signature: hexToBytes(signature) };
}

/** Decodes even-length hex that has already been checked by a pattern. */
export function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) {
    out[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return out;
}

export function bytesToHex(bytes: ArrayBuffer | Uint8Array): string {
  const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  let hex = "";
  for (const byte of view) {
    hex += byte.toString(16).padStart(2, "0");
  }
  return hex;
}

const encoder = new TextEncoder();

/** The exact bytes that are signed: `<timestamp>.<nonce>.` followed by the body. */
export function signingBytes(timestamp: string, nonce: string, body: Uint8Array): Uint8Array {
  const prefix = encoder.encode(`${timestamp}.${nonce}.`);
  const out = new Uint8Array(prefix.byteLength + body.byteLength);
  out.set(prefix, 0);
  out.set(body, prefix.byteLength);
  return out;
}

async function importKey(secret: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey("raw", secret, { name: "HMAC", hash: "SHA-256" }, false, ["sign", "verify"]);
}

/** Constant-time check of one signature against one secret. */
export async function verifySignature(
  secret: Uint8Array,
  signature: Uint8Array,
  data: Uint8Array,
): Promise<boolean> {
  const key = await importKey(secret);
  return crypto.subtle.verify("HMAC", key, signature, data);
}

/** Lower-case hex HMAC-SHA256. Used by tests and by callers written in TypeScript. */
export async function sign(secret: Uint8Array, data: Uint8Array): Promise<string> {
  const key = await importKey(secret);
  return bytesToHex(await crypto.subtle.sign("HMAC", key, data));
}
