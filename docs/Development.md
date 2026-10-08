# Development

Working on AmoBot itself: what you need, how to run it locally, the checks, and where things are. The engineers'
handbook — every subsystem's rules and the decisions behind them — is
[`CLAUDE.md`](https://github.com/amir-aghajani/amo-bot/blob/main/CLAUDE.md) at the repository's root: read the part you touch.

## The stack

| Concern | Choice |
|---|---|
| HTTP | [Slim 4](https://www.slimframework.com/) (PSR-7/15) and [PHP-DI](https://php-di.org/) |
| Panels | React 19, Vite, TypeScript, Tailwind 4 and shadcn/ui — the owner's and the agents', one project built to static files; live through a change feed |
| API | JSON only — `/api/admin` (the owner's), `/api/agent` (an agent's), `/api/store/v1` (the shops' websites) —, described in OpenAPI 3 (`resources/api/openapi.yaml`): the tests validate every request and response against it, and the panels' types are generated from it |
| Database | MySQL / MariaDB through Eloquent (`illuminate/database`), behind a driver layer; SQLite for the tests |
| CLI | Symfony Console (`php bin/console`) |
| Outgoing HTTP | Guzzle — the VPN panels, Telegram, the OpenID providers, Cloudflare |
| Email | symfony/mailer, and Resend's API |
| Sign-in | firebase/php-jwt (OpenID Connect), the app's own TOTP |
| Logging | Monolog → `storage/logs/`, secrets taken out |
| Quality | PHPUnit 11, PHPStan (level 6, and dead code: shipmonk/dead-code-detector), PHP-CS-Fixer (PER-CS 2); TypeScript, oxlint, knip, Prettier, Vitest |

## What you need

- PHP 8.2 or newer with the extensions of [Installation](Installation.md#what-you-need) (XAMPP ships GD: uncomment
  `extension=gd` in its `php.ini`), and `pdo_sqlite` for the test suite; Composer.
- MySQL or MariaDB for a local shop (XAMPP will do). The tests need none: they run on SQLite in memory.
- Node 22.12 or newer and pnpm 12 (`package.json` names its version: with Corepack switched on, `corepack enable`, the
  right one runs) — only to work on the panels. A release ships them built.

## Running it locally

```bash
composer install
pnpm install && pnpm build       # the panels -> public/admin, public/agent, public/assets
composer serve                   # the API on http://127.0.0.1:8080 (php -S); /admin/ and /agent/ are the last build
```

Open `http://127.0.0.1:8080/` — it goes to the owner's panel, which starts the web installer until the shop is
installed ([the web installer](Installation.md#the-web-installer)), as on a host.

Working on the panels: run `pnpm dev` beside `composer serve` and use `http://127.0.0.1:5173/admin/` (the owner's) or
`http://127.0.0.1:5173/agent/` (an agent's) — hot reload; `/api` is passed on to PHP. `http://127.0.0.1:8080/admin/`
and `/agent/` are always the last `pnpm build`. The owner's panel keeps the shop it shows in its address:
`/admin/s/<id>/…` is an agent's shop. An agent signs in only with the one-time link the main bot gives them
(«نمایندگی» › «🔐 ورود به پنل»); its code rides in the address's `#fragment`, which never reaches a server's logs.

The bot: paste a token from @BotFather into «تنظیمات پنل › ربات تلگرام» (or set `'TELEGRAM_BOT_TOKEN'` in
`config.php`), then:

```bash
php bin/console bot:poll --watch -v # long polling for every bot; restarts on code changes; runs the scheduler too
php bin/console bot:info            # every bot's identity and webhook state
```

Long polling needs no public address, which is why a developer's machine uses it; a shop on a host runs on webhooks
([how the bot receives updates](Running-In-Production.md#how-the-bot-receives-updates)). `bot:poll` holds a lock, so a
second poller refuses to start. On Windows, stopping a background shell may leave its `php.exe` child running old code:
find it by its command line and stop it.

## The checks

```bash
composer check      # code style (dry run) + PHPStan (level 6, and dead code) + the PHP tests
composer fix        # apply the code style (PER-CS 2)
composer test       # the PHP tests alone
composer analyse    # PHPStan alone
pnpm check          # the panels: API types current + typecheck + oxlint + knip + prettier --check + unit tests
pnpm format         # prettier: import order, Tailwind's class order
pnpm api:types      # regenerate the panels' API types after changing resources/api/openapi.yaml
php scripts/docs.php          # regenerate the documentation's generated pages
php scripts/docs.php --check  # the documentation current, and every link between its pages leading somewhere
```

Run them before a pull request; CI runs them all ([Testing](Testing.md)).

## Project layout

```
app/
  Core/            framework glue: Application, config.php, the database (schema, change feed, drivers), the HTTP kernel,
                   drivers and forms, mail, captcha, security, sessions, scheduling
  Console/         bin/console's kernel and commands
  Modules/         one folder per domain (Controllers/ or Api/, Models/, Services/, …) — which modules each may name is
                   tests/Unit/Core/LayersTest's MODULES map
    Accounts       website customers' sign-in and accounts: sessions, OpenID Connect, email, two-factor, ways in, merging
    Admin          the panels' JSON API (/api/admin, /api/agent) and the dashboard
    Agency         resellers: requests, levels (a price per GB), credit, their own bots and the traffic they sell
    Api            /api/app and /health
    Auth           the panels' sign-in: the owner's login and its recovery, an agent's one-time link, the throttle;
                   who acts — a principal, an Actor
    Bots           the bots, and whose shop the code works in
    Catalog        plans, their categories, which servers can sell them
    Installer      the web installer's API (/api/install)
    Notifications  what the shop tells a customer: kept for their website, sent to Telegram or by email
    Orders         the order's lifecycle
    Payments       gateways (wallet, card-to-card), the checkout, the settlement
    Providers      the VPN panels: the contract, 3x-ui and PasarGuard, servers and inbounds
    Referrals      invite links and commissions
    Reviews        customers' reviews of the shop: written on its website, decided by support
    Scheduling     the /cron/{token} trigger
    Settings       the settings table, and the settings screens of config.php and the bot
    Store          the shops' websites' API (/api/store/v1)
    Subscriptions  services on the panels: provisioning, renewal, sync, moves, grants, reminders
    Support        support tickets
    Telegram       the Bot API client, the update dispatcher, handlers, keyboards, texts, broadcasts, the report group
    Users          customers, the wallet ledger, customer groups, pictures
  Support/         helpers: input, money, Persian, traffic, requirements
bootstrap/         app.php (boot), container.php (the DI definitions and the driver registries), schedule.php (the tasks)
config/            config.php's settings made into the app's parts
database/          schema.php — every table (no migrations before the first release)
docs/              this documentation
public/            the web root: index.php, .htaccess and the built panels (admin/, agent/, assets/)
resources/         panel/ (the panels' React source), api/openapi.yaml (the API's description), assets/
routes/            web.php, api.php, webhooks.php, bot.php
scripts/           release.php, docs.php, api-types.mjs, timezones.php
storage/           logs, cache, sessions, uploads, installed.lock
tests/             PHPUnit: Feature/, Unit/, the fakes and the fixtures
```

The panels are one React project (`resources/panel`, Vite, TypeScript, Tailwind 4, shadcn/ui): the owner's app and an
agent's (`src/apps/admin`, `src/apps/agent`) over the shop's pages both have (`src/pages`), shared components
(`src/components`) and libraries (`src/lib`). One `vite build` writes both panels and the files they share.

Adding something: [Extending](Extending.md), [Drivers](Drivers.md).
