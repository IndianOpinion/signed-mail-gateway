# signed-mail-gateway: product requirements

Status: draft for review. Version target: 0.1.0.

The project has two halves in one repository:

- **The gateway.** A Cloudflare Worker in TypeScript. It accepts a signed
  JSON message over HTTPS and sends it through Cloudflare Email Sending. It
  refuses anything that is not signed with a shared secret, anything signed
  too long ago or too far in the future, and anything it has seen before.
- **The client.** A small WordPress plugin in PHP that hooks `pre_wp_mail`.
  It turns every `wp_mail()` call into a signed gateway request, and when the
  gateway cannot take the message it hands it back to the normal WordPress
  mail path (usually an SMTP plugin) once.

Words used in this document:

- *Operator*: the person who deploys the Worker and installs the plugin.
- *Origin*: the WordPress site that sends mail.
- *Signed request*: one HTTP POST carrying the three signature headers.
- *Sender domain*: the domain part of a From address.
- *Binding*: a Cloudflare `send_email` binding on the Worker.
- *Core path*: what `wp_mail()` does when no `pre_wp_mail` filter answers,
  that is PHPMailer plus whatever SMTP plugin the site uses.

## 1. Problem

A WordPress site sends account mail (sign-in codes, password resets,
comment notices) and often newsletters through `wp_mail()`. On many hosts
that path is fragile:

- VPS providers block outbound SMTP ports by default. `wp_mail()` then fails
  with a connect timeout, often silently, and sign-in mail stops.
- SMTP credentials live on the web server. Anyone who reads them can send as
  the domain from anywhere, with no limit on From addresses.
- A plain HTTPS mail API key stored on the origin has the same problem: a
  leaked key sends any mail as any address the key allows, at any time, and
  a captured request can be replayed.

Cloudflare Email Sending can be called only from a Worker, through a
binding. So the site needs a small Worker in front of it, and the Worker
must not become an open relay. A shared secret alone is not enough: a
captured request must not be replayable, and a leaked secret must still not
let anyone send as an address the operator did not list.

Measured result: on the site this came from, outbound SMTP was blocked on
one host and mail stopped until an HTTPS path existed. Since October 2026
the gateway has been the primary sender for every message the site sends,
with the SMTP path as a fallback.

## 2. Goals and non-goals

### Goals

1. A Worker that sends one message, or a small batch, through Cloudflare
   Email Sending, only for a request with a valid HMAC-SHA256 signature over
   timestamp, nonce and raw body.
2. Refuse a request whose timestamp is more than 300 seconds (configurable)
   from the Worker clock, in either direction.
3. Refuse any (timestamp, nonce) pair seen before inside the window, with
   storage that is strongly consistent across Worker instances.
4. Allow a From address only on sender domains the operator lists, and only
   addresses the binding allows, so a leaked secret is bounded.
5. Key rotation with no lost mail: two secrets accepted at once during a
   change.
6. Strict limits on body size, recipients, headers and batch length.
7. A stable JSON reply with a clear HTTP status and a machine code for every
   outcome.
8. Logs that let an operator see every decision, and never contain an
   address, a subject, a body, a header value or a secret.
9. A WordPress client that makes `wp_mail()` use the gateway with no change
   to the plugins that call it, and that falls back to the core path once,
   only when that is safe.
10. A published set of test vectors so any client in any language can be
    checked against the Worker.

### Non-goals

- Inbound mail, Email Routing, or reply handling.
- Delivery events (bounces, complaints, opens). Forwarding Email Sending
  event subscriptions to the origin is a possible later version; see
  section 13.
- Attachments and inline images. The gateway refuses them by design in
  0.1.0; the client sends such a message through the core path.
- Templates, open tracking, link tracking, or any message content
  processing. The gateway sends what it is given.
- Queues, scheduled sends, retries inside the Worker, or per-day quota
  accounting. The caller decides when to retry.
- Multi-tenant use. One deployment serves one operator with one secret
  (two during rotation).
- An admin screen in WordPress. Configuration is by constants, options and
  WP-CLI.
- Public-key signatures. HMAC with a shared secret is enough for one origin
  talking to one Worker.

## 3. Users and setting

- **Operator of a WordPress site** on any host that can make outbound HTTPS
  requests. They own a domain on Cloudflare, have onboarded one or more
  sending subdomains for Email Sending, and can deploy a Worker with
  Wrangler.
- **The Worker** runs on Cloudflare Workers with one or more `send_email`
  bindings and one Durable Object namespace for nonces. It is reached at
  its own URL (a custom domain or route the operator picks). It has no
  public use besides the three routes below.
- **The client** runs inside WordPress 6.4 or later on PHP 8.1 or later, as
  a normal plugin or as a must-use plugin. It needs no Composer at runtime.
- **Other callers** (a cron script, another service) can use the gateway
  directly with the test vectors in section 12.4 as their reference.

## 4. Behaviour

### 4.1 The signed request

1. Every request is an HTTP POST to one of three paths: `/v1/send`,
   `/v1/batch`, `/v1/check`. Any other path returns 404 with a short plain
   text body. Any other method on a known path returns 405 with
   `Allow: POST`.
2. Every request carries three headers. Header names are case-insensitive.
   - `X-Gateway-Timestamp`: Unix time in seconds, exactly 10 ASCII digits.
   - `X-Gateway-Nonce`: 32 to 64 lowercase hexadecimal characters. The
     client makes a fresh one for every request from a cryptographic random
     source (16 random bytes, hex encoded, is the default).
   - `X-Gateway-Signature`: 64 hexadecimal characters, the HMAC-SHA256 of
     the signing string under the shared secret.
3. The signing string is the byte sequence: timestamp, a full stop, nonce,
   a full stop, then the raw request body exactly as sent. In other words
   `<timestamp>.<nonce>.<body>`. The secret is used as its UTF-8 bytes.
   There is no other normalisation: no trimming, no JSON re-encoding, no
   line ending change.
4. The Worker reads the body as raw bytes and computes the HMAC over those
   bytes. It never decodes and re-encodes the body before verifying.
5. The signature is compared in constant time (the Web Crypto `verify`
   operation, not a string comparison).
6. The signature hex may be upper or lower case; the Worker decodes it to
   bytes before comparing. The nonce must be lower case, because it is part
   of the signed string: an upper case nonce is refused as malformed (case
   12), never silently lowered.
7. The body of `/v1/send` and `/v1/batch` must be UTF-8 JSON. The body of
   `/v1/check` may be empty or any JSON; it is signed all the same and
   ignored.
8. Body size is measured in bytes. A `Content-Length` above the limit is
   refused before the body is read. A body that turns out larger than the
   limit once read is refused too (a missing or false `Content-Length` must
   not get past the limit).

### 4.2 Order of checks (each one fails closed)

The Worker checks in this exact order and stops at the first failure. No
JSON is parsed and nothing is stored before the signature is verified, so an
unsigned caller cannot fill the nonce store or probe the validator.

9. Path and method (404, 405).
10. Configuration: at least one secret of 32 bytes or more is set, and the
    nonce store binding exists. If not: 503 `misconfigured`. This is
    logged, because it means every request is failing.
11. Size: `Content-Length` and then the read body against the body limit.
    Over: 413 `body_too_large`.
12. Header shape: timestamp is 10 digits, nonce matches the pattern,
    signature is 64 hex characters. If not: 401 `auth_failed`.
