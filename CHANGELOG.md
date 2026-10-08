# Changelog

What each release of AmoBot brought, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions [Semantic Versioning](https://semver.org/);
a release's section is its notes on GitHub.

## Unreleased

## 0.1.0

The first release: an open-source shop for VPN services — a Telegram bot customers buy from, the owner's panel, a panel
for each reseller and an API for the shop's own website — over the VPN panels it connects to, on shared hosting.
Everything a customer or an owner sees is Persian, right to left.

### Added

- **Hosting and installation** — shared hosting (cPanel, DirectAdmin…) as the one supported way: a release zip, a web
  installer opened by a key only the host's files hold, the configuration in one `config.php`, the host's cron (or a
  cron address) for the scheduler, Telegram's webhooks for every bot, and a lost panel login recovered the same way.
- **The bot** — plans by category and location, a checkout that pays from the wallet or by card-to-card with a receipt,
  the subscription link as a QR card, each service's usage and a new link on request, renewals by hand and automatic,
  reminders, the wallet, referrals, a short guide and support tickets; required channels, a verified phone and a master
  switch; broadcasts. Every word it says and its whole menu are the owner's to reword.
- **The panels** — the owner's (`/admin/`) and each agent's (`/agent/`), live as the shop changes, light and dark, on a
  phone too: the dashboard; servers, plans, categories and payment methods; customers with their page, groups, wallet and
  website account; orders, payments (receipts reviewed one after another) and services (synced, extended, switched,
  moved between servers); support tickets and reviews; referrals; broadcasts and gifts of days and traffic; the bot's
  texts, keyboards and settings; the website's settings; and the installation's own settings, email included.
- **VPN panels** — 3X-UI v3 and PasarGuard 3.1+ through connectors: one subscription link a service, its term counted
  from the first connection, every running service read from its panel every 15 minutes. A new panel is a driver.
- **Payments** — the wallet and card-to-card, one checkout for the bot and the website, every payment settled and every
  order delivered once, refunds, unpaid orders expired; receipts approved from the panels or the report group, or by
  themselves after a method's window. A payment gateway is a driver.
- **Agency** — resellers with a bot, a shop and a panel of their own on the shop's servers: requests reviewed, levels
  priced per GB, traffic bought from the main bot with a credit, and the owner able to open any agent's shop.
- **Report group** — a Telegram group whose topics carry the sales, receipts with approve and reject buttons, failed
  deliveries with a retry, agency requests, support tickets answered by a reply, and new reviews.
- **The Store API** — every shop's website on one versioned API described in OpenAPI: sign-in by Telegram, Google or
  an email and password, two-factor sign-in, accounts merged, a captcha (Cloudflare Turnstile or ALTCHA); the catalogue,
  the checkout with receipts uploaded from the site, services, orders, the wallet, referrals and notices — emailed to a
  customer Telegram cannot reach —; and an admin side where the shop's admins do its daily work.
- **Support tickets** — opened from the bot or the website, answered from either panel or the report group, with
  pictures and a rating, the customer told wherever they can be reached.
- **Reviews** — written on the shop's website by a customer, or by a guest behind the captcha, decided by support, the
  approved ones shown with their average.
- **The updater** — a new release installed from the owner's panel in one press, a step a request within a shared
  host's limits: only a release signed with the key built into the shop, its zip checked against the signed manifest,
  the shop held a moment while its folders are swapped and its database upgraded, the swap taken back by itself when
  anything fails, and the previous version kept for a rollback; the dashboard says when a new version is out. From this
  release on, every change of the database ships as an upgrade.
- **Documentation** — one `docs/` tree, read in the repository, as its wiki and on GitHub Pages: installing and
  running a shop, the owner's and the bot's guides, the Store API's guides and reference, the `config.php` and bot texts
  references, and the developers' pages.
