# Architecture

This document describes how signed-mail-gateway 0.1.0 is built and records
every decision taken while building it from `docs/PRD.md`, including the
answers to the PRD's open questions (section 13). Each decision has its
reason. Decisions 1 to 9 follow the owner's rulings on those questions;
where a ruling differs from the PRD text, the ruling wins and the
difference is named.

## Layout

| Path | Language | Toolchain |
|---|---|---|
| `worker/` | TypeScript, ES modules, `strict` | npm, wrangler, vitest in the Workers runtime, eslint (typescript-eslint, strict type-checked), prettier |
| `client/` | PHP 8.1+ | Composer for dev tools only, PHPUnit 10, PHPStan level 8, phpcs PSR-12 |
| `test-vectors/v1.json` | JSON | Read by both test suites and by `examples/sign-request.mjs` |
| `examples/` | PHP, JS | Runnable samples |
| `.github/workflows/ci.yml` | | One workflow: a Node job (20, 22) and a PHP job (8.1, 8.2, 8.3) |

## The Worker

### Modules (`worker/src/`)

| Module | Role |
|---|---|
| `index.ts` | Fetch handler. Runs the checks in the PRD's order (cases 9 to 16), routes, batches with bounded concurrency, writes one log line per request. `createHandler()` takes a clock and a nonce store name so tests can drive both. |
| `config.ts` | Reads secrets and variables. Integers are parsed, defaulted and clamped; each fallback is logged once per isolate as `config_default`. |
| `body.ts` | Reads the raw body with a byte cap (see decision 6). |
| `signature.ts` | Header shape checks, the signing bytes, HMAC verification with Web Crypto `verify`. |
| `nonce-store.ts` | `NonceStore`, a SQLite-backed Durable Object with one RPC method, `claim(key, now, windowSeconds): boolean`, and an alarm sweep. |
| `address.ts` | Address parsing and validation (case 29). |
| `message.ts` | Message validation and the binding call shape (cases 28 to 38). |
| `headers.ts` | Custom header filter and the pinned Cloudflare allowlist (cases 36 and 37). |
| `provider.ts` | Binding error to reply mapping, quota normalisation, address masking (cases 40 to 42). |
| `reply.ts` | JSON and text replies, fixed reply texts. |
| `log.ts` | The log line and its allowed fields. |

### Data flow for one request

1. Path and method: 404 or 405 as plain text.
2. Configuration: a secret of 32 bytes or more and the nonce store binding, else 503 `misconfigured`.
3. Size: `Content-Length`, then the streamed body, against `MAX_BODY_BYTES`, else 413.
4. Header shape, else 401 `auth_failed` ("malformed headers").
5. HMAC over `<ts>.<nonce>.<raw body bytes>`, current secret then next, else 401 `auth_failed` ("bad signature").
6. Clock: `|now - ts| > skew` gives 401 `stale_timestamp` with `server_time`.
7. Claim `<ts>.<nonce>` in the Durable Object: 409 `replay` if seen, 503 `nonce_store_unavailable` if the store throws.
8. Parse JSON, then route: send, batch or check.

Nothing is parsed or stored before step 5 passes.

### Nonce store

One Durable Object instance, addressed by a fixed name, holds a table
`nonces (k TEXT PRIMARY KEY, claimed_at INTEGER) WITHOUT ROWID`. A claim is
one `INSERT ... ON CONFLICT DO NOTHING`, run synchronously before the
method's first `await`; `rowsWritten` says whether the key was new. A
Durable Object runs one request's synchronous code at a time on a single
strongly consistent writer, so concurrent claims of one key give exactly
one `true` (test T18 claims one key 20 times at once; T18/T96 sends one
signed request twice at once through the handler). Old keys go in an alarm
sweep (decision 3); keys start with the 10-digit timestamp so they sort in
time order. Only the key and the claim time are stored.

## The WordPress client

### Classes (`client/src/`, namespace `IndianOpinion\SignedMailGateway`)