13. Signature: verified against the current secret, then against the next
    secret if one is set (section 4.6). If neither verifies: 401
    `auth_failed`. The reply never says which part was wrong beyond
    "malformed headers" or "bad signature".
14. Clock: `|now - timestamp| > max skew` gives 401 `stale_timestamp`. The
    reply includes the Worker's current Unix time in `server_time` so a
    client with a wrong clock can be diagnosed. The skew check comes after
    the signature check so an unsigned caller learns nothing, not even the
    Worker clock.
15. Replay: the key `<timestamp>.<nonce>` is claimed in the nonce store. If
    it was already claimed: 409 `replay`. If the store cannot be reached or
    throws: 503 `nonce_store_unavailable`, and nothing is sent (fail closed:
    sending without the replay check would make a captured request
    replayable during an outage).
16. Only now is the body parsed and the route handled.

A consequence the client must respect: the nonce is spent once the request
passes check 15, even if the message is then refused as invalid or the send
fails. Every new attempt, including a retry of the same message, needs a new
timestamp and a new nonce.

### 4.3 The nonce store and its window

17. Nonces live in one Durable Object instance (addressed by a fixed name)
    with its built-in storage. A Durable Object gives one strongly
    consistent writer, so two Worker instances racing with the same request
    cannot both win. KV is not acceptable: it is eventually consistent and
    would let a fast replay through.
18. Claim is atomic inside the object: if the key exists, return "seen";
    otherwise write it and return "new". Concurrent claims of one key from
    two requests give exactly one "new".
19. Keys sort in time order because they start with the 10-digit
    timestamp. After each successful claim the object deletes up to 128 of
    the oldest keys whose timestamp is older than `now - 2 * max skew`.
    Deleting a bounded number per call keeps each claim fast; steady
    traffic keeps the store small.
20. The window kept is twice the skew. A request is only accepted while
    `|now - timestamp| <= skew`, so a key older than `now - skew` can never
    be accepted again anyway. The second skew-width is a margin for clock
    steps on the Cloudflare side.
21. If traffic stops, old keys stay until the next claim. That is harmless:
    they are tiny and never match a fresh timestamp. An alarm-based sweep is
    optional (section 13).
22. The key stores only timestamp and nonce. Nothing about the message.

### 4.4 Allowed From domains and addresses

23. The operator lists sender domains and maps each to a binding name, in a
    JSON variable (section 7). Example: `{"mail.example.org":"MAIL_SENDER"}`.
24. A From whose domain (lower-cased) is not in the map: 403
    `domain_unknown`. A domain in the map whose binding is not present on
    the Worker is also `domain_unknown`, and the Worker logs
    `misconfigured_binding` with the domain once per request.
25. A second variable lists the domains currently enabled. A domain in the
    map but not enabled: 403 `domain_disabled`. This lets the operator turn
    one sending domain off (for example while its reputation recovers)
    without removing its binding.
26. Each binding should be declared with `allowed_sender_addresses` in
    `wrangler.toml`, listing the exact From addresses. Cloudflare then
    refuses any other address on that domain, so a leaked secret still
    cannot send as an address the operator did not list. The README must
    say this plainly and the sample `wrangler.toml` must show it.
27. The Worker does no other From rewriting. A From it refuses is refused;
    choosing a substitute is the client's job (case 71).

### 4.5 The message

28. `/v1/send` takes one JSON object:
    - `from` (required): an address (see case 29).
    - `to` (required): an address or a non-empty array of addresses.
    - `cc`, `bcc` (optional): an address or an array of addresses. Empty
      string, null or empty array mean none.
    - `reply_to` (optional): one address.
    - `subject` (required): a string.
    - `text`, `html` (optional each, at least one non-empty): strings.
    - `headers` (optional): an object of header name to string value.
    - `attachments` (any value other than absent, null or an empty array):
      refused with 400 `attachments_unsupported`.
    - Any other field is ignored.
29. An address is one of: `"user@example.org"`,
    `"Display Name <user@example.org>"` (quotes around the name are
    removed), or `{"email": "user@example.org", "name": "Display Name"}`.
    The address part must be at most 254 characters, contain no CR or LF,
    and match a conservative pattern (an RFC 5322 dot-atom local part, a
    domain of LDH labels with at least one dot). The display name has CR,
    LF and tab replaced by spaces, is trimmed, and is cut to 100
    characters. An empty name means no name.
30. Any invalid address in `to`, `cc` or `bcc`, or an empty `to`: 400
    `bad_recipient`. One bad address refuses the whole message; the gateway
    never sends to a partial list.
31. Total recipients (`to` + `cc` + `bcc`) above the limit (default 10):
    400 `too_many_recipients`.
32. Invalid `from`: 400 `bad_from`. Invalid `reply_to`: 400 `bad_reply_to`.
33. Subject: CR and LF runs are replaced by one space and the result is
    trimmed. Empty, or longer than 998 characters after that: 400
    `bad_subject`.
34. Both `text` and `html` empty or absent: 400 `no_body`.
35. A body that is not valid JSON, or a JSON value that is not an object:
    400 `bad_json`.
36. Headers. Each entry is kept only if all of these hold, otherwise it is
    dropped and its name is listed in the reply:
    - the name matches `[A-Za-z0-9_-]{1,100}`;
    - the value is a non-empty string after trimming, has no CR or LF, and
      is at most 2048 bytes;
    - the name is on Cloudflare's allowlist of custom headers (for example
      `List-Unsubscribe`, `List-Unsubscribe-Post`, `List-Id`,
      `In-Reply-To`, `References`, `Precedence`, `Auto-Submitted`,
      `Content-Language`), or it starts with `X-` and is not a header the
      operator lists as stripped (default: names starting with `X-SES-`,
      which mean nothing to Cloudflare).
    A dropped header never fails the message. An invalid name is listed as
    `(invalid)` rather than echoed.
37. `List-Unsubscribe-Post` is kept only when it equals
    `List-Unsubscribe=One-Click` and a kept `List-Unsubscribe` contains an
    `https://` URI in angle brackets. Otherwise it is dropped (one-click
    unsubscribe without an https target is invalid and hurts delivery).
38. The binding call gets `from`, `to`, `subject`, and when present `text`,
    `html`, `cc`, `bcc`, `replyTo` and `headers`. An address without a name
    is passed as a bare string, because the binding requires a string name
    when it gets an object.
39. On success: 200 with `{"ok": true, "id": "<provider message id>",
    "domain": "<sender domain>", "dropped_headers": [...]}`.
40. On a binding error, the Worker reads the error's code and message,
    maps the code to a status (section 5.3), masks anything that looks like
    an address in the message as `[address]`, cuts it to 300 characters,
    and replies `{"ok": false, "error": "provider_error", "provider_code":
    "<code>", "message": "<masked>", "domain": "<domain>"}`.
41. A binding refusal whose code is a daily-limit code, or whose code or
    message speaks of a quota or a daily sending limit, is reported with
    provider code `E_DAILY_LIMIT_EXCEEDED` and status 429. The client then
    treats it as "the gateway cannot take mail today", not as an unknown
    error.
42. A binding error with no code is reported as provider code
    `E_SEND_FAILED` and status 502.

### 4.6 Key rotation

43. The Worker reads two secrets: the current secret (required) and the
    next secret (optional). A request verifies if either matches. Each
    secret shorter than 32 bytes is ignored; if that leaves none, case 10.
