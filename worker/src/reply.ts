const JSON_HEADERS = {
  "content-type": "application/json",
  "cache-control": "no-store",
};

export function jsonReply(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

export function errorReply(status: number, error: string, message: string, extra: Record<string, unknown> = {}): Response {
  return jsonReply(status, { ok: false, error, message, ...extra });
}

export function textReply(status: number, text: string, headers: Record<string, string> = {}): Response {
  return new Response(text, {
    status,
    headers: { "content-type": "text/plain; charset=utf-8", "cache-control": "no-store", ...headers },
  });
}

/** Short reply texts. Never built from request content. */
export const MESSAGES: Readonly<Record<string, string>> = {
  misconfigured: "gateway is not configured",
  body_too_large: "body is over the size limit",
  auth_malformed: "malformed headers",
  auth_signature: "bad signature",
  stale_timestamp: "timestamp outside the allowed window",
  replay: "request already seen",
  nonce_store_unavailable: "replay check unavailable",
  bad_json: "body is not a valid JSON object",
  bad_batch: "messages must be a non-empty array",
  batch_too_large: "too many messages in the batch",
  attachments_unsupported: "attachments are not supported",
  bad_from: "from is missing or invalid",
  bad_recipient: "a recipient is missing or invalid",
  too_many_recipients: "too many recipients",
  bad_reply_to: "reply_to is invalid",
  bad_subject: "subject is empty or too long",
  no_body: "text or html is required",
  domain_unknown: "sender domain is not configured",
  domain_disabled: "sender domain is disabled",
  internal_error: "internal error",
};