| Class | Role |
|---|---|
| `Signer` | Pure signing: `sign()`, `nonce()`, `headers()`. |
| `Config` | Constants, env file, the config filter, per-site options, validation. Read fresh for each message. |
| `MessageBuilder` | Builds the payload the way core `wp_mail()` builds PHPMailer (cases 66 to 73). |
| `GatewayClient` | `send()`, `sendBatch()`, `check()` over the WordPress HTTP API. Encodes once, signs those bytes, no redirects. |
| `Classifier` | The one classifier (case 77) used by every path. |
| `Mailer` | The `pre_wp_mail` handler: decision, fallback with guard, events, last result, batch logging. |
| `Logger` | Attempt and notice lines through `error_log()`, keyed recipient hash. |
| `Cli` | `wp signed-mail-gateway status`, `check`, `send-test`. |
| `SiteHealth` | One direct Site Health test with a five-minute transient. |
| `Plugin` | Registers hooks; holds the instances. |
| `Uninstaller` | Deletes the four options and the transient on every site. |
| `Result`, `Reply`, `BuiltMessage`, `Outcome`, `Address` | Value objects and helpers. |

Global functions: `signed_mail_gateway_send_batch()` and
`signed_mail_gateway_last_result()` in `client/functions.php`.

### Data flow for one `wp_mail()` call

1. `wp_mail` filter (last priority): the `X-Gateway-Profile` header is
   removed and its name remembered.
2. `pre_wp_mail` (priority 10): an earlier answer wins; provider
   `wordpress` or the bypass filter returns null (core sends).
3. Configuration problems give `not_configured`.
4. `MessageBuilder` parses headers like core, applies `wp_mail_from`,
   `wp_mail_from_name`, `wp_mail_content_type`, `wp_mail_charset`, runs
   `phpmailer_init` on a private PHPMailer that is never sent, applies a
   known profile, then the domain policy (case 71).
5. Attachments give `attachments_unsupported`; no valid recipient gives
   `no_recipient`.
6. The payload filter runs, the payload is encoded once, signed and sent.
7. The reply is classified. `sent` returns true and fires
   `wp_mail_succeeded`. A fallback class with fallback on calls
   `wp_mail()` once with the guard set (cleared in `finally`). Anything
   else fires `wp_mail_failed` and returns false.

## Decisions

### Answers to the PRD's open questions

1. **Sender not allowed (ruling: `sender_not_allowed` when the binding's
   error says so, otherwise `provider_error`).** Checked against
   Cloudflare's public Email Sending documentation (Workers API, error
   codes, read 13 November 2026): the 21 published codes include no code
   for a From the account does not allow. So the Worker reads the wording:
   an error whose message names `allowed_sender_addresses` or says the
   sender is not allowed, not permitted or not authorised, and whose code
   is empty, `E_VALIDATION_ERROR` or not on the published list, is
   reported as 403 with `error: "sender_not_allowed"`; so is an unpublished
   code of the binding's own that says it (`E_SENDER_NOT_ALLOWED` and the
   like), should Cloudflare add one. `provider_code`
   carries the binding's own code, or `E_SEND_FAILED` when it gave none.
   The gateway never invents a Cloudflare-looking code. A documented,
   specific code (for example `E_SENDER_NOT_VERIFIED`) keeps its own
   meaning whatever its wording, and every other refusal is a plain
   `provider_error`. The client classes `sender_not_allowed` as
   `unavailable`: the message was certainly not sent and the core path may
   still deliver it. Tested in `worker/test/message.test.ts`.
2. **The binding is account-level (ruling).** A `send_email` binding is not
   tied to a domain: it can send from any domain onboarded to Email Sending
   on the account, so `SENDERS` may point several domains at one binding
   name and the sample declares one unrestricted binding. Which From
   addresses may send is set by the account holder in the Cloudflare
   dashboard, not in `wrangler.toml`, so the list changes without a
   redeploy and without anyone editing the Worker's config. This replaces
   the PRD's case 26 and the first half of T64, which asked for
   `allowed_sender_addresses` on every binding; T64 now checks that the
   sample binding is named in `SENDERS` and carries no hard-coded sender or
   destination list. Cloudflare's "Send bindings" page (read 13 November
   2026) also documents an optional `allowed_sender_addresses` attribute on
   the binding; the README and the sample mention it as an extra
   restriction an operator may add, never as the primary control. The
   Worker's domain map and enabled list stay a second line in either case.
