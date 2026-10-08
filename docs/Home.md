# AmoBot

AmoBot is an open-source shop for VPN services: a Telegram bot customers buy from, a panel the shop is run from
(`/admin/`) and a panel of their own for its resellers (`/agent/`), and an API for the shop's own website — over VPN
panels it connects to (3X-UI, PasarGuard) and the payment methods it takes. It runs on shared hosting — cPanel,
DirectAdmin and the like —, the one way it is supported: PHP answers a JSON API, the panels are static files, a web
installer sets it all up from the browser, the host's cron runs its scheduler and Telegram reaches its bots through
webhooks. Everything a customer or a shop owner sees is Persian, right to left; the code and these pages are English.

## Where to start

| You are | Start with |
|---|---|
| Setting up a shop | [Installation](Installation.md), then [Running in production](Running-In-Production.md) and the [Owner's guide](Owners-Guide.md) |
| Running a shop | The [Owner's guide](Owners-Guide.md) — the panels, servers, plans, payments, customers, support — and the [Bot guide](Bot-Guide.md) |
| Building a shop's website | The [Store API](Store-API.md) |
| Working on AmoBot | [Architecture](Architecture.md), [Development](Development.md), [Contributing](Contributing.md) |

## Reference

- [config.php reference](Config-Reference.md) — every setting of the installation's configuration, generated from the
  code.
- [Bot texts reference](Bot-Texts-Reference.md) — everything the bot says, by group, generated from the code.
- The API, generated from its description: the [Store API](Store-API-Reference.md) for a shop's website, its
  [admin API](Store-Admin-API-Reference.md) for the shop's admins there, the [panels' API](Panels-API-Reference.md) for
  contributors, and the [schemas](API-Schemas.md) they share — and the description itself as one file for tools,
  [`docs/openapi.json`](https://github.com/amir-aghajani/amo-bot/blob/main/docs/openapi.json).
- [Console commands](Console-Commands.md), [Drivers](Drivers.md), [Roadmap](Roadmap.md), [Security](Security.md), and
  what each release brought: the
  [changelog](https://github.com/amir-aghajani/amo-bot/blob/main/CHANGELOG.md).

## These pages

One tree of Markdown pages in the repository's `docs/` folder, written to be read as it is in three places: the
repository on GitHub, the project's GitHub wiki and GitHub Pages. How they are written, checked and published:
[Contributing](Contributing.md#documentation).
