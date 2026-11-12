/**
 * Mapping of Cloudflare Email Sending binding errors to gateway replies.
 *
 * Codes are those published for the Workers binding on 11 November 2026
 * (Email Service, "Workers API", error handling; and "Email headers").
 */

export const DAILY_LIMIT_CODE = "E_DAILY_LIMIT_EXCEEDED";
export const SEND_FAILED_CODE = "E_SEND_FAILED";
export const SENDER_NOT_ALLOWED_CODE = "E_SENDER_NOT_ALLOWED";
export const UNKNOWN_CODE = "E_UNKNOWN";
export const MAX_PROVIDER_MESSAGE = 300;

/** Published code to HTTP status. Codes not listed give 502. */
export const PROVIDER_STATUS: Readonly<Record<string, number>> = {
  E_VALIDATION_ERROR: 400,
  E_FIELD_MISSING: 400,
  E_TOO_MANY_RECIPIENTS: 400,
  E_TOO_MANY_ATTACHMENTS: 400,
  E_HEADER_NOT_ALLOWED: 400,
  E_HEADER_USE_API_FIELD: 400,
  E_HEADER_VALUE_INVALID: 400,
  E_HEADER_VALUE_TOO_LONG: 400,
  E_HEADER_NAME_INVALID: 400,
  E_HEADERS_TOO_LARGE: 400,
  E_HEADERS_TOO_MANY: 400,
  E_RECIPIENT_NOT_ALLOWED: 403,
  E_SENDER_NOT_ALLOWED: 403,
  E_CONTENT_TOO_LARGE: 413,
  E_RECIPIENT_SUPPRESSED: 422,
  E_SENDER_NOT_VERIFIED: 424,
  E_SENDER_DOMAIN_NOT_AVAILABLE: 424,
  E_ACCOUNT_SUSPENDED: 424,
  E_RATE_LIMIT_EXCEEDED: 429,
  E_DAILY_LIMIT_EXCEEDED: 429,
  E_DELIVERY_FAILED: 502,
  E_INTERNAL_SERVER_ERROR: 503,
};

export interface ProviderFailure {
  status: number;
  error: "provider_error" | "sender_not_allowed";
  providerCode: string;
  message: string;
}

// Anything shaped like local@domain. Deliberately loose: masking too much is
// harmless, leaking an address is not.
const ADDRESS_LIKE = /[^\s<>"'(),;:[\]]+@[^\s<>"'(),;:[\]]+/g;

/** Replaces anything that looks like an address with `[address]` and cuts to 300 characters. */
export function maskProviderMessage(message: string): string {
  return Array.from(message.replace(ADDRESS_LIKE, "[address]"))
    .slice(0, MAX_PROVIDER_MESSAGE)
    .join("");
}

function isDailyLimit(code: string, message: string): boolean {
  return (
    code === DAILY_LIMIT_CODE ||
    /QUOTA/i.test(code) ||
    /DAILY/i.test(code) ||
    /quota/i.test(message) ||
    /daily\s+(sending\s+)?limit/i.test(message)
  );
}

// No distinct code is published for a From outside `allowed_sender_addresses`.
// If the binding's error says so, in its code or its wording, it is reported
// as `sender_not_allowed`; otherwise it stays a provider error.
function isSenderNotAllowed(code: string, message: string): boolean {
  if (code === SENDER_NOT_ALLOWED_CODE) {
    return true;
  }
  if (code.startsWith("E_RECIPIENT_")) {
    return false;
  }
  return (
    /allowed_sender_addresses/i.test(message) ||
    /\bsender\b[^.]{0,60}\bnot\s+(allowed|permitted|authori[sz]ed)\b/i.test(message)
  );
}

/** Reads `code` and `message` from whatever the binding threw. */
export function mapProviderError(err: unknown): ProviderFailure {
  let rawCode = "";
  let rawMessage = "";
  if (typeof err === "object" && err !== null) {
    const record = err as { code?: unknown; message?: unknown };
    if (typeof record.code === "string") {
      rawCode = record.code.trim();
    }
    if (typeof record.message === "string") {
      rawMessage = record.message;
    }
  } else if (typeof err === "string") {
    rawMessage = err;
  }
  const message = maskProviderMessage(rawMessage);

  if (isDailyLimit(rawCode, rawMessage)) {
    return { status: 429, error: "provider_error", providerCode: DAILY_LIMIT_CODE, message };
  }
  if (isSenderNotAllowed(rawCode, rawMessage)) {
    return {
      status: 403,
      error: "sender_not_allowed",
      providerCode: rawCode !== "" && /^[A-Za-z0-9_]{1,64}$/.test(rawCode) ? rawCode : SENDER_NOT_ALLOWED_CODE,
      message,
    };
  }
  if (rawCode === "") {
    return { status: 502, error: "provider_error", providerCode: SEND_FAILED_CODE, message };
  }
  if (!/^[A-Za-z0-9_]{1,64}$/.test(rawCode)) {
    return { status: 502, error: "provider_error", providerCode: UNKNOWN_CODE, message };
  }
  return { status: PROVIDER_STATUS[rawCode] ?? 502, error: "provider_error", providerCode: rawCode, message };
}