44. Each signed request's log line records which slot matched (`current`
    or `next`), never the secret.
45. `/v1/check` returns the slot that matched, so the operator can confirm
    which key a client is using without sending mail.
46. Rotation procedure, documented in the README:
    1. Generate a new secret (32 random bytes, hex or base64url).
    2. Set it as the next secret on the Worker. Both keys now verify.
    3. Change the client to the new secret. Run the client's check
       command; it must report slot `next`.
    4. Set the new secret as the current secret and delete the next
       secret. Run the check again; it must report slot `current`.
    5. Destroy the old secret.
    At no step does a correctly configured client fail.
47. The nonce store is shared by both keys. A request signed with the old
    key cannot be replayed with the new one, because the pair is claimed
    regardless of the key.
48. Emergency rotation (secret leaked): set the new secret as current and
    remove the next secret in one step. Mail fails with `auth_failed` until
    the client is updated; the client falls back to the core path meanwhile
    (case 79), so mail keeps flowing if the core path works.

### 4.7 Batch

49. `/v1/batch` takes `{"messages": [ ... ]}`, a non-empty array of message
    objects in the `/v1/send` shape. Not an array or empty: 400 `bad_json`.
    More than the batch limit (default 10): 400 `batch_too_large`, nothing
    sent.
50. The whole batch is one signed request with one nonce. Each message is
    validated and sent on its own. One bad message does not stop the
    others.
51. Messages are sent with bounded concurrency (default 4, never above 6,
    because a Worker invocation may hold only six outbound connections at
    once).
52. The reply is 200 with `{"ok": true, "batch": true, "n": <count>,
    "sent": <count ok>, "results": [ ... ]}`. `results` has exactly one
    entry per input message, in input order. Each entry is the `/v1/send`
    reply object plus `status`, the HTTP status that message would have had
    alone.
53. A batch reply of 200 means "the batch was processed", not "every
    message was sent". Callers must read each result.
54. The batch limit and concurrency are clamped: limit to 1..50,
    concurrency to 1..6. Bad values fall back to the defaults.

### 4.8 Check

55. `/v1/check` runs every check in section 4.2, including the nonce claim,
    and sends nothing. It returns 200 with `{"ok": true, "check": true,
    "key_slot": "current|next", "server_time": <unix>, "enabled_domains":
    [...], "max_recipients": n, "batch_max": n, "batch_concurrency": n,
    "max_body_bytes": n, "version": "<package version>"}`.
56. It is the only way to test signing end to end without sending a
    message. The client's status command and Site Health test use it.

### 4.9 Failure modes of the gateway

57. Secret missing or short: 503 `misconfigured` for every request (fail
    closed). Logged as `misconfigured`.
58. Nonce store down: 503 `nonce_store_unavailable` (fail closed).
59. Binding throws: mapped per case 40. The Worker never retries a send
    itself: a retry after an ambiguous failure could send the message
    twice.
60. Worker exception anywhere else: 500 `internal_error` with no detail in
    the reply. Logged with the error name only (not the message, which
    could carry data).
61. All replies are JSON with `content-type: application/json` and
    `cache-control: no-store`, except the 404 and 405 replies, which are
    plain text.

### 4.10 The WordPress client: sending one message

62. The client hooks `pre_wp_mail` at priority 10. If a value other than
    null is already set by an earlier filter, it returns that value
    untouched.
63. If the provider setting is `wordpress`, the client does nothing and
    core sends as usual. If it is `gateway` (the default when the gateway
    is configured), the client takes over.
64. If the gateway URL or secret is missing or invalid, the client logs
    `not_configured` once per request and either falls back (case 79) or,
    with fallback off, fails the message.
65. A filter can bypass the client for one message (returning true lets
    core send it).
66. The client builds the message the way core `wp_mail()` builds it for
    PHPMailer: the same header parsing (`From`, `Content-Type` with
    charset, `Cc`, `Bcc`, `Reply-To`, everything else as custom headers),
    the `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type` and
    `wp_mail_charset` filters, then `phpmailer_init` on a private PHPMailer
    instance that is never sent. Plugins that set the From, a default
    Reply-To or the plain-text part in `phpmailer_init` keep working.
67. Recipients given as a string are split on commas, as core does.
    `Name <address>` forms are parsed. Addresses PHPMailer refuses are
    skipped, as core skips them.
68. For an HTML message the plain-text part is PHPMailer's alternative body
    if a plugin set one, else a text conversion of the HTML. For a
    plain-text message `html` is empty.
69. `MIME-Version` and `X-Mailer` headers are not passed on.
70. A message with attachments is not sent to the gateway. The client
    records `attachments_unsupported` and falls back (case 79). With
    fallback off, it fails.
71. A From whose domain is not on the client's list of gateway domains is
    replaced by the configured default From address. If the original From
    is a valid address that does not look like a no-reply address, and the
    message has no Reply-To, the original From becomes the Reply-To so
    answers still reach it. The display name is kept if there was one.
72. Sender profiles: a caller may add the header `X-Gateway-Profile: <name>`
    to a `wp_mail()` call. The client removes this header before either
    path sees the message. If the name is a profile the operator defined
    (filter, section 5.6), the profile's From address and name replace the
    From after `phpmailer_init`, because an SMTP plugin may force its own
    From there. An unknown profile name is ignored and logged. The header
    is removed even when unknown.
73. Headers whose names the operator lists as client-stripped (default:
    `X-SES-` prefix) are removed from the gateway payload, not from the
    core path.
74. The JSON body is encoded once. The exact bytes signed are the exact
    bytes sent. The encoder escapes non-ASCII characters as `\uXXXX` and
    slashes are left unescaped; whatever flags are used, the client must
    sign the encoded string it passes to the HTTP call, never re-encode.
75. The request: POST to the configured URL with path `/v1/send`,
    `Content-Type: application/json`, the three signature headers, a
    user agent naming the plugin and version, a 10 second timeout (filter),
    and redirects disabled. A redirect would send a signed body to a host
    the operator did not configure.
76. The client never logs or stores the secret, the signature, the body,
    the subject or any address.

### 4.11 The WordPress client: outcome classes

Every gateway attempt ends in exactly one class. The same classifier is used
by the single-message path and the batch API.

77. Classes:
    - `sent`: 200 and `ok` true. The provider id is kept.
    - `suppressed`: provider code `E_RECIPIENT_SUPPRESSED`. The recipient
      is known to bounce or complain. Never fall back.
    - `rejected`: the message or its recipients are wrong: `bad_recipient`,
      `too_many_recipients`, `bad_subject`, `no_body`, `bad_from`,
      `bad_reply_to`, `bad_json`, a client-side `no_recipient`, and any
      other 4xx not listed below. Falling back would send a wrong message
      by another road. Never fall back.
    - `retry`: certainly not sent, try later: 429 with
      `E_RATE_LIMIT_EXCEEDED`, `stale_timestamp`, `replay`.
    - `quota`: provider code `E_DAILY_LIMIT_EXCEEDED`.
    - `unavailable`: certainly not sent, the gateway cannot take it:
      `not_configured`, `attachments_unsupported`, `encode_failed`,
      `auth_failed`, `misconfigured`, `nonce_store_unavailable`,
      `domain_unknown`, `domain_disabled`, `batch_too_large`,
      `body_too_large`, provider codes for an unverified sender, an
      unavailable sender domain, a recipient not allowed, and the
      provider's internal-error code (503); a connection refused or a name
      that did not resolve (the request never left).
    - `unknown`: the request may have reached the gateway and the message
      may have been sent: a timeout after connecting, a reset, a 5xx
      without a known code, or a reply that is not JSON.
