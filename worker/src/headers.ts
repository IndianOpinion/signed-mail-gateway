/**
 * Custom header filter.
 *
 * The allowlist is Cloudflare Email Sending's list of non-X custom headers as
 * published on 11 November 2026 (Email Service reference, "Email headers").
 * Any other non-X header makes the whole send fail at the provider, so it is
 * dropped here and named in the reply instead.
 */

export const ALLOWED_HEADERS: readonly string[] = [
  // Threading
  "In-Reply-To",
  "References",
  "Thread-Index",
  "Thread-Topic",
  // List management
  "List-Unsubscribe",
  "List-Unsubscribe-Post",
  "List-Id",
  "List-Archive",
  "List-Help",
  "List-Owner",
  "List-Post",
  "List-Subscribe",
  "Precedence",
  // Automated messages
  "Auto-Submitted",
  // Content and display
  "Content-Language",
  "Keywords",
  "Comments",
  "Importance",
  "Priority",
  "Sensitivity",
  "Organization",
  // Delivery and notification
  "Require-Recipient-Valid-Since",
  "Expires",
  "Reply-By",
  // Modern standards
  "Archived-At",
  // One-time passcodes
  "OTP-Token",
];

/** Provider limits on custom headers, from the same page. */
export const MAX_ALLOWLISTED_HEADERS = 20;
export const MAX_HEADER_VALUE_BYTES = 2048;
export const MAX_HEADERS_TOTAL_BYTES = 16 * 1024;

export const INVALID_NAME = "(invalid)";

const NAME_RE = /^[A-Za-z0-9_-]{1,100}$/;
// eslint-disable-next-line no-control-regex -- matching control characters is the point
const CONTROL_RE = /[\x00-\x08\x0a-\x1f\x7f]/;
const allowed = new Map(ALLOWED_HEADERS.map((name) => [name.toLowerCase(), name]));
const encoder = new TextEncoder();

export interface HeaderFilterResult {
  kept: Record<string, string>;
  dropped: string[];
}

function hasHttpsUnsubscribe(value: string): boolean {
  return /<https:\/\/[^<>\s]+>/i.test(value);
}

/**
 * Keeps the headers that the provider will accept and that are safe; lists
 * the rest by name. A dropped header never fails the message.
 */
export function filterHeaders(raw: unknown, stripPrefixes: readonly string[]): HeaderFilterResult {
  const kept: Record<string, string> = {};
  const dropped: string[] = [];
  if (typeof raw !== "object" || raw === null || Array.isArray(raw)) {
    return { kept, dropped };
  }

  const seen = new Set<string>();
  let allowlistedCount = 0;
  let totalBytes = 0;

  for (const [name, value] of Object.entries(raw as Record<string, unknown>)) {
    if (!NAME_RE.test(name)) {
      dropped.push(INVALID_NAME);
      continue;
    }
    const lower = name.toLowerCase();
    const isX = lower.startsWith("x-");
    const isAllowed = allowed.has(lower);
    // CR and LF would start a new header; other control characters (NUL
    // among them) have no place in a header value either. Tab is allowed.
    if (typeof value !== "string" || CONTROL_RE.test(value)) {
      dropped.push(name);
      continue;
    }
    const trimmed = value.trim();
    const valueBytes = encoder.encode(trimmed).byteLength;
    if (trimmed === "" || valueBytes > MAX_HEADER_VALUE_BYTES) {
      dropped.push(name);
      continue;
    }
    if (!isAllowed && (!isX || stripPrefixes.some((prefix) => lower.startsWith(prefix)))) {
      dropped.push(name);
      continue;
    }
    if (seen.has(lower)) {
      dropped.push(name);
      continue;
    }
    if (isAllowed && allowlistedCount >= MAX_ALLOWLISTED_HEADERS) {
      dropped.push(name);
      continue;
    }
    const cost = encoder.encode(name).byteLength + 2 + valueBytes + 2;
    if (totalBytes + cost > MAX_HEADERS_TOTAL_BYTES) {
      dropped.push(name);
      continue;
    }
    seen.add(lower);
    if (isAllowed) {
      allowlistedCount++;
    }
    totalBytes += cost;
    kept[name] = trimmed;
  }

  // One-click unsubscribe is valid only with an https target.
  const postName = Object.keys(kept).find((n) => n.toLowerCase() === "list-unsubscribe-post");
  if (postName !== undefined) {
    const unsubName = Object.keys(kept).find((n) => n.toLowerCase() === "list-unsubscribe");
    const unsub = unsubName === undefined ? undefined : kept[unsubName];
    if (
      kept[postName] !== "List-Unsubscribe=One-Click" ||
      unsub === undefined ||
      !hasHttpsUnsubscribe(unsub)
    ) {
      dropped.push(postName);
      return { kept: Object.fromEntries(Object.entries(kept).filter(([n]) => n !== postName)), dropped };
    }
  }

  return { kept, dropped };
}
