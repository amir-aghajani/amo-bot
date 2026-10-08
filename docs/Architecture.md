# Architecture

A map of the system for someone new to the code: what the parts are, and how a request, an update and a minute of
background work travel through them. The rules each part keeps — and the decisions behind them — are the engineers'
handbook, [`CLAUDE.md`](https://github.com/amir-aghajani/amo-bot/blob/main/CLAUDE.md) at the repository's root.

## The shape of it

PHP renders no HTML. Two panels — the owner's at `/admin/` and an agent's at `/agent/` — are one React project
(`resources/panel`) built to static files (`public/admin/`, `public/agent/`, and the files both share in
`public/assets/`) that the web server hands out as they are. Everything PHP answers is JSON — the panels' API, the
installer's, the shops' websites' (the [Store API](Store-API.md)), a health check — or a webhook's `ok`; the Telegram
bots are its other face.

```
public/index.php ─► bootstrap/app.php ─► Application::boot()
                                          ├─ PHP's errors kept out of answers (before anything can fail)
                                          ├─ config.php ─► config/*.php (each a closure over its settings)
                                          ├─ PHP-DI container ← bootstrap/container.php (services, driver registries)
                                          ├─ the database: the driver DB_CONNECTION names → Eloquent
                                          └─ the change feed (counts committed writes per area)
                     Application::http() ─► HttpKernel
                                          ├─ global: request id › security headers › CORS (the websites' API only) ›
                                          │          stray output kept out › errors (one JSON shape) › routing ›
                                          │          JSON body (1 MiB at most)
                                          └─ routes/{web,api,webhooks}.php, each group with its own guards:
                                               /api/install        installer open › CSRF header › install key
                                               /api/admin          installed › CSRF header › session › the owner signed in
                                                                   (› the shop the tab names, or the shop as a whole)
                                               /api/agent          installed › CSRF header › session › an agent signed in
                                               /api/store/v1/{key} installed › the website the key opens › JSON bodies
                                                                   (› a customer's bearer token › a recent sign-in)
                                                 …/admin           › a customer's bearer token › one of the shop's
                                                                   admins, let in, signed in within 12 hours
                                                                   (› a grant, or approving a payment › a recent sign-in)
                                               /webhooks, /cron    installed (a secret in the address authenticates)
                                               /, /health, /api/app  nothing: they answer either way
bin/console ─► Application::console() ─► Console\Kernel (each command made only when it runs)
```

The router decides what a request is and the route's group decides what guards it — no middleware matches paths, so an
address spelled another way (encoded, under a sub-folder) is the same route behind the same guards. A controller is
`load → service → present`: a refusal the shop words (`Core\Exceptions\DomainRuleException`, `ValidationException`) or
a row that is not there (`ModelNotFoundException`) is answered by `Http\ErrorHandler`, anything else is a logged 500.

## Failures and hardening

Every request gets an id of the app's own (`Http\RequestId`): the answer says it (`X-Request-Id`), every error answer
carries it in the one shape `{message, errors?, request_id}`, and every log line the request writes holds it — a failure
of the server's is shown in the panel as «کد پیگیری», and the owner finds its lines by it. The details of a failure (its
class, where it was thrown) are never a visitor's: only a 500, only with `APP_DEBUG`, only to a request made on the
server itself. The panels read every failure one way (`lib/failure`) and draw it one way (`ErrorState`), and what breaks
in a panel's own code reaches the shop's log too (`POST /api/{panel}/client-errors`, held to a size and a rate). What the
app's handling cannot reach — a `config.php` that does not parse, a fatal error — is `public/index.php`'s last resort:
the same JSON 500 with its id.