78. A `wp_mail()` call returns true only for `sent`.

### 4.12 The WordPress client: when the gateway is down

79. With fallback on (the default), the client sends through the core path
    once after the classes `unavailable`, `quota` and `retry`. In all three
    the message certainly did not go out, and a synchronous `wp_mail()`
    call has no later moment to retry, so the core path is the only way
    the reader gets their sign-in code.
80. The client never falls back after `sent`, `suppressed`, `rejected` or
    `unknown`. `unknown` is the important one: a timeout does not mean the
    message was not sent, and sending it again by SMTP would give the
    reader two copies. The message is reported as failed.
81. During the fallback attempt the client sets a guard so its own
    `pre_wp_mail` filter returns null and core sends normally. The guard is
    cleared in a `finally` block, even if core throws.
82. If the fallback attempt also fails, the gateway's class and code stand
    as the reason. The fallback's error is logged as a second attempt but
    never replaces the first verdict (an SMTP sandbox refusing a recipient
    must not hide a gateway problem).
83. With the provider setting `wordpress` and fallback on, the client does
    not try the gateway after a core failure. The switch is one-way on
    purpose: `wordpress` means "the operator turned the gateway off".
84. Core fires `wp_mail_succeeded` and `wp_mail_failed` only when it sends.
    For a gateway outcome the client fires them itself, with the same
    `$mail_data` shape core uses, so logging plugins keep working.
    `wp_mail_failed` gets a `WP_Error` whose message names the gateway code,
    never an address.
85. The result of the last decision in the request (class, path used,
    provider id, attempts) is available to the caller through a function,
    so a caller such as a sign-in form can show "we could not send the
    code" with a reason.

### 4.13 The WordPress client: batch API

86. A function sends a list of prepared messages in one signed `/v1/batch`
    call, at most the batch limit per call (callers split longer lists).
    The timeout is 30 seconds plus 5 per message.
87. It returns one result per input, in input order, each with its class.
    It never falls back on its own: bulk callers (a newsletter) decide per
    message whether to retry later, fall back, or give up.
88. Messages that fail client-side checks (no recipient, attachments) are
    not put in the batch and get their result directly.
89. If the gateway answers 404 on `/v1/batch` (an older gateway), the
    function sends each message through `/v1/send` instead.
90. If the whole call fails (signature, replay, size, transport), every
    message in the call gets that one result and class.
91. If the reply is 200 but `results` is missing or has the wrong length,
    every message is classed `unknown` (some may have been sent).

### 4.14 Edge cases

92. Clock on the origin wrong by more than the skew: every request gets
    `stale_timestamp` (class `retry`, fallback applies). The client logs
    the gateway's `server_time` and the local time difference once per
    request, so the operator sees "origin clock is 412 s fast" rather than
    a vague failure.
93. Timestamp exactly at the skew boundary (`|now - ts| == skew`) is
    accepted; one second beyond is refused.
94. Body exactly at the size limit is accepted; one byte over is refused.
95. The same nonce with a different timestamp is a different key and is
    accepted. Clients must still never reuse a nonce; uniqueness comes from
    16 random bytes.
96. Two identical requests arriving at the same moment: exactly one is sent,
    the other gets 409.
97. A secret containing non-ASCII characters is used as its UTF-8 bytes on
    both sides. The README recommends hex or base64url secrets to avoid
    the question.
98. A `to` given as one string with commas is one address string at the
    gateway and fails as `bad_recipient`. Splitting is a client job (case
    67), the gateway takes arrays.
99. Unicode in subject and names is sent as given; the binding handles
    encoding. The signature is over the JSON bytes, so `\u` escapes and raw
    UTF-8 give different signatures, and each verifies only as sent (test
    vectors V3a and V3b).
100. A body with a UTF-8 byte order mark fails JSON parsing as `bad_json`
     after the signature verifies. Clients must not add one.
101. Empty `headers` object or an array instead of an object: no headers,
     nothing dropped.
102. A display name made only of spaces becomes no name.
103. Plugin active on a multisite network: settings are per site; constants
     apply to all sites.

## 5. Public interface

### 5.1 HTTP routes

| Route | Body | Success |
|---|---|---|
| `POST /v1/send` | one message object | 200 `{ok, id, domain, dropped_headers}` |
| `POST /v1/batch` | `{messages: [...]}` | 200 `{ok, batch, n, sent, results}` |
| `POST /v1/check` | ignored | 200 `{ok, check, key_slot, server_time, ...}` |

### 5.2 Request headers

| Header | Format |
|---|---|
| `X-Gateway-Timestamp` | 10 digits, Unix seconds |
| `X-Gateway-Nonce` | `[0-9a-f]{32,64}` |
| `X-Gateway-Signature` | `[0-9A-Fa-f]{64}`, HMAC-SHA256 of `ts.nonce.body` |
| `Content-Type` | `application/json` (not enforced; the body must parse) |

### 5.3 Response codes

Every JSON error reply is `{"ok": false, "error": "<code>", "message":
"<short text>"}`, plus `provider_code` and `domain` for provider errors and
`server_time` for `stale_timestamp`.

| Status | `error` | Meaning |
|---|---|---|
| 400 | `bad_json` | Body not JSON, not an object, or `messages` not a non-empty array |
| 400 | `bad_from` | From missing or invalid |
| 400 | `bad_recipient` | `to` empty or any recipient invalid |
| 400 | `too_many_recipients` | Over the recipient limit |
| 400 | `bad_subject` | Subject empty or over 998 characters |
| 400 | `no_body` | Neither text nor html |
| 400 | `bad_reply_to` | Reply-To invalid |
| 400 | `attachments_unsupported` | Attachments present |
| 400 | `batch_too_large` | Over the batch limit |
| 401 | `auth_failed` | Headers malformed or signature does not verify |
| 401 | `stale_timestamp` | Outside the skew window |
| 403 | `domain_unknown` | Sender domain not mapped or binding missing |
| 403 | `domain_disabled` | Sender domain mapped but not enabled |
| 404 | (plain text) | Unknown path |
| 405 | (plain text) | Not POST |
| 409 | `replay` | (timestamp, nonce) already seen |
| 413 | `body_too_large` | Body over the byte limit |
| 500 | `internal_error` | Unexpected Worker error |
| 503 | `misconfigured` | No usable secret, or no nonce store binding |
| 503 | `nonce_store_unavailable` | Replay check could not run |
| varies | `provider_error` | Binding refused; see `provider_code` |

Provider code to status (Cloudflare's published Email Sending codes; the
builder confirms the list against current documentation):

| Status | Provider codes |
|---|---|
| 400 | validation, missing field, too many recipients, too many attachments, every header-related code |
| 403 | recipient not allowed |
| 413 | content too large |
| 422 | recipient suppressed |
| 424 | sender not verified, sender domain not available |
| 429 | rate limit exceeded, daily limit exceeded (and quota wording, case 41) |
| 502 | delivery failed, any unknown code, no code |
| 503 | provider internal error |

In a batch, these statuses appear per message in `results[i].status`.

### 5.4 Worker bindings and variables

