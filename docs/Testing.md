# Testing

> This page is the outline; CLAUDE.md's "Conventions" has every test helper in full.

## The PHP suite

`composer test` (or `vendor/bin/phpunit`) runs the PHPUnit suite — `tests/Feature` and `tests/Unit`:

- **On its own configuration and an in-memory SQLite database**: the app boots once a test process on a `config.php` of
  the suite's own (a fixed `APP_KEY`, UTC, no bot token, no panel login), never this machine's; every file it writes
  goes to a folder of the run's own.
- **Off the network**: every outgoing call — Telegram, the VPN panels, the OpenID providers, Cloudflare, the mail server
  — goes through a transport the suite swaps for a fake (`tests/Support`: `FakeTelegram`, `FakePanel`,
  `FakeTelegramLogin`, `FakeGoogleLogin`, `FakeTurnstile`, and `AltchaWidget` to solve the shop's own captcha;
  `tests/Fakes`: `FakeProvider` and `FakePanelDriver` — a panel connector the tests answer —, `ProbedDriver` — a database
  whose probe a test answers —, the recording mail transport), and nothing sleeps.
- **Strict**: tests run in random order, and a deprecation, a notice or stray output fails one.
- **Held to the API's description**: every request an HTTP test sends and every answer it gets back is validated against
  `resources/api/openapi.yaml`, as `tests/Support/ApiDescription.php` reads it (every operation the shop's admins also
  have given its path on the website): a new field, or a new answer shape, fails until the description has it. A test
  whose point is a request no client sends passes it through `unchecked()`. `tests/Unit/Drivers/DriverFormsTest` holds
  each driver's request shape to its form ([Drivers](Drivers.md#the-kernel)).
- **Held to their cost**: `ListQueriesTest` reads every list with a few rows and with many and fails when the second
  asks more queries; `BotQueryBudgetTest` holds each common bot screen to its budget of queries and Telegram calls.
- **Held to their layers**: `tests/Unit/Core/LayersTest` fails when Core or Support names a module, and when the modules
  each module names differ from its `MODULES` map — a new dependency between modules is an edit of the map, by decision
  ([Architecture](Architecture.md#modules)).

Writing one: a feature test extends `DatabaseTestCase` (every case in a transaction), `HttpTestCase` (`postJson()`,
`loginAsAdmin()`, `loginAsAgent()`, `bearer()` for a website's customer, `loginAsStaff()` for one of its admins,
`upload()` for a form) or `BotTestCase` (`send($this->message(…))`, `tap()`, `said()`, `popup()`); its rows come from
`Tests\Support\Fixtures`
(`customer()`, `plan()`, `sellingServer()`, `buy()`, `agent()`, `website()`, `ticket()` …), never `Model::create()` by
hand.

## Static analysis and style

`composer analyse` runs PHPStan at level 6 over all the PHP there is — the app, the tests, the scripts — with
shipmonk's dead-code detector: a method, constant or property nothing uses fails it, and so does what only the tests
use. `composer lint` (a dry run) and `composer fix` keep PER-CS 2. `composer check` is the three together.

## The panels

`pnpm test` runs the panels' Vitest suite (happy-dom): tests sit beside what they test as `*.test.ts[x]`. Every test
gets a fake server in place of `fetch`, and a request no route of the test answers — or one the API description does
not take — fails it. `pnpm check` is the whole of it: the API types current, the type check, oxlint, knip (unused files,
exports and dependencies), Prettier, and the tests.

## The documentation

`php scripts/docs.php --check` fails while a generated page is stale or a link between pages leads nowhere
([Contributing](Contributing.md#documentation)).

## CI

Every push to `main` and every pull request to it runs, on GitHub Actions (`.github/workflows/ci.yml`):
`composer check` and the documentation's check on PHP 8.2, 8.3 and 8.4 — the versions a shop is supported on —, and the
panels' `pnpm check` and a production build.
