# Contributing

Thank you for helping. This repository has two halves with their own tools:
the Worker in `worker/` (TypeScript) and the WordPress client in `client/`
(PHP). Read `docs/PRD.md` for what the project must do and
`docs/ARCHITECTURE.md` for how it does it.

## Setup

Worker, with Node 20 or later:

```sh
cd worker
npm ci
```

Client, with PHP 8.1 or later and Composer:

```sh
cd client
composer install
```

## Tests and checks

Worker:

```sh
npm run typecheck      # tsc --noEmit, strict
npm run lint           # eslint, typescript-eslint strict type-checked
npm run format:check   # prettier
npm test               # vitest in the real Workers runtime
npm run build          # wrangler deploy --dry-run --outdir dist (no account, no login)
```

Client:

```sh
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G   # level 8
vendor/bin/phpcs                               # PSR-12
```

CI runs all of these on Node 20 and 22 and on PHP 8.1, 8.2 and 8.3. Only
write PHP that runs on 8.1: no readonly classes, no DNF types, no `true`
type, no constants in traits.

Never run `wrangler deploy`, `wrangler login` or `wrangler secret` as part
of development or CI. The dry run needs no account.

## Rules for changes

- Every behaviour change starts in `docs/PRD.md` and gets a test. Test
  names start with the PRD case or test number they cover.
- If you change signing, update `test-vectors/v1.json` and make both suites
  and `node examples/sign-request.mjs` pass.
- Never log or return an address, a subject, a body, a header value, the
  nonce, the signature or the secret. The Worker suite fails if a test run
  logs one.
- No runtime dependencies in either half.
- Prose: short sentences, plain words, British spelling. No em dashes.

## Pull requests

- Keep each pull request to one change, with tests.
- Fill in the pull request template, including how you tested.
- All checks must pass. A maintainer reviews and merges.
- A sign-off line (DCO) is not required.

## Licence

By contributing you agree that your contribution is licensed under the MIT
licence of this project.
