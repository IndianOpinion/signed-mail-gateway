import { LOG_EVENT } from "./version";

/**
 * Fields allowed in a log line. Nothing here may carry an address, a display
 * name, a subject, a body, a header value, the signature, the secret, the
 * nonce or a provider error message.
 */
export interface LogFields {
  route?: string;
  outcome:
    | "sent"
    | "failed"
    | "refused"
    | "batch"
    | "check"
    | "misconfigured"
    | "misconfigured_binding"
    | "config_default"
    | "error";
  code?: string;
  key_slot?: "current" | "next";
  domain?: string;
  rcpt?: number;
  dropped?: number;
  n?: number;
  sent?: number;
  ms?: number;
  var?: string;
  error_name?: string;
}

/** Writes one JSON log line. Workers Logs indexes the fields. */
export function log(fields: LogFields): void {
  console.log(JSON.stringify({ event: LOG_EVENT, ...fields }));
}
