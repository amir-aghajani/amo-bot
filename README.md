# AmoBot

[![CI](https://github.com/amir-aghajani/amo-bot/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/amir-aghajani/amo-bot/actions/workflows/ci.yml)

An open-source shop for VPN services: a Telegram bot customers buy from, a panel the shop is run from (`/admin/`), a
panel of their own for its resellers (`/agent/`), and an API for the shop's own website — over the VPN panels it
connects to (3X-UI, PasarGuard). Everything a customer or a shop owner sees is Persian and right to left, by design; the
code and the documentation are English.

It runs on shared hosting — cPanel, DirectAdmin and the like —, the one way it is supported: PHP answers a JSON API and
nothing else, the panels ship as static files, a web installer sets the shop up from the browser, the host's cron runs
its scheduler and Telegram reaches its bots through webhooks — no shell, Composer or Node on the host.

> **Status:** pre-release (0.1.0). The [Roadmap](docs/Roadmap.md) says what is done and what is left, the
> [changelog](CHANGELOG.md) what each release brought.

## Features

- **A bot that sells** — plans by category and location, the wallet and card-to-card payments with a receipt, the
  subscription link delivered as a QR card, renewals by hand and automatic, reminders before a service ends, referrals,
  and support tickets. Every word it says and its whole menu are the owner's to reword.
- **The owner's panel** — servers, plans, payment methods, customers, orders and payments (receipts reviewed one after
  another), services (extended, switched, moved between servers), support, broadcasts, gifts of days and traffic, the
  bot's texts, menu and rules; live as the shop moves, light and dark, on a phone too.
- **Resellers** — agents with a bot, a shop and a panel of their own on the same servers, who buy the traffic their bot
  sells.
- **VPN panels through connectors** — 3X-UI v3 and PasarGuard 3.1+. A new panel, payment gateway, database, mail service
  or captcha is a driver.
- **A report group** — sales, receipts with approve and reject buttons, failed deliveries, agency requests and support
  tickets answered by a reply, in a Telegram group's topics.
- **The Store API** — a shop's own website on the same shop: sign-in by Telegram, Google or an email (with two-factor
  sign-in and a captcha), the catalogue, the checkout, services, notices, support tickets and reviews — and an admin
  side where the shop's admins do its daily work —, described in OpenAPI.

## Requirements

- A shared host with PHP 8.2 to 8.4 — `pdo_mysql`, `curl`, `mbstring`, `openssl`, `bcmath` and `fileinfo` (`gd` for
  the QR card, `zip` and `sodium` for the updater), a `memory_limit` of 64M or more — and MySQL 5.7.8+ or MariaDB 10.3+.
- A domain with HTTPS, a cron job every minute, and a Telegram bot's token from @BotFather.
- Only to build from the source: Composer, and Node 22.12+ with pnpm for the panels. A release zip carries both built.

## Quick start

**Running a shop:** upload a [release](https://github.com/amir-aghajani/amo-bot/releases) zip to the host, open the
site and follow the web installer — it asks for a key only the host's files hold, checks the host, connects the
database, sets the panel's login and takes the bot's token. Then point Telegram at the shop from the panel and add the
scheduler's cron line ([Installation](docs/Installation.md)).

**Working on AmoBot:**

```bash
composer install
pnpm install && pnpm build   # the panels -> public/admin, public/agent, public/assets
composer serve               # http://127.0.0.1:8080 — the web installer, until the shop is installed
```

Then `pnpm dev` for the panels with hot reload, and `php bin/console bot:poll --watch -v` for the bot — long polling, a
developer's way: a shop on its host runs on webhooks ([Development](docs/Development.md)).

## Documentation

The [`docs/`](docs/Home.md) folder — the same pages the project's GitHub wiki and its GitHub Pages site show (a release
zip carries no `docs/`: https://github.com/amir-aghajani/amo-bot/tree/main/docs):

- running a shop: [Installation](docs/Installation.md), [Running in production](docs/Running-In-Production.md),
  [Configuration](docs/Configuration.md), [Upgrading](docs/Upgrading.md), [Backups](docs/Backups.md);
- the [Owner's guide](docs/Owners-Guide.md) and the [Bot guide](docs/Bot-Guide.md);
- the [Store API](docs/Store-API.md), for a shop's website, and the API's [reference](docs/Store-API-Reference.md);
- [Architecture](docs/Architecture.md), [Drivers](docs/Drivers.md), [Development](docs/Development.md).

## Contributing

Issues and pull requests are welcome: [CONTRIBUTING.md](CONTRIBUTING.md) says how to set up, the checks to run and the
rules a change keeps. A security weakness is reported privately: [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