| Name | Kind | Required | Purpose |
|---|---|---|---|
| `GATEWAY_SECRET` | secret | yes | Current HMAC key, 32 bytes or more |
| `GATEWAY_SECRET_NEXT` | secret | no | Second key accepted during rotation |
| `NONCE_STORE` | Durable Object namespace | yes | Replay guard class |
| `SENDERS` | var, JSON object | yes | Sender domain to binding name |
| `ENABLED_DOMAINS` | var, comma list | yes | Domains that may send now |
| `MAX_SKEW_SECONDS` | var | no | Default 300, clamped 30..900 |
| `MAX_RECIPIENTS` | var | no | Default 10, clamped 1..50 |
| `MAX_BODY_BYTES` | var | no | Default 4194304, clamped 1024..26214400 |
| `MAX_BATCH` | var | no | Default 10, clamped 1..50 |
| `BATCH_CONCURRENCY` | var | no | Default 4, clamped 1..6 |
| `STRIP_HEADER_PREFIXES` | var, comma list | no | Default `X-SES-` |
| one `send_email` binding per domain | binding | yes | Named in `SENDERS` |

A variable that does not parse, or is out of range, falls back to its
default and is logged once as `config_default` with the variable name.

### 5.5 Worker exports

- `default` with `fetch(request, env, ctx)`.
- The Durable Object class for nonces, with one RPC method that claims a
  key and returns a boolean. The builder names it.
- Pure helper modules (signature, validation, header filter, error mapping)
  exported for tests, not part of a public API.

### 5.6 WordPress client

Namespace `IndianOpinion\SignedMailGateway`. The builder names classes;
this is the minimum surface:

- A signer: given secret, timestamp, nonce and body, returns the hex
  signature. Pure, no WordPress.
- A gateway client: `send(array $message): Result`,
  `sendBatch(array $messages): Result[]`, `check(): Result`. Uses the
  WordPress HTTP API.
- A message builder: from `wp_mail()` attributes to the message array
  (case 66 to 73).
- A classifier: from a raw attempt to a class (case 77).
- Global functions for callers who do not use the classes:
  `signed_mail_gateway_send_batch(array $messages): array` and
  `signed_mail_gateway_last_result(): array`.

Constants (in `wp-config.php`, take precedence over options):

| Constant | Purpose |
|---|---|
| `SIGNED_MAIL_GATEWAY_URL` | Gateway base URL, must be `https://` |
| `SIGNED_MAIL_GATEWAY_SECRET` | Shared secret, 32 bytes or more |
| `SIGNED_MAIL_GATEWAY_ENV_FILE` | Optional path to a `KEY=value` file holding the two values above, for operators who keep secrets out of `wp-config.php` |

Options (stored by WordPress, set by WP-CLI):

| Option | Default | Values |
|---|---|---|
| `signed_mail_gateway_provider` | `gateway` | `gateway`, `wordpress` |
| `signed_mail_gateway_fallback` | `1` | `1`, `0` |
| `signed_mail_gateway_domains` | empty | comma list of sender domains the gateway accepts |
| `signed_mail_gateway_default_from` | empty | address used when a From is not on a gateway domain |

Filters (each default can be changed):

| Filter | Default |
|---|---|
| `signed_mail_gateway_bypass` (bool, `$atts`) | false |
| `signed_mail_gateway_profiles` (array name => {email, name}) | empty |
| `signed_mail_gateway_payload` (array message, `$atts`) | unchanged; runs before encoding |
| `signed_mail_gateway_timeout` (int seconds, route) | 10, batch 30 + 5n |
| `signed_mail_gateway_fallback_classes` (string[]) | `unavailable`, `quota`, `retry` |
| `signed_mail_gateway_strip_header_prefixes` (string[]) | `X-SES-` |
| `signed_mail_gateway_config` (array url, secret) | from constants or file |

Actions:

| Action | When |
|---|---|
| `signed_mail_gateway_attempt` (array log fields) | after every attempt, gateway or fallback |
| `signed_mail_gateway_result` (array result, `$atts`) | after the final decision for a message |

WP-CLI (`wp signed-mail-gateway ...`):

- `status`: prints provider, fallback, configured or not (never the secret),
  domains, default From, and the result of a `/v1/check` call including
  `key_slot` and clock difference.
- `check`: only the `/v1/check` call; exit code 0 on success, 1 otherwise.
- `send-test <address>`: sends one short plain-text message through the
  normal `wp_mail()` path and prints the class, path and provider id.

Site Health: one test that runs `/v1/check` (cached for 5 minutes) and
reports good, recommended (clock difference over half the skew) or
critical (check fails or not configured with provider `gateway`).

## 6. Data

- **Worker:** the Durable Object stores keys `<timestamp>.<nonce>` with the
  claim time as value. Retention: twice the skew window plus whatever is
  left when traffic stops (case 21). No message data is ever stored.
- **Client:** four options (section 5.6). No tables, no transients except
  the Site Health check cache (5 minutes). The last result is kept in
  memory for the request only.
- **Uninstall:** `uninstall.php` deletes the four options and the Site
  Health transient, on every site of a network. It never touches mail
  settings of other plugins.
- **Worker removal:** deleting the Worker and its Durable Object namespace
  removes all state. The README says how.

## 7. Configuration and sensible defaults

- Skew 300 seconds. Long enough for normal clock drift and slow requests,
  short enough that a captured request dies quickly.
- Nonce window twice the skew.
- Body limit 4 MiB. Cloudflare's own message size limit is higher, but a
  transactional message above 4 MiB without attachments is a bug.
- Recipients 10 per message. Account mail has one; a larger number usually
  means a caller is misusing `cc` for a mailing list.
- Batch 10 messages, concurrency 4.
- Client timeout 10 seconds for one message. A page that sends a sign-in
  code should not hang longer.
- Fallback on.
- Sample `wrangler.toml` ships with placeholders only (`<your-worker>`,
  `<your-domain>`, `<sender@your-domain>`), `compatibility_date` of
  2026-10-01 or later, observability on, and the Durable Object migration
  declared.
- The README includes a full worked example: one sender domain, one
  binding with two allowed addresses, secrets set with Wrangler, plugin
  configured with two constants.

## 8. Security and privacy

- **Threats addressed:** forged requests (HMAC), replay of a captured
  request (nonce store plus skew), slow replay after the window (skew),
  a leaked secret (bounded by enabled domains and the binding's allowed
  addresses, and replaced by rotation), header injection (CR and LF refused
  in addresses and header values, header allowlist), open relay (no
  unsigned path sends anything), log leakage (section 11).
- **Threats not addressed:** a compromised origin can send any mail the
  gateway allows, within the limits. The secret on the origin is as
  sensitive as SMTP credentials; the README says to keep it out of the web
  root and readable only by the PHP user.
- The secret is compared only through HMAC verification in constant time.
- The Worker reply never echoes request content except dropped header
  names (validated) and the sender domain.
- Provider error messages are masked for addresses before they leave the
  Worker.
- The client refuses a gateway URL that is not `https://` and never follows
  redirects.
- The env file, if used, must not be inside the web root; the client logs a
  warning if the file path is under `ABSPATH`.
- WP-CLI commands need no capability checks; there is no admin-facing web
  surface. The Site Health test runs only for users who can view Site
  Health.
- Recipient logging uses a keyed hash (HMAC-SHA256 with a WordPress salt,
  first 16 hex characters) so an operator can match log lines for one
  reader without the address being in the log.

## 9. Performance budget

