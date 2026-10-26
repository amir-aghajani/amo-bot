# AmoBot

[![CI](https://github.com/amir-aghajani/amo-bot/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/amir-aghajani/amo-bot/actions/workflows/ci.yml)

AmoBot is an open-source shop for selling VPN subscriptions. Customers buy, pay for and manage their services in a
Telegram bot; the owner runs the shop from a web panel; and AmoBot makes each service on the owner's own VPN panels
(3X-UI or PasarGuard). It is a PHP application built for ordinary shared hosting: you upload a release zip and set it
up in the browser, with no shell.

Everything a customer, an agent or a shop owner sees is Persian and right to left, by design: prices in Toman, dates in
the Jalali calendar. The code is English; the documentation is English and Persian.

**Documentation: [amir-aghajani.github.io/amo-bot](https://amir-aghajani.github.io/amo-bot/)** — in English and in
[فارسی](https://amir-aghajani.github.io/amo-bot/#/fa/), with search.

> **Status: pre-release.** Versions are 0.x. [Releases](docs/Releases.md) says what each version brought — its
> *Unreleased* section is what `main` has that no release has yet —, and the [Roadmap](docs/Roadmap.md) what is done
> and what is planned.

## Contents

- [Who it is for](#who-it-is-for)
- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Documentation](#documentation)
- [Getting help](#getting-help)
- [Contributing](#contributing)
- [Versions and releases](#versions-and-releases)
- [License](#license)

## Who it is for

- **Shop owners** who sell VPN services through Telegram, run their own 3X-UI or PasarGuard servers, and want the shop
  on an ordinary shared host.
- **Agents** — a shop's resellers. The owner approves them, and each gets a bot, a shop and a panel of their own on the
  same installation: they install nothing.
- **Website developers** who build a shop's own website on the Store API.
- **Contributors and maintainers** of AmoBot itself.

## Features

### Selling in the bot

- Plans by category and location (the servers a plan is sold on), by the day or by the hour, with first-purchase and
  agent prices — and custom services the customer builds by the gigabyte and the day, priced by tiers.
- A service is delivered as its subscription link, on a QR card or as text.
- Each service has its own screen: its usage and its end, a new link («تغییر لینک»), renewal by hand or automatic
  (within the window and by the methods the owner allows), and extra traffic, time or devices at the owner's prices.
- Services handed to another customer, services added to an account by their link, and free test accounts on each
  server's own terms.
- A wallet, with top-ups and money sent to another customer; reminders before a service ends or runs out of traffic;
  referrals; support tickets.
- Every text the bot says, and its whole menu, are the owner's to reword.

### Payments

- **The wallet**, and **card-to-card** with a receipt: several cards per method, each with a daily cap; buttons that
  copy the card number and the sum; receipts approved in the panel or from the report group — or, for a receipt sent
  in the bot, by itself after a review window you set.
- **Online gateways**, which confirm a payment by themselves — no receipt to review:

  | Gateway | How the customer pays |
  |---|---|
  | CubePay | Card-to-card, confirmed from your bank's text message, or crypto (USDT, TRX, TON) |
  | Zarinpal | Any Iranian bank card |
  | AtlasPay | Card-to-card in AtlasPay's mini app inside Telegram, confirmed from your bank's text message |
  | AbanGateway | Card-to-card to your own cards, confirmed from your bank's text message |
  | Plisio (0.7.0 and later) | A coin of the customer's choice, the order priced in US dollars at your rate |
  | AqayePardakht (0.7.0 and later) | Any Iranian bank card, through Shaparak |
  | Variza (0.7.0 and later) | Card-to-card, confirmed from your bank's text message and reported by a signed webhook |

- Every method — the wallet too — has a least and a most amount. An online payment that does not match its order is
  held for support, never settled by itself.

### The panels

- **The owner's panel** (`/admin/`): servers, plans, payment methods, customers, orders and payments, services,
  support tickets, broadcasts, discounts and rewards, the bot's settings, texts and menu, and the installation's own
  settings — for the main shop and for every agent's shop.
- **An agent's panel** (`/agent/`): the same shop screens for the agent's own shop — not the servers, the agents or the
  installation's settings —, signed in with a one-time link from the main bot.
- Servers: a SOCKS5 proxy for a VPN panel the host cannot reach directly, and all of a server's services moved to
  another server in the background — even from a panel that is down.
- Services extended, switched off, moved or handed on; an order made by hand for a customer; receipts reviewed one
  after another, or rejected in bulk.
- Lists, counts and the dashboard follow the shop live. Light and dark, and usable on a phone.
- **A report group**: sales, receipts with approve and reject buttons, failed deliveries, agency requests and support
  tickets, each in its topic of a Telegram group, acted on from there.
- **One-press updates** from the panel, which installs only a signed release (where the host allows it; otherwise the
  shop is updated by hand).

### Agents

- An agent has a bot, a shop and a panel of their own on the shop's servers, and buys the traffic their bot sells from
  the main bot, at their level's price per GB.
- The owner decides which servers and plans each agent sells, until when, and the least they buy each month. Past
  those terms the agent's selling pauses; their customers' services keep working.

### Discounts and rewards

- Discount codes, timed sales and a customer's own discount.
- Loyalty tiers by what a customer spent, cashback, gift codes, a lucky wheel in the bot, and a nightly draw among the
  day's buyers.
- Referral gifts and plan rewards, with an alert for fake referrals.

### The Store API, for a shop's own website

AmoBot has no storefront of its own: it gives the shop's website an API. There, customers sign in with Telegram,
Google, or an email and a password (with two-factor sign-in and a captcha), browse the catalogue, buy and pay, manage
their services and wallet, read the shop's notices, open support tickets and write reviews. The shop's admins can do
its daily work there too, once the owner lets them in. Every operation is described in OpenAPI
(`resources/api/openapi.yaml`). Every shop can have a website of its own: the main shop and each agent's.

### VPN panels and drivers

- **3X-UI** (v3 and later, MHSanaei's) and **PasarGuard** (3.1 and later).
- Each VPN panel, payment gateway, database, mail service and captcha is a driver: a new one is a class and its
  registration ([Drivers](docs/Drivers.md)). Other panels — Marzban, Hiddify — are on the [Roadmap](docs/Roadmap.md).

### Built for shared hosting

- PHP answers a JSON API and nothing else; the panels are static files, built into the release.
- A web installer sets the shop up from the browser, the host's cron runs the scheduler, and Telegram reaches the bots
  through webhooks. One installation runs the main bot and every agent's bot.
- No shell, Composer or Node on the host: an owner never types a command.

## Requirements

To run a shop — [Installation](docs/Installation.md#what-you-need) has the details, and the web installer checks them:

| What | Needed |
|---|---|
| Hosting | Shared hosting with cPanel, DirectAdmin or the like — the one supported way to run a shop |
| PHP | 8.3.36, 8.4.27 or 8.5.12, or a later release of the same branch, with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `bcmath` and `fileinfo`, and a `memory_limit` of 64M or more |
| Optional PHP extensions | `gd` for the QR card (without it, the bot sends the link as text); `zip` and `sodium` to update from the panel (without them, you update by hand) |
| Database | MySQL 5.7.8+ or MariaDB 10.3+: an empty database, and a user with every privilege on it |
| Domain | HTTPS — Telegram sends its webhooks only to `https://` addresses |
| Uploads | 10 MB (`upload_max_filesize` 10M, `post_max_size` 12M), for receipts and pictures |
| Scheduler | A cron job every minute — or an outside service that calls the shop's cron address every minute |
| Telegram | A bot token from @BotFather |

To build from the source: Composer, PHP's `pdo_sqlite` for the test suite, and Node 22.12+ with pnpm 12 for the panels
([Development](docs/Development.md#what-you-need)). A release zip carries the PHP packages and the built panels.

## Quick start

### Running a shop

No step needs a console: each is done in the browser or in your host's control panel.

1. Download `amobot-<version>.zip` from the [releases](https://github.com/amir-aghajani/amo-bot/releases). Never upload
   a copy of the repository: only a release carries the PHP packages and the built panels.
2. In cPanel, create a database and its user (*MySQL Databases*), and pick the newest PHP the host offers for the
   domain: at least 8.3.36, 8.4.27 or 8.5.12 on its branch, the releases that closed PHP's latest known security holes
   ([what you need](docs/Installation.md#what-you-need)).
3. Upload the zip and extract it. Best: point the domain's document root at the release's `public/` folder
   ([Installation](docs/Installation.md#shared-hosting)).
4. Open `https://your-domain/` and follow the web installer: the install key (in `storage/install-key.txt`, which you
   open in the host's File Manager), the requirements, the database, the panel's login, the site and the bot
   ([the web installer](docs/Installation.md#the-web-installer)).
5. Point Telegram at the shop: in the panel, «تنظیمات پنل › ربات تلگرام» › «ثبت Webhook».
6. Add the scheduler as a cron job every minute (cPanel › *Cron Jobs*). Installation gives the line — or save a cron
   token in «تنظیمات پنل › پیشرفته», and copy the line it prepares with «کپی خط Cron».
7. Set the shop up — a server, a plan and a way to pay: the [Owner's guide](docs/Owners-Guide.md#setting-a-shop-up).

### Working on AmoBot

```bash
git clone https://github.com/amir-aghajani/amo-bot.git
cd amo-bot
composer install
pnpm install && pnpm build   # the panels -> public/admin, public/agent, public/assets
composer serve               # http://127.0.0.1:8080 — the web installer, until the shop is installed
```

- `pnpm dev`, beside `composer serve`, runs the panels with hot reload at `http://127.0.0.1:5173/admin/`.
- `php bin/console bot:poll --watch -v` runs the bot with long polling, a developer's way: a shop on its host runs on
  webhooks.
- Before a pull request: `composer check`, `pnpm check` (when the panels changed) and `php scripts/docs.php --check`.

[Development](docs/Development.md) has the rest: a local database, the bot's token, the project's layout.

## Documentation

The documentation is the [`docs/`](docs/Home.md) folder: English, the default — start at [Home](docs/Home.md) — and
Persian, [فارسی](docs/fa/Home.md), every page translated. Its site, https://amir-aghajani.github.io/amo-bot/, shows the
same pages with a sidebar, search and both languages, and so does the project's
[GitHub wiki](https://github.com/amir-aghajani/amo-bot/wiki) ([how they are published](docs/Contributing.md#documentation)).
A release zip carries no `docs/`: read them there.

### For shop owners

| To | Read |
|---|---|
| Check your host and install the shop | [Installation](docs/Installation.md) |
| Keep it running: the bot's updates, the scheduler, the logs, a lost login | [Running in production](docs/Running-In-Production.md) |
| Set the shop up and run it: servers, plans, payments, customers, agents and the rest | [Owner's guide](docs/Owners-Guide.md) |
| See what customers and the bot's admins see in the bot | [Bot guide](docs/Bot-Guide.md) |
| Connect the shop's own website | [Website](docs/Website.md) |
| Change the installation's settings (`config.php`) | [Configuration](docs/Configuration.md) |
| Install a new version | [Upgrading](docs/Upgrading.md) |
| Back the shop up | [Backups](docs/Backups.md) |

### For website developers

- The [Store API](docs/Store-API.md) overview: the base address, CORS, conventions, errors and a minimal client.
- Then, a subject a page: [signing in](docs/Store-API-Sign-In.md), [the account](docs/Store-API-Account.md),
  [the shop](docs/Store-API-Shop.md), [buying](docs/Store-API-Buying.md),
  [after buying](docs/Store-API-After-Buying.md), [notifications](docs/Store-API-Notifications.md),
  [support tickets](docs/Store-API-Support-Tickets.md), [reviews](docs/Store-API-Reviews.md) and
  [the shop's admins](docs/Store-API-Admins.md).
- Every operation, generated from the API's description: the [Store API reference](docs/Store-API-Reference.md), the
  [admin API reference](docs/Store-Admin-API-Reference.md), the [schemas](docs/API-Schemas.md) they share, and the
  description as one file for tools, [`docs/openapi.json`](docs/openapi.json).

### For contributors and maintainers

| To | Read |
|---|---|
| Learn the system | [Architecture](docs/Architecture.md), then [Database](docs/Database.md), [API contract](docs/API-Contract.md) and the internals of the area you touch: [commerce](docs/Internals-Commerce.md), [services](docs/Internals-Services.md), [the Telegram bot](docs/Internals-Telegram-Bot.md), [the website API](docs/Internals-Website-API.md), [the panels](docs/Internals-Panels.md) |
| Set up, run and check the code | [Development](docs/Development.md) |
| Write and run the tests | [Testing](docs/Testing.md) |
| Write code like the code around it | [Conventions](docs/Conventions.md) |
| Add a VPN panel, a payment gateway, a database, a mail service or a captcha | [Drivers](docs/Drivers.md) |
| Add a panel screen, a bot screen, an endpoint, a setting or a scheduled job | [Extending](docs/Extending.md) |
| Propose a change, and change the documentation | [Contributing](docs/Contributing.md) |
| Publish a release | [Releasing](docs/Releasing.md) |
| Handle a security report | [Security](docs/Security.md) |

## Getting help

- **A question about running a shop**: the [Owner's guide](docs/Owners-Guide.md) first — most of its pages have a
  *Watch out for* section of the usual surprises.
- **A bug**: open an [issue](https://github.com/amir-aghajani/amo-bot/issues/new/choose) with the *Bug report* form.
  It asks for the version (the panel's dashboard shows it, on the «وضعیت سیستم» card), your host, the steps, and the
  «کد پیگیری» the panel showed with its log lines. Leave every token, password, key and customer's detail out.
- **An idea**: the *Feature request* form, on the same page.
- **A security weakness**: never in a public issue. Report it privately, through the repository's **Security** tab ›
  **Report a vulnerability** ([what to include](docs/Security.md#reporting-a-vulnerability)).

## Contributing

Issues and pull requests are welcome. Open an issue first for anything larger than a fix — a feature, a new driver, a
change to an API — so its shape is agreed before the work. [Contributing](docs/Contributing.md) has the rules a change
keeps, the checks to run, and how the documentation works. CI runs the checks on every push to `main` and every pull
request, on PHP 8.3, 8.4 and 8.5 ([Testing](docs/Testing.md#ci)).

## Versions and releases

- Versions follow [Semantic Versioning](https://semver.org/). While AmoBot is pre-release, fixes go to `main` and into
  the next release; there are no older release lines ([supported versions](docs/Security.md#supported-versions)).
- Each [GitHub release](https://github.com/amir-aghajani/amo-bot/releases) is the zip a shop uploads, with a manifest
  signed with the project's Ed25519 key. A shop's panel installs only a release signed with the key built into it
  ([Upgrading](docs/Upgrading.md#from-the-panel), [Releasing](docs/Releasing.md)).
- What each version brought, and what to do when you upgrade to it: [Releases](docs/Releases.md).

## License

MIT — see [LICENSE](LICENSE). What you contribute is published under the same license.
