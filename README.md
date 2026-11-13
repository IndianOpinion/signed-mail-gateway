# signed-mail-gateway

A Cloudflare Worker that sends mail through Cloudflare Email Sending only for requests signed with HMAC-SHA256 over timestamp, nonce and body, and that refuses replays and stale clocks. It comes with a small WordPress plugin that sends every `wp_mail()` call through the Worker and falls back to the normal mail path once, only when the message certainly did not go out.

Measured result: on the site this came from, outbound SMTP was blocked on one host and sign-in mail stopped until an HTTPS path existed; since October 2026 the gateway has sent every message the site sends, with SMTP as the fallback.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Contents

- [What is in the box](#what-is-in-the-box)
- [Install](#install)
- [Quick start: a worked example](#quick-start-a-worked-example)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Key rotation](#key-rotation)
- [Operating it](#operating-it)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## What is in the box

| Path | What |
|---|---|
| `worker/` | The gateway: a TypeScript Worker with zero runtime dependencies, a Durable Object nonce store, and vitest tests that run in the real Workers runtime. |
| `client/` | The WordPress plugin (PHP 8.1+, WordPress 6.4+). No Composer at runtime. |
| `test-vectors/v1.json` | Signing test vectors, used by both test suites. Check any other client against them. |
| `examples/` | Runnable samples: sign a request from PHP or Node, a must-use plugin loader, a batch call. |
| `docs/PRD.md`, `docs/ARCHITECTURE.md` | The requirements and the design decisions. |

## Install

### The Worker

You need a Cloudflare account with Email Sending enabled, at least one sending domain onboarded, and Node 20 or later.

```sh
git clone https://github.com/IndianOpinion/signed-mail-gateway.git
cd signed-mail-gateway/worker
npm ci
# Edit wrangler.toml: name, route, SENDERS, ENABLED_DOMAINS, the send_email binding and its allowed addresses.
npx wrangler secret put GATEWAY_SECRET
npx wrangler deploy
```

### The WordPress plugin, without Composer

Copy the `client/` directory to `wp-content/plugins/signed-mail-gateway/` and activate **Signed Mail Gateway**. That is all: the plugin loads its own classes through `client/bootstrap.php`.

As a must-use plugin, copy the directory to `wp-content/mu-plugins/signed-mail-gateway/` and add a one-line loader, `wp-content/mu-plugins/signed-mail-gateway-loader.php`:

```php
<?php
require __DIR__ . '/signed-mail-gateway/signed-mail-gateway.php';
```

`examples/mu-loader.php` is that file.

### The WordPress plugin, with Composer

Composer is only used for the plugin's development tools; the plugin has no runtime dependencies. On a Composer-managed site (Bedrock and similar), point a path repository at a checkout:

```json
{
    "repositories": [{ "type": "path", "url": "../signed-mail-gateway/client" }],
    "require": { "indianopinion/signed-mail-gateway": "*" }
}
```

With `composer/installers` the package lands in your plugins directory because its type is `wordpress-plugin`. Without it, require `vendor/indianopinion/signed-mail-gateway/signed-mail-gateway.php` from a must-use plugin.

## Quick start: a worked example

One sending subdomain, `mail.example.org`, with two allowed From addresses.

1. **`worker/wrangler.toml`.** Keep every top-level key above the first table: in TOML a key written after a `[table]` header belongs to that table, so a variable placed below the bindings silently disappears.

   ```toml
   name = "mail-gateway"
   main = "src/index.ts"
   compatibility_date = "2026-10-01"
   workers_dev = false
   routes = [{ pattern = "mail-gateway.example.org", custom_domain = true }]

   [observability]
   enabled = true

   [vars]
   SENDERS = '{"mail.example.org":"MAIL_SENDER"}'
   ENABLED_DOMAINS = "mail.example.org"

   [[send_email]]
   name = "MAIL_SENDER"
   allowed_sender_addresses = ["news@mail.example.org", "accounts@mail.example.org"]

   [[durable_objects.bindings]]
   name = "NONCE_STORE"
   class_name = "NonceStore"

   [[migrations]]
   tag = "v1"
   new_sqlite_classes = ["NonceStore"]
   ```

   **List the exact From addresses in `allowed_sender_addresses` on every binding.** Cloudflare then refuses any other address, so even a leaked gateway secret cannot send as an address you did not list. The Worker's own domain check is a second line, not the only one.

2. **Secret.** Generate 32 random bytes as hex, set it on the Worker, deploy:

   ```sh
   openssl rand -hex 32
   npx wrangler secret put GATEWAY_SECRET
   npx wrangler deploy
   ```

3. **WordPress.** In `wp-config.php`:

   ```php
   define( 'SIGNED_MAIL_GATEWAY_URL', 'https://mail-gateway.example.org' );
   define( 'SIGNED_MAIL_GATEWAY_SECRET', 'the-64-hex-characters-from-step-2' );
   ```

   Then the options, with WP-CLI:

   ```sh
   wp option update signed_mail_gateway_domains mail.example.org
   wp option update signed_mail_gateway_default_from 'Example News <news@mail.example.org>'
   wp signed-mail-gateway check
   wp signed-mail-gateway send-test you@example.com
   ```

   `check` must print `key slot current`. `send-test` prints the class, the path and the provider id.

### Manual end-to-end check (local)

`npx wrangler dev --local` in `worker/` runs the gateway with a local nonce store; point `SIGNED_MAIL_GATEWAY_URL` at it through a local HTTPS proxy, run `wp signed-mail-gateway send-test`, then replay the captured request with curl: the replay gets 409 `replay`. `examples/sign-request.php` and `examples/sign-request.mjs` build such a request by hand.

## Configuration

### Worker

| Name | Kind | Required | Default | Purpose |
|---|---|---|---|---|
| `GATEWAY_SECRET` | secret | yes | | Current HMAC key, 32 bytes or more |
| `GATEWAY_SECRET_NEXT` | secret | no | | Second key accepted during a key change |
| `NONCE_STORE` | Durable Object | yes | | Replay guard, class `NonceStore` |
| `SENDERS` | var, JSON object | yes | | Sender domain (lower case) to `send_email` binding name |
| `ENABLED_DOMAINS` | var, comma list | yes | | Domains that may send now |
| `MAX_SKEW_SECONDS` | var | no | 300 | Clamped 30..900 |
| `MAX_RECIPIENTS` | var | no | 10 | Per message, clamped 1..50 |
| `MAX_BODY_BYTES` | var | no | 4194304 | Clamped 1024..26214400 |
| `MAX_BATCH` | var | no | 10 | Clamped 1..50 |
| `BATCH_CONCURRENCY` | var | no | 4 | Clamped 1..6 |
| `STRIP_HEADER_PREFIXES` | var, comma list | no | `X-SES-` | X- headers to drop |

A value that does not parse falls back to its default; a value out of range is clamped. Both are logged once as `config_default` with the variable name.

### WordPress constants (win over everything else)

| Constant | Purpose |
|---|---|
| `SIGNED_MAIL_GATEWAY_URL` | Gateway base URL. Must be `https://`. |
| `SIGNED_MAIL_GATEWAY_SECRET` | Shared secret, 32 bytes or more. |
| `SIGNED_MAIL_GATEWAY_ENV_FILE` | Path to a `KEY=value` file holding the two values above. Keep it outside the web root; the plugin logs `env_file_in_web_root` if it is not. |

### WordPress options (per site)

| Option | Default | Values |
|---|---|---|
| `signed_mail_gateway_provider` | `gateway` | `gateway`, `wordpress` (turns the gateway off) |
| `signed_mail_gateway_fallback` | `1` | `1`, `0` |
| `signed_mail_gateway_domains` | empty | Comma list of sender domains the gateway accepts |
| `signed_mail_gateway_default_from` | empty | Address used when a From is not on a gateway domain |

### Filters and actions

| Hook | Default |
|---|---|
| `signed_mail_gateway_bypass` (bool, `$atts`) | `false`; return true to let core send this one message |
| `signed_mail_gateway_profiles` (array name => `{email, name}`) | empty |
| `signed_mail_gateway_payload` (array message, `$atts`) | unchanged; runs before encoding |
| `signed_mail_gateway_timeout` (int seconds, route) | 10; batch 30 + 5 per message |
| `signed_mail_gateway_fallback_classes` (string[]) | `unavailable`, `quota`, `retry` |
| `signed_mail_gateway_strip_header_prefixes` (string[]) | `X-SES-` |
| `signed_mail_gateway_config` (array `url`, `secret`) | from constants or the env file |
| action `signed_mail_gateway_attempt` (array log fields) | after every attempt |
| action `signed_mail_gateway_result` (array result, `$atts`) | after the final decision for a message |

Sender profiles: add `X-Gateway-Profile: alerts` to a `wp_mail()` call and define the profile:

```php
add_filter( 'signed_mail_gateway_profiles', fn () => [
    'alerts' => [ 'email' => 'alerts@mail.example.org', 'name' => 'Site alerts' ],
] );
```

The header is removed before either path sees the message. The profile From is applied after `phpmailer_init`, so an SMTP plugin that forces its own From there does not override it.

### PHP functions

- `signed_mail_gateway_send_batch( array $messages ): array` sends up to the gateway's batch limit of prepared messages (the `/v1/send` shape) in one signed call and returns one result per input, in order. It never falls back.
- `signed_mail_gateway_last_result(): array` returns the last decision in this request: `class`, `code`, `path`, `id`, `attempts`, `status`.

## How it works

```
wp_mail() -> pre_wp_mail (client) -> build like core, phpmailer_init on a private PHPMailer
          -> encode JSON once -> sign "<ts>.<nonce>.<body>" -> POST /v1/send (no redirects)
Worker:   path, method -> config -> size -> header shape -> HMAC verify (current, then next)
          -> clock skew -> claim "<ts>.<nonce>" in the Durable Object -> parse -> validate -> send_email binding
```

- **Authenticate first.** No JSON is parsed and nothing is stored before the signature verifies. The skew check comes after the signature, so an unsigned caller does not even learn the Worker clock.
- **Replays.** The key `<timestamp>.<nonce>` is claimed in one SQLite-backed Durable Object, which has one strongly consistent writer: two racing copies of one request give exactly one send. Keys are kept for twice the skew; each claim deletes up to 128 expired keys and an alarm sweeps when traffic stops.
- **A spent nonce stays spent.** The nonce is claimed before the message is validated, so every retry needs a new timestamp and nonce. The client always makes new ones.
- **Leaked secret.** It can only send from the domains in `ENABLED_DOMAINS`, only as the addresses each binding allows, within the limits, until you rotate it.
- **Client outcome classes.** Every attempt ends in exactly one class: `sent`, `suppressed`, `rejected`, `retry`, `quota`, `unavailable` or `unknown`. The core path is tried once only after `unavailable`, `quota` or `retry`, when the message certainly did not leave. A timeout is `unknown` and never falls back: the message may have gone, and a second copy by SMTP is worse than a reported failure.
- **Events.** The client fires `wp_mail_succeeded` and `wp_mail_failed` itself for gateway outcomes, with core's `$mail_data` shape, so mail logging plugins keep working.

Replies are JSON with `cache-control: no-store`: `{"ok": true, "id": ..., "domain": ..., "dropped_headers": [...]}` on success, `{"ok": false, "error": "<code>", "message": ...}` otherwise. `docs/PRD.md` section 5.3 lists every code and status.

## Key rotation

1. Generate a new secret: `openssl rand -hex 32`.
2. Set it as the next secret: `npx wrangler secret put GATEWAY_SECRET_NEXT`. Both keys now verify.
3. Change the client to the new secret. Run `wp signed-mail-gateway check`: it must report key slot `next`.
4. Set the new secret as `GATEWAY_SECRET` and delete the next one: `npx wrangler secret put GATEWAY_SECRET`, then `npx wrangler secret delete GATEWAY_SECRET_NEXT`. Run the check again: key slot `current`.
5. Destroy the old secret.

At no step does a correctly configured client fail. **If the secret leaked**, set the new secret as `GATEWAY_SECRET` and delete `GATEWAY_SECRET_NEXT` in one step: mail fails with `auth_failed` until the client has the new key, and the client falls back to the core path meanwhile.

## Operating it

- `wp signed-mail-gateway status` prints the provider, fallback, configuration (never the secret), domains, default From, the check result, the key slot and the clock difference.
- `wp signed-mail-gateway check` exits 0 on success and 1 otherwise. Use it in monitoring.
- Site Health shows one test, cached for five minutes: good, recommended (clocks differ by more than half the skew) or critical.
- The Worker logs one JSON line per request with `route`, `outcome`, `code`, `key_slot`, `domain`, recipient count, dropped header count and time. Filter the Workers log stream on `outcome`.
- The client logs one line per attempt through `error_log()`, prefixed `signed-mail-gateway:`, with the class, code, status, provider id, a keyed hash of the first recipient and the recipient count. Neither side ever logs an address, a subject, a body, a header value, the nonce, the signature or the secret.
- A wrong origin clock shows up as `stale_timestamp` plus a client log line such as `origin clock is 412 s fast`.

Removing the Worker: `npx wrangler delete` in `worker/` removes the Worker together with its Durable Objects and their stored keys. To keep the Worker but drop the store, add a migration with `deleted_classes = ["NonceStore"]` and deploy. Nothing about any message is ever stored, only `<timestamp>.<nonce>` keys and their claim time.

## Limitations

- No attachments or inline images in 0.1.0. The client sends such a message through the core path instead.
- No delivery events (bounces, complaints). `docs/ARCHITECTURE.md` sketches how a later version adds them.
- No retries inside the Worker: after an ambiguous failure a retry could send twice. The caller decides.
- One deployment serves one operator and one secret (two during rotation).
- The secret on the origin is as sensitive as SMTP credentials. Keep it out of the web root and readable only by the PHP user. A compromised origin can send whatever the gateway allows, within its limits.
- One nonce Durable Object handles hundreds of claims a second, far above a site's mail. It does not shard.

## FAQ

**Why not just put an Email Sending API key on the server?** A leaked key sends any mail as any address it allows, at any time, and a captured request can be replayed. Here a captured request dies after five minutes and cannot be replayed even inside that window, and the binding bounds what a leaked secret can send.

**Why is my upper-case nonce refused?** The nonce is part of the signed string. Lower-casing it on the server would turn your request into a bad signature, so it is refused as malformed instead.

**Why did a message fail without falling back to SMTP?** Its class was `rejected`, `suppressed` or `unknown`. Sending a wrong message, or a message to a suppressed recipient, by another road hurts the domain; after `unknown` the message may already have been delivered.

**Can several sender domains share one binding?** Yes: a binding is not tied to a domain, so `SENDERS` may point several domains at one binding name, as long as its `allowed_sender_addresses` lists the addresses for each. One binding per domain keeps each list short and is easier to read.

**Can I call the gateway from something that is not WordPress?** Yes. Sign `<timestamp>.<nonce>.<body>` with HMAC-SHA256, send the three headers, and check your code against `test-vectors/v1.json`. See `examples/`.

**Does the plugin slow down pages that send no mail?** No. Loading it only registers hooks.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security problems privately as described in [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE](LICENSE).