- Worker CPU per `/v1/send` excluding the provider call: under 5 ms
  (one or two HMACs, one JSON parse, validation).
- One Durable Object round trip per request. The single nonce object
  handles hundreds of claims a second; that is far above the expected load
  of a site's mail (dozens per minute). If it ever matters, shard by the
  first nonce character (section 13).
- Batch of 10 at concurrency 4: about three provider round trips of wall
  time.
- Client overhead per `wp_mail()` before the HTTP call: under 5 ms
  (PHPMailer build without send).
- No work at all in WordPress on requests that send no mail: the plugin
  only registers hooks on load.

## 10. Compatibility

- Worker: Cloudflare Workers with Email Sending enabled on the account and
  the sending domains onboarded; Durable Objects with SQLite storage;
  `compatibility_date` 2026-10-01 or later. Zero runtime dependencies.
- Client: PHP 8.1, 8.2, 8.3; WordPress 6.4 or later; works as a plugin or
  must-use plugin. No Composer at runtime.
- SMTP plugins: works next to any plugin that hooks `phpmailer_init` or
  replaces the core path. The fallback path is whatever the site does
  without this plugin.
- Other `pre_wp_mail` filters: a filter at a lower priority that already
  answered wins (case 62).
- Page caches: not relevant; mail is sent during dynamic requests.
- Hosts: any host that allows outbound HTTPS on port 443.

## 11. Observability

Worker: one JSON log line per request through `console.log`, with
observability enabled in `wrangler.toml`.

| Field | Content |
|---|---|
| `event` | constant name of the gateway |
| `route` | `/v1/send`, `/v1/batch`, `/v1/check` |
| `outcome` | `sent`, `failed`, `refused`, `batch`, `check`, `misconfigured` |
| `code` | gateway error code or provider code |
| `key_slot` | `current` or `next` when a signature verified |
| `domain` | sender domain |
| `rcpt` | recipient count (a number, never an address) |
| `dropped` | count of dropped headers |
| `n`, `sent` | batch size and count sent |
| `ms` | wall time |

Never logged: addresses, display names, subjects, bodies, header values,
the signature, the secret, the nonce, the provider's error message.

Client: one line per attempt through `error_log`, prefixed with the plugin
name, JSON with: attempt number, path (`gateway` or `wordpress`), class,
code, HTTP status, provider id, keyed hash of the first recipient,
recipient count, profile name if any, dropped header count, time in ms. The
same array goes to the `signed_mail_gateway_attempt` action so an operator
can store it elsewhere.

How an operator sees it work: `wp signed-mail-gateway status`, the Site
Health test, the Worker log stream filtered on `outcome`, and the client
log lines.

## 12. Acceptance tests

Worker tests run in the real Workers runtime (vitest with the Workers pool),
with a fake `send_email` binding that records calls and can be told to
throw a given code. Client tests run with PHPUnit and a stub layer for
WordPress functions.

### 12.1 Gateway

| Test | Cases |
|---|---|
| T1 GET on `/v1/send` gives 405 with `Allow: POST`; POST on `/x` gives 404 | 1 |
| T2 Missing, short (9 digit) or non-digit timestamp gives 401 `auth_failed` | 2, 12 |
| T3 Nonce of 31 or 65 characters, or non-hex, gives 401 | 2, 12 |
| T4 Vector V1 verifies and sends; the fake binding gets the parsed message | 3 |
| T5 Body changed by one byte (V2 signature on V1 body) gives 401 | 3, 4 |
| T6 Signature is checked with constant-time verify (spy on `crypto.subtle.verify`) | 5 |
| T7 Upper case signature hex verifies; upper case nonce is refused | 6 |
| T8 `/v1/check` with empty body and with `{}` both verify | 7 |
| T9 `Content-Length` over the limit gives 413 without reading the body | 8, 11 |
| T10 Chunked body over the limit with no length gives 413 | 8 |
| T11 Bad signature on an invalid JSON body gives 401, not 400; nonce store untouched | 9 to 16 |
| T12 Secret unset or 31 bytes gives 503 `misconfigured` for valid-looking requests | 10, 57 |
| T13 Timestamp 301 s old and 301 s ahead give 401 `stale_timestamp` with `server_time` | 14 |
| T14 Stale timestamp with a bad signature gives `auth_failed` (no clock leak) | 14 |
| T15 Same request twice gives 200 then 409 `replay` | 15 |
| T16 Nonce store throwing gives 503 `nonce_store_unavailable` and no send | 15, 58 |
| T17 A request refused as `bad_recipient` spends its nonce; the same headers again give 409 | 16 |
| T18 Two concurrent claims of one key: exactly one true | 17, 18, 96 |
| T19 After a claim, keys older than 2x skew are deleted, at most 128 per claim | 19, 20 |
| T20 Keys newer than 2x skew survive the sweep | 20 |
| T21 Stored value contains no message data | 22 |
| T22 From on an unmapped domain gives 403 `domain_unknown` | 23, 24 |
| T23 Mapped domain whose binding is absent gives 403 `domain_unknown` and a log line | 24 |
| T24 Mapped but not enabled gives 403 `domain_disabled` | 25 |
| T25 Domain match is case-insensitive | 23 |
| T26 Missing `to`, empty `to` array, one bad address in `bcc` each give 400 `bad_recipient` | 28, 30 |
| T27 Address forms: bare, `Name <a@b>`, quoted name, object form all parse | 29 |
| T28 Address with CR, over 254 characters, or no dot in domain is invalid | 29 |
| T29 Display name over 100 characters is cut; tabs become spaces | 29, 102 |
| T30 11 recipients across to, cc, bcc gives 400 `too_many_recipients` | 31 |
| T31 Bad from and bad reply_to give their codes | 32 |
| T32 Subject with CRLF is folded; empty and 999 characters refused; 998 accepted | 33 |
| T33 No text and no html gives `no_body`; html only is accepted | 34 |
| T34 Body `[]`, `"x"`, and `{` each give 400 `bad_json` | 35 |
| T35 Attachments field gives 400 `attachments_unsupported`; empty array is accepted | 28 |
| T36 Header filter: allowlisted kept, `X-Custom` kept, `X-SES-Tags` dropped, `Bcc` dropped, value with LF dropped, 2049-byte value dropped, bad name listed as `(invalid)` | 36 |
| T37 `List-Unsubscribe-Post` kept with https `List-Unsubscribe`, dropped with mailto only, dropped alone | 37 |
| T38 Binding gets bare string for nameless address and object for named | 38 |
| T39 Success reply shape and `id` from the binding | 39 |
| T40 Binding throws with an address in its message: reply masks it as `[address]` | 40 |
| T41 Each provider code maps to its status per the table; unknown code gives 502 | 40, section 5.3 |
| T42 Daily-limit code, a code containing QUOTA, and a message "daily sending limit reached" all give 429 `E_DAILY_LIMIT_EXCEEDED` | 41 |
| T43 Error with no code gives 502 `E_SEND_FAILED` | 42 |
| T44 Request signed with the next secret verifies; log and check say `next` | 43, 44, 45 |
| T45 Next secret shorter than 32 bytes is ignored | 43 |
| T46 Same request first signed with current, replayed after rotation: 409 | 47 |
| T47 Batch of 3 with the middle one invalid: two sent, results in order, middle has its 400 status | 49, 50, 52 |
| T48 Batch of 11 gives 400 `batch_too_large` and sends nothing | 49 |
| T49 Batch with `messages` missing or empty gives `bad_json` | 49 |
| T50 Batch concurrency never exceeds the setting (fake binding counts in-flight sends) | 51, 54 |
| T51 Bad `MAX_BATCH` and `BATCH_CONCURRENCY` values fall back to defaults; 99 is clamped | 54 |
| T52 Check sends nothing and returns the documented fields | 55 |
| T53 A thrown error elsewhere gives 500 `internal_error` with no detail | 60 |
| T54 Every JSON reply has `cache-control: no-store` | 61 |
| T55 Timestamp exactly 300 s off accepted; 301 refused | 93 |
| T56 Body exactly at the limit accepted; one byte over refused | 94 |
| T57 Same nonce, different timestamp: both accepted | 95 |
| T58 Vectors V3a and V3b each verify as sent and fail when swapped | 99 |
| T59 Body with a UTF-8 BOM and a valid signature gives `bad_json` | 100 |
| T60 Headers as an array or empty object: nothing kept, nothing dropped | 101 |
| T61 Log lines for a full run of T4 to T68 contain none of the test addresses, subjects, bodies, nonces or secrets | section 11 |
| T62 Every vector in 12.4 passes in a table-driven test | section 12.4 |
| T63 With no further traffic, stored keys are unchanged (no timer deletes them); the next claim sweeps the old ones | 21 |
| T64 The sample `wrangler.toml` declares allowed sender addresses on every binding (checked by a test that reads the file); the From is passed to the binding exactly as given and a refusal comes back as a provider error, never a rewrite | 26, 27 |
| T65 Rotation sequence: current only, current plus next, next promoted to current; the client key verifies at every step and `/v1/check` reports the slot. Emergency rotation: the old key gives 401 at once | 46, 48 |
| T66 Batch where one message fails still returns 200 with `sent` less than `n`; a binding that throws is called once, never retried | 53, 59 |
| T67 A secret with non-ASCII characters verifies when both sides use its UTF-8 bytes | 97 |
| T68 A single `to` string with a comma gives 400 `bad_recipient` | 98 |

