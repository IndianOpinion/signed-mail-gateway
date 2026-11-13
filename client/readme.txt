=== Signed Mail Gateway ===
Contributors: indianopinion
Tags: email, smtp, cloudflare, wp_mail, security
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Sends wp_mail() through an HMAC-signed Cloudflare Worker, with a safe one-time fallback to the normal mail path.

== Description ==

Many hosts block outbound SMTP, and SMTP credentials or mail API keys on the web server let anyone who reads them send as your domain. This plugin sends every wp_mail() call as a signed HTTPS request to a small Cloudflare Worker (in the same repository) that sends it through Cloudflare Email Sending. The Worker refuses unsigned, stale and replayed requests, and only sends from the domains and addresses you list.

* Builds each message the way core wp_mail() does, including the From, content type and charset filters and phpmailer_init, so other plugins keep working.
* Falls back to the normal mail path once, only when the message certainly did not go out. A timeout is reported, never resent, so readers do not get two copies.
* Fires wp_mail_succeeded and wp_mail_failed for gateway outcomes, so mail logging plugins keep working.
* Sender profiles, a batch API, WP-CLI commands and a Site Health test.
* Logs codes, counts and a keyed recipient hash: never addresses, subjects, bodies or the secret.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

== Installation ==

1. Deploy the Worker from the repository's worker/ directory (see the README).
2. Copy this directory to wp-content/plugins/signed-mail-gateway/ and activate it, or use it as a must-use plugin with a one-line loader.
3. In wp-config.php define SIGNED_MAIL_GATEWAY_URL (https only) and SIGNED_MAIL_GATEWAY_SECRET (32 bytes or more).
4. Set the gateway domains and default From: `wp option update signed_mail_gateway_domains mail.example.org` and `wp option update signed_mail_gateway_default_from 'News <news@mail.example.org>'`.
5. Run `wp signed-mail-gateway check`.

== Frequently Asked Questions ==

= How do I turn the gateway off? =

`wp option update signed_mail_gateway_provider wordpress`. Mail then goes through WordPress as if the plugin were not there.

= Does it send attachments? =

Not in 0.1.0. A message with attachments goes through the normal mail path.

= Does it slow down my site? =

No. It only registers hooks, and does work only when mail is sent.

== Changelog ==

= 0.1.0 =
* First release.
