# Releases

What each release brought, newest first: a change a shop's owner, its customers or a website's developer would notice.
The section of a version is its release notes — the release workflow publishes it on GitHub, and the panel's update
screen shows it to every shop before it updates ([Releasing](Releasing.md#publishing-it)).

While a version is being made its changes collect under **Unreleased**; releasing it renames that heading to
`## Version <version>`. The versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

Nothing yet.

## Version 0.5.0

### Added

- **A SOCKS5 proxy per server** («اتصال از طریق پراکسی SOCKS5» under the server's «تنظیمات پیشرفته») — for a panel the
  shop's host cannot reach directly. Only that panel's requests go through it, and the proxy looks the panel's name up;
  Telegram, mail and everything else go straight as before. Its password is kept encrypted, and a failure at the proxy —
  its name not found, down, refusing the login or unable to reach the panel — is reported as the proxy's, the panel left
  alone for ten minutes. `panel:probe --proxy` tries one from a shell.
- **Moving a server's services** («انتقال سرویس‌ها» on a server's page) — every shop's services on a server go to another
  server in the background: by **migration** while its panel answers (each client read, made on the target with what it
  has left, deleted from the old one), or by **rescue** when it is down (each rebuilt from the shop's last copy, the old
  clients removed by themselves once that panel answers again). A preview says how many services and which shops; a
  filter by plan and the switched-off services are yours to choose; it pauses, resumes and cancels, and a migration
  waiting on a panel that no longer answers goes on as a rescue with «ادامه با حالت نجات». Each customer gets the new
  link in their own shop's bot; the report group hears the start and the end. A service keeps its plan, end, device
  limit, queued renewal and orders; its link changes and its traffic is counted afresh on what was left.
- **Wallet transfers** («انتقال موجودی») — a customer sends money from their wallet to another customer of the shop by
  their numeric Telegram id: «💸 انتقال موجودی» beside «➕ افزایش موجودی», the id, the amount, then a confirmation of
  whom and how much; on the website too. No fee; only the wallet's own money, never an agent's credit; your least, most
  and daily cap. Both are told, each wallet's history names the other, and the report group's «شارژ کیف پول» hears of
  it. Off until switched on in «تنظیمات ربات › انتقال موجودی».
- **Payment methods' bounds and cards** — each way to pay, the wallet too, can have a least and a most amount: a
  checkout offers only those that take its price, and says so when none does. The wallet's row opens a form of its own
  now — its label, its bounds and its switch —, so what customers see it called is yours to change too. A service whose
  renewal price the wallet does not take is not offered «تمدید خودکار»: it is reminded and renewed by hand. The orders
  support makes from a customer's wallet are not held to its bounds — they are for the customers' own checkouts. A
  card-to-card method holds several cards, each with its bank and an optional daily cap: every payment is shown one at
  random among those with room today, and always that one; the receipts, the payments screen and the report group name
  it, and the customer's message shows its bank.
- **Card-to-card payments** («تنظیمات ربات › پرداخت کارت به کارت»):
  - buttons that copy the card number and the amount, in the digits a banking app takes;
  - the card's message protected from forwarding and saving (on by default);
  - one receipt at a time (on by default): while a customer's receipt waits for review, no other receipt or card
    payment is taken from them, in the bot or on the website;
  - «پرداخت‌ها» rejects waiting receipts in bulk — those ticked, or every one that had come when you confirmed —, each
    customer told, the report group's «رسیدها» hearing one line for each hundred;
  - support's private chat instead of a card: the customer is sent to support's chat with the order's number and
    amount, and support approves the payment in the panel; such a payment never expires by itself — not even after the
    option is switched off —, support approves it or cancels it with «لغو پرداخت».
- **Top-up amounts** — beside the buttons you set, buttons of amounts drawn at random within a range; and a few Toman
  added at random to each card-to-card top-up, so every transfer is a different sum and its receipt is found at a glance
  — the whole sum is paid and credited.
- **Orders support makes** («افزودن سفارش» on a customer's page) — support makes a customer a service by hand, on a plan
  or of its own traffic, days and devices, on the server it picks, with an optional name: free, charged to the
  customer's wallet, or paid outside the shop (counted in the revenue). It is delivered at once and the customer told;
  the report group says support made it. An agent makes them in their own shop from their traffic; a website's admins
  with the new «افزودن سفارش برای مشتری» grant.
- **What an agent may sell, and for how long** — on «نمایندگان» you hide servers and plans from an agent's shop (their
  existing services on them keep working), and set an end date and a monthly minimum purchase of traffic (by the
  Persian calendar month) — on «تنظیمات نمایندگی», the ones every agent approved from then on starts with. The agent is
  warned days ahead; then their shop's selling — never their bot — is suspended: new purchases, renewals, extras,
  top-ups and free tests are refused politely, and so are gifts of a service, traffic or days (the wheel, gift codes,
  the draw, referral rewards; Toman still reaches the wallet), a service extended by the agent or their website's
  admins (yours still is), and every order made by hand, yours too; automatic renewals wait until it sells again. Their
  customers' services keep working, and their panel says why. It opens again when you extend it or resume it, or as
  soon as the month's purchases reach the minimum; you can also suspend a shop yourself.

### Changed

- **The report group never shows your panel login**: who of support did something reads «🎧 توسط پشتیبانی», naming an
  admin or an agent and you as plain «پشتیبانی».
- **A grant is ended with «لغو»** («لغوشده»), so «متوقف» always means a pause.
- **A move, a purchase or a free test whose panel stops answering half-way** is asked again before anything is undone or
  made twice: an old client it deleted without saying so leaves the service moved, a client it made without saying so is
  the one delivered — a customer is never left without a service nor given two. A client a move leaves on a panel — the
  old one it could not delete, or one the new panel may have made — is listed on that server's page and removed by
  itself once that panel answers; and when a move cannot be written down after the old client is gone, the new client is
  kept. A grant whose update met an error page from something in front of the panel is counted failed, never given
  twice. A server is not deleted while a move from or to it is under way.
- **Money is written once even when MySQL aborts a write for a deadlock**: wallet transfers, top-ups, checkouts,
  approvals, refunds, orders by support, cashback, referral money, gift codes and the wheel are tried again.
- **You can move one service of an agent's shop onto a server hidden from that shop**; the agent cannot.
- **«انتقال بدون حذف از سرور قبلی»** no longer leaves the old client for you to remove: it is listed on that server's
  page and removed by itself once its panel answers again.

### For website developers

- Wallet transfers: `GET /wallet/transfer?telegram_id=` (who an id is) and `POST /wallet/transfer` (after a recent
  sign-in, with an `Idempotency-Key`); `GET /wallet` gains `transfer` (null while off) and `top_up.random`; the notices
  `wallet_sent` and `wallet_received`. The recipient of a service or a wallet transfer is one `StoreRecipient` shape.
  Looking a recipient up — each `GET` or `POST` of `/wallet/transfer` and of `/subscriptions/{id}/transfer` — draws on
  one budget a customer has, 10 in 10 minutes, wallet and service transfers together. While the customer can send
  nothing — less than the least in their wallet, or the day's cap used up —, `GET /wallet/transfer` is a 422 on `amount`
  before any id is looked up.
- `GET /payment-methods?amount=` lists the ways that take that amount, each with its `min_amount` and `max_amount`; an
  order whose price a method does not take is a 422 on `method_id`, and so is a card payment that finds no card with
  room after all — the order just made is cancelled at once and the `Idempotency-Key` left free to send again.
- A service's `auto_renew.offered` is also false while the wallet does not take its renewal price; a `PATCH` of
  `auto_renew` then is a 422.
- A card payment's `transfer` names the card's `bank`; while the shop sends card payments to support's chat its `card`
  and `holder` are null and `contact` says where to send the receipt. A card top-up's `transfer.amount` may be a few
  Toman more than its order's `amount` (the shop's random sum): show the customer `transfer.amount`. An order paid in
  support's chat never expires; it counts among the customer's unpaid orders until support approves or cancels it.
- While a customer's receipt waits for review — on by default after the update —, another receipt or card payment is a
  422 on `status`.
- An agent's shop whose selling is suspended answers ordering with a 503 (`shop.taking_orders` false), a renewal
  preview, or a discount-code check for a renewal or an extra, with a 422 on `status`, and a discount-code check for a
  purchase with a 422 on `plan_id`; a gift code worth a service is a 422 on `code` in the suspension's words; the
  receipt of an order made before is still taken. The main shop's notices to an agent may now be `agency_expiring`,
  `agency_minimum_short`, `agency_suspended` and `agency_resumed`.
- A card method whose cards are all switched off, or have no room left today, is not listed.
- The missing-`Idempotency-Key` message is worded for any request that needs one.
- For a shop's admins on the website: `POST /admin/payments/dismiss` under the new `payments` grant, and `POST
  /admin/users/{id}/orders` (with an `Idempotency-Key`), an order made for a customer, under the new `manual_orders`
  grant (both after a recent sign-in); in an agent's shop, extending a service while its selling is suspended is a 422
  on `status`, and a plan or a move onto a server hidden from the shop a 422. Rows gain `manual` (orders), `counterpart`
  (wallet lines), `hidden_by_owner` (plans) and the dashboard `agency_standing` (the agent's panel only). A payment's
  `gateway` may be `outside` (money support took outside the shop for an order it made), and its `summary` names the
  card it was shown — or says «کارت در پیوی پشتیبانی داده شد» for one paid in support's chat. A move with
  `leave_previous` (`POST /admin/subscriptions/{id}/move`) has the old client removed by itself once that panel answers.

### Upgrading

- The database gains each server's proxy, the moves of a server's services and the clients they left behind, wallet
  transfers, each payment method's bounds, the card-to-card cards and the card each payment was shown, who made an order
  by hand and their note, the servers and plans hidden from an agent's shop, and each agent's end date, monthly minimum
  and suspension. The panel's update runs this upgrade by itself; a shop updated by hand runs it from «تنظیمات پنل ›
  به‌روزرسانی».
- Each card-to-card method's card moves into its list of cards, and the payments made with it keep it. A method whose
  card number was not a valid card has no card after the upgrade and is offered to nobody until you add one; the
  methods screen says «کارتی ندارد».
- Every shop gets a built-in way to pay «خارج از فروشگاه», used only by the orders support makes — never offered to a
  customer, never listed among the ways to pay.
- Agents start with no end date and no monthly minimum, until you set them.
- Every shop takes one receipt at a time and sends the card's message protected from the update on — both on by
  default; «تنظیمات ربات › پرداخت کارت به کارت» switches them off.
- A payment gateway driver of your own receives the payment in `initiate()`, and its `settle()` may run more than once
  for one payment — the settlement's transaction is tried again when MySQL aborts it for a deadlock —, so it does
  nothing but database work; a panel connector of your own reaches its panel through `PanelHttp` to go through the
  server's proxy ([Drivers](Drivers.md)).
- Tools of your own that write through the panels' API send the new fields: `proxy_enabled` on a server
  (`POST`/`PUT /servers`, `POST /servers/test`), a card method's `cards` list in place of its `card_number` and
  `card_holder`, the wallet settings' `topup_random_count`, `topup_random_min`, `topup_random_max`,
  `topup_random_round` and `topup_unique_max`, and the agency settings' `term_days`, `monthly_minimum` and `warn_days`;
  the «خارج از فروشگاه» method is never listed and cannot be changed.

## Version 0.4.0

### Added

- **Services added by their link** («افزودن سرویس با لینک») — a customer adds a client you made by hand on a panel to
  their account by sending the bot its subscription link or one of its configs (`vless://`, `vmess://`, `trojan://`,
  `ss://`, `hysteria2://`, `tuic://`): anywhere the bot waits for nothing else, or after «➕ افزودن سرویس» under
  «سرویس‌های من». The shop finds which of its panels has the client and adds it as its panel has it — traffic, term, IP
  limit, switched off or not —, and the client takes the customer's Telegram id there. The first customer to send it
  gets it; a client the shop keeps already is never taken from its customer, every claim is reported in the report
  group's «خریدها», and a service's dialog can delete a claimed one from the shop alone, its client left working, so the
  right customer sends the link again. Such a service has no plan until its first renewal, when the customer picks one
  of the plans sold on its server — or builds one, where the shop sells custom services —; from then on it renews on
  that plan, «تمدید خودکار» included (refunded, that first renewal takes the plan back). The panels file them under
  «نوع › افزوده‌شده با لینک». The main shop's alone, off until it is switched on in «تنظیمات ربات › افزودن سرویس با
  لینک».