3. **Nonce sweep (ruling: Durable Object with an alarm sweep).** The alarm
   is the sweep that keeps the store bounded: it is set on the first claim
   (one `getAlarm()` read per claim), deletes keys older than twice the
   skew by the object's own clock in batches of 1,000, up to 10 batches per
   run, re-arms one second later while a full batch was deleted, re-arms
   after one window while keys remain, and stops once the store is empty.
   The PRD's per-claim sweep (case 19, up to 128 old keys after each new
   claim) is kept beside it as a cheap extra for busy stores, and T19 still
   checks it; it is not what bounds an idle store. PRD case 21 and T63 said
   no timer deletes keys; under the ruling T63 checks instead that keys
   stay until the alarm runs, that the alarm removes only old keys, and
   that it re-arms or stops correctly.
4. **Shard the nonce store? (ruling: no sharding in 0.1.0).** One Durable
   Object is single-threaded; Cloudflare documents a soft limit of about
   1,000 requests a second for one object, and each claim here costs one
   insert, one small upsert and a bounded delete. A site sends dozens of
   messages a minute. Sharding would start to matter above roughly 200
   signed requests a second sustained, where claim latency begins to queue;
   then shard by the first nonce character (16 objects), which keeps one
   key on one object and so keeps replay detection exact.
5. **Error codes and the header allowlist (ruling: pinned to a
   documentation date).** Pinned to Cloudflare's Email Service
   documentation as read on 11 November 2026 and checked again on
   13 November 2026, with no change:
   - error codes, 21 of them: `E_VALIDATION_ERROR`, `E_FIELD_MISSING`,
     `E_TOO_MANY_RECIPIENTS`, `E_TOO_MANY_ATTACHMENTS`,
     `E_SENDER_NOT_VERIFIED`, `E_RECIPIENT_NOT_ALLOWED`,
     `E_RECIPIENT_SUPPRESSED`, `E_ACCOUNT_SUSPENDED`,
     `E_SENDER_DOMAIN_NOT_AVAILABLE`, `E_CONTENT_TOO_LARGE`,
     `E_DELIVERY_FAILED`, `E_RATE_LIMIT_EXCEEDED`,
     `E_DAILY_LIMIT_EXCEEDED`, `E_INTERNAL_SERVER_ERROR` and the seven
     header codes (`E_HEADER_NOT_ALLOWED`, `E_HEADER_USE_API_FIELD`,
     `E_HEADER_VALUE_INVALID`, `E_HEADER_VALUE_TOO_LONG`,
     `E_HEADER_NAME_INVALID`, `E_HEADERS_TOO_LARGE`,
     `E_HEADERS_TOO_MANY`). Source:
     `https://developers.cloudflare.com/email-service/api/send-emails/workers-api/`.
   - custom header allowlist, 26 names, with the limits of 20 allowlisted
     headers per message, 100-byte names, 2,048-byte values and 16 KB in
     total. Source:
     `https://developers.cloudflare.com/email-service/reference/headers/`.
   The codes and their statuses are in `worker/src/provider.ts` and the
   allowlist is in `worker/src/headers.ts`, each with the date and source
   in its file header. `E_ACCOUNT_SUSPENDED` maps to 424 like the other
   sender-side codes. `E_SEND_FAILED` and `E_UNKNOWN` are the gateway's own
   codes for "no code" and "a code not safe to echo"; they are not
   Cloudflare's. When Cloudflare changes either list, update both files and
   this date together.
6. **Reading the body under a cap (ruling: an ArrayBuffer under a
   `Content-Length` check with a streaming cap).** A declared
   `Content-Length` over the cap is refused with 413 before a byte is
   read. The stream is then read chunk by chunk with a running byte count
   and cancelled as soon as the total passes the cap, so a missing or
   false length never gets a larger body through and the Worker never
   buffers more than the cap plus one chunk. The chunks are copied into one
   ArrayBuffer, and the HMAC is computed over exactly those bytes. The
   platform's own request limit is a backstop, not the control. Tests T9,
   T10 and T56.
7. **Delivery events (ruling: sketch here only, not built).** A later version consumes Email
   Sending event subscriptions from a Queue in the same Worker and POSTs
   them to an origin URL the operator configures, for example
   `POST <origin>/wp-json/signed-mail-gateway/v1/events`, body
   `{"events": [{"type": "delivered|bounced|complained", "id": "<provider message id>", "at": <unix>}]}`.
   The request carries the same three headers, but the signature is over
   `event.<timestamp>.<nonce>.<body>` (lesson 26), so an event signature
   can never verify as a send request and the reverse. The origin keeps
   its own nonce cache for twice the skew. 0.1.0 does not block it: the
   provider message id is already returned, kept in the last result and
   passed to the attempt action, so a site can store it now and match
   events later.
