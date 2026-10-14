# Architecture

A map of the system for someone new to the code: the parts, how a request, a Telegram update and a minute of
background work travel through them, and the few ideas everything else leans on. Each area's own page goes deeper:
[Database](Database.md), [Conventions](Conventions.md), [API contract](API-Contract.md), [Drivers](Drivers.md), and the
Internals pages — [commerce](Internals-Commerce.md), [services](Internals-Services.md),
[the Telegram bot](Internals-Telegram-Bot.md), [the website API](Internals-Website-API.md), [the panels](Internals-Panels.md).

## The shape of it

AmoBot is a PHP 8.2 application (Slim 4, PHP-DI, Eloquent, Symfony Console) that renders no HTML. It has four faces:

- **Two panels** — the owner's at `/admin/` and an agent's at `/agent/` — one React project (`resources/panel`) built
  to static files the web server serves as they are. They talk to PHP only through the JSON API.
- **The Telegram bots** — the main bot and every agent's — fed by webhooks or long polling.
- **The Store API** (`/api/store/v1/{store}`), which a shop's own website calls from its visitors' browsers.
- **Background work**, ticked every minute by a cron, an external pinger or the poller.

Everything must run on shared hosting (cPanel and the like): no shell for the owner, no daemons, no cache server, no
build step. That one constraint explains many decisions below — `config.php` instead of an environment, files instead
of Redis, the web installer and updater, a scheduler that works in slices.

## Booting

`public/index.php` (HTTP) and `bin/console` (CLI) both call `bootstrap/app.php`, which runs `Core\Application::boot()`:

