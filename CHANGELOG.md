# Changelog

All notable changes to this project are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-13

### Added

- Cloudflare Worker gateway with `/v1/send`, `/v1/batch` and `/v1/check`.
- HMAC-SHA256 signatures over timestamp, nonce and raw body, verified in
  constant time, with a current and a next secret for key rotation.
- Clock skew check (300 seconds by default) that reports the server time.
- Replay guard in a SQLite-backed Durable Object with an alarm sweep (and
  a small per-claim sweep).
- Sender domain map and enabled-domain switch over one account-level
  `send_email` binding; allowed From addresses are set in the Cloudflare
  dashboard. `sender_not_allowed` when the provider's error says so.
- Message validation, header allowlist pinned to Cloudflare's
  documentation, one-click unsubscribe check, provider error mapping with
  quota normalisation and address masking.
- Batch sending with bounded concurrency and per-message results.
- One privacy-safe JSON log line per request.
- WordPress client plugin: `pre_wp_mail` takeover that builds messages the
  way core does, one outcome classifier, a single safe fallback to the
  core path, core mail events, sender profiles, batch API, WP-CLI commands
  (`status`, `check`, `send-test`), a Site Health test and uninstall
  cleanup.
- A small built-in HTML to text converter for the text part, with no
  dependency.
- Shared test vectors in `test-vectors/v1.json`, used by both test suites,
  including a cross-half vector produced by the client's own encoder.

[Unreleased]: https://github.com/IndianOpinion/signed-mail-gateway/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/signed-mail-gateway/releases/tag/v0.1.0