Around it the rest of the hardening: every PHP answer carries a sandboxing policy and is not cached (but a picture it
streams), and none names what runs it; a body is JSON read to 1 MiB and no further; secrets (bot tokens, the machine
addresses' own) are taken out of every log line and error; every sign-in — the panels' and the websites' — is
throttled and fails closed; a fresh upload's installer asks for a key only the host's files hold; a picture a customer
or support sends is judged by its bytes and written again; and every file the app writes — `config.php`, the host keys,
the pictures it keeps, the log, the sessions' folder — is its owner's alone (`0600`, its folders `0700`) where PHP runs
as the account that owns the app, since a shared host has other accounts — `0644` and `0755` under `mod_php`, which runs
as another user (`Core\Support\Files`, decided as the app boots). CLAUDE.md's "Production" has the rules.

## Many bots, one database

The installation runs the shop's own bot and every agent's («نماینده»): each bot is a row of `bots` (#1 the main one,
its token in `config.php`) with **a shop of its own** — customers, plans, categories, payment methods, orders, payments,
subscriptions, settings (texts, keyboards, rules), report group, broadcasts, website, tickets — while the servers, their
grants and the agency are the shop's as a whole. A shop's rows carry `bot_id`, and `Bots\CurrentBot` says whose shop the
code works in: the bot an update came to, the shop a panel shows, the website a store key opens, each shop in turn for
the scheduler. A global scope (`BelongsToBot`) holds every query to the current shop; the servers' work runs across all
of them on purpose (`CurrentBot::everywhere()`). `BotApi` speaks as the current bot, `Settings` reads its rows, a
notification runs in its recipient's shop and a report goes to the group of the bot it is about. What an agent's bot
sells is drawn from the agent's prepaid traffic, bought from the main bot at their level's price per GB.

## The two panels and their API

`/api/app` (the shop's name, whether it is installed, its time zone) is a panel's first question; `/api/install/*` is the
web installer; `/api/admin/*` is everything the owner's panel does and `/api/agent/*` an agent's — the shop's own screens
are the same routes under both, each prefix with its own session key and sign-in (`Auth\PanelAuthMiddleware`). The
owner's panel keeps the shop it shows in its address — `/admin/…` the main shop, `/admin/s/<id>/…` an agent's — so each
tab works in a shop of its own, and every request it makes names that shop in an `X-Shop` header. The owner's sections
that are the shop's as a whole (servers, grants, the agency, the panel's settings, the bots' webhooks, the owner's own
login) exist only under `/api/admin`, worked across every shop or in the main bot's (`Bots\ShopScopeMiddleware`). An
agent's panel works in their bot's shop and nothing else.

The shop's screens are registered once, in two parts: its daily work (`$operations` in `routes/api.php`: payments,
orders, services, customers, tickets, reviews, plans, referrals, broadcasts, the dashboard) and its configuration
(`$configuration`: payment methods, the bot's settings, texts and keyboards, the report group, the website, who its
admins are). Both panels mount both; the website mounts the daily work again under its `/admin`, for the shop's admins
signed in there (`Store\Http\StaffMiddleware` — [the shop's admins](Store-API-Admins.md)). Whoever a request acts for is
its principal (`Auth\Principal`, `Auth\CurrentPrincipal`: the owner, an agent, one of the shop's admins), and every
decision takes an `Auth\Actor` — that principal, an admin in the report group, or the shop's own timer —: who decided,
kept as the decision's reviewer and on the request's log lines, and what they may not decide (an admin about
themselves).

A list is read a page at a time on the server (`Core\Database\PageRequest` → `Page`): its status tab, filters (a
customer, a server, a range of days in the shop's calendar), one search box (`#12` is that row alone) and the column it
is sorted by (`Core\Database\Sort`, the row's id breaking ties), each in the list's address on screen. A customer has a
page of their own (`/users/{id}`: their services, payments, orders and tickets, wallet, referrals, agency, website
account), and what waits on a person — receipts to review, paid orders not delivered, tickets waiting on an answer — is
counted for the menu (`GET /queues`).

`resources/api/openapi.yaml` describes every operation, every request body and every success answer, with closed
objects; an operation of the daily work the shop's admins also have is marked (`x-staff`), and
`tests/Support/ApiDescription.php` gives it its path under the website's `/admin` — the one place those are made. It
binds both sides: every request the HTTP tests send and every answer they get is validated against it
(`HttpTestCase`), a unit test checks it names exactly the app's routes, the panels' TypeScript types — each write typed
by its path — are generated from it (`pnpm api:types`), every request a panel's test sends is held to it too, and the
API's reference is written from it (`php scripts/docs.php`: the [Store API](Store-API-Reference.md), the
[admin API](Store-Admin-API-Reference.md), the [panels' API](Panels-API-Reference.md), and `docs/openapi.json`).

The panels follow the shop without a reload: `Core\Database\ChangeFeed` reads every committed write off the database
connection and bumps its area's number (`change_versions`); a panel asks `GET /api/{admin,agent}/changes` every few
seconds and refetches what the areas that moved show.

## The websites

Every shop can have a website of its own — a landing page and a customer dashboard someone builds — which talks to the
shop through the [Store API](Store-API.md) under `/api/store/v1/{store-key}`. It is the third door into the same shop,
by decision: one domain service holds each rule (the checkout, a renewal, a ticket), and the bot, the panels and the
website only render what it decides. Its parts:

- `Store` — the website (`websites`: the store key, its address and origins, the ways in and the captcha it offers) and
  the API's HTTP layer: the key opens the website and its shop, CORS answers its origins alone, a body is JSON.
- `Accounts` — the customer's sign-in and own account: bearer sessions (`customer_sessions`, a token's hash), every
  short-lived secret of the sign-in flows (`auth_challenges`: nonces, redirect states, emailed codes, second steps,
  merge tickets), OpenID Connect for Telegram and Google, email and password, two-factor sign-in, the ways in added and
  removed, and two accounts merged (`account_merges`). It leans on no website: what a sign-in needs of one is its own
  interface (`Accounts\Contracts\SignInSite`), which the website implements.
- `Notifications` — every notice the shop sends a customer, kept for their website (`notifications`) and sent to their
  Telegram chat, or by email to a customer Telegram cannot reach.
- `Support` — support tickets (`tickets`, `ticket_messages`): the website's and the bot's conversations, answered from
  either panel or the report group.
- `Reviews` — customers' reviews of the shop (`reviews`): written on the website while it takes them, by a signed-in
  customer or — behind its captcha — a guest; decided by support — approved, the website shows them —; read there with
  their count and average.

A customer is one account per person per shop, whichever door they came through: `users.telegram_id` may be empty (a
customer who signed up on the website), `email` is kept only once proven, `google_sub` is a Google account. Orders made
over the API carry an `Idempotency-Key`, kept a week (`request_keys`), so a request made again never charges twice.

## Modules

Each module under `app/Modules/` owns its models, services and controllers; `app/Core` (HTTP, database, drivers, forms,
mail, captcha, scheduling, security, configuration) and `app/Support` (input, money, Persian, traffic) name no module —
what they need of one is an interface of theirs the module implements. Which modules each module names is one map,
`MODULES` in `tests/Unit/Core/LayersTest.php`, held to the code exactly: a dependency one module takes on another — or
one it gives up — fails the test until the map is edited, so a new edge between modules is a decision, never an
accident. Where a module leans on another, the other asks what it needs back through an interface of its own: the
customer's account (Accounts) names no website class — its sign-ins ask the website (Store) through
`Accounts\Contracts\SignInSite`.

| Module | What it is |
|---|---|
| Providers | The VPN panels: one driver per panel (3x-ui, PasarGuard) and its client (`ProviderInterface`: clients in and out, inbounds), `ProviderRegistry`, the servers and their checks |
| Catalog | Plans, their categories, the servers each is sold on, and `ServerSelector` — which of them can deliver today |
| Subscriptions | A customer's service on a panel: provisioning, sync, renewal (by hand and automatic), moves, grants and one service's extension, reminders |
| Orders, Payments | The order and the payment's state machine (every transition a compare-and-swap), the stuck deliveries resumed, the one checkout every door goes through; gateways (wallet, card-to-card) |
| Users, Referrals | Customers, their wallet ledger, groups and pictures; invite links and commissions |
| Agency, Bots | Agents, their levels and traffic; the bots and whose shop the code works in |
| Auth | The two panels' sign-in (the owner's login and its recovery, an agent's one-time link), the sign-in throttle, and who acts (`Principal`, `Actor`) |
| Accounts, Store, Notifications, Support, Reviews | The websites: customers' sign-in and accounts, the Store API, notices, support tickets, reviews |
| Telegram | The bots: update dispatch, handlers, gates, texts, keyboards, broadcasts, the report group, polling |
| Settings, Installer, Admin, Api | The settings table and `config.php`'s screens, the web installer, the panels' controllers and the dashboard, `/api/app` and `/health` |
| Scheduling | The `/cron/{token}` trigger |

## Drivers

What the shop does through someone else is a driver of a family (`Core\Drivers`): a VPN panel (`PanelDriver`, whose
client of one server is a `ProviderInterface`), a payment gateway (`GatewayDriver`, whose gateway of one method row is a
`GatewayInterface`), a database (`DatabaseDriver`), a mail service (`MailDriver`), a captcha (`CaptchaDriver`). A family
is one interface and one `Registry` in `bootstrap/container.php`, and a new driver is a class, its registration and its
request shape. What a driver asks of the admin is its form (`Core\Forms`: typed fields, every refusal at once, a secret
kept only while the address it was given for stays), which it describes for the panels: one generic form
(`components/driver-form`) draws every family's — the server's connection, a payment method's settings, the database,
the email, a website's captcha. [Drivers](Drivers.md) says how to add one.

## The bots at work

An update arrives two ways: Telegram's webhook (`POST /webhooks/telegram/{secret}` for the main bot,
`/webhooks/telegram/bot/{id}/{secret}` for an agent's — `Http\Urls` spells and builds these addresses) or `bot:poll`,
whose `Telegram\Polling\Poller` keeps one long poll open per bot over a curl multi handle and serves whichever answers.
Either way the update is served in its bot's shop by `Telegram\Update\Dispatcher`: each update id once (a chat that
floods the bot is left unanswered while it keeps on, and an agent's webhook takes a budget of updates of its own), the
customer resolved, the gates (bot off, phone, channels), then the handler its command, button, callback or conversation
state routes to (`routes/bot.php`), and finally what the bot's report group has queued is sent. A bot Telegram refuses
(a revoked token, another program taking its updates) keeps the reason on its row for the agent and the owner
(`Telegram\Services\BotHealth`). Every bot is put on webhooks, or taken off them, by one service
(`Telegram\Services\BotWebhooks`) — the console's commands, and the owner's panel for a host without a shell.

## A minute of background work

`schedule:run` from a cron, the `/cron/{token}` address, or the poller's own tick start a run of
`Core\Scheduling\Scheduler` (`bootstrap/schedule.php` lists the tasks); the poller starts it as a process of its own
(`Core\Scheduling\BackgroundRun`), so the polls go on while it runs. One run at a time (a file lock); a task's time is
written before it runs; a shop's task runs in every shop (`Core\Scheduling\Shops`, the bots'), the servers' across all of
them. One run's time is shared over its turns (`Budget`), so a run does not grow with the number of agent bots: card
receipts nobody reviewed, paid orders a dying process left undelivered (resumed; a failed delivery waits for support),
walked-away checkouts, broadcasts in batches, automatic renewals, the next period of a renewed service, grants worked
through service by service under a lease, the periodic sync of every running service from its panel, reminders, the
report groups' queue at Telegram's pace, and the housekeeping (the updates served, the websites' sessions and
challenges, old notices, closed tickets' pictures). [Running in production](Running-In-Production.md#the-scheduler)
lists them with their intervals.

## Data

One file, `database/schema.php`: every table in the order they are made, and the rows a new shop starts with (the main
bot, the wallet). `Core\Database\Schema` makes the tables a database lacks (the installer, the tests) and, after an
edit, `db:rebuild` makes every table again with its rows carried over by column name — stopping, with nothing lost, at
a row the new shape will not take. Upgrades start from the first release: every change of the schema ships as
`database/upgrades/<version>.php`, which `Core\Database\Upgrades` runs in version order from the version the
installation's lock records, as the panel's updater installs a release ([Upgrading](Upgrading.md)). Values are not copied between tables:
a balance is its ledger's, a status is one column moved by compare-and-swap. The database is a driver: MySQL/MariaDB for
a shop, SQLite for the tests and a developer. An index answers a query that was measured (on a shop of 50,000
customers), and a list reads what its rows show with them — a relation read row by row fails the tests.

Time is kept in UTC everywhere; the shop's zone (`APP_TIMEZONE`, `Support\LocalTime`) is applied only where a time is
shown — the bot's Jalali dates, the dashboard's days, a list's range of days, and the panels, which read it off
`/api/app`.

## Installation

A shop runs on shared hosting (cPanel, DirectAdmin…), the one way it is supported: the release zip uploaded, the site
opened, and the panel starts the web installer (`/admin/install`) — no shell needed; a developer's machine (XAMPP) runs
the same installer. `storage/installed.lock` marks a completed installation. Until it exists the shop's routes answer a
503 and the panel shows nothing but the installer; afterwards the installer's routes are a 404. The installer
(`Installer\Services\Installer`) checks the requirements, writes the database settings into `config.php` once the
driver's probe answers to them (making the file, with an `APP_KEY` of its own, the first time), makes the tables, writes
the panel's login (`config.php`'s `ADMIN_USERNAME` and the password's hash — the owner changes it later from the panel,
and the sign-in page's recovery is the way back in), the site's name and address and the bot's token, and finally the
lock. [Installation](Installation.md) is the operator's guide.