### 12.2 WordPress client

| Test | Cases |
|---|---|
| C1 Earlier filter answered: its value is returned and nothing is sent | 62 |
| C2 Provider `wordpress`: returns null, core sends | 63, 83 |
| C3 Not configured: logs `not_configured`, falls back; with fallback off returns false | 64 |
| C4 Bypass filter true: returns null | 65 |
| C5 Headers parsed as core does: From with name, Content-Type with charset, Cc, Bcc, Reply-To, custom | 66 |
| C6 A `phpmailer_init` callback that sets AltBody and Reply-To is reflected in the payload | 66, 68 |
| C7 String `to` with two addresses and a `Name <addr>` form gives two recipients | 67 |
| C8 HTML message without AltBody gets a text conversion | 68 |
| C9 `MIME-Version` and `X-Mailer` are not in the payload | 69 |
| C10 Attachments: no gateway call, falls back | 70 |
| C11 From on a non-gateway domain becomes the default From; original becomes Reply-To; a no-reply original does not | 71 |
| C12 Profile header: removed in both paths; known profile sets From after `phpmailer_init`; unknown is logged and ignored | 72 |
| C13 `X-SES-` headers are removed from the gateway payload but kept for the core path | 73 |
| C14 Signed bytes equal sent bytes (capture the HTTP call and recompute) | 74 |
| C15 Request has the three headers, JSON content type, timeout 10, redirects 0 | 75 |
| C16 No log line, action payload or `WP_Error` message contains the secret, subject, body or an address | 76, 84 |
| C17 Classifier table: every code and transport error in case 77 maps to its class | 77 |
| C18 `wp_mail()` returns true only for `sent` | 78 |
| C19 Each of `unavailable`, `quota`, `retry` falls back once; core success makes the call true | 79 |
| C20 `suppressed`, `rejected`, `unknown` never fall back | 80 |
| C21 A connect timeout (cURL 28) is `unknown`; connection refused (7) and DNS failure (6) are `unavailable` | 77, 80 |
| C22 Guard is cleared after the fallback even when core throws | 81 |
| C23 Fallback also fails: final reason is the gateway's code; two attempts logged | 82 |
| C24 `wp_mail_succeeded` and `wp_mail_failed` fire for gateway outcomes with core's `$mail_data` shape | 84 |
| C25 Last result exposes class, path, id and attempts | 85 |
| C26 Batch of 3 maps results in order; timeout is 45 s | 86, 87 |
| C27 Batch entry without recipients is not sent and gets `rejected` | 88 |
| C28 Batch 404 falls back to one `/v1/send` per message | 89 |
| C29 Batch whole-call 401 gives every message `unavailable` | 90 |
| C30 Batch 200 with wrong result count gives every message `unknown` | 91 |
| C31 `stale_timestamp` reply logs the clock difference from `server_time` | 92 |
| C32 Non-https URL is refused as `not_configured` | 75, section 8 |
| C33 Env file under ABSPATH logs a warning; values still load | section 8 |
| C34 Uninstall deletes the four options and the transient | section 6 |
| C35 Signer reproduces every vector in 12.4 | section 12.4 |
| C36 `wp signed-mail-gateway check` exits 0 on a good check and 1 on a 401 | 56, section 5.6 |
| C37 Site Health test reports good, recommended (clock difference over half the skew) and critical (check fails) from a stubbed `/v1/check`, and caches the result for 5 minutes | 56, section 5.6 |
| C38 On a multisite stub, options are read per site and constants apply to every site | 103 |

### 12.3 Integration (manual, documented in the README)

- `wrangler dev --local` with a fake binding, the client pointed at it,
  `wp signed-mail-gateway send-test` succeeds and a replay of the captured
  request with curl gets 409.

### 12.4 Test vectors

All vectors use:

- secret A: `gateway-test-secret-A-0123456789abcdef` (38 bytes)
- secret B: `gateway-test-secret-B-fedcba9876543210` (38 bytes)
- timestamp: `1767225600` (2026-01-01T00:00:00Z); tests set the Worker
  clock to this value
- nonce: `0123456789abcdef0123456789abcdef`

Bodies are given exactly; there is no trailing newline. Signatures are
lower-case hex of HMAC-SHA256(secret, `1767225600.0123456789abcdef0123456789abcdef.` + body).

**V1, valid send (secret A).** Body (117 bytes):

```
{"from":"Example News <news@mail.example.org>","to":["reader@example.com"],"subject":"Hello","text":"Hello, reader."}
```

Signature: `5b56c78ceb06a10c7b062c67a66e4a414e3d7c2eb2e15e93ee22cca8b54d353b`.
Expected: 200 when `mail.example.org` is mapped and enabled.

**V2, one byte changed (secret A).** Body as V1 with the final full stop of
the text replaced by `!` (117 bytes):

```
{"from":"Example News <news@mail.example.org>","to":["reader@example.com"],"subject":"Hello","text":"Hello, reader!"}
```

Signature: `4bba3d9a61897a56b58d0e56d22b0e746e130bfa0e5e600b8b82e101f7be9e1b`.
Expected: V2 body with V2 signature gives 200. V2 body with V1 signature
gives 401 `auth_failed`.

**V3a, raw UTF-8 (secret A).** Body (106 bytes):

```
{"from":"news@mail.example.org","to":["reader@example.com"],"subject":"नमस्ते","text":"Café"}
```