1. PHP's warnings are kept out of answers and go to `storage/logs/php-errors.log`; exceptions keep no call arguments.
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
| `/api/agent` (an agent's panel) | installed › CSRF header › session › an agent signed in, in their bot's shop |
| `/api/install` | not installed yet › CSRF header › the install key |
| `/api/store/v1/{store}` | installed › the website the key opens › JSON bodies › (a customer's bearer token) |
| `…/admin` under the store | › a customer's token › one of the shop's admins, let in, signed in recently enough |
| `/webhooks/…`, `/cron/{token}` | installed (a secret in the address authenticates) |
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

Around that: every answer carries a sandboxing policy and is not cached; secrets (bot tokens, the webhook and cron
secrets) are taken out of every log line and error (`Core\Logging\Redact`); every sign-in is throttled and the throttle
fails closed; a fresh upload's installer needs a key from the host's files; uploaded pictures are judged by their
bytes and written again; and every file the app writes is owner-only where PHP runs as the account that owns the app
(`Core\Support\Files`). [Security](Security.md) has the reporting policy and
[Running in production](Running-In-Production.md) the operator's side.

## Modules

Domain code lives in `app/Modules/<Module>/` (models, services, controllers, enums, tasks). `app/Core` (HTTP,
database, drivers and forms, mail, captcha, scheduling, security, configuration) and `app/Support` (input, money,
Persian, traffic) name no module: what Core needs from one is an interface the module implements (`Core\Scheduling\Shops`
is the Bots module's `BotShops`).

| Module | What it is |
|---|---|
| Providers | The VPN panels: connectors (3x-ui, PasarGuard), servers and their health |
| Catalog | Plans, categories, and which servers can sell a plan today |
| Subscriptions | A customer's service on a panel: provisioning, sync, renewal, moves, grants, extras, reminders |
| Orders, Payments | The order's lifecycle; payment methods, the checkout the bot and the website share, the settlement |
| Discounts, Rewards | Codes, sales, loyalty tiers; cashback, gift codes, the lucky wheel, the nightly draw |
| Users, Referrals | Customers, wallets, groups and pictures; invite links and commissions |
| Agency, Bots | Agents, their levels and traffic; the bots, and whose shop the code works in |
| Telegram | The bots: update dispatch, handlers, gates, texts, keyboards, broadcasts, the report group |
| Accounts, Store | Website customers' sign-in and accounts; the websites' API |
| Notifications, Support, Reviews | What the shop tells a customer; support tickets; customers' reviews |
| Auth | The panels' sign-in, the throttle, principals and actors |
| Admin | The panels' controllers, the dashboard and the queues |
| Settings, Installer, Updates | The settings screens; the web installer; the one-press update |

Which modules each module may name is one map, `MODULES` in `tests/Unit/Core/LayersTest.php`, held to the code
exactly: a new dependency between modules fails the test until the map says so, with a comment saying why where it is
more than a plain use of the other's services. A module another leans on does not lean back: Store calls Accounts, and
Accounts asks what it needs of a website through its own interface (`Accounts\Contracts\SignInSite`). The doors to the
whole shop — Admin, Store, Telegram — name most of it.

## Many bots, one database

One installation runs the main bot and every agent's bot, **each with a shop of its own** — customers, plans,
categories, payment methods, orders, payments, services, settings, texts, keyboards, report group, broadcasts,
website, tickets — in the same database. The servers, grants, the agency and the bots themselves belong to the
installation as a whole.

- A shop's row carries `bot_id`, and its model uses `Bots\Models\Concerns\BelongsToBot`: a global scope holds every
  query to the current shop and a new row is filled in with it. Another shop's row is simply not found — a 404.
- `Bots\CurrentBot` says whose shop the code works in: the bot an update came to, the shop a panel request names, the
  website a store key opens, each shop in turn for a scheduled task. `CurrentBot::run($bot, $work)` works in a shop and
  comes back; `CurrentBot::everywhere($work)` lifts the scope for work on the servers.
- Everything per shop follows: `BotApi` speaks as the current bot, `Settings` reads its rows, a notification runs in
  its recipient's shop, a report goes to the group of the bot it is about.
- The owner's panel keeps the shop it shows in its address (`/admin/s/<id>/…`) and names it on every request in the
  `X-Shop` header (`Auth\NamedShop`) — never in the session, which every tab of a browser shares.

## Principals and actors

Whoever works a shop through an API is a **principal** (`Auth\Principal`): the owner on their panel, an agent on
theirs, or one of a shop's admins (a customer whose role is admin) on its website's admin API. The route group's guard
puts it on the request (`Principal::of()`), runs the request in its shop, and makes it the request's reader
(`Auth\CurrentPrincipal`) — an answer can depend on who reads it; the owner's login, for one, is shown to nobody else.

Every operation that records a decision takes an **actor** (`Auth\Actor`): a principal, an admin pressing a button in
the report group, or the shop itself (a timer). The actor's name is kept as the decision's `reviewer` and appears on
the request's log lines, and an admin acting as themselves is refused decisions about their own account
(`ActorRefusedException`). Anywhere else the name is read through `Auth\Services\Reviewers`: the owner's panel shows it
as kept, everyone else — an agent, a website's admins, a report group (`ShopReports::bySupport()`, «🎧 توسط پشتیبانی») —
reads the owner's login as «پشتیبانی».

## The bots at work

An update arrives through Telegram's webhook (`/webhooks/telegram/{secret}`, or `/webhooks/telegram/bot/{id}/{secret}`
for an agent's bot) or `bin/console bot:poll`, which keeps one long poll per bot open and serves whichever answers. In
its bot's shop, `Telegram\Update\Dispatcher` claims the update id (each is served once), resolves the customer, runs the
gates (bot off, phone, channels) and hands the update to the handler `routes/bot.php` maps its command, button,
callback or conversation step to; then the report group's queue is sent. The details are on
[Internals: Telegram bot](Internals-Telegram-Bot.md).

## Background work

`Core\Scheduling\Scheduler` runs the tasks `bootstrap/schedule.php` lists, at their intervals. It is ticked every
minute by `bin/console schedule:run` from a cron, by the `/cron/{token}` address for a host whose cron can only call a
URL, or by `bot:poll` (in a process of its own, so the polls go on). One run at a time (a file lock); a task's time is
written before it runs, so one that kills the process does not run again every minute. A shop's task runs once in
every shop; the servers' tasks run once across all of them. A run's 45 seconds are shared over its turns
(`Core\Scheduling\Budget`), and a task working through a queue stops at its share, so a run does not grow with the
number of agents. [Running in production](Running-In-Production.md#the-scheduler) lists the tasks.

## The change feed

The panels follow the shop without a reload. `Core\Database\ChangeFeed` reads every committed write off the database
connection's own events — whoever made it: a panel request, the bot, a task — and bumps a version number per area of
the shop (`change_versions`). A panel polls `GET /api/{panel}/changes` every few seconds and refetches what the moved
areas show. No service has to report its writes; a write a screen need not follow (a customer's last-seen time) is
made inside `ChangeFeed::quietly()`.

## Data

`database/schema.php` is every table; an installed shop follows a release through `database/upgrades/`. Balances are
ledgers, states are compare-and-swap columns, and every list loads what its rows show in a fixed number of queries.
See [Database](Database.md).

## Files the app writes

Shared hosting has no cache server or lock service, so the app keeps its state in files under `storage/`, each a
container entry so the test suite points it at a folder of its own:

| What | Where (container entry) |
|---|---|
| The configuration, and its writers' lock | `config.php` beside the app (`config.file`), `storage/cache/config.lock` |
| The installation lock and the database's version | `storage/installed.lock` (`installation.lock`) |
| The installer's and the recovery's one-time keys | `storage/install-key.txt`, `storage/recovery-key.txt` |
| The scheduler's state and lock; the poller's lock | `storage/cache/schedule.json`, `storage/cache/bot-poll.lock` |
| Throttle counts | `storage/cache/throttle/` (`throttle.path`) |
| Short-lived copies of pictures Telegram keeps | `storage/cache/files/` (`files.cache`) |
| The OpenID providers' keys; the router's table | `storage/cache/jwks/`, `storage/cache/routes/` |
| Uploaded receipts, tickets' pictures, QR backgrounds | `storage/uploads/{receipts,tickets,qr}` |
| Sessions, logs | `storage/sessions/`, `storage/logs/` |
| The updater's work and the maintenance flag | `storage/updates/`, `storage/updating.flag` |

Writes are atomic (a temporary file renamed over the real one) or done under a file lock (`Core\Support\Files`,
`FileLock`), so a reader never sees half a file. `storage/` is refused to the web by its `.htaccess`.

## Drivers

What the shop does through someone else — a VPN panel, a payment gateway, the database, email, a captcha — is a driver
of a family on one small kernel (`Core\Drivers`, `Core\Forms`). A driver describes its own form, and the panels draw
it from that description alone. See [Drivers](Drivers.md).
