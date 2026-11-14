# Security policy

## Reporting a problem

Please report security problems privately by email to
hello@indianopinion.org. Do not open a public issue.

Include what you found, how to reproduce it, and which version or commit
you tested. If you have a proof of concept, attach it.

## What happens next

- We acknowledge your report within 3 working days.
- We send a first assessment within 10 working days.
- We aim to release a fix within 30 days for a confirmed problem, sooner
  for a severe one, and we agree a disclosure date with you.
- We credit you in the release notes unless you prefer not to be named.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Scope

In scope: signature verification, replay protection, the clock check, size
and recipient limits, header filtering, error masking, anything that lets
an unsigned caller send mail or learn something, and anything in the
client that leaks the secret, a recipient or message content, or follows
a redirect with a signed body.

Out of scope: a compromised origin (it holds the secret by design; see the
README's limitations), and problems in Cloudflare or WordPress themselves.
