[فارسی](fa/Testing.md)

# Testing

How the test suites are built, what each base class and helper gives you, and what keeps the code honest beyond the
tests themselves. Run everything with `composer check` and `pnpm check` before a pull request
([Development](Development.md#the-checks)).

## The PHP suite

`vendor/bin/phpunit` (or `composer test`) runs `tests/Unit` and `tests/Feature`, some 3,700 tests:

- **On its own configuration**: the app boots once per test process on a `config.php` of the suite's own
  (`TestCase::CONFIG`: an in-memory SQLite database, a fixed `APP_KEY`, UTC, no bot token, no panel login), deleted as
  soon as it is read — never this machine's. Every file the app writes (`TestCase::FILES`: the install lock and keys, the
  throttle's counts, uploads, the scheduler's state…) goes to a folder of the run's own, emptied after each test.
- **On prefixed tables**: every table is behind `TestCase::TABLE_PREFIX` (`amo_`), as on a shop that shares its database
  (`DB_PREFIX`), so SQL that names a table bare fails a test. Raw SQL names its columns through the grammar
  (`$query->getQuery()->getGrammar()->wrap('orders.created_at')`); a test that knows a statement by its table names it
  with `sqlTable('orders')` (`"amo_orders"`), and its own raw SQL adds `TABLE_PREFIX`.
- **Off the network**: every outgoing transport answers "no network" until a test hands it a fake, and nothing sleeps.
- **Strict**: random order, and a deprecation, a notice or stray output fails a test. PHPStan checks the tests too.

## The base classes

| Class | For | Gives |
|---|---|---|
| `Tests\TestCase` | a unit test that needs the app | `app()`, `service()`, `config()`, `swap()`, `logs()`, `configFile()`, `scratchDir()`, and the fakes below |
| `Tests\DatabaseTestCase` | anything that touches the database | the tables made once a process, every test in a transaction rolled back after it, the `Fixtures` |
| `Tests\HttpTestCase` | the API | `get()`, `postJson()`… (with the CSRF header), `send()` (without), `upload()`, `loginAsAdmin()`, `loginAsAgent()`, `openShop()`, `bearer()`, `loginAsStaff()`, `storeApi()`, `unchecked()`, `decode()` |
| `Tests\BotTestCase` | the Telegram bot | `send($this->message(…))`, `tap()`, `contact()`, the report group's `groupTap()`, `groupText()`, `groupReply()`, `botMembership()`; then `calls()`, `params()`, `said()`, `popup()`, `inlineKeyboard()`, `callbacks()`, `buttonsUnder()` |

A few things to know:

- **Swap a leaf, not a service.** The container is one per process. A test hands the app its double by replacing a
  transport or a file path, never a service that others hold. `swap($id, $double, ...$rebuild)` exists for the rare
  one-off double, listing the entries that hold it so they are rebuilt and restored.
- **The change feed** counts the test's transaction as the database (`ChangeFeed::countAsCommitted()`), so writes in a
  test move the panels' areas as committed writes would.
- **Leave event listeners as you found them**: a test that ends with them changed fails. Never `forget()` one by hand,
  which drops the app's own listeners too. To act at a precise moment — another process's move in a race — use
  `whileListening($event, $listener, $work)`, which puts the listeners back;
  `whileTheOrderClosesOnceRead()` is the settlement's race. `withTheTurnOver($work)` runs work as at the end of a
  scheduler's turn.
- **HTTP tests validate both ways** against the API description ([API contract](API-Contract.md)). A request no client
  sends goes through `unchecked()`.
- **The shop counts as installed** in an HTTP test; `installed(false)` plays a fresh one, `configureAdmin(null)` one
  without a login. `loginAsAdmin()` opens the session directly — signing in over HTTP is the auth tests' business.
- **Time** is moved with `Carbon::setTestNow()`; the throttle, the TOTP codes and the leases all read the shop's clock.

## Fixtures

Rows come from `Tests\Support\Fixtures`, never from `Model::create()` in a test. Each maker has the defaults a test
rarely cares about and `$overrides`, merged last, for the ones it does: `customer()`, `webCustomer()`, `admin()`,
`plan()`, `sellingServer()` (on the fake panel, ready to sell), `fakeServer()`, `panelServer()`, `cardMethod()`,
`purchaseOrder()`, `cardPayment()`, `receipt()`, `subscription()`, `agent()`, `agentBot()`, `website()`,
`customerSession()`, `ticket()`, `review()` and more. Some build a whole scenario: `buy()` (paid from the wallet),
`renew()`, `paidByCard()` (a receipt sent and approved), `reportGroup()` (connected, every topic made), the programs'
switches (`referralProgram()`, `agencyProgram()`, `extrasOnSale()`…). Balances are ledgers: `wallet($user, $balance)` and
`traffic($bot, $gb)` add the one line that brings them there. A fixture builds in the current shop; wrap it in
`CurrentBot::run($bot, …)` for an agent's. `panelActor()` and `groupAdminActor()` are the actors for a test that calls a
service directly.

## Fakes

Each fake stands in for one outside party and records what the app sent it. A fake of an HTTP service is a Guzzle
handler put under the app's one outgoing client: `TestCase::outgoingFake($class)` makes it the first time a test asks
for it and puts it in place, and the client is offline again after the test. Each such fake has its helper below,
which calls `outgoingFake()`.

| Fake | Stands for | Reach it with |
|---|---|---|
| `Support\FakeTelegram` | the Bot API and bot:poll's long polls | `telegram()` — `reply()`, `fail()`, `on($method, …)`, `calls()`, `params()`, `sentTo()` |
| `Support\FakePanel` | any other outgoing HTTP: a VPN panel, Resend | `panelHttp()` — `ok()`, `fail()`, `raw()`, `refuse()`, `calls()`, `request()`, `params()`, `options()` |
| `Fakes\FakePanelDriver`, `Fakes\FakeProvider` | a VPN panel connector, for everything above the connector | `fakePanel()`, then a server with `driver: fake` |
| `Support\FakeCubePay`, `FakeZarinpal`, `FakeAtlasPay`, `FakeAban`, `FakePlisio`, `FakeAqayePardakht`, `FakeVariza` | each online gateway's API | `cubePay()`, `zarinpal()`, `atlasPay()`, `aban()`, `plisio()`, `aqayePardakht()`, `variza()` — each pays, closes or refuses a payment as its gateway does, `down()` takes it out of reach; `FakeVariza::webhook()` writes and signs a webhook for the test to send |
| `Support\FakeTelegramLogin`, `FakeGoogleLogin` | the OpenID providers (keys signed by `SigningKey`) | `telegramLogin()`, `googleLogin()` |
| `Support\FakeTurnstile`, `AltchaWidget` | Cloudflare's siteverify; a visitor's browser solving ALTCHA | `turnstile()`; `AltchaWidget::solve()` |
| `Fakes\RecordingMailTransport` | the mail server | `mail()` — `sent()`, `to()`, `codeIn()`, `failing()` |
| `Fakes\ProbedDriver` | a database whose probe the test answers | `probedDatabase($key)` |
| `Support\FakeGitHub`, `FakeRelease` | GitHub's latest release and its files (`down()`, `answer()`, `storeOn()`), and a release signed for the test | the update tests' `UpdateTestCase`: `$this->gitHub` (`outgoingFake(FakeGitHub::class)`), `release()` |
| `Fakes\NullSleeper`, `RecordingSleeper` | waiting | in place from the boot; the recording one when a test asserts the waits |

`tests/Fakes` also holds small stand-ins a single test needs as a named class (a handler that throws, a task that
counts its runs, a leased table).

## Guards beyond the tests

- **Query budgets**: `tests/Feature/ListQueriesTest` reads every list of the panels and the Store API with a few rows
  and with many and fails when the second asks more queries; `BotQueryBudgetTest` holds each common bot screen to its
  queries and Telegram calls. Raising a budget says what the new query buys.
- **Layers**: `tests/Unit/Core/LayersTest` fails when Core or Support names a module, or when the modules a module
  names differ from its `MODULES` map ([Architecture](Architecture.md#modules)).
- **Routes**: `tests/Feature/RouteGuardsTest` walks every route through every guard in order, and with another shop's
  row.
- **The API description**: `tests/Unit/Http/ApiDescriptionTest` holds `resources/api/openapi.yaml` to the routes, its
  objects closed, and readable by a website's own tools ([API contract](API-Contract.md#how-the-php-side-is-held-to-it)).
- **Upgrades**: `tests/Unit/Database/UpgradesTest` holds every schema change to its upgrade ([Database](Database.md#upgrades)).
- **Drivers**: `tests/Unit/Drivers/DriverFormsTest` holds each driver's request shape to its form
  ([Drivers](Drivers.md#the-kernel)).
- **Dead code**: PHPStan's dead-code detector fails on anything nothing calls — a test is no caller.

## The panels

`pnpm test` runs the panels' Vitest suite (happy-dom). Tests sit beside what they test as `*.test.ts[x]` and test
behaviour the way a screen uses it. Every test gets a fake server in place of `fetch` (`src/test/server`:
`server().on(method, path, answer)`, `hold()`, `sent()`); a request no route answers fails the test, and so does one
the API description does not take. `src/test/render` renders a screen with the app's providers (`providers()`,
`signedIn()`), `src/test/rows` makes whole API rows, and time is faked — nothing waits for real.
[Internals: panels](Internals-Panels.md) has more.

## The documentation

`php scripts/docs.php --check` fails while a generated page is stale, or a link between pages leads nowhere
([Contributing](Contributing.md#documentation)).

## CI

Every push to `main` and every pull request to it runs on GitHub Actions (`.github/workflows/ci.yml`): `composer check`
and the documentation's check on PHP 8.3, 8.4 and 8.5 — every version a shop may run, each held to the whole suite —,
and the panels' `pnpm check` and a production build. The update tests need the `sodium` and `zip` extensions, which CI
has; locally, `php -d extension=sodium -d extension=zip vendor/bin/phpunit` runs them.