8. **Text conversion of HTML (ruling: a small built-in converter, no
   dependency).** `client/src/HtmlToText.php`, about a hundred lines, used
   only when no plugin set `AltBody` in `phpmailer_init` (a plugin's
   `AltBody` always wins). It does not call PHPMailer's converter. Rules:
   invalid UTF-8 is scrubbed; comments, CDATA and the elements `script`,
   `style`, `head`, `title`, `template`, `noscript`, `svg`, `object` and
   `iframe` are removed with their content by string search (an element
   left open removes the rest, as a browser hides it, and no input size
   can hit a regex backtracking limit); source whitespace collapses;
   `http`, `https` and `mailto` links keep their target as `text (url)`;
   other link schemes keep the text only; block elements and `br` become
   line breaks and list items `- `; remaining tags go; entities are
   decoded exactly once, after the tags are gone, so `&lt;script&gt;` is
   text and never a tag. The output is a text part, never rendered as
   HTML, so it is not a sanitiser. Tested in
   `client/tests/HtmlToTextTest.php` against script, comment, entity and
   size edge cases.
9. **Layout for two languages (ruling: one repo, one CI with a Node job
   and a PHP job).** The Worker lives in `worker/`, not at the
   root, and the client in `client/` with its own `composer.json` for dev
   tools. Reason: each half has its own lock file, config files and
   ignore rules, and neither toolchain should see the other's files (eslint
   and prettier would otherwise walk the PHP tree, phpcs the TypeScript).
   The test vectors stay at the root because both halves read them. One CI
   workflow has a Node job and a PHP job.

### Other decisions

10. **Test runner package.** The PRD names `@cloudflare/vitest-pool-workers`.
    Its last release (0.23.0) is deprecated and its bundled runtime cannot
    run `compatibility_date` 2026-10-01 (it supports up to 2026-08-22).
    The tests use its renamed successor, `@cloudflare/vitest-plugin`
    1.6.1, with wrangler 4.159.0 and vitest 4. Same idea, same real
    runtime.
11. **Lock file and npm.** `npm install` with npm 10.9 crashed while
    resolving this tree, so `package-lock.json` was produced with npm 11.
    `npm ci` with npm 10 installs from it correctly, which is what CI runs.
12. **Worker name in the sample `wrangler.toml`.** The PRD asks for
    placeholders such as `<your-worker>`. Wrangler refuses a `name` that is
    not lower case letters, digits and dashes, even in a dry run, so the
    sample uses `signed-mail-gateway` with a comment to rename it. Every
    other operator value (domains, addresses) stays an angle-bracket
    placeholder. The tests do not read `wrangler.toml` for bindings; one
    test reads it to check the sample binding is named in `SENDERS` and
    carries no hard-coded address list (T64, decision 2).
13. **Bad versus out-of-range variables.** A variable that does not parse
    falls back to its default; a number outside its range is clamped to
    the nearest bound (case 54 says 99 is clamped). Both are logged once
    as `config_default`.
14. **`/v1/check` also reports `max_skew_seconds`.** One field beyond the
    PRD's list, so the client's Site Health test can judge the clock
    difference against the gateway's real skew rather than assuming 300.
15. **Header filter details.** First occurrence of a name wins; later
    duplicates are dropped and listed. Beyond the PRD's rules, kept headers
    are capped at Cloudflare's published limits on count and total size,
    and anything over is dropped and listed rather than failing the
    message.
16. **Client: a non-JSON 4xx is `unavailable`.** A plain-text 404 or 405,
    or a proxy or firewall refusal page, means the request never reached a
    gateway route, so the message was certainly not taken and the fallback
    may run. A non-JSON 2xx or 5xx is `unknown`. A JSON 4xx with a code not
    listed in case 77 stays `rejected`, as the PRD says.
17. **Client: transport errors.** Only cURL errors 6 (name lookup) and 7
    (connect refused), or the same wording from another transport, are
    `unavailable`. Every other transport error, including TLS errors and
    resets, is `unknown` (lesson 9).
