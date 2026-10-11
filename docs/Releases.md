# Releases

What each release brought, newest first: a change a shop's owner, its customers or a website's developer would notice.
The section of a version is its release notes — the release workflow publishes it on GitHub, and the panel's update
screen shows it to every shop before it updates ([Releasing](Releasing.md#publishing-it)).

While a version is being made its changes collect under **Unreleased**; releasing it renames that heading to
`## Version <version>`. The versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

Nothing yet.

## Version 0.2.0

### Added

- **Extra traffic and time** («حجم و زمان اضافه») — a customer buys more traffic or more days for one of their
  services, each on its own, from the service's screen in the bot or on the shop's website, paid any way the checkout
  offers. The owner prices them in the bot settings: traffic per GB, time per day, the amounts offered as buttons, each
  at a price of its own or the unit price, and the least and the most a customer may type. A plan may have prices of its
  own instead. Traffic brings a service whose traffic ran out back to life. Days for one whose time ran out start its
  term again from its next connection. An agent's shop sells no more traffic than its pool holds. Off until the owner
  turns it on.
- **Hourly plans** — a plan's term may be hours as well as days («مدت اعتبار»: a number and its unit, «روز» or
  «ساعت»): a one-hour test, a six-hour plan. The hours count from the customer's first connection, as days do. The bot
  and the panels word a term in days, hours or both («۱ روز و ۶ ساعت»), and less than a day before its end they give its
  hour and the hours left. A service renews itself («تمدید خودکار») only on a plan whose term is at least the days it
  renews within, and is reminded of its end only when its term is longer than the reminder's days. The APIs add
  `duration_hours` beside `duration_days`, which rounds a part of a day up; a plan is saved with either.
- **First-purchase prices** («قیمت خرید اول») — a plan may have a price of its own for a customer's first purchase: a
  customer who has never bought a service in the shop pays it, then the plan's price; `0` makes the first purchase free.
  The bot shows a newcomer the plan at that price, its details saying the plan's own price beside it; the website's
  catalogue gives it as `first_purchase_price` (`null`, read with the customer's token, once they have bought); and both
  checkouts charge it. Every plan is offered to everyone, and its services renew at the plan's price. If two orders were
  made at it before either was paid, the one paid first is the customer's first purchase, and the other is not
  delivered, for support to give the money back. The orders screen and the report group mark an order made at it.
- **Agent prices** («قیمت نماینده») — a plan may have a lower price for the shop's agents and for its colleagues: the
  customers of a group the owner marks «همکار» on the users page (an agent's shop marks its own groups). They pay it for
  the plan's purchases and its renewals, by hand or by «تمدید خودکار»; one who could pay a first-purchase price too pays
  the lower. The bot shows them the plan at that price with its own price beside it, the website's catalogue gives it
  as `agent_price` to them alone, and the orders screen and the report group mark an order made at it (the APIs'
  `pricing`: `regular`, `first_purchase` or `agent`).
- **Several plans' prices at once** — «تغییر گروهی قیمت» on the plans screen raises or lowers the plans picked by a
  percent or by a number of Toman, rounded to the nearest 100, 1,000 or 10,000 Toman if asked, on their prices, their
  first-purchase prices, their agent prices or any of them. It shows every plan's prices before and after first, and
  changes none while one of them cannot take it (a price below zero, a first-purchase or agent price no longer below the
  price).
- **Plan and location buttons in the bot** — each plan's button in «خرید اشتراک», and each server's button among a
  plan's locations, may be blue, green or red, and may share its row with its neighbours: two or three to a row.
- **A plan's checkout note** («یادداشت پیش‌فاکتور») — a few lines the bot says under the plan's checkout, a purchase's
  and a renewal's: the apps it was tested on, a caveat. The website reads it as `checkout_note`.
- **Discount codes** («کدهای تخفیف») — a percent off (with a ceiling if wanted), a number of Toman off, or the whole
  price. A code may be held to purchases, renewals, extra traffic or extra time, to some plans or servers, and to a
  customer's first purchase. It can have a least amount, a start and an end to the hour, a number of uses in all and
  for each customer. The bot's checkout offers «🏷 کد تخفیف دارم» while the shop has a code, and the website sends
  `discount_code` with an order (`POST /discount-codes/check` prices one first). A use is held by an unpaid order for
  as long as the owner sets (an hour by default), then the order is dropped and the use given back; a cancelled order
  gives its use back at once.
- **Sales** («فروش ویژه») — a percent off the shop's plans, or some of them, between two moments. The bot and the
  website show the plan at its sale price beside its own.
- **A customer's own discount** («تخفیف اختصاصی») — a percent off everything a customer buys, set on their page.
  A sale, a customer's own discount and their loyalty tier's are never added together: the largest applies, then a
  discount code on what it left. Top-ups and an agent's traffic are never discounted.
- **Gift codes** («کدهای هدیه») — codes made in batches, each worth Toman for the wallet or a plan's service given
  free, used once, until a day or for ever. A customer types one in the bot («🎁 کد هدیه») or on the website
  (`POST /gift-codes/redeem`).
- **The lucky wheel** («گردونه شانس») — a spin in the bot every so many hours, for buyers alone if the owner wants (a
  purchase with a price — a free trial does not count) and for a price from the wallet if set. Its prizes are Toman, traffic, days, a plan's service or nothing, each with its
  chance.
- **Cashback** («کش‌بک») — a percent of a purchase, a renewal or an extra paid back to the wallet once it is delivered,
  by rules the owner sets for a customer group and a way to pay. Giving the payment back takes the cashback back with
  it.
- **Loyalty tiers** («سطوح وفاداری») — Bronze, Silver, Gold or as the owner names them: a customer reaches a tier by
  what they spent, and each tier gives a discount and extra cashback. The bot's wallet, the customer's page and the
  website (`loyalty` in `GET /me`) say where a customer stands and what is left to the next one.
- **The nightly draw** («قرعه‌کشی شبانه») — at an hour the owner picks, the day's buyers (one entry an order, or one
  a buyer) are drawn and the winners given Toman, traffic, days or a plan's service. The shop's admins never enter,
  and its agents only if the owner wants.
- **Referral upgrades** («زیرمجموعه‌گیری») — a commission on renewals at a rate of its own, a gift in Toman to both the
  referrer and the newcomer (on joining, or on the newcomer's first paid purchase), a plan's service given to a referrer
  for every so many referrals who bought, and an alert when a link brings many newcomers at once or many who never buy.
  Only a purchase with a price counts for these: a free trial unlocks none of them. While an alert stands, the gifts
  are held back, and «رفع هشدار» on the referrals page clears it.
- **A mass gift to one customer group** — «هدیه همگانی» can now go to the services of one customer group.

### Changed

- For contributors: the documentation is one wiki, `docs/` — the developers' handbook became its developer pages
  (Architecture, Conventions and the internals of each area), the changelog
  became this page, and the code says what each class and function does in short comments.

### Fixed

- A plan at no price could not be taken: the wallet refused to charge nothing (the bot said something went wrong, the
  website answered a 500), and a card transfer of nothing made no sense. Its checkout now offers one «🎁 دریافت رایگان»,
  and the service comes at once with nothing taken, whichever way to pay the website sends. Giving one back gives back
  nothing.

### Upgrading

- The database gains columns — a plan's own prices, its first-purchase and agent prices, its checkout note and its
  button in the bot; the days an order brings and the price it was made at; whether a customer group pays agent prices
  — and keeps a plan's and a service's term in hours: the upgrade turns every term's days into hours. It also adds the
  tables of the discount codes, the sales, the loyalty tiers, the cashback, the gift codes, the lucky wheel, the nightly
  draw and the referral gifts and alerts. Renewals keep earning referrers the rate they earn today: the upgrade copies
  each shop's referral rate to its new renewal rate. The panel's update runs this upgrade by itself; a shop updated by
  hand runs it from «تنظیمات پنل › به‌روزرسانی».

## Version 0.1.1

### Fixed

- The panels stayed on their loading screen on LiteSpeed servers (most Iranian shared hosts): their compressed scripts
  and stylesheets were sent as `application/octet-stream`, which a browser refuses to run. `public/assets/.htaccess` now
  names their types; a shop on 0.1.0 can take that file from this release by hand
  ([the panel stays on its loading screen](Installation.md#the-panel-stays-on-its-loading-screen)).

## Version 0.1.0

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