Signature: `ee9c50409c61179c7427a0c95f97cde9b817d6715fcc49847014807f998ec64b`.

**V3b, the same message with `\u` escapes (secret A).** Body (128 bytes):

```
{"from":"news@mail.example.org","to":["reader@example.com"],"subject":"\u0928\u092e\u0938\u094d\u0924\u0947","text":"Caf\u00e9"}
```

Signature: `dea3f00920588d0001e296bda2664e78a74eb7d6083b2bf55cc6b47cc4657ef3`.
Expected: each verifies with its own signature and sends the same subject
and text; swapping the signatures gives 401.

**V4, check (secret A).** Path `/v1/check`, body `{}` (2 bytes).
Signature: `67ea6f0dce81cb15a91232faae99547b2c1ba8697e113a94c110696f3ddac43f`.
Expected: 200, `key_slot` `current`.

**V5, upper case signature.** V1 with its signature in upper case hex.
Expected: 200.

**V6, upper case nonce.** V1 with the nonce header in upper case and the
V1 signature. Expected: 401 `auth_failed`.

**V7, next key (secret B as the next secret, secret A current).** V1 body.
Signature: `344dd40efdaec51321e5d4086616a8fcd10aa003e964da41c681708e35dd782d`.
Expected: 200 and `key_slot` `next` in the log. With secret B unset: 401.

**V8, replay.** V1 sent twice. Expected: 200, then 409 `replay`.

**V9, skew.** V1 headers and body with the Worker clock at `1767225900`
(300 s later): 200. At `1767225901`: 401 `stale_timestamp`. At `1767225299`
(301 s earlier): 401 `stale_timestamp`.

The builder ships these vectors as a JSON file in the repository (for
example `test-vectors/v1.json`) used by both the Worker tests and the PHP
tests, so the two halves can never drift apart. A PHP cross-check of V4
with `hash_hmac` was made while writing this document.

## 13. Open questions (answered by the builder in ARCHITECTURE.md)

1. Does the `send_email` binding throw a distinct, documented error code
   when the From is not in `allowed_sender_addresses`? If so, map it to 403
   with its own provider code and add a test; if not, document what it
   does.
2. Can one `send_email` binding serve several sender domains? If yes, the
   `SENDERS` map may point several domains at one binding; confirm and
   document.
3. Should the nonce object use an alarm to sweep keys when traffic stops,
   or is the per-claim sweep enough? Decide and record why.
4. Should the nonce store shard (for example by the first nonce character)
   for higher throughput? Default answer: no for 0.1.0; record the
   threshold at which it would matter.
5. The exact current list of Cloudflare Email Sending error codes and the
   custom header allowlist. Pin them to the documentation date and record
   the source.
6. How the Worker reads the raw body bytes while enforcing the size limit
   without buffering an unbounded stream (stream with a byte counter, or
   rely on the platform request size limit plus a post-read check). Record
   the choice.
7. Delivery events: a later version may forward Email Sending event
   subscriptions (delivered, bounced, complained) from a queue to the
   origin, signed with the same secret under a distinct prefix. Sketch the
   interface so 0.1.0 does not block it, but do not build it.
8. The client's text conversion of HTML: use PHPMailer's `html2text` or a
   small dedicated converter. Record the choice.
9. Repository layout for two languages (Worker at the root, client under
   `wordpress/` with its own `composer.json` for dev tools, one CI with a
   Node job and a PHP job). Confirm and document.

## 14. Lessons from production

1. **Sign the bytes you send.** Encode the JSON once, sign that string, send
   that string. Any re-encoding between signing and sending (a library that
   pretty-prints, a different escape flag) breaks every signature.
2. **Verify the bytes you received.** The Worker must hash the raw body,
   not a parsed and re-serialised copy.
3. **Authenticate before anything else.** No parsing, no storage, no
   clock answer before the signature verifies. Otherwise unsigned callers
   can fill the nonce store or probe the validator.
4. **A spent nonce stays spent.** The nonce is claimed before the message
   is validated, so a retry must always get a new timestamp and nonce.
   Reusing headers on retry gives 409.
5. **Use a strongly consistent store for nonces.** An eventually consistent
   store lets a fast replay through. One Durable Object is enough.
6. **Keep the nonce window twice the skew.** It costs nothing and covers
   clock steps.
7. **Measure size in bytes.** A string length check counts characters and
   lets a multi-byte body past the limit.
8. **Lower-case nothing that is signed.** Normalising the nonce on the
   server after the client signed it turns every upper case nonce into a
   bad signature that is hard to debug. Refuse it as malformed instead.
9. **A timeout is not a failure.** After a timeout the message may have
   gone. Never resend it by another road; report it as unknown. Only a
   refused connection or a failed name lookup proves it did not leave.
10. **A fallback must never decide a message's fate.** If the backup path
    also fails, the first verdict stands. A sandboxed SMTP account refusing
    an address once hid a real gateway problem.
11. **Never fall back after a recipient verdict.** A suppressed or invalid
    recipient sent by SMTP instead hurts the domain's reputation.
12. **Use one classifier for every path.** Two code paths that classify the
    same gateway reply differently (one falls back on a stale clock, the
    other does not) cause mail that works in one place and not another.
13. **Bound the leaked-secret case at the provider.** List exact From
    addresses on each binding. The Worker's domain check is a second line,
    not the only one.
14. **Turn a domain off without a redeploy of bindings.** Keep a separate
    enabled list so one sending domain can stop while its reputation recovers.
15. **Normalise quota errors.** The provider's daily-limit refusal arrives
    with more than one code and wording. Map every variant to one code so
    the client sees "quota", not an ambiguous 502.
16. **Mask addresses in provider error text.** Provider messages can quote
    the recipient. Mask before the text reaches a reply or a log.
17. **Do not log what you would not publish.** Counts, codes, domains and
    keyed hashes are enough to debug delivery. Addresses, subjects and
    bodies are not needed and must not be there.
18. **Build the message the way core builds it.** Plugins rely on
    `phpmailer_init` and the From filters; skipping them silently changes
    the From, loses the Reply-To or drops the plain-text part.
19. **Apply sender profiles after `phpmailer_init`.** SMTP plugins force
    their own From inside that hook; a profile From set before it is lost.
20. **Fire the core mail events yourself.** When the filter answers, core
    fires neither `wp_mail_succeeded` nor `wp_mail_failed`; logging
    plugins go blind unless the client fires them.
21. **Guard against recursion.** The fallback calls `wp_mail()`, which runs
    the filter again. A guard flag, cleared in `finally`, stops the loop.
22. **One-click unsubscribe needs an https target.** A
    `List-Unsubscribe-Post` header without an https `List-Unsubscribe`
    makes the message look broken to receivers. Drop it.
23. **Pass a bare address when there is no name.** The binding refuses an
    address object without a string name.
24. **Never follow redirects with a signed body.** A redirect sends the
    signed message to a host nobody configured.
25. **Keep top-level keys above the first table in `wrangler.toml`.** In
    TOML a key written after a `[[table]]` header belongs to that table, so
    a variable placed below the bindings silently disappears.
26. **Give every future signed message type its own prefix.** If the
    Worker ever signs callbacks to the origin with the same secret, sign
    `<type>.<timestamp>.<nonce>.<body>` so a signature for one kind can
    never verify as another.
27. **Report the server clock on a stale timestamp.** A wrong origin clock
    otherwise looks like a random authentication failure.
