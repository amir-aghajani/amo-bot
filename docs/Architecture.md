[فارسی](fa/Architecture.md)

# Architecture

A map of the system for someone new to the code: the parts, how a request, a Telegram update and a minute of
background work travel through them, and the few ideas everything else leans on. Each area's own page goes deeper:
[Database](Database.md), [Conventions](Conventions.md), [API contract](API-Contract.md), [Drivers](Drivers.md), and the
Internals pages — [commerce](Internals-Commerce.md), [services](Internals-Services.md),
[the Telegram bot](Internals-Telegram-Bot.md), [the website API](Internals-Website-API.md), [the panels](Internals-Panels.md),
[the mini app](Internals-Mini-App.md).

## The shape of it

AmoBot is a PHP 8.3 application (Slim 4, PHP-DI, Eloquent, Symfony Console) that renders no HTML but one small page —
the one a customer's browser comes back to from an online payment gateway (`Payments\Controllers\ReturnPage`). It has
five faces:

- **Two panels** — the owner's at `/admin/` and an agent's at `/agent/` — one React project (`resources/panel`) built
  to static files the web server serves as they are. They talk to PHP only through the JSON API.
- **The shops' Telegram mini app** — each shop's customer side inside Telegram, at `/app/{its store key}/` — a third
  page of the same React project, built apart to static files of its own (`public/app`). It talks to PHP only through
  the Store API ([Internals: mini app](Internals-Mini-App.md)).
- **The Telegram bots** — the main bot and every agent's — fed by webhooks or long polling.
- **The Store API** (`/api/store/v1/{store}`), which a shop's own website calls from its visitors' browsers, and the
  shop's mini app from Telegram — each under a store key of its own.
- **Background work**, ticked every minute by a cron, an external pinger or the poller.

Everything must run on shared hosting (cPanel and the like): no shell for the owner, no daemons, no cache server, no
build step. That one constraint explains many decisions below — `config.php` instead of an environment, files instead
of Redis, the web installer and updater, a scheduler that works in slices.

## Booting

`public/index.php` (HTTP) and `bin/console` (CLI) both call `bootstrap/app.php`, which runs `Core\Application::boot()`:

1. The files the app writes are made owner-only when PHP runs as the account that owns the app (`Core\Support\Files`).
   PHP's warnings are kept out of answers and go to `storage/logs/php-errors.log`; exceptions keep no call arguments.
