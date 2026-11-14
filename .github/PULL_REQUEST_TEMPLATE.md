## What and why

## PRD cases and tests

Which cases in `docs/PRD.md` this touches, and which tests cover them.

## Checklist

- [ ] Worker: `npm run typecheck`, `npm run lint`, `npm run format:check`, `npm test`, `npm run build`
- [ ] Client: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, `vendor/bin/phpcs`
- [ ] Test vectors updated if signing changed
- [ ] No address, subject, body, header value, nonce, signature or secret in logs or replies
- [ ] Docs updated (README, ARCHITECTURE, CHANGELOG)
