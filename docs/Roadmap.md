# Roadmap

Rough order of work. Items marked ✅ exist in the code today; `[ ]` are open.

## Foundation
- ✅ Project skeleton, DI container, the shop's configuration in one `config.php` (no environment, no `.env`), logging (secrets taken out), sessions; PHP answers JSON only (one error shape) — the panels are static files
- ✅ Persian-only UI (bots, panels, installer), RTL panels with Vazirmatn, Persian digits and Jalali dates
- ✅ The schema in one file, `database/schema.php`, `db:rebuild` to follow an edit with the rows carried over, and from the first release on an upgrade for every change (`database/upgrades/`)
- ✅ Drivers, one family each — VPN panels, payment gateways, databases, mail, captchas: a new one is a class and its registration
- ✅ Database drivers: MySQL / MariaDB for a shop, SQLite for the tests and a developer
- ✅ CLI, a developer's tools (and the shop's, on a host with a terminal): db:rebuild, bot:poll, bot:webhook:set/delete, bot:info, panel:probe, schedule:run
- ✅ Panel connectors: the provider contract, 3x-ui and PasarGuard drivers
- ✅ Gateway contract + wallet & manual (card-to-card) drivers
- ✅ Order / payment / provisioning services, every state change a compare-and-swap; one checkout for the bot and the website
- ✅ Telegram: Bot API client, dispatcher (each update served once), session state, gates, webhook + long polling for every bot at once
- ✅ PHPUnit (SQLite), PHPStan level 6 with dead-code detection (code only the tests call counts as dead), PHP-CS-Fixer; every route walked for its guards
- ✅ API contract: `resources/api/openapi.yaml` describes every operation, request body and answer; every request and response of the HTTP tests (and of the panels' tests) is validated against it, and the panels' TypeScript types — each write typed by its path — are generated from it (`pnpm api:types`)
- ✅ Production hardening: security headers and a sandboxing policy on every PHP answer, HSTS, trusted proxies for the visitor's address, sign-in throttles that fail closed, uploads judged by their bytes, request bodies read to 1 MiB, a failure's details never shown to a visitor, a chat that floods a bot left unanswered
- ✅ Failures in one shape with a request id (`X-Request-Id`, on every log line, «کد پیگیری» in the panel); the panels' own crashes reach the shop's log
- ✅ Performance: indexes for measured queries (a shop of 50,000 customers), no query per row (a lazy load fails the tests), the poller's scheduler in a process of its own
- ✅ The shop's time zone: times kept in UTC, shown in `APP_TIMEZONE` by the bot and both panels
- ✅ Email: SMTP, the host's own mail or Resend, set from the panel with a test send
- ✅ Documentation: one tree in `docs/` for the repository, the wiki and GitHub Pages, its references generated from the code — `config.php`, the bot's texts and the API (with `docs/openapi.json`)

## Customer flows (bot)
- ✅ Buy plan: plan details → choose server → choose how to pay (wallet / card receipt) → the subscription link (the one thing a customer gets); clients named `username_n` / `USER_n` with the Telegram id in the comment
- ✅ My services: paged list, one screen per service (status, usage, expiry, last connection, online now; the row's last numbers when the panel is down), the link on request (QR card or text), «تغییر لینک», «تمدید خودکار», renewal by hand, a report of a problem (a support ticket about the service)
- ✅ Wallet: balance + ledger, top-up by preset or typed amount through the checkout; support's credit/debit from the users table
- ✅ Notifications: payment settled / rejected / refunded, a service ending soon or its traffic running low («یادآوری»), a service granted, moved, switched off or deleted, an automatic renewal
- ✅ Referrals («زیرمجموعه‌گیری») and agency («نمایندگی»): invite links and commissions; agents' requests, levels, prepaid traffic and a bot of their own
- ✅ Support tickets: opened and answered in the bot, on the website, from both panels and from the report group
- ✅ «آموزش»: a short guide the shop ships, which the owner rewords
- [ ] A test account («اکانت تست»)
- [ ] Subscription link endpoint `/sub/{uuid}` of our own (today the panel's native subscription URL is handed out)

## The shop's website
- ✅ The Store API: every shop's website (the main shop's and each agent's) on one versioned, described API — sign-in by Telegram, Google or an email and password, two-factor sign-in, accounts merged, the catalogue, the checkout with receipts, services, wallet, referrals, notices, support tickets
- ✅ A captcha on the website's forms — Cloudflare Turnstile or ALTCHA (open source, no third party) — and for the site's own forms, judged by the shop for its backend
- ✅ Customers without Telegram, reached by email
- ✅ Reviews: switched on per website, written there by a customer or — behind the captcha — a guest, decided by support in the panels (or the website's admin side), the approved ones shown with their average — a captcha, a pace and a cap on what waits
- ✅ The shop's admins on the website: the panels' daily work under `/admin` for the bot's admins — let in, a strong sign-in asked and grants given from the panel, a sign-in good for 12 hours (15 minutes for a grant or a payment's approval); no admin decides about themselves, every change logged with who made it
- [ ] Online payment gateways (the checkout already answers with a next step, so a redirect gateway fits in)
- [ ] Discount codes
- [ ] An AI support assistant
- The site's own content (blog, FAQ, app downloads) stays the site's.

## Panels
- ✅ Two panels from one React project: the owner's (`/admin/`, each tab in the shop its address names) and an agent's (`/agent/`, their shop only, signed in by a one-time link from the main bot) — the Claude Console's design language, light and dark
- ✅ Live: lists, counts, the dashboard and open detail modals follow changes made anywhere within seconds (the change feed)
- ✅ Dashboard; servers (checks, inbounds for sale, «افزودن زمان و حجم»); plans and categories; payment methods; users and their groups; orders, payments (receipts reviewed one after another with «بعدی», approve / reject / refund / retry) and subscriptions (sync, extend, switch, move, delete); referrals; agents; support tickets
- ✅ Lists: paged on the server, sorted by a column, filtered (status, customer, server, a range of days in the Jalali calendar), searched (`#12` is that row), the view kept in the address; the stuck-orders queue («نیازمند رسیدگی»), the receipts to review and the tickets waiting on an answer counted in the menu
- ✅ A customer's page: their services, payments, orders and tickets, wallet, referrals, groups, agency, website account
- ✅ Extend one service: days and traffic on top of what it has (an agent's GB from their traffic)
- ✅ Bot: keyboards editor (premium-emoji icons), bot texts editor (Telegram HTML checked, live preview), settings (master switch, phone, required channels, wallet, renewal, reminders, QR card), report group with topics and buttons, broadcasts («پیام همگانی», «هدیه همگانی»)
- ✅ Settings: the panel's own configuration in `config.php` (application, database, Telegram, email, advanced), the bots' webhooks set or removed, the owner's login changed and recovered without a shell; the website's settings in both panels
- ✅ Failures drawn one way (what failed, why, what to do, the request's id), offline and stale-build handling
- [ ] Activity log

## Scheduled tasks
- ✅ Auto-approval of unreviewed receipts, paid orders a dying process left undelivered resumed, walked-away checkouts dropped, broadcasts in batches, automatic renewal, the next renewed period, grants, the periodic sync of every running service, reminders, the report groups' queue, the housekeeping — one run's time shared over them

## More connectors and gateways
- [ ] Panels: Marzban, Hiddify, WireGuard-based panels
- [ ] Gateways: crypto (NowPayments / direct TRX-USDT), Zarinpal / IDPay style IRR gateways, Telegram Stars
- [ ] Databases: PostgreSQL (a driver; the schema tooling's sequences after a rebuild, a case-insensitive search)

## Installers and operations
- ✅ Shared hosting (cPanel, DirectAdmin…) as the one supported way: the host's cron (or the cron address) runs the scheduler, Telegram's webhooks bring the updates
- ✅ Web installer at `/admin/install` (install key → requirements → database → tables → panel login → site and bot → finish) over `/api/install`: the one way to install, no shell needed
- ✅ Release zips with `vendor/` and the built panels bundled (`scripts/release.php`, a GitHub release per tag)
- ✅ The owner's one-press update («تنظیمات پنل › به‌روزرسانی»): only a release signed with the key built into the shop, a step a request, the shop held a moment, taken back by itself when it fails; database upgrades from the first release on ([Upgrading](Upgrading.md))
- [ ] Backup/restore (the database, `config.php` and the uploads) for moving between hosts
- [ ] Docker compose for development
