/**
 * Address parsing. Three input forms are accepted:
 *
 *   "user@example.org"
 *   "Display Name <user@example.org>"   (quotes around the name are removed)
 *   { "email": "user@example.org", "name": "Display Name" }
 */

export interface Address {
  email: string;
  name?: string;
}

/** The binding wants a bare string when there is no name. */
export type BindingAddress = string | { email: string; name: string };

export const MAX_ADDRESS_LENGTH = 254;
export const MAX_NAME_LENGTH = 100;

// RFC 5322 dot-atom local part; a domain of LDH labels with at least one dot.
const ATEXT = "[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+";
const LABEL = "[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?";
const ADDRESS_RE = new RegExp(`^${ATEXT}(?:\\.${ATEXT})*@${LABEL}(?:\\.${LABEL})+$`);

const NAME_FORM_RE = /^([^<>]*)<([^<>]*)>$/;

/** True for an address that is safe to hand to the binding. */
export function isValidEmail(email: string): boolean {
  if (email.length === 0 || email.length > MAX_ADDRESS_LENGTH) {
    return false;
  }
  if (/[\r\n]/.test(email)) {
    return false;
  }
  const at = email.lastIndexOf("@");
  if (at < 1 || at > 64) {
    return false;
  }
  return ADDRESS_RE.test(email);
}

/**
 * Cleans a display name: CR, LF, tab and every other control character
 * become spaces, the result is trimmed and cut to 100 characters. An empty
 * result means no name.
 */
export function cleanName(raw: string): string | undefined {
  // eslint-disable-next-line no-control-regex -- matching control characters is the point
  const cleaned = Array.from(raw.replace(/[\x00-\x1f\x7f]/g, " ").trim())
    .slice(0, MAX_NAME_LENGTH)
    .join("")
    .trim();
  return cleaned === "" ? undefined : cleaned;
}

function stripQuotes(name: string): string {
  const trimmed = name.trim();
  if (trimmed.length >= 2 && trimmed.startsWith('"') && trimmed.endsWith('"')) {
    return trimmed.slice(1, -1).replace(/\\(["\\])/g, "$1");
  }
  return trimmed;
}

function build(email: string, name: string | undefined): Address | null {
  if (!isValidEmail(email)) {
    return null;
  }
  const cleaned = name === undefined ? undefined : cleanName(name);
  return cleaned === undefined ? { email } : { email, name: cleaned };
}

/** Parses one address in any accepted form. Returns null when invalid. */
export function parseAddress(value: unknown): Address | null {
  if (typeof value === "string") {
    if (/[\r\n]/.test(value)) {
      return null;
    }
    const trimmed = value.trim();
    const match = NAME_FORM_RE.exec(trimmed);
    if (match !== null) {
      return build((match[2] ?? "").trim(), stripQuotes(match[1] ?? ""));
    }
    return build(trimmed, undefined);
  }
  if (typeof value === "object" && value !== null && !Array.isArray(value)) {
    const record = value as Record<string, unknown>;
    if (typeof record.email !== "string") {
      return null;
    }
    if (record.name !== undefined && record.name !== null && typeof record.name !== "string") {
      return null;
    }
    if (/[\r\n]/.test(record.email)) {
      return null;
    }
    return build(record.email.trim(), typeof record.name === "string" ? record.name : undefined);
  }
  return null;
}

/**
 * Parses a recipient field. Absent, null, empty string and empty array mean
 * none. Any invalid entry fails the whole list (null).
 */
export function parseAddressList(value: unknown): Address[] | null {
  if (value === undefined || value === null || value === "") {
    return [];
  }
  const items = Array.isArray(value) ? value : [value];
  const out: Address[] = [];
  for (const item of items) {
    const parsed = parseAddress(item);
    if (parsed === null) {
      return null;
    }
    out.push(parsed);
  }
  return out;
}

export function toBindingAddress(address: Address): BindingAddress {
  return address.name === undefined ? address.email : { email: address.email, name: address.name };
}

export function domainOf(email: string): string {
  return email.slice(email.lastIndexOf("@") + 1).toLowerCase();
}