- **Custom services** («سرویس دلخواه») — a customer builds a service of the traffic and days they choose: «🛠 ساخت سرویس
  دلخواه» under the plans, then the gigabytes and the days — a button, or any amount typed within the bounds —, the
  server when more than one sells it, and the checkout as for a plan, discount codes included. Each amount is priced by
  its tiers: the whole amount at the rate of the tier it falls into (60 GB at the rate from 50 GB on), the traffic's
  price and the days' added. The new «فروشگاه › سرویس دلخواه» page holds it all: the switch, a tier list for the
  gigabytes and one for the days, their bounds and buttons, how many devices a built service takes, the servers it is
  sold on, and a calculator that prices an amount as the customer sees it. A built service has every inbound its server
  sells and no plan: the services call it «سرویس دلخواه», its orders and the reports «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». It
  renews on a plan
  sold on its server, or built again with «🛠 تمدید با سرویس دلخواه» beside the plans; «تمدید خودکار» never renews
  it. The main shop's alone, off until it is switched on.
- **Extra devices** («کاربر اضافه») — a third extra beside «حجم اضافه» and «زمان اضافه»: «👥 کاربر اضافه» raises how
  many devices may use a service at once. A device is priced for 30 days and charged for the days the service has left
  (rounded up to the Toman): its whole term while it waits for its first connection, 30 days when it never ends. It is
  offered for a service with a device limit on a panel that enforces one — 3x-ui; PasarGuard does not —, never for a
  free test or a service whose time or traffic ran out. Its prices sit beside the other extras' on «تنظیمات ربات › خرید اضافه»
  and on a plan's own price list. The devices last until the end of the term they were bought for: a renewal gives
  the plan's limit — from the end of the term in use, when it extends a running service —, and the customer buys them
  again for the new term. A refund takes them back, and in an agent's shop they draw nothing from the agent's traffic.
  The bot's service screen says how many devices a service takes.
