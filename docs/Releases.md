[فارسی](fa/Releases.md)

# Releases

What each release brought, newest first: a change a shop's owner, its customers or a website's developer would notice.
The section of a version is its release notes — the release workflow publishes it on GitHub, and the panel's update
screen shows it to every shop before it updates ([Releasing](Releasing.md#publishing-it)).

While a version is being made its changes collect under **Unreleased**; releasing it renames that heading to
`## Version <version>`. The versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

Nothing yet.

## Version 0.7.1

### Security

- **PHP with known security holes is refused.** The shop needs PHP 8.3.36, 8.4.27 or 8.5.12, or a later release of
  the same branch: the releases that closed two critical holes through which anyone who can reach a site could run
  their own code there (CVE-2026-109618 and CVE-2026-109619, 21 October 2026). On an older release
  ([what you need](Installation.md#what-you-need)):
  - the installer stops and names the release to move to;
  - the panel's update installs no release;
  - a strip over every page of the owner's panel says so while the shop runs on it («PHP این هاست … حفره امنیتی جدی
    دارد…»);
  - below 8.3.36, the shop does not start at all.
- **PHP 8.2 is no longer supported**, though PHP still patches it until the end of 2026: a shop on it moves to PHP
  8.3.36 or later before this release. CI runs the suite on PHP 8.3, 8.4 and 8.5.
- **Every dependency on its latest patch.** The PHP packages — Illuminate 12.70, Guzzle 8.3, Symfony 7.4.21, Carbon
  3.15, Monolog 3.12.1 and the rest — have no known advisory against them. The tools that build the panels and run
  their tests never reach a shop: Vite 8.3.5 (GHSA-rq7h-c2jc-7f22, GHSA-vfpm-58rq-9qcg, GHSA-9jrq-w75r-8gcw, and
  launch-editor's GHSA-587h-7wmj-h5pm, GHSA-r825-w8mm-5pxw and GHSA-698v-4r3x-3vcv), happy-dom 21.0.4
  (GHSA-834v-8h66-3h79), source-map-js (GHSA-68fv-2mgg-jv7q) and zod (GHSA-mrhx-c7xc-m3w5).

### Changed

- **The amount to transfer to a card is in Rial**, ten to the Toman, as Iran's banks count. The card's message still
  reads the price in Toman, and gives the amount to transfer in Rial — «💰 مبلغ: 1200000 ریال» for a price of 120,000
  Toman —; «📋 کپی مبلغ (ریال)» and a tap on it copy `1200000`. The same goes for an online payment's card. In
  «متن‌های ربات», `%amount_rial%` is that amount; `%amount_digits%` is still the Toman.

### Fixed

- **Rows written outside AmoBot without a time** — imported, or added by hand in phpMyAdmin, with no `created_at` or
  `updated_at` — no longer stop a page of the panels with a server error. The customers, orders, payments, services,
  tickets, plans, the dashboard and the rest list them, the missing time as «—»; so do the bot's wallet history and
  ticket messages. The shop's website gets such a time as null.
- **A required channel with no way in** — its handle and invite link taken out by hand — no longer stops the channels
  list. The list says it has no link to join by, and the bot asks no one to join it. «بررسی دوباره» gets a public
  channel's handle back, or a private one's invite link once the bot is an admin there.
- **A broadcast run edited by hand** to name no message or no audience is cancelled at its next batch, as one whose
  admin is gone already was, instead of failing every minute; its progress message reads «—» for the audience. Such a
  run — or an unpin run left without its source — no longer stops «پیام همگانی»'s list, which shows what is unknown as
  «—».
- **A database the shop cannot reach** — stopped, out of reach, refusing `config.php`'s credentials, or out of
  connections — no longer gives a bare server error: every page of the panels says «دیتابیس فروشگاه در دسترس نیست…»
  (a `503`). Its log line holds the database's own words, without a trace.
- **On PHP 8.5**, drawing a service's QR card or shrinking an uploaded picture no longer writes a deprecation notice to
  the log.
- **Renewing a service without a plan** — added by its link, or built — offers its plans by category, as «خرید اشتراک»
  does, instead of every plan on its server in one list. When they are in more than one category, a button per category
  comes first — only those with such a plan, «سایر پلن‌ها» last —, then that category's plans; «بازگشت» from a plan's
  checkout leads back to them. A new text in «متن‌های ربات», «انتخاب پلن تمدید در دسته», heads a category's plans.
- **A plan's dialog** scrolls only as far as the tab shown: every tab was as long as the longest, so a short one ended
  in blank space. The dialog keeps its height as the tabs change, its buttons at its foot.

### Upgrading

- **Check the host's PHP first**, in cPanel › *MultiPHP Manager* or *Select PHP Version*: at least 8.3.36, 8.4.27 or
  8.5.12 on its branch. From 0.7.0, the panel's update refuses a PHP older than 8.3.36 — PHP 8.2 among them —, and a
  release put in place by hand does not start on one. On an older 8.4 or 8.5 release, 0.7.0's update still installs
  0.7.1; its strip then names the release to move to, and no later release installs until you do.
- **An owner's own card texts** — «راهنمای کارت به کارت» or «کارت پرداخت آنلاین» reworded in «متن‌های ربات» — that show
  the amount as the default did, `<code>%amount_digits%</code> تومان`, are changed to `<code>%amount_rial%</code> ریال`
  by the upgrade, in every shop; the rest of their wording stays. Any other `%amount_digits%` there is left as written:
  it is still the Toman, which a banking app does not take.

### For website developers

- **`amount_rial`** beside `amount` in a checkout's `transfer` and `link`: the same sum in Rial, ten to the Toman — the
  digits a banking app takes. Copy it for a transfer; show `amount` as the price.
- **`created_at` and `updated_at` may be null** wherever the Store API sends them, for a row written outside AmoBot
  without them: show such a time as unknown.
- **A broadcast's `mode` and `audience` may be null** in the admin API's `GET /admin/broadcasts`, for a run edited by
  hand to name no audience, or an unpin run left without its source.
- **A `503` with «دیتابیس فروشگاه در دسترس نیست؛ …»** answers any request while the shop's database cannot be reached.
- **`category_id`** on each plan of `GET …/renewal-plans`: the category it is listed under, as `GET /plans` groups the
  catalogue (null for «سایر پلن‌ها»), and the list now comes in that order. Show a service's plans by category, as the
  bot does.

## Version 0.7.0

### Added

- **Plisio, crypto payments** («افزودن روش پرداخت» › «Plisio», in both panels) — the customer pays a coin of their
  choice on Plisio's page (Tether, TRON, Bitcoin, TON… as you switch them on at Plisio), and the order is delivered
  once Plisio confirms it on its chain ([Payment methods](Payment-Methods.md#plisio)). Its settings are your secret key
  (kept encrypted), how many Toman a US dollar is — each order is asked of Plisio in dollars at your rate, which you keep
  up with the market — and, if you wish, the coins offered. A coin seen on the chain and awaiting its confirmations is
  waited for; a payment the customer switched to another coin is found all the same; an invoice that expired with money
  on it is held for you. Plisio calls a shop on https at once and sends its customers back to it.
- **AqayePardakht** («افزودن روش پرداخت» › «آقای پرداخت») — online payment with any Iranian bank card through Shaparak.
  Its one setting is the gateway's pin (kept encrypted; `sandbox` is its test service, where no money moves but a
  payment still delivers its order) ([Payment methods](Payment-Methods.md#aqayepardakht)). «💳 پرداخت» opens a short
  page on the shop's own address with «ورود به درگاه پرداخت», since Shaparak opens the bank's page only for a visitor
  from the domain registered for the gateway (the shop's); the customer comes back to a page of what came of it, and
  the order is delivered and they are told once.
- **Variza** («افزودن روش پرداخت» › «واریزا») — card-to-card that Variza confirms by itself from your bank's text
  message ([Payment methods](Payment-Methods.md#variza)). Its settings are your API key and webhook secret (both kept
  encrypted) and the destination card, typed as «تصادفی» (one of your cards), a card's last four digits, or «واریزا»
  (Variza's own cards, paid out to you in Tether) — or as Variza names them, `random` and `variza`. Variza answers no
  question about a payment: the shop believes only the webhook it signs with your secret, at `/payments/webhooks/variza` (the method's form shows the address), and a webhook naming
  another sum is held for you.
- **Dates in the bot's texts** — «متن‌های ربات» takes Telegram's `<tg-time unix="…" format="…">…</tg-time>`: a moment
  the customer's own app writes in their language and time zone, relative to now or as a weekday, a date and a time,
  as `format` says; its words show where a date cannot. The editor and its preview read it as Telegram does
  ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md#what-telegram-refuses)).
- **«نگه داشته شده» on the payments screen** — a tab of the online payments held for support and still waiting, after
  the receipts to review ([Payment methods](Payment-Methods.md#a-payment-held-for-you)).

### Changed

- **«حذف سرویس» waits for the service's open order**: while an order of the service is unpaid, being delivered, or
  failed and waiting for a retry, the panels do not offer it and the shop refuses it, naming the order («سفارش #… این
  سرویس هنوز تمام نشده است …»). Deliver, cancel or refund that order first.
- **A service with no end date can be renewed at any time**: of «آستانه مجاز تمدید», only «درصد حجم مانده» holds it
  back, when it is set and the service has a traffic limit. Before, a threshold of days alone kept it from renewing.
- **The panels while the browser is offline**: every change fails at once, and the strip at the top says «اتصال اینترنت
  قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها ذخیره نمی‌شوند.». Before, some changes waited and were
  sent minutes later, when the connection came back.
- **What you type in a settings card or a server's «تنظیمات اتصال» stays** when the same card or server is saved
  somewhere else (another tab, another browser): the card says so, saving puts your version in place of the new one,
  and «دیدن نسخه تازه» drops yours for it. Before, the new copy replaced what you had typed.
- **The cron line for cPanel, ready to copy**: «تنظیمات پنل › پیشرفته» shows it beside the cron address («کپی خط
  Cron», `curl -fsS <the cron address> > /dev/null`), for cPanel's Cron Jobs form at once per minute; the installer's
  last step points to it.
- **Agents read «پشتیبانی فروشگاه اصلی»** where their panel said «مدیر فروشگاه اصلی», and a plan kept from their shop
  reads «پنهان‌شده توسط پشتیبانی».
- The panels' hints say a customer is told «در تلگرام یا با ایمیل» where they said in the bot: a customer of the website
  alone gets an email.
- The default text «منتظر رسید» tells the customer to send /menu for anything else («لطفا عکس رسید پرداخت را بفرستید.
  برای کار دیگری /menu را بفرستید.»), since no menu is shown under it. A text you changed stays as you wrote it.
- Removing the bots' webhook («تنظیمات پنل › ربات تلگرام») now says to press «ثبت Webhook» to receive messages again,
  not to run a command on the server.
- An agent's end date typed in a form that had none ends at 23:00 of that day, as a discount code's or a sale's end
  does (it was the day's first hour).
- On a phone, a dialog no longer opens the keyboard by itself unless typing is what it is for, and its ✕ is larger.
- Ratings are drawn as five stars everywhere in the panels, the reviews and the tickets alike.
- The installer's requirements list gd as optional: without it a service's link goes as text, with no QR card.
- The logs no longer show a key an address carries in its query (`…?api_key=…`).
- **The panels on a phone**: a list keeps its title, its amount and its controls, and what else a row needs (its status
  first) goes under its title; fields are 16px, so the phone does not zoom in as you type; buttons, menu items, tabs,
  filter pills, switches, «تنظیمات پیشرفته», the dashboard's «نیازمند توجه» rows, fields and selects are a finger's
  44px, and the ▲▼ arrows 32px and 12px apart; on a sheet, only the title and ✕ stay at the top — the description
  scrolls with the rest —, and the save, like a receipt review's decisions, stays in sight at its very foot, nothing
  scrolling by beneath it; the dashboard's figures go two to a row; and the screens fit a phone 320px wide.
- **The panels on a tablet**: the long lists — customers, payments, orders, services, tickets, plans — keep to the
  columns that matter and fit without scrolling sideways; their other columns come back on a computer's wider screen.
- **The dark theme**: form fields' borders and the filters are easier to see, and so is a refused field's red border.
- **The dashboard's words**: «وضعیت سیستم» names the scheduler «کارهای زمان‌بندی‌شده» (it said «Scheduler») and a bot
  that polls «پولینگ» (it said «bot:poll»); a bot without a token reads «وصل نشده» and one that gets no messages «مشکل
  دارد», as the agents list says of an agent's bot (they read «راه‌اندازی نشده» and «خاموش»; «دریافت پیام‌ها» in
  «تنظیمات پنل» says «مشکل دارد» too); a figure with nothing to compare against says «دوره قبل رقمی برای مقایسه نداشت».
- **Who decided** a payment, a review or an agency request, wrote a wallet's line or an agent's traffic line, answered a
  ticket, made an order by hand, or started a broadcast, a grant of days or traffic or a move of a server's services
  reads as the admin's name — their first and last name, as their account has them —, in your panel and in an agent's;
  for an admin whose account shows no name, their `@handle`, else «کاربر #12», «نماینده #3» or «شناسه تلگرام …» instead
  of `user#12` or `tg:…`. A Telegram first name of nothing but invisible characters counts as no name.
- **A customer without a name** is shown by their `@handle`, with their Telegram id under it, where the panels said «بدون
  نام» (it stays for a customer with no handle either).
- **Digits in the panels' hints**: a bound or a value as you would type it is in Latin digits («حداکثر 100 درصد», «0»,
  «3.1»), a quantity in Persian ones («قیمت هر کاربر برای ۳۰ روز»).
- The search boxes say what they find in fewer words — the users list's «نام، نام کاربری، ایمیل، موبایل یا شناسه», the
  payments' «شماره پرداخت، سفارش یا مشتری», the referred customers' «زیرمجموعه یا معرف: نام یا شناسه» —, with no stray
  comma («کد یا یادداشت آن») and no browser spelling marks under what you type; a search box is right to left while
  empty; a website's stored secret shows as «••••••••».
- **A wallet top-up's refund takes back the referral commission it earned**: out of the referrer's wallet, with a line
  of its own in their history («برگشت پورسانت زیرمجموعه (پرداخت #…)») and a word to them; while the referrer's wallet
  no longer holds it, the refund is refused («پورسانتی که این شارژ برای معرف مشتری آورد خرج شده است: …»). A purchase's,
  a renewal's or an extra's refund leaves the commission: that money stays in the shop, as the customer's wallet credit
  ([Referrals](Referrals.md)).
- **A refunded order gives its discount code's use back.** A code used only in refunded orders still cannot be deleted
  — switch it off instead. «حذف» on a code an order keeps, used or refunded, now says at once that it is kept and why
  («این کد ۳ بار در سفارش‌ها استفاده شده و برای سابقه می‌ماند؛ …», «این کد فقط در سفارش‌هایی آمده که بازپرداخت
  شده‌اند؛ …»), with «بستن» alone, where it asked first and was then refused.
- **A refunded purchase no longer counts as bought**: its customer pays the first-purchase price again and may use a
  first-purchase-only code, and it counts for no referral gift or plan reward, nor for the fake-referral alert or the
  lucky wheel's rule of buyers only. Free test accounts still count it: a customer who once had a paid service is no
  newcomer there.
- **A discount code whose use the customer's own unpaid order holds** is refused saying so — «این کد تخفیف روی سفارش
  پرداخت‌نشده دیگری از شما ثبت است (سفارش #…)؛ آن سفارش را پرداخت کنید، یا صبر کنید تا مهلت پرداختش تمام شود و کد آزاد
  شود.» — where it said the code's uses were spent.
- **An online payment its gateway closes after saying money had come in on it is held for support at once**; the shop
  went on asking about it for 48 hours first ([Payment methods](Payment-Methods.md#a-payment-held-for-you)).
- **The dashboard's «نیازمند توجه»** counts only what waits on you — receipts, held payments, failed deliveries, tickets
  and reviews waiting, failing servers, an agent's selling or traffic notice —: «… مورد منتظر اقدام شماست», and with
  nothing waiting «همه‌چیز مرتب است» alone. What waits on customers — services ending soon, unpaid orders — is listed
  under «در انتظار مشتری», uncounted. A server you switched off is no longer among «سرورهای دارای خطا».
- **Lifting an agent's passed end date** no longer reopens a shop that missed last month's minimum and is short of it
  this month too: it stays suspended, now for the minimum, and the agent and the report group are told so
  ([Agency](Agency.md)).
- **The shop's admins on the website need «پلن‌ها و دسته‌بندی‌ها»** (the `catalog` grant) to add, change, reorder or
  delete a customer group, and to add a customer to a «همکار» group, whose members pay the agents' prices; the plain
  groups ask none ([Website](Website.md#the-shops-admins)).
- **A server's stored panel secret is tied to its proxy and its TLS check** as to its address: changing the proxy's host
  or port, or «بررسی گواهی TLS», asks for the password, token or TOTP key again («آدرس پنل، پراکسی یا بررسی گواهی TLS
  عوض شده است؛ … را دوباره وارد کنید.»); switching the proxy off keeps it, and a proxy host typed again in other letter
  case asks nothing. Likewise the main bot's token is asked again when «آدرس API تلگرام» moves to another address: a
  kept token never goes to an address it was not saved for.
- **A stored secret's hint** shows its last four characters only for a secret of 12 characters or more; a shorter one
  shows «••••••••».
- **The wallet's buttons**: «➕ افزایش موجودی» comes first, on the right, and «💸 انتقال موجودی» beside it.
- **The test account screen's servers** take their colour and row width, as a purchase's locations do.
- **A server's last error** reads as what to fix, with the technical detail — the panel's own words, or cURL's — on a
  line of its own under it, left to right: beside «خطا در اتصال» (ⓘ) on the servers list and on the server's «وضعیت».
  On one line, each scrambled the other.
- The lucky wheel's, the extras' and custom services' settings hold their fields while switched off, as the other
  programs' do.
- The light theme's red border is darker: a refused field and a danger button stand out on every surface.

### Fixed

- **In the bot**
  - **A checkout takes only the price it showed**: the wallet and «🎁 دریافت رایگان» pay the price on screen; a price
    that changed since (a sale ended, a first purchase paid elsewhere) draws the checkout again, at the new price, first.
    So does a tap on an older checkout's message while another checkout is on screen.
  - **Amounts typed with words**: «۵۰ هزار» is 50,000 Toman (it read 50), «۲ میلیون» 2,000,000, and «۲ هزار گیگ» 2,000
    GB. Each step takes its own unit after the number — «تومان», «گیگ», «روز» or «کاربر», and forms like «۲۰ گیگی» or
    «۲ نفره» —; any other word («۵۰۰ ریال», «۲ ساعت», «۲۰ روز» where GB are asked) is refused instead of being read in
    the wrong unit. «میلیون» is for money alone, and days and devices take no «هزار».
  - **A service that is no longer the customer's**: «تغییر لینک», «تمدید خودکار», a renewal or an extra pressed on a
    service just handed to another account is refused, and nothing of the new owner's service changes — «تغییر لینک»,
    a renewal or an extra says «این سرویس همین حالا به حساب دیگری منتقل شد.» where the bot said something went wrong.
    A service its panel lost shows only «⬅️ بازگشت به لیست سرویس‌ها».
  - **Rows of buttons read from the right**, the first on the right: the top-up amounts, the extras' and the custom
    builder's amounts, an agent's traffic amounts, and the ways to name the service and the renewal methods at checkout.
  - **The QR card**: a small background is drawn larger so the code stays readable; a link too long for a code, or a
    card Telegram will not take, sends the service as text. The customer always gets their link.
  - A receipt sent as several pictures at once (an album) is its first picture, the others passing without a reply; a
    ticket message sent as an album with words is one message; a ticket whose last message is long now opens.
  - A menu whose buttons all belong to programs switched off shows the built-in menu instead of none.
  - A required channel is no longer marked broken because Telegram could not answer for one customer: that customer is
    let through.
  - A client name the customer types with Persian digits is accepted.
  - **A number, a command or a handle in a Persian line reads the right way round** in Telegram: an order's, a
    ticket's or a broadcast's number after Persian words («سفارش #12», «تیکت #37»…) in the default texts, the report
    group, the broadcasts' progress and the wallet history; /menu, /newbot, `@BotFather` and `@userinfobot` in the default
    texts and the bot's own messages about a token or /broadcast; and a handle after Persian words — the other customer
    of a transfer, an agent's bot, support's contact, and a customer or an admin in the report group. A text you changed
    keeps your wording, and a handle or a number the bot fills into it (`%name%`, `%bot%`, `%contact%`) reads right there
    too ([numbers, commands and handles](Bot-Texts-And-Keyboards.md#numbers-commands-and-handles)).
  - **A list too long for one keyboard is paged** with «بعدی» and «قبلی» — more than 90 categories, plans, a plan's
    locations or plans to renew on, more than 20 test servers: Telegram takes no keyboard of more than 100 buttons.
  - **An order that is no longer the customer's first purchase** — made at a first-purchase price or with a
    first-purchase-only code, another purchase of theirs paid before it — fails as it is delivered (the code is checked
    again then too), and its customer is told why: «⚠️ پرداخت سفارش #… ثبت شد، ولی این سفارش با قیمت یا کد تخفیف خرید
    اول ثبت شده بود و خرید دیگری از شما زودتر پرداخت شد؛ پس دیگر خرید اول شما نبود و انجام نشد.» — where they were told
    support would finish it.
  - **A renewal or an extra paid for a service support handed to another customer meanwhile** is never delivered, and
    its customer is now told so, and that support settles the payment with them: «⚠️ پرداخت سفارش #… ثبت شد، ولی
    سرویس … پیش از انجام آن به حساب دیگری منتقل شد و دیگر سرویس شما نیست؛ پس چیزی به آن اضافه نشد.» — in the bot and
    among their website notices, by email when Telegram cannot bring it —, where they were told support would finish it.
  - The extras' price per unit for an amount typed, and the amounts a refusal lists, are the customer's own after their
    automatic discount, as the buttons are.
- **The report group and broadcasts**
  - **An admin's press or reply in a busy group is no longer ignored**: the bot's flood guard (more than 20 updates in 10
    seconds) counts only what is meant for it there — a press, a command, a reply to it —, not the members' talk.
  - An agency request whose approval is refused (its end past the last day the shop keeps) keeps its buttons, the
    reason in a popup.
  - A receipt from a customer who blocked the bot no longer stops the report group's queue: it arrives as text, with
    «🖼 تصویر کپی نشد؛ در پنل مدیریت دیده می‌شود.».
  - A broadcast whose admin's account is gone, or has no Telegram any more, ends «لغوشده» instead of staying «در حال
    ارسال».
- **Payments and orders**
  - **A receipt rejected at the moment it is approved stays rejected**: the approval — from a panel, the report group or
    the review window — is refused with «این پرداخت همین حالا به شکل دیگری تعیین تکلیف شد.».
  - **A slow delivery is never delivered twice**: a delivery still at work on a slow panel was taken for a dead one
    after 10 minutes and started again. A delivery now keeps its order fresh as it works; one whose process died is
    resumed after 18 minutes of silence, and a paid order nobody started delivering after 10.
  - **A prize's service whose delivery broke counts as given** (the lucky wheel, the nightly draw, a gift code, a
    referral plan reward): it waits for support's retry in «نیازمند رسیدگی» and is never given a second time.
  - An automatic renewal never charges a customer for a service they handed to someone else a moment before.
  - A refund refused because another order holds the service says what to do with that order: cancel it, wait for its
    delivery, or refund it first.
  - The payments screen names a renewal's or an extra's server (its service's).
  - A card payment first sent to support's private chat and later shown a card gets a new random sum when its own has
    become another payment's, so their receipts are told apart.
  - The page a gateway sends the customer back to, and the shop's page that leads on to the bank's, read the order's
    number the right way round («سفارش #12»), and so does the order's description on the gateway's own page.
- **Discounts and rewards**
  - **The nightly draw** enters the purchases since the previous draw (at most 48 hours; the first draw, the last 24),
    so moving its hour never enters a purchase twice nor leaves one out; its settings say so.
  - **The plan reward** («جایزه دعوت») counts each newcomer's purchase at its place: two at the same moment no longer
    share or skip a milestone, and a milestone whose service could not be given comes with the next purchase.
- **Services and VPN panels**
  - **No service ends after 17 January 2038 («۲۸ دی ۱۴۱۶»)**, the last day the shop's dates hold: a purchase, a service
    built, a renewal, days given by support or an agent's term that would end later is refused before anything is
    charged («تاریخ پایان سرویس نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد؛ مدت کوتاه‌تری انتخاب کنید.»), where it failed with a
    server error. Extra time offers no more days than that; a mass gift skips a service it would carry past it; a prize
    of such a plan, or a test on such a server, is not given; automatic renewal is not offered for such a service,
    whose customer is reminded of its end instead; and an online payment page a gateway dates past it no longer fails.
  - **A change the panel made but whose answer was lost** — it timed out, or reading it back failed — is found on the
    panel and counted once: a purchase, a renewal, an extra, support's extension, a grant or a refund is no longer
    counted failed, nor given twice on a retry.
  - A switched-off service moved to another server stays switched off: its new client was made switched on.
  - The periodic sync goes on past a client its panel answers badly for; before, that client stopped its server's sync.
  - 3X-UI: a change after the panel's session expired signs in again once instead of failing; a subscription path of
    `/` gives working links; a panel address that is redirected (http for an https panel) is said so, with where it
    leads, instead of as an answer the shop cannot read.
  - PasarGuard: a deadline the panel writes as a number is read, `expire: 0` is no deadline, and users are read past
    the first page when the panel gives no total.
  - An inbound with a very long name or tag is kept, cut short; a proxy's address and a panel's address and port typed
    in Persian digits are read; a plan's inbound that is no id is refused («شناسه اینباند انتخاب‌شده درست نیست»).
- **The panels**
  - **A credit, an adjustment or an answer sent again is made once**: pressing «افزایش موجودی»/«کاهش موجودی», «افزایش
    حجم»/«کاهش حجم» or «ارسال پاسخ» again after the panel got no answer no longer credits, adjusts or answers twice —
    each change goes with a key the shop remembers.
  - **A confirmation pressed twice sends once** — a delete, an agent suspended or revoked, receipts rejected in bulk, an
    update, the webhook: it is held until its change is done.
  - **A server's page never takes on another server's form**: going from one server's page to another's kept the first
    one's unsaved connection settings, which saving then wrote onto the second.
  - «انتقال به مشتری دیگر» refuses a handle typed by mistake («`@sara1990`») instead of reading the digits in it as the
    id 1990.
  - Ticked payments that leave the list (another filter, decided elsewhere) are unticked, so a bulk action acts only on
    what is shown; «ساخت کپی» pressed twice makes one copy, shown right after its plan.
  - The bot texts editor reads a wording as Telegram does: an attribute name in capitals, or an entity Telegram does not
    read (`&nbsp;`), is refused with why, since Telegram would show it as typed; the preview draws the parts filled
    with other texts as the bot sends them. A wording that would show nothing — only spaces or invisible characters,
    such as a no-break space — is refused as empty («متن نمی‌تواند خالی باشد.»), and its counter says 0.
  - A bot-settings field you changed while its program is switched off no longer locks under your typing — even typed
    back to what it was — until you switch the program on, save or revert.
  - A row's number («#12»), a slash command, a handle or another Latin word inside a Persian line keeps its place — in
    a wallet's or an agent's traffic history, the shop's refusals and the shop picker's «نماینده: #3» too —, and a
    typed price's preview («۵۰ هزار») reads it as the shop will. The browser tab's title reads them the right way round
    too, with no stray box or gap. A search with a number pasted from a bot message — which carries an invisible mark
    before its «#» — finds its row, and the bot texts' search finds «سفارش #» or «/menu» in the default texts.
  - «ادامه با حالت نجات» is offered only while a move waits for the old server's panel: a rescue cannot help when the
    new server's is down.
  - A gift code's last day, a discount code's or a sale's start and end, and an agent's end date after 17 January 2038
    («۲۸ دی ۱۴۱۶») are refused, naming that day; before, the database refused them with a server error.
  - A failed «تلاش دوباره» keeps its message and spins while it tries again; a refresh that fails keeps the page and
    what you typed; a page that could not load offline offers to try again; and the panel no longer reloads itself over
    and over after an update while offline or when the browser blocks its storage.
  - A payment method's form shows its gateway's notes — Variza's webhook address among them — when adding it, as when
    editing it.
  - Smaller ones: «متن پیش‌فرض» and resetting the QR background ask first; «باز کردن فروشگاه» on «نمایندگان» closes
    its menu before asking about unsaved changes, a question that could not be answered under it; the review dialog no
    longer calls a payment made outside the shop the wallet's; «−۰» reads «۰», and 1,024 of a unit reads as one of the
    next; a list asked for a page past its last shows the last; the users list's filter by a deleted group reads «گروه
    حذف‌شده»; a customer's services list drops the one they just handed away.
  - A picture refused for its format says «تصویر باید JPG، PNG یا WebP باشد.» with the formats in order in a
    right-to-left line.
- **Agents**
  - An agent's shop refuses new orders and gifts from the moment its selling is suspended; a bot no longer sells on for
    a few seconds.
  - A traffic purchase refused for its amount names both bounds («حجم باید بین ۱۰ و ۱۰۰٬۰۰۰ گیگابایت باشد.»), where it
    named the least alone.
  - A traffic purchase that reaches the month's minimum lifts the suspension at once, even when the shop was suspended
    while it was being delivered.
  - An agent's panel no longer reloads its screens when another shop changes something.
  - A line of an agent's terms in the bot («پایان نمایندگی», «کف خرید ماهانه»…) whose text you emptied no longer
    leaves a blank line.
- **The website**
  - **Security**: the shop's admins on the website can no longer change an admin's or an agent's customer groups, their
    own included, nor switch «همکار» on for a group an admin is in — either would set an admin's prices. The panels
    still can. The second is refused naming the group («گروه «…» حساب یکی از مدیران فروشگاه را دارد؛ «همکار» کردن آن
    فقط از پنل ممکن است.»).
  - **Security**: merging two accounts no longer brings a «همکار» group into an admin's or an agent's account — the
    other side's «همکار» groups are left behind.
  - An invite code typed with Persian digits at sign-up is read.
- **Running the shop**
  - **Security**: a `TRUSTED_PROXIES` range whose length was missing or mistyped trusted every address; an entry the
    shop cannot read now trusts nobody.
  - Signing in at the address of a shop that was deleted (an old tab) no longer counts as a failed sign-in towards the
    login's lockout.
  - A `config.php` written by hand gets its `APP_KEY` when the installer finishes, if it had none.
  - The cron address's answer lists a shop's task as run only when it went through in at least one shop.

### Upgrading

- **The database gains a request key** on each wallet line, each agent's traffic line and each ticket message — what the
  panels send with a credit or debit, a traffic adjustment or a ticket's answer, so one sent again after a lost answer
  is made once. The panel's update runs this upgrade by itself (after an upgrade by hand, «ادامه به‌روزرسانی» does);
  the lines written before keep no key. Since it changes the database, going back to 0.6.0 afterwards takes your backup
  ([Upgrading](Upgrading.md)).
- **The shop's admins on the website** get a 403 for an admin's or an agent's groups and for switching «همکار» on for a
  group an admin is in; what they set before stays. Look over who is in your «همکار» groups on the users page. The
  customer groups — and adding a customer to a «همکار» group — now ask «پلن‌ها و دسته‌بندی‌ها» under «مدیران سایت ›
  کارهای بیشتر»: switch it on for admins who manage them.
- A server whose proxy or «بررسی گواهی TLS» you change asks for its panel's secret again; a stored secret shorter than
  12 characters shows only «••••••••».
- A gift code, a discount code, a sale or an agent's end date can end no later than 17 January 2038 («۲۸ دی ۱۴۱۶»),
  the last day the database's dates hold — and so can a service: a plan, a renewal or a gift that would end one later
  is refused.
- A QR background already uploaded that is a banner (its long side more than 20 times its short side) makes no card:
  services go as text until you upload another. Such a picture is refused from now on.
- A bot text saved before with an attribute name in capitals or an entity Telegram does not read (`&nbsp;`) is sent as
  before, Telegram showing it as typed; the editor refuses it the next time you save that text.
- Plisio answers only a shop whose domain is verified there and whose host's IP is in its «Request IP»; it calls the
  shop back at an `https://` address.
- AqayePardakht's gateway must be registered for the domain of the shop's address («آدرس سایت», `APP_URL`).
- Variza sends its webhooks to the `callback_url` in its profile: set it to the address the method's form shows
  (`https://<the shop's address>/payments/webhooks/variza`); the shop's address must start with `https://`.
- Tools of your own that write through the panels' API: a Plisio method's request is `api_key`, `usd_rate` and
  `currencies`, an AqayePardakht method's `pin`, a Variza method's `api_key`, `webhook_secret` and `card` (each with
  `label`, the bounds and `enabled`), and a row never returns a key, a pin or a secret. A payment row's `gateway` may be
  `plisio`, `aqayepardakht` or `variza`. An id sent as `true` is a 422, no longer read as 1, and so is a reorder's
  `ids` entry that is not an id; deleting a service while an order of it is open is a 422 on `status`. A move of a
  server's services gains `waiting_on`, a bot text's variable `html`; `CustomerGroupRef`, `RewardPlanRef` and
  `RewardServiceRef` are one schema, `NamedRef` — the JSON is the same, but generated types change their name. A
  server row's `last_error` is the diagnosis alone, and its technical detail is apart in a new `last_error_detail`
  (`null` when there is none). A
  wallet's credit or debit (`POST /users/{id}/wallet`), an agent's traffic adjustment
  (`POST /agency/agents/{id}/traffic`) and a ticket's answer (`POST /tickets/{id}/messages`) take an optional
  `Idempotency-Key`: sent again with it, the request answers with what the key did — the wallet's `200` where the
  first was a `201` — and changes nothing twice; the same key with another request is a 422 on `idempotency_key`. A
  payment row, a review row, a wallet line, a ticket's message, an agency request, an agent's traffic line, a
  broadcast, a server's grant, a mass grant and a move of a server's services gain `reviewer_name`, and an order made
  by hand `manual.by_name`; a discount code row gains `deletable` (false while an order but a cancelled one names it:
  its delete would be a 409); the payments list takes `status=held` and its `meta` counts `held`; the dashboard's
  `attention` gains `held_payments`.

### For website developers

- **`expected_amount`**, an optional field of every ordering request but a top-up (`POST /orders`,
  `POST /custom-services/orders`, a renewal, extra traffic, time or devices): the amount the customer was shown, in
  whole Toman. When the price is another by the time the request arrives — a sale ended, a first purchase paid in the
  bot meanwhile —, the request is a `409` on `expected_amount` that names the price now («قیمت این سفارش تغییر کرده و
  الان … است؛ اگر هنوز می‌خواهید، دوباره پرداخت کنید.»), and nothing is ordered. Send it with every order
  ([the price shown](Store-API-Buying.md#the-price-shown)); left out, the order is made at the price of the moment, as
  before.
- A checkout's `link.url` may be a page on the shop's own address that leads on to the gateway's (AqayePardakht's: the
  bank's page opens only for a visitor from the domain registered for the gateway, the shop's). Open it as any `url`.
- `POST /auth/nonce` is a 422 while neither Telegram nor Google sign-in is on for the website («ورود با تلگرام و Google
  برای این وب‌سایت فعال نیست.»): no sign-in would take its nonce.
- A JSON `true` sent where a number belongs (an id, an amount) is a 422 on its field, no longer read as 1.
- **An invisible left-to-right mark** (U+200E, `‎` in raw JSON) now comes before an «#N» or an «`@handle`» that
  follows Persian words in some of the shop's words, so they read the right way round on a right-to-left page: refusals
  that name an order or a location (an order no longer open, a payment held for support, a discount code another order
  of the customer's holds, a location not available), an order's `note` while its payment is held, the notices' `text`
  (worded as the bot's texts), and a wallet transfer's line («انتقال موجودی به …», before the other customer). Show
  them as they are; where you compare or search such words, drop the mark first.
- A request with a method its address does not take is a 405 with an `Allow` header naming the methods it does take.
- A `referral_code` in Persian digits is read.
- **The extras' prices as the order charges them**: `GET /subscriptions/{id}/extras` gives each kind an `offer_price`
  (one unit's, after the customer's automatic discount) and a `discount` (in a plan's shape), and each option an
  `offer_price` — what its order charges: show it, and send it as `expected_amount`
  ([extra traffic, time and devices](Store-API-Buying.md#extra-traffic-time-and-devices)). The listed prices were the
  shop's, so a customer with an automatic discount sending them met the `409`. An amount not on offer is refused
  listing the customer's own prices.
- A purchase, a service built or a renewal that would end its service after 17 January 2038 («۲۸ دی ۱۴۱۶») is a `422`
  with no field («تاریخ پایان سرویس نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد؛ مدت کوتاه‌تری انتخاب کنید.»); extra time offers no
  more days than that, and `auto_renew.offered` is false for a service whose renewal would end it later.
- A refunded purchase no longer counts as bought: `first_purchase_price` comes back for its customer, and a code for a
  first purchase only holds for them again. Such a code is checked again as its order is delivered (the order
  `failed`, its `failure` `first_purchase_used`, its `payment_settled` notice saying why). A refunded order gives its
  code's use back; a code whose use the customer's own unpaid order holds is a `422` on `discount_code` naming that order.
- **`failure` on every order** — `GET /orders`, `GET /orders/{id}` and a checkout's `order`: why a `failed` order
  failed, for the site to word it — `delivery` (support finishes it), or `first_purchase_used` or
  `service_changed_hands` (never delivered: support settles the payment with the customer); `null` for any other order
  ([why an order failed](Store-API-Buying.md#why-an-order-failed)).
- A new notice type, `referral_commission_back`: a commission taken back out of the referrer's wallet, the wallet top-up
  that earned it refunded ([notifications](Store-API-Notifications.md#notice-types)).
- For a shop's admins on the website:
  - `POST /admin/users/{id}/wallet` and `POST /admin/tickets/{id}/messages` take an optional `Idempotency-Key`: sent
    again with it, the request answers with what the key did (the wallet's `200` where the first was a `201`) and
    changes nothing twice; the same key with another request is a `422` on `idempotency_key`
    ([a change sent again](Store-API-Admins.md#what-it-is)).
  - The customer groups' writes — `POST`, `PUT` and `DELETE /admin/customer-groups…`, the reorder — ask the `catalog`
    grant, and so does `PUT /admin/users/{id}/groups` adding a customer to a «همکار» group (a 403, «فروشگاه گذاشتن
    مشتری در گروه «همکار» را به مدیران وب‌سایت نسپرده است.»); each asks a recent sign-in, as a granted operation does.
  - `reviewer_name` beside `reviewer` on a payment row (`PaymentRow`), a review row (`ReviewRow`), a wallet line
    (`WalletTransactionRow`), a ticket's message and a broadcast (`BroadcastRow`), and `manual.by_name` beside
    `manual.by` on an order made by hand: the admin's first and last name, `null` for the owner, an agent or an account
    that shows none.
  - The payments list's `held` tab (`?status=held`: online payments held for support and still waiting), counted in
    `meta.held`, and the dashboard's `attention.held_payments`.
  - `GET /admin/changes` counts their own shop's writes and what is written for every shop, no longer every shop's: a
    site that reloads on it reloads less.
  - `PUT /admin/users/{id}/groups` is a 403 for an admin's account (their own included) or an agent's, and
    `PUT /admin/customer-groups/{id}` a 403 when it would switch «همکار» (`agent_pricing`) on for a group one of the
    shop's admins is in, its message naming the group.
  - The reorders (`POST /admin/plans/reorder`, `/admin/plans/categories/reorder`, `/admin/customer-groups/reorder`) are
    a 422 on `ids` for an entry that is not an id («یکی از شناسه‌ها درست نیست؛ صفحه را دوباره باز کنید.»), where
    «2abc» was read as 2; a list of more than 1,000 ids is refused for its length first («لیست شناسه‌ها بلندتر از حد
    است؛ حداکثر ۱٬۰۰۰ شناسه.»).
  - `POST /admin/subscriptions/{id}/delete` is a 422 on `status` while an order of the service is unpaid, being
    delivered or failed, naming it; a service row's `actions.delete` is false meanwhile.
  - A payment row's `order.server` names a renewal's or an extra's server (its service's); `CustomerGroupRef`, a user
    row's groups, is now `NamedRef` (the same JSON).
- The API description says more: each list's filters and their values, each operation's refusals (403, 409, 422,
  502…), and which fields may be null and why — a service's or an order's `plan` is null only when it has none, since
  the shop never deletes a plan one of them names.

## Version 0.6.0

### Added

- **CubePay, the first online payment gateway** («افزودن روش پرداخت» › «CubePay», in both panels) — card-to-card and
  crypto payments CubePay confirms by itself: a card transfer from your bank's text message, a crypto one from the
  chain. Its settings are your API token from `@cubepy_bot`, kept encrypted, and the fee you pass on to customers
  ([Payment methods](Payment-Methods.md#cubepay)).
  - The customer gets CubePay's page — «💳 پرداخت», the exact sum it asks and until when — and the order is delivered as
    soon as CubePay says it was paid: no receipt, nobody to approve it. With «🤖 نمایش کارت در ربات» on at CubePay, the
    bot also shows the card, its holder and the exact sum with copy buttons, protected like a card-to-card payment's.
    A page answered paid turns into a short line saying so, its buttons gone.
  - The shop never takes a call to its address as CubePay's word: it asks CubePay itself — when CubePay calls (a few
    times a minute at most for one payment; a call past that is answered with what the shop knows), when the customer
    taps «🔄 بررسی پرداخت» (once every few seconds at most), when support taps «استعلام از درگاه» on the payments
    screen, and on its own every few minutes while a page is open and every quarter of an hour for two hours after it
    closed —, so a payment is settled and its customer told once, even when a call is lost or CubePay takes the money
    late. While CubePay cannot be asked, the customer's check says to try again a little later («الان نمی‌شود از درگاه
    پرسید؛ …», a text you may reword).
  - A page whose time ran out unpaid fails its payment («مهلت پرداخت در درگاه تمام شد»); a payment has one page — the
    same page is shown again while it has a few minutes left, two taps at once included —, and before a new one opens
    CubePay is asked about the old one — a bank's text can reach it minutes late —: paid there, the order is delivered
    and nobody pays twice. An order whose page still takes money never expires, a discount code's hold included.
  - **CubePay's fee passed on to customers**: «کارمزد درصدی بر عهده مشتری» and «کارمزد ثابت بر عهده مشتری» on the
    method add the fee to what each payment asks — said apart on the bot's page and the website («(شامل … کارمزد
    درگاه)»), and on the method's line in the list —, as CubePay's guide for shops has it: a card payment's fixed fee
    in the fixed field, a percent one a little higher than CubePay's (3.1 for its 3%), since it is taken of the whole
    sum.
  - The order is paid at its price — a top-up credits its amount —, as CubePay advises: the fee and the few Toman
    CubePay adds to tell transfers apart are CubePay's.
  - **No money is lost**: what CubePay takes for an order that is no longer waiting — paid another way, cancelled,
    expired — goes to the customer's wallet («پرداخت آنلاین #… پس از بسته شدن سفارش #…»), they and the report group's
    «شارژ کیف پول» told; such a payment is never refunded or delivered again from the panels.
  - **A payment the shop cannot take at its gateway's word is held for you** — one confirmed for another order or
    amount, or one on which a gateway kept money unsettled for 48 hours
    ([Payment methods](Payment-Methods.md#a-payment-held-for-you)). It is asked about no more, the report group's
    «خطاها» hears of it once, and the payments screen marks it with why («لغو پرداخت» the one thing offered). While it
    still waits — pending, or failed with its page —, its order never expires and is paid no other way, and the
    customer who asks — in the bot, on the website, on the page a gateway sends them back to — is told it is with
    support and not to pay again («پرداخت شما برای بررسی به پشتیبانی رسید؛ …»); once you cancel it, it holds nothing.
  - CubePay refusing to open a payment — a wrong token, an account not approved yet, a fee wallet short at CubePay —
    is the checkout's «درگاه پرداخت الان در دسترس نیست؛ …», and the log says what CubePay said.
- **Zarinpal** («افزودن روش پرداخت» › «زرین‌پال») — online payment with any Iranian bank card. Its settings are your
  merchant code («مرچنت کد», kept encrypted) and «حالت آزمایشی (sandbox)», Zarinpal's test service
  ([Payment methods](Payment-Methods.md#zarinpal)). The customer pays on Zarinpal's page and is sent back to a page of
  the shop's saying what came of it — the order paid, the money gone to their wallet, not paid yet, or not paid (then
  to message support should money have left their account) —, with «بازگشت به ربات» for a customer with a Telegram
  account (one of the website alone is told to look at their account on the site); the order is delivered and they are
  told once.
  The shop asks Zarinpal to confirm every payment with the bank by itself, so nothing is handed back for want of the
  shop's answer, and asks about every unpaid one as it does CubePay's. Zarinpal's fee, when you set the buyer to pay it
  there, is shown apart in the sum the page asks; the order is paid at its price.
- **AtlasPay** («افزودن روش پرداخت» › «اطلس‌پی») — card-to-card payments AtlasPay confirms from your bank's text
  message, paid in its mini app inside Telegram. Its one setting is your API key, kept encrypted
  ([Payment methods](Payment-Methods.md#atlaspay)). The customer pays the exact sum AtlasPay asks — its markup and last
  digit said apart — and the order is delivered and credited at its price; with AtlasPay's «نمایش مستقیم اطلاعات
  پرداخت», the bot also shows the card with copy buttons. AtlasPay calls a shop on https at once, and the shop asks
  about every unpaid payment as it does CubePay's — one with a receipt in review at AtlasPay, or the rest of an
  underpayment to come, for up to 48 hours after its page closed. An underpayment AtlasPay accepted is never delivered
  by itself: it is held for you.
- **AbanGateway** («افزودن روش پرداخت» › «آبان گیت وی») — card-to-card payments to your own cards, confirmed by
  AbanGateway from your bank's text message. Its settings are your API key (kept encrypted; a `ag_test_` key is its
  test mode) and «نمایش کارت در ربات و سایت» ([Payment methods](Payment-Methods.md#abangateway)). The customer pays the
  exact sum AbanGateway asks — a few Rial more than the order's —, on its page or to the card shown in the bot; a shop
  on https is called at once and the customer's browser sent back to it, and a paid invoice is claimed once, so nothing
  is delivered twice; a large sum split into parts and paid in part is waited for, up to 48 hours after its page
  closed. The order is credited at its price.

### Changed

- **A secret a connection needs** — a panel's token or password, a payment gateway's token, an email service's or a
  captcha's key — can no longer be cleared from the panels' forms (the server always refused it), only replaced.

### For website developers

- A checkout may answer `outcome: "link"` with a `link`: `payment_id`, `url` (open it for the customer), `expires_at`,
  `amount` (the exact sum the page asks — a few Toman more than the order's for a card payment: show this one),
  `fee` (of `amount`, the gateway's fee the customer pays — the one the shop passes on, Zarinpal's when the payer is
  set to pay it, AtlasPay's markup —; `null` for none) and `card` (`{number, holder, bank}` when the gateway gave one
  to show — `holder` and `bank` `null` when it did not say —, else `null`). A request made again with its
  Idempotency-Key answers the same page while it has a few minutes left; after that, should the old page turn out
  paid, the order as it stands (`settled` or `processing`) and no new page.
- `POST /payments/{id}/check` asks the gateway about the customer's own online payment: `{check: {state, order,
  subscription}}`, `state` being `paid`, `waiting` or `closed`; 404 another's, 422 on `status` for a card or wallet
  payment — or one held for support (send the customer to support) —, 429 past 10 a minute, 503 while the gateway
  cannot be asked. The gateway tells the shop by itself; the site may ask when the customer comes back, and watch
  `GET /orders/{id}`.
- A payment in `GET /orders` carries `link` while its page is open (never one held for support); a method's `kind` may
  be `online`; an online payment takes no receipt (the receipt upload refuses it).
- A gateway that will not open the payment is the refused checkout (422 on `method_id`), the order left open. An order
  whose online payment is held for support is paid no other way meanwhile: its checkout is a 422 on `status`, whatever
  the way to pay ([a payment held for support](Store-API-Buying.md#a-payment-held-for-support)).
- A gateway may send the customer back to the shop's own address after paying (Zarinpal and AbanGateway do): a page
  there says what came of it and leads a customer with a Telegram account back to the bot; one without is told to look
  at their account on the site, with no link — open `link.url` in a new tab and learn the outcome with
  `POST /payments/{id}/check` or `GET /orders/{id}`.
- `link.url` may be a Telegram link (AtlasPay's mini app): the customer pays in Telegram.
- The notice `payment_credited`: an online payment that came after its order closed went to the wallet.
- For a shop's admins on the website: `POST /admin/payments/{id}/check` («استعلام از درگاه»), no grant needed — a 422
  on `status` when the gateway's answer holds the payment for support; payment rows gain `gateway_label`,
  `link_expires_at`, `checked_at`, `to_wallet` and `held`, an order's payments `to_wallet` and `held`, and the
  actions `check`.

### Upgrading

- The database gains each payment's online page: its address, until when it takes money, the gateway's details of it
  and when the shop last asked about it. The panel's update runs this upgrade by itself.
- CubePay calls the shop back at its address («آدرس سایت», `APP_URL`), which must begin with `https://`, and needs the
  scheduler (the cron or the poller) to ask about payments whose call was lost. Zarinpal sends customers back to that
  address too: its domain must be the one registered for your gateway at Zarinpal. AtlasPay and AbanGateway call a
  shop on `https://` only — AbanGateway one the internet reaches —; on http the scheduler's asking is all they have.
- A payment gateway driver of your own: a `Secret` field of its form is now kept encrypted with `APP_KEY` and handed to
  the driver readable (one that no longer decrypts reads as empty); an online gateway is a kind of its own (`online`,
  `Contracts\OnlineGateway`) — [Drivers](Drivers.md#an-online-gateway).
- Tools of your own that write through the panels' API: a CubePay method's request is `token`, `fee_percent` and
  `fee_fixed`, a Zarinpal method's `merchant_id` and `sandbox`, an AtlasPay method's `api_key`, an AbanGateway method's
  `api_key` and `show_card` (each with `label`, the bounds and `enabled`), and a row never returns the token, the code
  or the key. A payment row's `gateway` may be `cubepay`, `zarinpal`, `atlaspay` or `abangateway`.

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
