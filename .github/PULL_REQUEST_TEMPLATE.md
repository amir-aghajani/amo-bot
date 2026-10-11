<!-- What changes for the shop's owner, an agent, a customer or a website's developer, and why. Link the issue. -->

## What and why


## How it is tested


## Checks

- [ ] `composer check` — the code style, PHPStan (level 6, dead code) and the PHP tests
- [ ] `pnpm check` — the panels: API types, typecheck, oxlint, knip, Prettier and their tests (when they changed)
- [ ] `php scripts/docs.php --check` — after `php scripts/docs.php` when what a generated page is made from changed
- [ ] `resources/api/openapi.yaml` changed with the API, then `pnpm api:types`
- [ ] the wiki pages in `docs/` changed with the behaviour they describe, and new code says what it does in short
      class and function comments
- [ ] a line in `docs/Releases.md` under `## Unreleased`, for a change someone would notice
