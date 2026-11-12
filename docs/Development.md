[فارسی](fa/Development.md)

# Development

Working on AmoBot itself: what you need, how to run it locally, the checks, and where things are. Start with
[Architecture](Architecture.md) for the map and [Conventions](Conventions.md) for how the code is written; each area's
rules are on its Internals page and in its classes' docblocks.

## The stack

| Concern | Choice |
|---|---|
| HTTP | [Slim 4](https://www.slimframework.com/) (PSR-7/15) and [PHP-DI](https://php-di.org/) |
| Panels | React 19, Vite, TypeScript, Tailwind 4 and shadcn/ui — the owner's and the agents', one project built to static files; live through a change feed. The shops' Telegram mini app is a third page of the same project, built apart |
| API | JSON only — `/api/admin` (the owner's), `/api/agent` (an agent's), `/api/store/v1` (the shops' websites and mini apps) —, described in OpenAPI 3 (`resources/api/openapi.yaml`): the tests validate every request and response against it, and the panels' types are generated from it |
| Database | MySQL / MariaDB through Eloquent (`illuminate/database`), behind a driver layer; SQLite for the tests |
| CLI | Symfony Console (`php bin/console`) |
| Outgoing HTTP | Guzzle — the VPN panels, Telegram, the OpenID providers, Cloudflare |
| Email | symfony/mailer, and Resend's API |
| Sign-in | firebase/php-jwt (OpenID Connect), the app's own TOTP |
| Logging | Monolog → `storage/logs/`, secrets taken out of the app's own lines (PHP's own `php-errors.log` is not cleaned) |
| Quality | PHPUnit 11, PHPStan (level 6, and dead code: shipmonk/dead-code-detector), PHP-CS-Fixer (PER-CS 2); TypeScript, oxlint, knip, Prettier, Vitest |

## What you need

- PHP 8.3.36, 8.4.27 or 8.5.12, or later on the same branch, with the extensions of
  [Installation](Installation.md#what-you-need) (XAMPP ships GD: uncomment `extension=gd` in its `php.ini`), and
  `pdo_sqlite` for the test suite; Composer.
- MySQL or MariaDB for a local shop (XAMPP will do). The tests need none: they run on SQLite in memory.
- Node 22.12 or newer and pnpm 12 (`package.json` names its version: with Corepack switched on, `corepack enable`, the
  right one runs) — only to work on the panels. A release ships them built.

## Running it locally

```bash
composer install
pnpm install && pnpm build       # the panels -> public/admin, public/agent, public/assets; the mini app -> public/app
composer serve                   # the API on http://127.0.0.1:8080 (php -S); /admin/, /agent/ and /app/ are the last build
```

Open `http://127.0.0.1:8080/` — it goes to the owner's panel, which starts the web installer until the shop is
installed ([the web installer](Installation.md#the-web-installer)), as on a host.

Working on the panels: run `pnpm dev` beside `composer serve` and use `http://127.0.0.1:5173/admin/` (the owner's) or
`http://127.0.0.1:5173/agent/` (an agent's) — hot reload; `/api` is passed on to PHP. `http://127.0.0.1:8080/admin/`
and `/agent/` are always the last `pnpm build`, which runs both builds: `vite build` for the panels, then
`vite build --mode app` for the mini app. The owner's panel keeps the shop it shows in its address:
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

## Trying the mini app

The shop's mini app is at `/app/{its key}/` — the address «مینی‌اپ تلگرام» shows in the panel: `pnpm dev` serves it with
hot reload (`http://127.0.0.1:5173/app/{key}/`), `composer serve` the last `pnpm build` (`http://127.0.0.1:8080/app/…`).

- **In a browser** there is no launch data, so the app signs nobody in: it says to open it from the bot. Its screens are
  tried in their tests, each with a fake Telegram and the shop's and the account's answers
  ([Internals: mini app](Internals-Mini-App.md#tests)).
- **In Telegram**: Telegram opens a mini app only from an https address, and signs its launch data with the bot's
  token. Use a development bot of your own, never a shop's live bot — switching the app on sets that bot's menu
  button. Expose `composer serve` through an https tunnel (any tunnelling service), make the tunnel's address
  `APP_URL` («تنظیمات پنل › برنامه»), run `pnpm build`, and switch the mini app on in «مینی‌اپ تلگرام»: the bot's menu
  button beside the message box opens it (or the main menu's «📱 اپلیکیشن فروشگاه», in an inline menu). After a change,
  `pnpm build` again and reopen the app. Put `APP_URL` back when you are done; the next save of the mini app's settings
  takes the menu button off again while it cannot be opened.

## The checks

```bash
composer check      # code style (dry run) + PHPStan (level 6, and dead code) + the PHP tests
composer fix        # apply the code style (PER-CS 2)
composer test       # the PHP tests alone
composer analyse    # PHPStan alone
pnpm check          # the panels: API types + settings index current + typecheck + oxlint + knip + prettier + tests
pnpm format         # prettier: import order, Tailwind's class order
pnpm api:types      # regenerate the panels' API types after changing resources/api/openapi.yaml
pnpm settings:index # regenerate the settings search's index after changing a settings section
php scripts/docs.php          # regenerate the documentation's generated pages
php scripts/docs.php --check  # the documentation current, and every link between its pages leading somewhere
```

Run them before a pull request; CI runs them all ([Testing](Testing.md)). To fix the style of some files only, name the
configuration with them: `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php <paths>`.

The panels' settings search («جستجوی تنظیمات», `lib/settings-search/*`, drawn by `components/settings-search`) finds
every single setting by an index generated from the settings sections' own source: `pnpm settings:index`
(`scripts/settings-index.mjs`, TypeScript's parser) writes `lib/settings-search/settings.generated.ts` — each section's
cards, fields, switches and folds with their labels and hints, as the source writes them — and `pnpm check` fails
(`node scripts/settings-index.mjs --check`) while that file is not what the sources give. So after changing a settings
section's words, run it and commit what it wrote ([Extending](Extending.md#a-setting)).

## Project layout

```
app/
  Core/            framework glue: Application, config.php, the database (schema, upgrades, change feed, drivers), the
                   HTTP kernel and its own endpoints (/api/app, /health, /cron), drivers and forms, mail, captcha,
                   security, sessions, logging, scheduling, files and locks
  Console/         bin/console's kernel and commands
  Modules/         one folder per domain (Controllers/ or Api/, Models/, Services/, …) — which modules each may name is
                   tests/Unit/Core/LayersTest's MODULES map
    Accounts       customers' sign-in on a website or the mini app, and their accounts: sessions, OpenID Connect, email,
                   two-factor, Telegram's launch data, ways in, merging
    Admin          the panels' JSON API (/api/admin, /api/agent) and the dashboard
    Agency         resellers: requests, levels (a price per GB), credit, their own bots and the traffic they sell
    Auth           the panels' sign-in: the owner's login and its recovery, an agent's one-time link, the throttle;
                   who acts — a principal, an Actor
    Bots           the bots, and whose shop the code works in
    Catalog        plans, their categories, which servers can sell them
    Discounts      discount codes, timed sales, loyalty tiers, a customer's own discount
    Installer      the web installer's API (/api/install)
    Notifications  what the shop tells a customer: kept for their website, sent to Telegram or by email
    Orders         the order's lifecycle
    Payments       gateways (wallet, card-to-card, CubePay, Zarinpal, AtlasPay, AbanGateway, Plisio, AqayePardakht,
                   Variza, and "outside": money support took for an order it made), the checkout, the settlement
    Providers      the VPN panels: the contract, 3X-UI, PasarGuard and HMPanel, servers and inbounds
    Referrals      invite links and commissions
    Reviews        customers' reviews of the shop: written on its website, decided by support
    Rewards        cashback, gift codes, the lucky wheel, the nightly draw
    Settings       the settings table, and the settings screens of config.php and the bot
    Store          the Store API (/api/store/v1) the shops' websites and mini apps call, and the mini apps' settings
    Subscriptions  services on the panels: provisioning, renewal, sync, moves, grants, reminders
    Support        support tickets
    Telegram       the Bot API client, the update dispatcher, handlers, keyboards, texts, broadcasts, the report group
    Updates        the owner's one-press update from a signed GitHub release
    Users          customers, the wallet ledger, customer groups, pictures
  Support/         helpers: input, validation, money, time (the shop's clock, Jalali months), Persian, traffic,
                   requirements
bootstrap/         app.php (boot), container.php (the DI definitions and the driver registries), schedule.php (the tasks)
config/            config.php's settings made into the app's parts
database/          schema.php (every table) and upgrades/ (a release's changes for installed shops — see Database)
docs/              this documentation
phpstan/           the project's own PHPStan extensions: Eloquent's forwarded query methods, and what the dead-code check
                   counts as used (the container's entries, the routes, the models' relations something reads)
public/            the web root: index.php, .htaccess, the built panels (admin/, agent/, assets/) and mini app (app/)
resources/         panel/ (the panels' and the mini app's React source), api/openapi.yaml (the API's description), assets/
routes/            web.php, api.php, webhooks.php, bot.php
scripts/           release.php and release-key.php, docs.php, api-types.mjs, timezones.php
storage/           logs, cache, sessions, uploads, installed.lock
tests/             PHPUnit: Feature/, Unit/, the fakes and the fixtures
```

The panels are one React project (`resources/panel`, Vite, TypeScript, Tailwind 4, shadcn/ui): the owner's app and an
agent's (`src/apps/admin`, `src/apps/agent`) over the shop's pages both have (`src/pages`), shared components
(`src/components`) and libraries (`src/lib`). One `vite build` writes both panels and the files they share; the
shops' Telegram mini app (`src/apps/app`) is built apart by `vite build --mode app` into `public/app`
([Internals: mini app](Internals-Mini-App.md)).

Adding something: [Extending](Extending.md), [Drivers](Drivers.md).