18. **Client: the domain policy needs both options.** The From rewrite of
    case 71 runs only when `signed_mail_gateway_domains` and
    `signed_mail_gateway_default_from` are both set. With either empty the
    From is sent as built and the gateway decides (`domain_unknown`, then
    the fallback). An invalid From is also replaced when both are set.
19. **Client: what counts as a no-reply From.** Local parts `noreply`,
    `no-reply`, `do-not-reply`, `donotreply`, `mailer-daemon`, `bounce`,
    `bounces` and `wordpress` (core's default From), with optional
    suffixes such as `noreply+x`. Such a From never becomes the Reply-To.
20. **Client: mail events after a fallback.** When the core path runs as
    the fallback, core fires its own `wp_mail_succeeded` or
    `wp_mail_failed`; the client does not fire a second one. The client
    fires them only when the gateway alone decided.
21. **Client: profiles on the core path.** The `X-Gateway-Profile` header
    is removed in the `wp_mail` filter at the last priority, so neither
    path sees it. A known profile is applied to the gateway payload after
    `phpmailer_init`, and on the core path by a `phpmailer_init` callback
    at the last priority. An unknown name is logged as `unknown_profile`
    (sanitised to 40 safe characters) and ignored.
22. **Client: non-UTF-8 charsets.** If a message's charset is not UTF-8 and
    mbstring is available, subject, bodies, names and header values are
    converted to UTF-8 before encoding, because the gateway takes JSON.
    JSON encoding of invalid UTF-8 fails as `encode_failed`
    (`unavailable`), so the core path can still send it.
23. **Client: last result after a fallback.** If the core path delivers,
    the last result is class `sent`, path `wordpress`, two attempts, and
    `code` is the gateway code that caused the fallback. If it fails, the
    gateway's class and code stand with path `wordpress` (lesson 10).
24. **Client: Composer.** `client/composer.json` describes the plugin for
    development and for Composer-managed sites through a path repository.
    It is not published to Packagist in 0.1.0 because Packagist needs the
    manifest at the repository root, and the root belongs to both halves.
25. **Client: batch input.** Batch messages are in the `/v1/send` shape.
    The client checks only what it can know (recipients present, no
    attachments); everything else is the gateway's job, so both paths
    validate the same way.
26. **Tests: PHP stubs.** `client/tests/stubs/` is a small WordPress stand
    in (hooks with priorities, options per site, transients, HTTP, a
    reduced `wp_mail()` with core's filter order and events, `WP_Error`,
    and `WP_CLI`). PHPStan uses the `php-stubs` packages for real
    WordPress and WP-CLI signatures.

### Security review notes (0.1.0)

27. **One signing string, two halves.** Both halves sign
    `<timestamp>.<nonce>.<body>` over bytes: the Worker builds the prefix
    with `TextEncoder` and appends the raw body bytes; the client
    concatenates PHP byte strings and calls `hash_hmac`. Vector V10 in
    `test-vectors/v1.json` is the cross-half check: its body is exactly
    what the client's encoder produces for its `message` (non-ASCII escaped
    as `\u`, slashes not escaped, a 64-character nonce). The PHP suite
    asserts the encoder reproduces the body byte for byte and signs it to
    the vector signature; the Worker suite verifies the same bytes and
    checks the binding receives the intended message.
28. **The secret is used byte for byte on both sides.** Neither half trims
    it. The client used to trim the constant while the Worker did not, so
    a secret with surrounding whitespace would have failed every request.
29. **Control characters.** Header values with any control character other
    than tab are dropped; display names turn every control character into
    a space; subjects fold CR and LF runs and turn other control
    characters (except tab) into spaces. Addresses are ASCII dot-atom only.
30. **Secrets out of output.** The client marks every secret parameter
    `#[\SensitiveParameter]` (effective in traces from PHP 8.2), redacts
    the secret from `var_dump()` and `print_r()` of `Config`, and the
    status command redacts any user part of the URL.

## Test map

Every acceptance test in PRD section 12 has a test whose name starts with
its number: `T1` to `T68` in `worker/test/*.test.ts`, `C1` to `C38` in
`client/tests/*Test.php`. The vectors in section 12.4 are checked by both
(`worker/test/vectors.test.ts`, `client/tests/SignerTest.php`).