2. `config.php` (the owner's settings — see [Configuration](Configuration.md)) is read into the parts `config/*.php`
   make of it (`Core\Config`). Nothing is read from the environment.
3. PHP's clock is set to UTC; the shop's zone (`APP_TIMEZONE`) is kept for display only.
4. The PHP-DI container is built from `bootstrap/container.php` — the factories, the driver registries and the path of
   every file the app writes. Anything not listed there is autowired.
5. The database is booted through its driver, the change feed starts listening, and the encrypted casts get their key.

Then `Application::http()` builds the Slim app (`Core\Http\HttpKernel`) and `Application::console()` the CLI
(`App\Console\Kernel`, each command built only when it runs).

## A request's path

The global middleware, outermost first: the request id, security headers, CORS (the websites' API only), the output
guard (stray output never reaches an answer), the error handler, routing, and the JSON body reader (1 MiB at most,
read before it is decoded).

Everything else a request needs is its **route group's middleware**, in `routes/api.php`, `routes/web.php` and
`routes/webhooks.php`. No middleware matches paths, so the router decides what a request is and an address spelled
another way (encoded, under a sub-folder) reaches the same route behind the same guards — `tests/Feature/RouteGuardsTest`
walks every route to prove it.

| Group | Guards, in order |
|---|---|
| `/api/admin` (the owner's panel) | installed › CSRF header on a change › session › the owner signed in › the shop the request names |
| `/api/agent` (an agent's panel) | installed › CSRF header on a change › session › an agent signed in, in their bot's shop |
| `/api/bot-admin/{owner,agent}` (the bot's management, in-process) | the seat the bot put on the request, or a 404 › the seat's kind is the door's › its shop, as its principal (`Telegram\Admin\Door\BotAdminDoor`) |
| `/api/install` | not installed yet › CSRF header on a change › the install key |
| `/api/store/v1/{store}` | installed › the website or the mini app the key opens › JSON bodies › (what one door alone has: that door) › (a customer's bearer token) |
| `…/admin` under the store | › the website's door › a customer's token › one of the shop's admins, let in, on a session that is not the mini app's, signed in recently enough |
| `/webhooks/…`, `/cron/{token}`, `/payments/callback/{payment}/{secret}` (an online gateway's callback) | installed (a secret in the address authenticates) |
| `/payments/webhooks/{driver}` (a gateway's webhook: one it signs, Variza's; one that only names a payment, Rialit's) | installed (the signature authenticates; an unsigned one is only a reason to ask the gateway) |
| `/webhooks/deposits/{source}/{bot}` (the bank SMS a phone app forwards: ForApp's) | installed (the shop's key in `X-API-Key` authenticates) |
| `/webhooks/rikhtam/{bot}` (Rikhtam's deposits and crypto word, the shop registering the address itself) | installed (the Rikhtam gateway's key in the body authenticates) |
| `/`, `/health`, `/api/app` | nothing: they answer before installation too |

A controller is *load → service → present*. It lets the shop's refusals through: a `Core\Exceptions\DomainRuleException`
(a `ValidationException`, a 409, a 429…) is answered by `Core\Http\ErrorHandler` with its own status, message and field
errors; a row that is not there (or is another shop's) is a 404; anything else is a generic 500, logged once with its
trace.

## Failures and hardening

Every request gets an id of the app's own (`Core\Http\RequestId`): it is in the `X-Request-Id` header, in every error
body (`{message, errors?, request_id}`), and on every log line the request writes, beside who it acted for. The panels
show it on a server failure as «کد پیگیری», and the owner finds the lines by it. A failure's details (class, trace) are
shown only with `APP_DEBUG`, only for a 500, and only to a request straight from the server itself. What the app cannot
handle — a broken `config.php`, a fatal error — is `public/index.php`'s last resort: the same JSON 500 with its id.

Around that:

- every answer carries a sandboxing policy and is not cached (the return page has a policy of its own, which runs no
  script either);
- the app takes secrets out of every line it writes to its log and of every failure's details (`Core\Logging\Redact`):
  bot tokens, the secret part of the machine addresses (the webhooks, the cron, the payment callbacks), a login written
  into an address (a proxy's) and a key in an address's query. PHP's own warnings and fatal errors, which PHP itself
  writes to `storage/logs/php-errors.log`, are not cleaned;
- an online gateway's callback is never believed — it only makes the shop ask the gateway itself
  (`Payments\Services\OnlinePayments`) —, a gateway's webhook is believed only when a method's secret signs it, and a
  forwarded bank SMS only with the shop's key;
- every sign-in is throttled, and the throttle fails closed;
- a fresh upload's installer needs a key from the host's files;
- uploaded pictures are judged by their bytes and written again;
- every file the app writes is owner-only where PHP runs as the account that owns the app (`Core\Support\Files`).

[Security](Security.md) has the reporting policy and [Running in production](Running-In-Production.md) the operator's
side.

## Modules

Domain code lives in `app/Modules/<Module>/` (models, services, controllers, enums, tasks). `app/Core` (HTTP,
database, drivers and forms, mail, captcha, scheduling, security, sessions, logging, configuration, files) and
`app/Support` (input, money, time, Persian, traffic) name no module. What Core needs from a module is an interface the
module implements: `Core\Scheduling\Shops` is the Bots module's `BotShops`.

| Module | What it is |
|---|---|
| Providers | The VPN panels: connectors (3X-UI, PasarGuard, HMPanel, Marzban, Rebecca, Remnawave), servers and their health |
| Catalog | Plans, categories, and which servers can sell a plan today |
| Subscriptions | A customer's service on a panel: provisioning, sync, renewal, moves, grants, extras, reminders |
| Orders, Payments | The order's lifecycle; payment methods, the checkout the bot and the website share, the settlement |
| Discounts, Rewards | Codes, sales, loyalty tiers; cashback, gift codes, the lucky wheel, the nightly draw |
| Users, Referrals | Customers, wallets, groups and pictures; invite links and commissions |
| Agency, Bots | Agents, their levels and traffic; the bots, and whose shop the code works in |
| Telegram | The bots: update dispatch, handlers, gates, texts, keyboards, broadcasts, the report group, the shop's management in the private chat |
| Accounts, Store | Customers' sign-in on a website or the mini app, and their accounts; the Store API, its two doors, and the mini app's settings |
| Notifications, Support, Reviews | What the shop tells a customer; support tickets; customers' reviews |
| Auth | The panels' sign-in, the throttle, principals and actors, the grants a shop gives its website's admins (`StaffGrant`) |
| Admin | The panels' controllers, the dashboard and the queues |
| Settings, Installer, Updates | The settings screens; the web installer; the one-press update |
| Backups | A whole installation's or one shop's backup: written, checked and restored, and sent to the report group ([below](#backups)) |
| Imports | Another bot's shop brought into the main shop: its data read once, reviewed by the owner and written ([below](#imports)) |

Which modules each module may name is one map, `MODULES` in `tests/Unit/Core/LayersTest.php`, held to the code
exactly: a new dependency between modules fails the test until the map says so, with a comment saying why where it is
more than a plain use of the other's services. A module another leans on does not lean back: Store calls Accounts, and
Accounts asks what it needs of a website through its own interface (`Accounts\Contracts\SignInSite`); Store keeps the
mini app, and the bot asks it for the app's address through Telegram's own interface
(`Telegram\Contracts\MiniAppAddress`). The doors to the whole shop — Admin, Store, Telegram — name most of it.

## Many bots, one database

One installation runs the main bot and every agent's bot, **each with a shop of its own** — customers, plans,
categories, payment methods, orders, payments, services, settings, texts, keyboards, report group, broadcasts,
website, mini app, tickets — in the same database. The servers, grants, the agency and the bots themselves belong to the
installation as a whole.

- A shop's row carries `bot_id`, and its model uses `Bots\Models\Concerns\BelongsToBot`: a global scope holds every
  query to the current shop and a new row is filled in with it. Another shop's row is simply not found — a 404.
- `Bots\CurrentBot` says whose shop the code works in: the bot an update came to, the shop a panel request names, the
  website or the mini app a store key opens, each shop in turn for a scheduled task. `CurrentBot::run($bot, $work)` works in a shop and
  comes back; `CurrentBot::everywhere($work)` lifts the scope for work on the servers.
- Everything per shop follows: `BotApi` speaks as the current bot, `Settings` reads its rows, a notification runs in
  its recipient's shop, a report goes to the group of the bot it is about.
- The owner's panel keeps the shop it shows in its address (`/admin/s/<id>/…`) and names it on every request in the
  `X-Shop` header (`Auth\NamedShop`) — never in the session, which every tab of a browser shares.

## Principals and actors

Whoever works a shop through an API is a **principal** (`Auth\Principal`): the owner on their panel, an agent on
theirs, or one of a shop's admins (a customer whose role is admin) on its website's admin API — or, in the bot's private
chat, whose management asks the panels' API in-process with a principal marked `inBot`, the owner's or an agent's, one
of the shop's admins among them with their account as its `customer` ([The bots at work](#the-bots-at-work)). The route
group's guard puts it on the request (`Principal::of()`), runs the request in its shop, and makes it the request's reader
(`Auth\CurrentPrincipal`) — an answer can depend on who reads it; the owner's login, for one, is shown to nobody else.

Every operation that records a decision takes an **actor** (`Auth\Actor`): a principal, an admin pressing a button in
the report group, or the shop itself (a timer). The actor's name is kept as the decision's `reviewer` and appears on
the request's log lines, and an admin acting as themselves is refused decisions about their own account
(`ActorRefusedException`). Anywhere else the name is read through `Auth\Services\Reviewers`: the owner themselves
reads it as kept (`Principal::isTheOwner()`), everyone else — an agent, a website's admins, a bot admin working with the
owner's seat, a report group (`ShopReports::bySupport()`, «🎧 توسط پشتیبانی») — reads the owner's login as «پشتیبانی».
Where a screen shows who wrote something (a ticket's answer),
`Reviewers::names()` reads a shop admin's first and last name beside it — every name a screen needs in one query.

## The bots at work

An update arrives through Telegram's webhook (`/webhooks/telegram/{secret}`, or `/webhooks/telegram/bot/{id}/{secret}`
for an agent's bot) or `bin/console bot:poll`, which keeps one long poll per bot open and serves whichever answers. In
its bot's shop, `Telegram\Update\Dispatcher` claims the update id (each is served once), resolves the customer, runs the
gates (bot off, phone, channels) and hands the update to the handler `routes/bot.php` maps its command, button,
callback or conversation step to; then the report group's queue is sent. The details are on
[Internals: Telegram bot](Internals-Telegram-Bot.md).

**The shop's management in the private chat** (`Telegram\Admin`) is a third client of the panels' API, beside the two
panels and the website's admin area: whoever has a seat (`AdminSeats`: the owner's seat — a linked Telegram account or an admin of
the main bot —, or an agent's — the agent or an admin of their bot) moves through screens whose every read and write is a
request to the panels' own routes, built by `PanelBridge` and handled by the app's HTTP kernel in the same process,
through the door `/api/bot-admin` — no copy of a rule, the same refusals, the same log lines, the change feed moving
open panels. A decision made there keeps its maker as the panels' do; a ticket's answer is written on the channel
`manage`.

## Background work

`Core\Scheduling\Scheduler` runs the tasks `bootstrap/schedule.php` lists, each on its grid — its start on the shop's
clock and every interval after it (`Scheduler::slot()`: a grid of a day or less starts again at its start every day, a
longer one counts from a Saturday), due once the grid's latest moment came since it last ran, so a late run moves
nothing and a missed moment is run once. The grid is the shop's wall clock: a moment a spring-forward jumps over comes
once, at the first minute after the jump, and in the hour a fall-back repeats a clock minute runs again only once a
whole interval of real time has passed since the run it repeats (`due()`), so a task every hour or more often keeps
running through it (an hourly one keeps its hour) and a daily 02:30 runs once, at the first 02:30. A last run ahead of
the clock (a clock put back) counts as now. Intervals and starts are the owner's, set in «زمان‌بندی کارها»
(`Core\Scheduling\Intervals`, which the Settings module's `ScheduleSettings` implements, so Core names no module), the
list's minutes and `at` the defaults — and the defaults while the database cannot be read. Its clock is the app's
(`Carbon::now()`), which a test may hold. It is ticked every
minute by `bin/console schedule:run` from a cron, by the `/cron/{token}` address for a host whose cron can only call a
URL, or by `bot:poll` (in a process of its own, so the polls go on). One run at a time (a file lock); a task's time is
written before it runs, so one that kills the process does not run again every minute. A shop's task runs once in
every shop; the servers' tasks run once across all of them. A run's 45 seconds are shared over its turns
(`Core\Scheduling\Budget`), and a task working through a queue stops at its share, so a run does not grow with the
number of agents. [Running in production](Running-In-Production.md#the-task-schedule) lists the tasks.

## The change feed

The panels follow the shop without a reload. `Core\Database\ChangeFeed` reads every committed write off the database
connection's own events — whoever made it: a panel request, the bot, a task — and bumps a version number per area
(`change_versions`, `ChangeFeed::AREAS` maps each table to its area). A panel polls `GET /api/{panel}/changes` (the
website's admins `…/admin/changes`) every few seconds and refetches what the moved areas show. No service has to
report its writes; a write a screen need not follow (a customer's last-seen time) is made inside
`ChangeFeed::quietly()`.

Each write counts twice: for the installation, and for the shop it was made in (`Core\Scheduling\Shops::current()`).

- **The owner's panel** reads the installation's numbers: every shop's writes.
- **An agent's panel and a website's admins** read their own shop's numbers, and the writes made for every shop (one
  made across every shop, or inside `ChangeFeed::forEveryShop()`) — another shop's activity never reloads their
  screens. The agency's area also follows the main shop's writes (an agent's traffic bought from the main bot, a
  request), and what the owner's agency screens write about an agent's shop counts for every shop
  (`Bots\ShopScopeMiddleware`).
- **The servers' areas** (servers, grants, a server's moves) are the installation's alone: one number for everyone.

## Data

`database/schema.php` is every table; an installed shop follows a release through `database/upgrades/`. Balances are
ledgers, states are compare-and-swap columns, and every list loads what its rows show in a fixed number of queries.
See [Database](Database.md).

## Backups

The Backups module saves the database to a file and puts it back (the owner's side: [Backups](Backups.md)). A backup is
of a **scope** (`BackupScope::for()`): the whole installation for the owner in the main shop, one shop in any other
panel. `BackupTables` says whose each table of `schema.php` is, one of four kinds: a shop's own (`SHOP`, found by its
`bot_id`), a shop's by its parent (`CHILDREN`: a plan's servers, a ticket's messages), the owner's (`OWNER`: the bots,
the servers, the agency, grants, moves, the agents' traffic — in the whole installation's backup alone), and what is
true only now (`TRANSIENT`: sessions, sign-in challenges, chat states, the report queue, request keys, the bots' update
dedupe, the change feed — never in a backup). **A new table joins one of them**: `BackupTablesTest` fails until it does,
and holds a shop's own to a `bot_id` and a child to a parent that reaches one.

- **The file** (`BackupWriter`) is gzip of JSON lines: a header (the format and its version, the app's version, the
  scope and its shop, when), each table as a line of its name and columns followed by its rows as arrays, the rows each
  table held, and last an HMAC-SHA256 over every line before it, keyed from `APP_KEY`
  (`Encrypter::macKey('amobot-backup')`). Rows are read and written a batch at a time — a table without an `id` by its
  primary key —, encrypted columns as they are stored. A shop's file holds nothing only the owner reads:
  `OWNER_ONLY_COLUMNS` are written empty (an order's `diagnosis`), and `REVIEWER_COLUMNS` — who of support decided —
  as «پشتیبانی», as the agent sees them everywhere else. Every write and the gzip's close are checked, and the file's
  ending against what was written, before it takes its name: a full disk leaves no half file behind.
- **Reading** (`BackupReader`) checks a file whole before anything moves: the signature — only this installation's key
  signs a file its restore takes, so nobody writes rows of another shop's, or of the owner's, into one —, the row
  counts, a version no newer than the code's, only the tables its scope holds and, a shop's, only that shop's rows
  (`ShopRows`: each own row under its `bot_id`, each child under a parent row read before it).
- **Restoring** (`Restorer`) is one transaction with the foreign keys out of the way (`ForeignKeys::without()`): the
  scope's rows deleted in the reverse of the schema's order and inserted in its order, a batch at a time, in the columns
  the table has now (a dropped column left out, an added one at its default). It also empties the transient tables but
  `KEPT_ON_RESTORE` (the change feed's counters, the update dedupe) — every row for the whole installation, the shop's
  own (by `bot_id`) for a shop: its customers sign in again on its website and mini app. The panels' sign-ins are files,
  never in the database: no restore signs anyone out of a panel. A shop's keeps the owner's part: `OWNERS_COLUMNS` read
  first and written back (a plan's `hidden_by_owner`), `OWNERS_REFERENCES` pointed again at the rows the file brought
  back, or at none; `SHOP_REFERENCES` say what becomes of a shop's row naming an owner's row gone since — `drop` it,
  `null` the column, or `refuse` the file (`analyse()`, whose `BackupAnalysis` words the warnings shown before the
  restore). The owner's part is repaired by a few statements over sets of rows, so a shop of any size restores. A
  shop's restore also puts back the owner's own values where the file holds them empty or «پشتیبانی». A file of an
  older version then goes through `Core\Database\Upgrades` from its version, after the restore's commit (not one with
  it); one older than `BackupTables::OLDEST_RESTORABLE` is refused — an upgrade that moves data from one column to
  another raises it, since the restore cuts rows to today's columns before the upgrades run.
- **The service** (`Backups`) keeps its files in `storage/backups/`: `made/` for a download (an hour), `uploads/` for a
  checked file waiting under a random token beside its description (30 minutes), `kept/<scope>/` for the states written
  just before a restore (the last `KEEP`, 3, pruned only once the restore succeeded). `SendBackupsTask` (`send_backups`,
  a day, in each shop) prunes the folder, then sends the scope's file to the report group's `Topic::Backups` thread,
  streamed from the disk — a line instead when it is over Telegram's 50 MB —, begins no backup once the run's time is
  used up, puts the shops a run left out first at the next (`backups.begun`), and keeps when it tried and what Telegram
  said (`Backups::LAST_SENT`). The panels reach it through `Admin\Api\BackupsController`, in
  both panels' `$configuration` group.

## Imports

The Imports module brings another bot's shop into the main shop («انتقال از ربات دیگر»; the owner's side:
[Importing from another bot](Importing.md)). An import (`Models\Import`, a row of `imports`) goes through
`Enums\ImportPhase` — **reading**, **review**, **importing**, then **done**, **failed** or **cancelled** —, one open at a
time in the installation (decided under `storage/imports/begin.lock`), always into the main shop (`bot_id` 1; its
routes are the owner's, in the `shop.main` group). The panel drives it a request at a time — `POST /imports/{id}/continue`,
a step of `Imports::STEP_SECONDS` (15) by its `Budget` —, and `import:run` from a shell the same way. A step holds the
import's lock file (`storage/imports/<id>.lock`): `Imports::advance()` answers false when another holds it and nothing
was done — `continue` says so as `stepped: false`, and the panel, or the command, waits before asking again.

- **Reading** (`Sources\Stager`) — the data comes one of two ways, the core's and never the migrator's
  (`Enums\ImportSource`): a backup file — `Sources\BackupFile` tells a zip, a gzip or plain SQL by its bytes and
  unpacks it to `storage/imports/<id>/dump.sql` a step at a time, 1 GiB at most and leaving 256 MiB of the disk free
  (`BackupFile::MOST`, `KEEP_FREE`); `DumpReader` streams its statements: `CREATE TABLE`'s columns, and the rows of
  `INSERT` and `REPLACE`, one or many, with or without a column list, a row of 16 MiB at most (less where PHP has little
  memory); anything else passed over, and a `DELIMITER` block too —, or a read-only connection to the other bot's MySQL
  database (`DatabaseSource` through a `Dialect`, `MySqlDialect`: a 5-second connect, a read-only session, 20 seconds a
  statement, `information_schema`, pages by a one-column primary key). Either is read once into the staged copy,
  `import_rows`: only the tables and columns the migrator names (`Migrator::tables()`, a name matched as written, then
  without case — `Names`), every value a string or null, its secrets encrypted with `APP_KEY`, 300 rows a batch written
  with where the reading got to in one transaction (`RowWriter`), so a step stopped anywhere stages no row twice.
  Everything after reads the staged copy (`Staging\DatabaseStaged`): the other bot may be gone, and a migrator never
  knows its source. Read whole, the schema is `Migrator::recognize()`d — not the bot's, the import fails in words naming
  the bot it is when another migrator takes it (Faoxima's database told from MirzaBot's, its ancestor's), else the
  tables missing (a dump of data without its tables' structure, in words of its own) —, `survey()` makes the review,
  and the panels' connections are kept encrypted apart from it (`imports.panels`), so the survey the panels read holds
  no secret. Then `Stager::readingDone()` deletes the uploaded file, its dump and the database's connection: the old
  database whole, the bot's token and keys in it, is not needed again.
- **The review** — `Imports::present()` (the `ImportState` the API answers) gives each panel the server it likely is
  (`Choices::suggestedServer()`: one of the panel's connector at the same scheme, host and port) and proposes choices
  while none are saved; `Choices::check()` holds the owner's to the survey and the shop's servers, every refusal at
  once under `choices.…`.
- **Writing** (`Importer`) — the stages of `Enums\ImportStage` in order, each from the import's `cursor`, a thing at a
  time by its writer (`Writers\*`, sharing a step's `Run`: the choices, the server each panel maps to, the tally), until
  the budget is spent. After every thing, the stage, the cursor, the tally (`imports.counts`) and the problems found are
  saved in one transaction, only while the import is still importing — a cancel stops it at the next thing. The
  migrator only translates; **the core writes, with the shop's own services**: `ServerService` (a panel added is created
  — switched off when the other bot had closed it, with its capacity — and checked, and deleted again when the check
  fails), `CustomerGroups`, the customers written directly — never through `Users\Services\Customers`, which registers a
  newcomer —, `WalletService` (a balance one line, a debt with the credit that covers it), `PlanService` and
  `PlanCategoryService`, `ProvisioningService::adopt()` for a service (one `listClients()` per server a step,
  `findClient()` by name where a connector cannot list — a non-sudo Marzban admin's —; nothing on the panel changes),
  `DiscountCodes`, the gift codes' rows, `RequiredChannels::add()`, `PaymentMethods` and `PaymentCards` (every card
  switched off). Nothing reports to a group, rewards a referral or tells a customer.
- **No newcomer** — a customer who bought in the other bot gets `users.bought_elsewhere_at` (`User::boughtElsewhere()`),
  which the shop reads wherever a newcomer is told apart: a plan's first-purchase price (`Pricing`), a code for a first
  purchase (`DiscountCodes`), a test for newcomers (`TestAccounts`), a referral's start gift
  (`OrderService::boughtBefore()`) and a referrer's milestones (`ReferralGifts`). An imported referral's start gift is
  written decided (a `ReferralBonus` of zeros), so neither side gets one; an account merge carries the mark.
- **Once, however often** — `Links` keeps in `import_links` each thing of the other bot, by its kind and the bot's own
  key for it — a code, a card's number, a channel's chat, a server's panel address (`Run::serverKey()`) — and the row
  it became (unique by shop, migrator, kind and key). A writer asks it first (`Run::broughtBefore()`, `Links::brought()`),
  so a step cut short, an import run again or a later import of a newer backup brings nothing twice — and **brought
  once, never again**: what the owner edited or deleted since is theirs, a deleted thing counted `removed` and never
  brought back (a deleted customer's balance with them; a balance is credited once, ever). Only a server the owner
  chooses to add again, and a category a plan brought now needs, are made again (`Links::alive()`). A customer merged
  since into another account is that account (`Links::customer()`, following `account_merges`), and a group membership
  is brought once (a `membership` link). A link outlives its import.
- **What did not come** is that thing's problem, never the import's: a reason of `Enums\ProblemReason` — only counted
  (the owner's choice, or nothing to act on), or a row of `import_problems` naming it and what finds it. A failure the
  shop did not expect is logged and counted `failed`. A panel that does not answer stops the step before its service
  (`Writers\TryAgain`) for up to three steps (`ServiceWriter::TRIES`), then its services are counted
  `panel_unreachable`. Only the staged copy gone (`Imports::STAGED_GONE` — a restore of the whole installation empties
  it), the migrator failing on its own data (`Imports::BROKEN`, the cause logged) or the bot's migrator gone fails an
  import.
- **Ended** — done, failed or cancelled —, an import's staged rows, files and panels' connections are deleted
  (`Imports::discard()`): they hold the other bot's passwords. Its record, tally and problems stay for its report until
  the owner deletes it. `Tasks\PruneImportsTask` (`prune_imports`, daily) cancels an open import untouched for
  `Imports::IDLE_DAYS` (7), and deletes what an ended one still keeps.

Imports names Backups (the upload limit), Bots, Catalog, Discounts, Orders, Payments, Providers, Referrals, Rewards,
Subscriptions, Telegram and Users; Admin's `ImportsController` and the `import:run` command name it. The other bots are
migrators, a driver family of their own ([Drivers](Drivers.md#a-migrator)). In a backup (`BackupTables`), `imports` and
`import_problems` are the owner's, `import_links` goes with the main shop's rows, and `import_rows` is never in one.

## Files the app writes

Shared hosting has no cache server or lock service, so the app keeps its state in files under `storage/`, each a
container entry so the test suite points it at a folder of its own:

| What | Where (container entry) |
|---|---|
| The configuration, and its writers' lock | `config.php` beside the app (`config.file`), `storage/cache/config.lock` |
| The installation lock and the database's version | `storage/installed.lock` (`installation.lock`) |
| The installer's and the recovery's one-time keys | `storage/install-key.txt`, `storage/recovery-key.txt` |
| The scheduler's state and lock; the poller's lock | `storage/cache/schedule.json` and `schedule.json.lock`, `storage/cache/bot-poll.lock` |
| Throttle counts | `storage/cache/throttle/` (`throttle.path`) |
| The locks that let one process at a time ask a gateway about an online payment: 32 lock files, a payment's picked by its id | `storage/cache/payment-turns/` (`payments.turns`) |
| Short-lived copies of pictures Telegram keeps | `storage/cache/files/` (`files.cache`) |
| The OpenID providers' keys; the router's table | `storage/cache/jwks/`, `storage/cache/routes/` |
| Uploaded receipts, tickets' pictures, QR backgrounds, the mini apps' logos | `storage/uploads/{receipts,tickets,qr,logos}` (`miniapp.logos` for the logos) |
| Sessions, logs | `storage/sessions/`, `storage/logs/` |
| The updater's work and the maintenance flag | `storage/updates/`, `storage/updating.flag` |
| Backups made for a download, uploaded to restore, kept from before a restore | `storage/backups/{made,uploads,kept}` |
| An import's uploaded file and the dump unpacked of it; its step's lock, and the one a new import begins under | `storage/imports/<id>/`, `storage/imports/<id>.lock`, `storage/imports/begin.lock` (`imports.path`) |
| The tokens Marzban panels signed the shop in with, kept across requests | `storage/cache/panel-tokens/` (`panel.tokens`) |

Writes are atomic (a temporary file renamed over the real one) or done under a file lock (`Core\Support\Files`,
`FileLock`), so a reader never sees half a file. `storage/` is refused to the web by its `.htaccess`.

## Drivers

What the shop does through someone else — a VPN panel, a payment gateway, the database, email, a captcha, the other bot
an import reads — is a driver of a family on one small kernel (`Core\Drivers`, `Core\Forms`). A driver describes its own form, and the panels draw
it from that description alone. See [Drivers](Drivers.md). Where a bank's deposits come from
(«تایید خودکار واریز») stands beside the kernel, with no form: each is a `Payments\Deposits\Contracts\DepositSource` that
reads its sender's body — ForApp's forwarded SMS, Rikhtam's word —, listed in `Payments\Deposits\DepositSources`.
