# Contributing to AmoBot

Thank you for helping. Issues and pull requests are welcome; a weakness that could hurt a shop is reported privately
instead ([SECURITY.md](SECURITY.md)).

## Setting up

You need PHP 8.2 to 8.4 with the extensions of [Installation](docs/Installation.md#what-you-need) and `pdo_sqlite`,
Composer, and — to work on the panels — Node 22.12+ with pnpm (`package.json` names its version; Corepack picks it). The
tests need no database: they run on SQLite in memory. A local shop needs MySQL or MariaDB (XAMPP will do).

```bash
composer install
pnpm install && pnpm build   # the panels -> public/admin, public/agent, public/assets
composer serve               # http://127.0.0.1:8080 — the web installer until the shop is installed
pnpm dev                     # the panels with hot reload: http://127.0.0.1:5173/admin/ and /agent/
```

[Development](docs/Development.md) has the rest: the bot on long polling, the project's layout, where each part lives.

## The checks

```bash
composer check                # code style (dry run) + PHPStan (level 6, dead code) + the PHP tests
pnpm check                    # the panels: API types current, typecheck, oxlint, knip, Prettier, the unit tests
php scripts/docs.php --check  # the documentation: generated pages current, every link leading somewhere
```

`composer fix` and `pnpm format` apply the code style. CI runs all three on every push to `main` and every pull request
to it, the PHP side on PHP 8.2, 8.3 and 8.4 ([Testing](docs/Testing.md)).

## The rules a change keeps

The engineers' handbook, [CLAUDE.md](CLAUDE.md), has every subsystem's rules and the decisions behind them — read the
part your change touches. In brief:

- **Every change of behaviour comes with its tests.** The suites are strict: random order, a deprecation, a notice or
  stray output fails a test; nothing reaches the network (every outside service has a fake); a Feature test builds its
  rows with `Tests\Support\Fixtures`.
- **The API is described once**, in `resources/api/openapi.yaml`, with closed objects: a change to a request or an
  answer changes the description too, then `pnpm api:types` regenerates the panels' types. Every HTTP test, and every
  request a panel's test sends, is checked against it.
- **Nothing without a caller.** PHPStan's dead-code check and knip fail on code nothing uses, and a test is no caller.
- **One rule, one place.** A business rule lives in one domain service that the bot, the panels and the website all
  call; find the helper that exists before writing another (input through `App\Support\Input`, money through
  `App\Support\Money`, a list through `Core\Database\PageRequest`, a panel screen from the shared components).
- **A module leans on another by decision.** Which modules each module names is one map, `MODULES` in
  `tests/Unit/Core/LayersTest.php`: a new dependency between modules fails it until the map is edited
  ([Architecture](docs/Architecture.md#modules)).
- **PHP**: `declare(strict_types=1)`, `final` classes, PER-CS 2. **Panels**: TypeScript, Prettier, oxlint without
  warnings.
- **Persian for users, English for code.** Everything a user sees is Persian, written plainly — no Arabic diacritics
  (`لطفا`, not `لطفاً`; `تایید`, not `تأیید`), technical terms in their Latin form or common transliteration, and a
  customer reads «پشتیبانی», never «مدیر». Code, comments, console output and the documentation are English.
- **The documentation** is the `docs/` tree; its generated pages are written by `php scripts/docs.php`, never by hand
  ([Contributing](docs/Contributing.md#documentation)).

## Proposing a change

1. **Open an issue first** for anything larger than a fix — a feature, a new driver, a change to the API's contract —
   so its shape is agreed before the work. For a bug, say the version (`Application::VERSION`, or the commit), the host,
   where it happens and how to make it happen: the issue forms ask for each.
2. **Branch from `main`**, keep the change to one subject, and run the checks. A change a shop's owner, its customers
   or a website's developer would notice gets a line in [CHANGELOG.md](CHANGELOG.md), under `## Unreleased` at its top.
3. **Open a pull request** that says what changes for the shop's owner, its customers or a website's developer, why,
   and how it is tested — its template lists the checks. CI must pass.

Adding a VPN panel, a payment gateway, a database, a mail service or a captcha has a recipe of its own:
[Drivers](docs/Drivers.md). Other additions — a panel screen, a bot screen, a Store API endpoint, a setting, a scheduled
task, a table: [Extending](docs/Extending.md).

By contributing you agree your work is published under the project's [MIT license](LICENSE).
