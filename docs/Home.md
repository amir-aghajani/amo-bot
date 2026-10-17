[فارسی](fa/Home.md)

# AmoBot

AmoBot is an open-source shop for selling VPN subscriptions. Customers buy, pay for and manage their services in a
Telegram bot; the owner runs the shop from a web panel (`/admin/`), and each agent — a reseller with a bot and a shop of
their own — from theirs (`/agent/`); and a shop's own website can sell through the Store API. AmoBot makes each service on the
VPN panels it connects to (3X-UI, PasarGuard), and takes the payment methods the owner sets up.

It runs on shared hosting — cPanel, DirectAdmin and the like —, the one supported way: PHP answers a JSON API, the
panels are static files, a web installer sets it all up from the browser, the host's cron runs its scheduler and
Telegram reaches its bots through webhooks. Everything a customer or a shop owner sees is Persian, right to left; the
code is in English, and these pages are written in English first.

## Shop owners

Setting up a shop, in order:

1. [Installation](Installation.md) — what the host needs, the release zip, the web installer.
2. [Running in production](Running-In-Production.md) — the bot's updates, the scheduler, the checklist, the logs, a
   lost login.
3. The [Owner's guide](Owners-Guide.md#setting-a-shop-up) — a server, a plan and a way to pay, then the rest of the
   shop.

Then, as the shop runs:

| To | Read |
|---|---|
| Work in the panels, a screen at a time | The [Owner's guide](Owners-Guide.md#the-pages) and its pages |
| See what customers and the bot's admins see in the bot | [Bot guide](Bot-Guide.md) |
| Connect the shop's own website | [Website](Website.md) |
| Change the installation's settings | [Configuration](Configuration.md) and the [config.php reference](Config-Reference.md) |
| Install a new version | [Upgrading](Upgrading.md) |
| Back the shop up | [Backups](Backups.md) |

None of it needs a console: the web installer, the panels and the host's control panel do everything.
[Console commands](Console-Commands.md) are a developer's tools, and a shop's only where its host has a terminal.

## Website developers

The [Store API](Store-API.md) is how a shop's own website signs its customers in, sells to them and serves them. Start
with its overview — the base address, CORS, conventions, errors and a minimal client —, then a subject a page:
[signing in](Store-API-Sign-In.md), [the account](Store-API-Account.md), [the shop](Store-API-Shop.md),
[buying](Store-API-Buying.md), [after buying](Store-API-After-Buying.md), [notifications](Store-API-Notifications.md),
[support tickets](Store-API-Support-Tickets.md), [reviews](Store-API-Reviews.md) and
[the shop's admins](Store-API-Admins.md). Every operation is in the generated [reference](Store-API-Reference.md) — the
shop's admins' in the [admin API reference](Store-Admin-API-Reference.md).

## Contributors and maintainers

| To | Read |
|---|---|
| Learn the system | [Architecture](Architecture.md), then [Database](Database.md), [API contract](API-Contract.md) and the internals of the area you touch: [commerce](Internals-Commerce.md), [services](Internals-Services.md), [the Telegram bot](Internals-Telegram-Bot.md), [the website API](Internals-Website-API.md), [the panels](Internals-Panels.md) |
| Set up, run and check the code | [Development](Development.md) |
| Write and run the tests | [Testing](Testing.md) |
| Write code like the code around it | [Conventions](Conventions.md) |
| Add a VPN panel, a payment gateway, a database, a mail service or a captcha | [Drivers](Drivers.md) |
| Add a panel screen, a bot screen, an endpoint, a setting or a scheduled job | [Extending](Extending.md) |
| Propose a change, and change these pages | [Contributing](Contributing.md) |
| Publish a release | [Releasing](Releasing.md) |

## Reference

- [config.php reference](Config-Reference.md) — every setting of the installation's configuration, generated from the
  code.
- [Bot texts reference](Bot-Texts-Reference.md) — everything the bot says, by group, generated from the code.
- The API, generated from its description: the [Store API](Store-API-Reference.md) for a shop's website, its
  [admin API](Store-Admin-API-Reference.md) for the shop's admins there, the [panels' API](Panels-API-Reference.md) for
  contributors, and the [schemas](API-Schemas.md) they share — and the description itself as one file for tools,
  [`docs/openapi.json`](https://github.com/amir-aghajani/amo-bot/blob/main/docs/openapi.json).
- [Console commands](Console-Commands.md) — every command of `php bin/console`.

## The project

- [Security](Security.md) — reporting a vulnerability privately, and how a shop is kept safe.
- [Releases](Releases.md) — what each version brought, and what to do when you upgrade to it.
- [Roadmap](Roadmap.md) — what is done and what is planned.
- A bug or an idea: the repository's [issues](https://github.com/amir-aghajani/amo-bot/issues), each with its form. A
  security weakness never goes there: report it privately ([Security](Security.md#reporting-a-vulnerability)).

## These pages

One tree of Markdown pages in the repository's `docs/` folder, written to be read as it is in three places: the
repository on GitHub, the project's GitHub wiki and [GitHub Pages](https://amir-aghajani.github.io/amo-bot/). English
is their own language, and every page has its Persian translation — the first line of a page links to its other
versions. How they are written, checked and published:
[Contributing](Contributing.md#documentation).
