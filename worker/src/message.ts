import {
  type Address,
  type BindingAddress,
  domainOf,
  parseAddress,
  parseAddressList,
  toBindingAddress,
} from "./address";
import { filterHeaders } from "./headers";

export const MAX_SUBJECT_LENGTH = 998;

/** The object handed to the `send_email` binding. */
export interface OutboundMessage {
  from: BindingAddress;
  to: BindingAddress[];
  subject: string;
  text?: string;
  html?: string;
  cc?: BindingAddress[];
  bcc?: BindingAddress[];
  replyTo?: BindingAddress;
  headers?: Record<string, string>;
}

export type MessageError =
  | "bad_json"
  | "attachments_unsupported"
  | "bad_from"
  | "bad_recipient"
  | "too_many_recipients"
  | "bad_reply_to"
  | "bad_subject"
  | "no_body";

export type ValidationResult =
  | {
      ok: true;
      message: OutboundMessage;
      domain: string;
      recipients: number;
      dropped: string[];
    }
  | { ok: false; error: MessageError };

export interface ValidationLimits {
  maxRecipients: number;
  stripHeaderPrefixes: readonly string[];
}

function isEmptyAttachments(value: unknown): boolean {
  return value === undefined || value === null || (Array.isArray(value) && value.length === 0);
}

function nonEmptyString(value: unknown): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}

/**
 * Subject: CR and LF runs become one space, any other control character
 * (except tab) becomes a space, then the result is trimmed.
 */
export function cleanSubject(raw: string): string {
  return (
    raw
      .replace(/[\r\n]+/g, " ")
      // eslint-disable-next-line no-control-regex -- matching control characters is the point
      .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, " ")
      .trim()
  );
}

/**
 * Validates one message object. The checks run in a fixed order so a message
 * with several faults always gets the same code. Domain checks are not done
 * here: a malformed message is refused as such before its domain is looked at.
 */
export function validateMessage(input: unknown, limits: ValidationLimits): ValidationResult {
  if (typeof input !== "object" || input === null || Array.isArray(input)) {
    return { ok: false, error: "bad_json" };
  }
  const m = input as Record<string, unknown>;

  if (!isEmptyAttachments(m.attachments)) {
    return { ok: false, error: "attachments_unsupported" };
  }

  const from = parseAddress(m.from);
  if (from === null) {
    return { ok: false, error: "bad_from" };
  }

  const to = parseAddressList(m.to);
  const cc = parseAddressList(m.cc);
  const bcc = parseAddressList(m.bcc);
  if (to === null || cc === null || bcc === null || to.length === 0) {
    return { ok: false, error: "bad_recipient" };
  }
  const recipients = to.length + cc.length + bcc.length;
  if (recipients > limits.maxRecipients) {
    return { ok: false, error: "too_many_recipients" };
  }

  let replyTo: Address | undefined;
  if (m.reply_to !== undefined && m.reply_to !== null && m.reply_to !== "") {
    const parsed = parseAddress(m.reply_to);
    if (parsed === null) {
      return { ok: false, error: "bad_reply_to" };
    }
    replyTo = parsed;
  }

  if (typeof m.subject !== "string") {
    return { ok: false, error: "bad_subject" };
  }
  const subject = cleanSubject(m.subject);
  if (subject === "" || Array.from(subject).length > MAX_SUBJECT_LENGTH) {
    return { ok: false, error: "bad_subject" };
  }

  const text = nonEmptyString(m.text);
  const html = nonEmptyString(m.html);
  if (text === undefined && html === undefined) {
    return { ok: false, error: "no_body" };
  }

  const headers = filterHeaders(m.headers, limits.stripHeaderPrefixes);

  const message: OutboundMessage = {
    from: toBindingAddress(from),
    to: to.map(toBindingAddress),
    subject,
  };
  if (text !== undefined) {
    message.text = text;
  }
  if (html !== undefined) {
    message.html = html;
  }
  if (cc.length > 0) {
    message.cc = cc.map(toBindingAddress);
  }
  if (bcc.length > 0) {
    message.bcc = bcc.map(toBindingAddress);
  }
  if (replyTo !== undefined) {
    message.replyTo = toBindingAddress(replyTo);
  }
  if (Object.keys(headers.kept).length > 0) {
    message.headers = headers.kept;
  }

  return { ok: true, message, domain: domainOf(from.email), recipients, dropped: headers.dropped };
}
