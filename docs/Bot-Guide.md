# Bot guide

What the bot offers a customer and its admins. Every shop's bot — the main one and each agent's — works the same way, in
its own shop, with its own wording: you (or the agent) reword every text a customer reads and arrange the menu ([Bot texts
and keyboards](Bot-Texts-And-Keyboards.md)). The labels below are the shop's defaults.

## Before the menu

`/start` (or `/menu`) greets the customer by their Telegram name, with the menu. On the way, in this order:

1. **A banned customer** gets one answer to everything: their account is blocked ([Customers](Customers.md#banning)).
2. **The bot switched off** — «⛔️ ربات فعلا غیرفعال است…», the menu taken away ([Bot settings](Bot-Settings.md#general)).
3. **The phone**, when the shop asks it — «📱 ارسال شماره موبایل», their own Telegram number, once.
4. **The channels**, when the shop asks them — a button per channel to join, then «✅ عضو شدم».

The bot's admins pass 2 to 4. A first `/start` from an invite link makes the newcomer the link owner's referral, before
any of these ([Referrals](Referrals.md)). A `/start` from the test link (`?start=usertest`) that 2 to 4 hold back waits
in the chat for an hour: the test screen opens once the customer gets through — the number sent, «✅ عضو شدم», or a
`/start` or `/menu` in that hour ([a free test](#a-free-test)). Anything the bot does
not understand — a word typed, a button of a screen long gone — is answered «متوجه نشدم. لطفا از منوی زیر استفاده
کنید.» with the menu; a chat that sends more than 20 updates in ten seconds is not answered while it keeps on.

## For customers

| Button | What it opens |
|---|---|
| «🔐 خرید اشتراک» | [Buying](#buying) |
| «🧪 اکانت تست» | While the shop gives tests: the servers giving one now, each with its traffic and term; a tap makes one ([a free test](#a-free-test)) |
| «🛍️ سرویس‌های من» | The running services (and those waiting for their first connection), newest first, five a page; each opens [its screen](#a-services-screen). While the shop takes services by their link, «➕ افزودن سرویس» under them ([adding a service by its link](#adding-a-service-by-its-link)) |
| «♻️ تمدید سرویس» | The services that may be renewed — running or ended, on a plan still there, or without a plan (added by their link, or built) to pick what to renew them on —, five a page; each opens its renewal ([renewing](#renewing)) |
| «💰 کیف پول + شارژ» | The balance («… تومان بدهی» for a debt; an agent's credit), the latest five lines, and «➕ افزایش موجودی» ([the wallet](#the-wallet)) |
| «👥 زیرمجموعه‌گیری» | The invite link (a tap copies it), the terms (the start gift and the plan reward while they are on), what it brought, «📤 ارسال لینک برای دوستان» — while the program runs ([Referrals](Referrals.md)) |
| «📚 آموزش» | A short guide to connecting — the shop's, which you may reword |
| «☎️ پشتیبانی» | Your contact, and the tickets ([support](#support)) |
| «🤝 نمایندگی» | The main bot only, while the program runs: the agency's terms and «📝 درخواست نمایندگی»; an agent's account ([Agency](Agency.md)) |
| «🎁 کد هدیه» | While gift codes are on: a code typed is redeemed — Toman into the wallet, or a plan's service ([Gift codes](Gift-Codes-And-Lucky-Wheel.md#how-a-customer-uses-a-code)) |
| «🎡 گردونه شانس» | While the wheel is on: its prizes, what a spin costs, and «🎡 بچرخان» — or when the next spin is ([The lucky wheel](Gift-Codes-And-Lucky-Wheel.md#a-spin)) |

### Buying

1. **The categories** — when the shop has an active one; then «سایر پلن‌ها» for the rest. Otherwise the plans at once.
2. **The plans** that can be delivered today — in an agent's bot, those their traffic covers —, each «name — traffic /
   term — price», in the colour and the row width its plan's form gives it.
3. **A plan's details** and its locations («🌍 …»): the servers that can sell it now, each in its server's colour and
   row width.
4. **The checkout** — what is bought and its price, and a button per way to pay — a free plan's (price `0`), one
   «🎁 دریافت رایگان» instead, which delivers it at once, nothing taken —, and under it the plan's note when it has one
   («📌 …», [Plans](Plans.md#a-plan)). **Nothing is ordered until one is picked**; there is no cancel — a checkout left
   alone orders nothing. While the shop lets buyers name their service ([Bot settings](Bot-Settings.md#service-usernames)),
   a line says what it will be called — «ترتیبی», the shop's pattern, until they choose — over a button per way:
   «🔢 ترتیبی», «🎲 رندوم» (a name drawn at random, another at each tap) and «✏️ دلخواه» (the name typed next, 3 to 32
   English letters, digits, `_` and `-`; one refused says why and another is awaited). The name is kept for that
   checkout, through a discount code and back from a card's screen, and the service is delivered with it — or with the
   pattern's, should a service on that server have taken it meanwhile.
5. - **The wallet** pays at once: the checkout gives way to the service — a QR card of its subscription link, or the link
     as text. A wallet short of the price says how much is missing, with «➕ افزایش موجودی».
   - **A card** shows the amount, the card's number (a tap copies it), its holder and your words: the customer transfers
     the money and **sends a picture of the receipt** — a photo, or a picture sent as a file (10 MB at most); its caption
     is their note. The bot says it is with support — «حداکثر تا … دقیقه دیگر» with a review window —, and the verdict
     comes later as a reply to it ([Orders and payments](Orders-And-Payments.md#payments)).

An order left unpaid expires after 48 hours of nothing — but one whose receipt is with support.

**A service the customer builds** — in the main bot, while «سرویس دلخواه» is on ([Custom services](Custom-Services.md)),
«🛠 ساخت سرویس دلخواه» stands under the plans (or the categories), even when no plan is on sale:

1. **The GB** — each gigabyte's price by its tiers, a button per amount offered, two a row; any amount within the bounds
   may be typed instead («۵۰ گیگ» is fine). One outside them is refused, saying them, and another is awaited.
2. **The days** — the same, for the days; «بازگشت» to the GB.
3. **The server** — a button per server it is built on now; with only one, this step is skipped.
4. **The checkout** — the GB and their price, the days and theirs (each whole at the rate of its tier, the two added),
   the devices, the server and what is paid after the customer's discounts, with «🏷 کد تخفیف دارم» and the ways to pay.
   Then as any purchase: the service comes with no plan, named «سرویس دلخواه» where a plan's name would be.

A step tapped after the bounds changed shows that step again; once nothing may be built, the bot says so, with
«بازگشت» to the plans. A payment reminder's «پرداخت دوباره» opens the checkout of what was built, as it is priced now.

### First-purchase prices

A plan may have a price of its own for a customer's first purchase — «قیمت خرید اول» on its form
([Plans](Plans.md#first-purchase-price)): a discount on the first one, or `0` for a free first purchase. A customer who
has never bought a service in your shop pays it: a purchase of theirs paid at some point, whatever came of it since
(delivered, failed, refunded), is one — an order never paid, or cancelled unpaid, is none, and neither is a wallet
top-up. Every plan is offered to everyone; only what it costs differs. Each shop counts its own: a customer of the main
bot is a newcomer in an agent's bot.

- **Until they have bought**, the plan is at that price on its button in «خرید اشتراک», in its details and at its
  checkout, and the details say under them «🎁 این قیمت ویژه اولین خرید شماست؛ قیمت عادی این پلن … است.» — a line you
  may reword, or empty to drop it ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)). A free first purchase's
  checkout is one «🎁 دریافت رایگان» button.
- **Once they have**, every screen says the plan's price, and that is what they pay.
- **Two at once** — two purchases ordered at that price before either was paid, say one by card waiting for its receipt
  while the wallet buys another —: the purchase paid first is their first purchase (of two paid in the same second, the
  one ordered first). The other fails as it is delivered, «این سفارش با قیمت خرید اول ثبت شده بود، ولی خرید دیگری از
  همین مشتری زودتر پرداخت شد؛ مبلغ آن را به مشتری برگردانید.», for you to give its money back
  ([Orders and payments](Orders-And-Payments.md#payments)); its failure reaches the report group as any failed delivery.
  One whose delivery failed and that you retry later is still their first purchase, whatever they bought meanwhile.
- **Its service renews at the plan's price**, as any — from the bot, or by itself with «تمدید خودکار».

The report group's sale says «🎁 قیمت خرید اول» beside what was paid, and the orders screen marks the order so.

### Agent prices

A plan may have a price for your agents and your colleagues — «قیمت نماینده» on its form
([Plans](Plans.md#agent-price)). An agent of the shop pays it, and so does every customer of a group you mark «همکار»
([Customers](Customers.md#groups)): the plan is at that price on its button, in its details — which say under them
«🤝 این قیمت ویژه نمایندگان و همکاران است؛ قیمت عادی این پلن … است.», a line you may reword or empty — and at its
checkout, and their services renew at it, by hand or by «تمدید خودکار». A colleague who has bought nothing yet and could
pay a first-purchase price too pays the lower. The report group's sale says «🤝 قیمت نماینده».

### A free test

«🧪 اکانت تست» on the menu, `/free`, or the shop's link `https://t.me/<bot>?start=usertest` (the greeting and the menu,
then the screen under them) opens the test screen: «🧪 اکانت تست رایگان», a line for each server giving a test now — its
traffic and term, «(از اولین اتصال)» when the term counts from the first connection — and a «🌍 …» button for each. A
tap makes the test at once and sends it as a bought service comes — a QR card of its link, or text —, with «📊 مشاهده
سرویس». A test that could not be sent still counts: the bot says so («⚠️ اکانت تست شما ساخته شد ولی ارسال آن ناموفق
بود؛ …») with the same button to open it. It is then one of their services, its plan named «اکانت تست»: no renewal, no
extra traffic, time or devices, no reminders; once it ends, or if it is never connected, the shop removes it after a
while, without a word — its screen says when.

What a customer may take is the shop's rule — so many tests each, in all or within so many days, a cap a day, perhaps
for newcomers only ([Test accounts](Test-Accounts.md#the-quota)). A test refused says why, with «🔐 خرید اشتراک» under
it where buying is the way on; a quota used for now says when the next test is possible. A tap on a server that
stopped giving tests since the screen was drawn shows the screen again as it is now. A panel that fails to make one
says so («⚠️ ساخت اکانت تست الان ممکن نشد؛ …»), and nothing of the quota is spent. The bot's admins are held to the
switch alone, though their tests count toward the shop's cap a day.

### Adding a service by its link

While the main shop takes services by their link ([Services added by their link](Service-Claims.md)), a customer who
was given a service's subscription link or one of its configs — a client support made on a panel by hand — sends it to
the bot, anywhere it waits for nothing else, or taps «➕ افزودن سرویس» under «سرویس‌های من» and sends it there. The
service is added to their account at once («✅ سرویس … روی لوکیشن … به سرویس‌های شما اضافه شد.», with «📊 مشاهده
سرویس»); a link the bot does not take — one no server has, one another customer has, one theirs already — is said in
a word. A message holding no service's link is answered so only after «➕ افزودن سرویس», where the link stays awaited.
Sent at no step, only a config or a web address on one of the shop's own subscription addresses is looked for: a
channel's post forwarded, any other web address, and a link no server has are any message the bot does not
understand. A picture's caption counts. Off, a link sent is an unknown message, as before.

### A service's screen

Its status, name, plan, location, quota, what is used and left, when it ends — «⏳ در انتظار اولین اتصال (n روز)» until
the first connection starts its days —, how many devices may connect at once (with any «کاربر اضافه» bought, «👥 تعداد
کاربر: ۳ کاربر»), its last connection and whether it is online now: read from its panel as the
screen opens (a panel that cannot be read shows the last numbers, said to be so). While the shop removes ended services
by itself ([Bot settings](Bot-Settings.md#ended-services)), the screen of one that ended — and its renewal's checkout,
from «♻️ تمدید سرویس» too — says when it goes: «🗑️ اگر این سرویس تا … تمدید نشود، حذف می‌شود.» — renewed before then,
it stays; removed, it is gone with its link, and a new purchase brings a new one. A free test's screen says «🗑️ این اکانت تست … حذف می‌شود.» by the shop's own rule for
tests. Its buttons:

- «🔄 به‌روزرسانی اطلاعات» — read it again;
- «♻️ تمدید سرویس» — [its renewal](#renewing) (a free test has none; one without a plan picks what to renew on first);
- «🔗 لینک اشتراک» — the link in place of the screen, as a QR card or text, a tap to copy — no panel asked, so it works
  while the panel is down;
- «🔋 حجم اضافه», «⏳ زمان اضافه» and «👥 کاربر اضافه» — [more traffic, time or devices](#extra-traffic-time-and-devices) for
  this service, each while the shop sells it for the service (never for a free test);
- «🔁 تمدید خودکار: روشن / خاموش» — the wallet renews it days before its end on its plan (shortly before the end where
  the shop renews automatically by «ریست زمان») — while the shop offers it
  ([Bot settings](Bot-Settings.md#renewal)) and it has a plan (not one added by its link or built, until a renewal on a
  plan); turned on, it says when and how much;
- «⚠️ ارسال گزارش اختلال» — a support ticket about this service, its first message awaited;
- «⚙️ تغییر لینک» — new credentials and a new link, asked first (every device on the old one drops), when its server can;
  the new link comes as a delivery;
- «🔁 انتقال سرویس» — [to another customer](#handing-a-service-to-another-customer), while the shop lets customers (never
  a free test);
- «⬅️ بازگشت به لیست سرویس‌ها».

### Handing a service to another customer

While the shop lets customers ([Bot settings](Bot-Settings.md#service-transfers)), «🔁 انتقال سرویس» on a service's screen
gives it to another customer of the shop, free:

1. **The recipient's numeric Telegram id** is asked for in place of the screen («بازگشت» leads back to it); Persian
   digits are fine. They must have started the bot once. An id that names nobody of the shop, the customer themselves or
   a banned account is said so in a word, and another awaited.
2. **Who that is** — their name and @handle — with «✅ تایید انتقال» and «❌ انصراف» (back to the service). Another id
   sent there asks about that one instead. A confirmation is good once: tapped again, or after the customer went
   elsewhere, it hands nothing over («این تایید دیگر معتبر نیست؛ …»).
3. **Confirmed**, the service is theirs: the question turns into «✅ سرویس … به … منتقل شد …», with the way back to
   «سرویس‌های من». The recipient gets «🎁 سرویس … به حساب شما منتقل شد …» with «📊 مشاهده سرویس».

The service goes as it is — its link, end and traffic —, its «تمدید خودکار» off. The link going with it, the previous
owner's devices stay connected until the recipient taps «⚙️ تغییر لینک»: where its server can change a link, the
recipient's message says so («🔐 صاحب قبلی این سرویس هنوز لینک آن را دارد؛ …»). A service with an order the customer
made still open — a renewal or an extra waiting for its payment or receipt, or being delivered — waits for it («⚠️ این
سرویس الان منتقل نمی‌شود: …»; an unpaid one is dropped by itself after 48 hours; one whose delivery failed sends the
customer to support, who retries it or gives the money back), and a customer who looked up 10 ids in 10 minutes, or
handed on 5 services in an hour, waits a while. Support transfers a service from the panel whatever the switch says
([Services](Services.md#handing-a-service-to-another-customer)).

### Renewing

A service is renewed on its own plan, at its price today: the checkout shows the service **as the renewal would leave
it** — its new end, its traffic, what the period in use leaves, and the devices the renewal gives (from the end of
the period in use, when the devices bought for it stay until then) —, then the ways to pay. A service added by its link or
built has no plan: its renewal first lists the plans on sale on its server, each at the
customer's renewal price, and the plan picked is the checkout's — and, once renewed, the service's own. While the
builder is on, «🛠 تمدید با سرویس دلخواه» comes under them — alone when no plan is sold there —: the builder's GB and
days, then the renewal's checkout of that package, «سرویس دلخواه · ۵۰ گیگ · ۳۰ روز»; renewed so, the service still has
no plan, and «تمدید خودکار» is never offered for it ([Custom services](Custom-Services.md#renewing-a-built-service)).
With nothing to pick: «فعلا پلنی برای تمدید این سرویس فروخته نمی‌شود …». The wallet renews it at once
(«📊 مشاهده سرویس» under the word); a card waits for its receipt. A service switched off by support, without a plan while neither a plan nor the builder is there to pick, or —
in an agent's bot — beyond the agent's traffic, cannot be renewed; one whose renewal is already with support or being
delivered says so. A service bought at a [first-purchase price](#first-purchase-prices) renews at the plan's price — an
agent's or a colleague's at its [agent price](#agent-prices). The plan's checkout note is under the renewal's checkout
too.

While the shop offers more than one renewal method ([Bot settings](Bot-Settings.md#renewal)) the checkout has a row of
them — «✅ تمدید پلن فعلی», «ریست حجم + افزایش روز», «ریست زمان + انتقال حجم» —, opens on the first one on, and draws the
service's end and traffic again by the one tapped; a discount code applied stays, and the ways to pay renew by the one
marked. A shop with one method shows no choice. A shop that holds a running service back until it nears its end
(«آستانه مجاز تمدید») still lists it in «♻️ تمدید سرویس»; the tap answers «⏳ تمدید این سرویس از … ممکن است.» with the
date and hour, or «وقتی ٪۲۰ از حجم یا کمتر مانده باشد», and nothing more happens. An ended service may always be
renewed.

### Extra traffic, time and devices

While you sell them ([Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)), a service's screen offers «🔋 حجم اضافه»,
«⏳ زمان اضافه» and «👥 کاربر اضافه» (more devices at once) — each bought on its own, at the prices of the service's plan
when it has its own, else the shop's:

1. **The kind's screen** — the service's traffic left and its end (for devices, how many it has now and the days their
   price is for), and a button per amount you offer with its price. With a price per GB, day or device the customer may
   type any amount within your bounds right there instead («۲۰ گیگ», «۷ روز», «۲ کاربر» and Persian digits are fine);
   one you do not sell is said so, and another awaited.
2. **The checkout** — what it costs and the service **as it would leave it** — its traffic left, its end, its devices
   —, then the ways to pay. Nothing is ordered until one is picked.
3. **The wallet** adds it at once («📊 مشاهده سرویس» under the word); **a card** waits for its receipt, as a purchase does.

Devices are priced for 30 days and charged for the days left of the service's period — a service 15 days from its end
pays half the price, its screen saying «قیمت‌ها برای ۱۵ روز مانده از دوره فعلی سرویس حساب شده است» —, and last as long:
a renewal brings its plan's number of devices, and they are bought again for the new period.

Traffic brings a service whose traffic ran out back to life; days for one whose time ran out count from its next
connection, as a renewal's do (its screen says so). Neither is offered for a service switched off by support, nor for one
that ended on both counts — that is a renewal's —; traffic is not offered for an unlimited one, nor time for one that
never ends. Devices are not offered for a service of unlimited devices, one whose time or traffic ran out, or one on a
server whose panel has no device limit (PasarGuard). A service whose traffic or time ran out is not in «🛍️ سرویس‌های من», which
lists the running ones: the menu's «♻️ تمدید سرویس» list reaches it, and its renewal's checkout offers the extra it may
take beside the renewal. In an agent's bot no more traffic is sold than the agent still has, and what is sold comes out
of it; days and devices cost the agent nothing. A button kept from before, for a service that can no longer take it, is
answered in a popup and orders nothing.

### The wallet

«➕ افزایش موجودی» offers your amounts as buttons and «✏️ مبلغ دلخواه»; the customer picks one, or types any amount
within your bounds right there (Persian digits, separators and «تومان» are fine); then the checkout, every way to pay
but the wallet itself.

### Support

«☎️ پشتیبانی» shows your contact ([Bot settings](Bot-Settings.md#general)), «📨 تیکت جدید» and «🗂️ تیکت‌های من»:

- **A new ticket** — which running service it is about (or «بدون سرویس مشخص»), then one message: words, and a picture if
  there is one, the picture's words as its caption. Its first line is the subject. A picture alone is kept until its
  words come.
- **What follows joins the ticket** — whatever the customer sends next is added to it, until they go elsewhere, a quarter
  of an hour passes since their last message, or a notice of another of their tickets arrives.
- **«🗂️ تیکت‌های من»** — the latest activity first, five a page («🟡» waiting on support, «🟢» answered, «🔒» closed). A
  ticket's screen shows its last five messages and offers «✍️ پاسخ», «🖼️ تصویر n» for each picture, «🔒 بستن تیکت» while
  open, and «⭐ امتیاز» once closed. A message to a closed ticket opens it again.
- **Support's answer** arrives in the chat — its picture with it, when it fits —, with «✍️ پاسخ» and «🗂️ مشاهده تیکت».

### What the bot tells a customer by itself

| When | They get |
|---|---|
| A card payment approved — or retried, or delivered after a restart | Their service (a QR card), its renewal or the traffic, time or devices it bought, the wallet charged or the agent's traffic added — or that the delivery failed and support will finish it |
| A receipt rejected, an order cancelled, a payment refunded | A word, with your note, as a reply to their receipt |
| A payment reminder from the panel | A nudge, with «📎 ارسال رسید» and «🔄 پرداخت دوباره» |
| A service switched off or on, deleted (while running), moved, given days or traffic | A word — a moved one with its new link |
| A service handed to them, by its previous customer or support — or handed on by support | A word — theirs now with «📊 مشاهده سرویس» |
| A service near its end or its traffic's end (when the shop turned reminders on) | A reminder, «📊 مشاهده سرویس» |
| An automatic renewal — done, short of money (once a window), failed | A word; short, «➕ افزایش موجودی» |
| A newcomer their link brought, a commission earned | A word |
| Support's answer to a ticket, its closing | The answer; the closing with «⭐ امتیاز» |
| An agent's request decided, their terms changed, their agency ended, their traffic short for a delivery | A word in the main bot |
| A change of how their website account is signed in to, or wrong codes tried on it | A word — in Telegram **and** by email |

Every notice is kept for the customer's website too, and one Telegram cannot bring — no Telegram account, or the bot
blocked — goes by email when the shop's email is set up ([Store API: notifications](Store-API-Notifications.md)).

## For the bot's admins

A bot admin is a customer you (or the agent) made one — «کاربران», the row's menu «مدیر ربات کردن»; an agent is their
own bot's admin by itself ([Customers](Customers.md#the-bots-admins)). They pass the bot's switch, phone and channels —
not a ban —, and have what answers nobody else at all:

- **`/broadcast`** — a message to many customers, composed in the bot: the message, then a draft card — how it goes, to
  whom, pinned or not, link buttons —, then the run's progress with its controls ([Broadcasts](Broadcasts.md)).
- **`/emoji`** — premium emoji for the bot's texts and buttons: a message with them, written the way a customer should see
  it; the bot keeps their ids for the panel's picker, sends the message back as itself — whether they stay premium says
  whether the bot may use them —, and answers with a template to paste into a text ([Bot texts and
  keyboards](Bot-Texts-And-Keyboards.md#premium-emoji)).
- **The report group** — a receipt's «✅ تایید» and «❌ رد», a failed delivery's retry, an agency request's verdict, a
  ticket's answer by reply and its «🔒 بستن تیکت» ([Report group](Telegram-Group.md#acting-from-the-group)).
- **The shop's website** — the shop's daily work from their own account there, while the website lets its admins in
  ([Website](Website.md#the-shops-admins)).

An admin is a customer still: what is their own — their payment, their wallet, their service, their review, their agency
request — another admin decides.

## Watch out for

- **A relabelled keyboard button** — a customer's old keyboard sends the old label once, answered with the new menu.
- **A contact card sent at any step** goes to the menu, leaving the step.
- **The menu's buttons for free tests, referrals, the agency, gift codes and the wheel** show only while their programs
  run.