- **Service usernames** («نام کاربری سرویس») — how the shop names its clients on the panels is yours to set, on
  «تنظیمات ربات › نام کاربری سرویس»: a pattern of `{username}`, `{seq}` (the customer's own count), `{tg_id}`,
  `{user_id}`, `{n}` (the shop's count), `{random}` and `{date}`, and one for free tests, each shown as an example while
  it is typed. The defaults name clients as before. A pattern makes a different name every time — `{n}`, `{random}`, or
  `{seq}` with something of the customer's —, and a name is always made to fit the panel it goes to. A buyer may choose
  the name at the checkout, in the bot and on the website — «🔢 ترتیبی» (the pattern), «🎲 رندوم», «✏️ دلخواه» typed —
  where the shop offers the last two; a name taken by the time it is delivered falls back to the pattern. A name the
  panel already has — a client made there by hand — gives way to the pattern's next, for purchases and free tests
  alike, where a purchase used to fail.
- **Service transfers** («انتقال سرویس») — a customer gives a service to another customer of the shop by their numeric
  Telegram id: «🔁 انتقال سرویس» on the service's screen, the id, then a confirmation that shows who it goes to. Free.
  Support transfers from a service's dialog («انتقال به مشتری دیگر»), and a website's admins with the «حذف یا انتقال
  سرویس» grant. A free test, a service gone from its panel, and — for a customer — a service with an order of theirs
  still open are refused, as is a recipient unknown to the shop, banned, or the owner. The new owner gets it with
  «تمدید خودکار» off and is told — with how «تغییر لینک» cuts off the devices of the previous owner, who keeps the
  link —, its client on the panel takes their Telegram id, and the report group's «خریدها» says so. A service being
  changed on its panel at that moment is refused for a moment («چند لحظه بعد دوباره امتحان کنید»). Transferred by
  support, the previous owner's unpaid orders of it are cancelled, an order of theirs paid later is never delivered
  onto it, and a refund of what they bought for it is refused until support settles the service by hand. The service
  keeps its username, which may name the previous owner (`{username}` of the pattern). Customers' transfers are off
  until switched on in «تنظیمات ربات › انتقال سرویس»; support's always work.
- **The renewal window** («آستانه مجاز تمدید») — a shop may let customers renew a running service only near its end:
  within so many days of its deadline, or once at most so many percent of its traffic is left, on «تنظیمات ربات ›
  تمدید سرویس». Before then the bot says from when, and the website refuses with the same words; an ended service renews
  any time, and automatic renewal keeps its own days. Off by default.
- **Renewal methods** («روش‌های تمدید») — beside «تمدید پلن فعلی» (the plan's term and traffic added, as before), a shop
  may offer «ریست حجم + افزایش روز» (the traffic starts over at the plan's, the days are added) and «ریست زمان + انتقال
  حجم» (the term starts over now, the traffic left carried into the new one), on «تنظیمات ربات › تمدید سرویس». With
  more than one on, the customer picks at the renewal's checkout, in the bot and on the website; automatic renewal uses
  «روش تمدید خودکار». An order says the method it renewed by, and the report group's «تمدیدها» names a reset. A refund
  of a reset takes back what it added beyond what the service had, and gives back what it replaced beyond what it gave
  (a week in place of 25 days left, 10 GB in place of 40). Automatic renewal by «ریست زمان» waits for the
  last half hour before the service's end, so it throws away no more of the paid term, while a wallet short of the
  price is still told days ahead; whatever the method, a service's end is renewed automatically once.
- **For website developers** — `POST /subscriptions/claim` adds a service by its link, offered while
  `shop.service_claims` of `GET /` is true. A service gains `claimed_at` and `picks_plan`: a service without a plan is
  renewed on what the customer picks — `GET /subscriptions/{id}/renewal-plans`, then `plan_id` on `…/renewal` (and on
  `POST /discount-codes/check` for a renewal), or `gb` and `days` when its `custom_services` is true. `renewable` keeps
  its meaning (renewed on its own plan), so a site that knows nothing of `picks_plan` offers nothing it cannot finish;
  for a service with its own plan a `plan_id` sent must be that plan. Also new:
  - custom services: `GET /custom-services` (its tiers and buttons empty while it is not offered),
    `POST /custom-services/quote` and `POST /custom-services/orders`, offered while `shop.custom_services` is true;
  - extra devices: `POST /subscriptions/{id}/extra-devices`, a `devices` offer in `GET …/extras` (each offer with its
    `days`), `devices` on a service (its device limit) and on an order, and `extra_devices` among the order kinds of
    the payment methods and the discount check;
  - client names: `name_mode` and `client_name` on `POST /orders` and `POST /custom-services/orders`, the modes
    offered in `shop.name_modes`;
  - transfers: `GET` (who an id is) and `POST /subscriptions/{id}/transfer` (after a recent sign-in, as a change of
    credentials), offered while `shop.service_transfers` is true, and the notices `service_transferred` and
    `service_received`;
  - renewals: `method` on `…/renewal` and the discount check, the preview's `method` and `methods`, a service's
    `renewal_window` (`open`, `opens_at`, …) — before it opens a renewal is a 422 on `status` —, and its
    `next_devices` (from when the device limit a renewal queued holds); `GET …/renewal-plans` lists nothing while the
    service cannot be renewed;
  - an order's `package_name`: what it buys in words, a plan's name or a built package;
  - the API description's `DiscountKind` and `CashbackOrderType` are one schema, `OwnOrderType`: the JSON is the same,
    but types generated from the description change their name;
  - for the shop's admins on the website: `GET` and `POST /admin/subscriptions/{id}/transfer` under the «حذف یا انتقال
    سرویس» grant (`delete`), services with `claimed_at`, the kind `claimed` and the action `transfer`, and orders with
    `package_name`, `devices`, `renewal_method` and the kind `extra_devices`. A plan's own extra prices sent without the
    devices' fields save as before and sell no devices.

### Changed

- **One word for a service's device limit**: plans, services, the extras and custom services say «کاربر» («۲ کاربر»)
  where some said «دستگاه», and the extras are «حجم، زمان و کاربر اضافه» («خرید اضافه» in the settings menu). The
  bot's default texts say it too; a text you changed stays as you wrote it.
- **A renewal's device limit waits for the period in use**: a running service renewed by «تمدید پلن فعلی» or «ریست حجم
  + افزایش روز» keeps its device limit until the end of its current period, then takes its plan's — before, a renewal
  set it at once. Any other renewal sets it at once, as before.
- **A device limit set on the panel is the shop's**: syncing reads it back from 3x-ui as it does the traffic and term,
  so the next extra builds on it instead of overwriting it (a renewal still brings its plan's).
- **An update of a client on its panel never switches it on by itself**: a renewal and an extra switch a service on, as
  before, but support's extension of a service support switched off, a refund, and a service changing owner leave it as
  it was. A refund of a renewal or an extra of a switched-off service is no longer refused: it takes back what it gave,
  and the service stays off.
- **A service's client follows its owner's Telegram account**: when two accounts are merged, or a Telegram account is
  linked to a website account or taken off it, the services' clients on their panels take the owner's Telegram id and
  comment.
- **A refund takes back what its order gave, as far as the service still has it**: what a later order started afresh
  — a reset of the time or the traffic, a renewal after the service ran out, its plan's device limit, days bought after
  the service's time ran out — is taken back by that order's own refund, and a reset that replaced more than it gave
  gives the rest back, so a service's renewals and extras, refunded in any order, leave it exactly where it was. A
  renewal's refund puts back the device limit it replaced, the term or quota it replaced where it gave the first or an
  endless one, and the end and traffic left it replaced where it put the service on a plan that never ends or has no
  quota; a refund of devices already gone with their period no longer says they were taken back.

### Fixed

- **Moving a service to a server whose panel has a client by its name** — one made there by hand — failed with the
  panel's refusal; the moved service now takes the customer's next name, as a purchase does.

### Upgrading

- The database gains when a service was added by its link, the device limit a renewal queued behind the period in use
  (and when a service's limit last went back to its plan's), what an order brings in devices, the client name a buyer
  asked for, its renewal method, and what a renewal or an extra started afresh or replaced — term, traffic, device
  limit — for its refund to put back; the renewals so far are recorded as
  «تمدید پلن فعلی», and what a renewal gave may now be less than nothing (on MySQL and MariaDB two columns of `orders`
  become signed). The panel's update runs this upgrade by itself; a shop updated by hand runs it from «تنظیمات پنل ›
  به‌روزرسانی».
- A plan with its own extra prices sells no extra devices until they are priced on it, and a discount code or a sale
  that lists its order kinds leaves out «کاربر اضافه» until it is added; a cashback rule that covered every kind of
  order covers it after the upgrade.
- A panel connector of your own implements one more method, `findClientByLink()`, says in its capabilities whether its
  panel enforces a device limit and what client names it takes, and keeps a client switched on or off as the update
  asks ([Drivers](Drivers.md#a-vpn-panel-connector)).

## Version 0.3.0

### Added

- **Free test accounts** («اکانت تست») — a customer takes a free test in the bot to try the shop before buying: from
  the menu's «🧪 اکانت تست», with `/free`, or from the link `https://t.me/<bot>?start=usertest`, which opens the test
  screen even for a newcomer the phone or the required channels held back first. The screen lists the servers that give
  a test now; a tap makes one at once and sends it like a bought service. Each server has its own terms, which the owner
  sets on its page: whether it gives tests, their traffic and hours, and whether the hours count from the first
  connection or from the moment the test is made. Each shop has its own rules on the new «فروشگاه › اکانت تست» page:
  the switch, how many tests a customer gets — for good or within so many days —, a cap a day, and newcomers only. A
  test is no purchase: it is never renewed and takes no extras, reminders or gifts. The tests that ended, or were never
  connected, are removed from their panels by themselves after a while — each read from its panel again first —,
  quietly, and still count in the quota. In an agent's shop each test's traffic comes out of the agent's pool. The page
  lists every test given, with whether the customer bought after it, and the services list marks the tests and filters
  by them; «رو به انقضا» and the dashboard's counts leave them out. The website's services say `is_test`. Off until a
  shop switches it on and the owner switches a server on for tests.
- **Automatic removal of ended services** («حذف خودکار سرویس‌های منقضی‌شده») — a shop may have its ended services —
  their time or traffic ran out, or their panel no longer has them — removed from their panels and the shop by
  themselves, quietly, so many hours after they ended: «فاصله حذف (ساعت)», 0 to 8760, 24 by default, `0` at once, on the
  new «تنظیمات ربات › سرویس‌های منقضی‌شده». The orders that sold one keep their record. Never removed: a service
  switched off by support, one with an order still open (a renewal waiting for its receipt, a delivery waiting for
  support's retry), and one renewed or extended in time — each is read from its panel again just before. The bot's
  service screen tells the customer when it goes unless renewed («🗑️ اگر این سرویس تا … تمدید نشود، حذف می‌شود.»), the
  panels' service dialog shows it, and the website's services say `ended_at` and `removes_at`. Off until a shop switches
  it on. The bot says it on a renewal's checkout too, where renewing keeps the service.

### Changed

- **The panels' top bar is on every screen**, desktop too, and stays at the top while the page scrolls: the page's
  name and the dark/light switch. The sign-in screens and the installer have the switch as well.
- **A refund takes back what its order gave**: «بازپرداخت» of a purchase removes the service it made — its client off
  its panel, the service off the shop —, and of a renewal or extra traffic or time takes its days and traffic back off
  the service (a renewal's as it gave them), before the money goes back; the customer's message says so, and in an
  agent's shop the traffic it drew goes back to the agent. A refund is refused, with nothing refunded, while the panel
  does not answer, another paid or open order holds a purchase's service, or a renewal's or an extra's service is
  switched off.

### Upgrading

- The database gains each server's test terms, the mark on a test's service, and the table of the tests given. The
  panel's update runs this upgrade by itself; a shop updated by hand runs it from «تنظیمات پنل › به‌روزرسانی». A menu
  saved before this release needs the «اکانت تست» button added on «کیبوردها»: the tests page says so while the switch
  is on.
- The database keeps when each service ended. The services already ended are given theirs as it upgrades — the deadline
  they passed, else when the shop last read them from their panel —, so a shop that switches the removal on counts its
  hours from then.
- A renewal delivered from this release on keeps what it gave its service, which its refund takes back. A renewal
  delivered before has nothing kept: its refund takes back its plan's term and traffic as the plan is then.

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
