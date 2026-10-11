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
any of these ([Referrals](Referrals.md)). Anything the bot does not understand — a word typed, a button of a screen long
gone — is answered «متوجه نشدم. لطفا از منوی زیر استفاده کنید.» with the menu; a chat that sends more than 20 updates in
ten seconds is not answered while it keeps on.

## For customers

| Button | What it opens |
|---|---|
| «🔐 خرید اشتراک» | [Buying](#buying) |
| «🛍️ سرویس‌های من» | The running services (and those waiting for their first connection), newest first, five a page; each opens [its screen](#a-services-screen) |
| «♻️ تمدید سرویس» | The services that may be renewed — running or ended, on a plan still there —, five a page; each opens its renewal ([renewing](#renewing)) |
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
   alone orders nothing.
5. - **The wallet** pays at once: the checkout gives way to the service — a QR card of its subscription link, or the link
     as text. A wallet short of the price says how much is missing, with «➕ افزایش موجودی».
   - **A card** shows the amount, the card's number (a tap copies it), its holder and your words: the customer transfers
     the money and **sends a picture of the receipt** — a photo, or a picture sent as a file (10 MB at most); its caption
     is their note. The bot says it is with support — «حداکثر تا … دقیقه دیگر» with a review window —, and the verdict
     comes later as a reply to it ([Orders and payments](Orders-And-Payments.md#payments)).

An order left unpaid expires after 48 hours of nothing — but one whose receipt is with support.

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

### A service's screen

Its status, name, plan, location, quota, what is used and left, when it ends — «⏳ در انتظار اولین اتصال (n روز)» until
the first connection starts its days —, its last connection and whether it is online now: read from its panel as the
screen opens (a panel that cannot be read shows the last numbers, said to be so). Its buttons:

- «🔄 به‌روزرسانی اطلاعات» — read it again;
- «♻️ تمدید سرویس» — [its renewal](#renewing);
- «🔗 لینک اشتراک» — the link in place of the screen, as a QR card or text, a tap to copy — no panel asked, so it works
  while the panel is down;
- «🔋 حجم اضافه» and «⏳ زمان اضافه» — [more traffic or time](#extra-traffic-and-time) for this service, each while the
  shop sells it for the service;
- «🔁 تمدید خودکار: روشن / خاموش» — the wallet renews it days before its end — while the shop offers it ([Bot
  settings](Bot-Settings.md#renewal)); turned on, it says when and how much;
- «⚠️ ارسال گزارش اختلال» — a support ticket about this service, its first message awaited;
- «⚙️ تغییر لینک» — new credentials and a new link, asked first (every device on the old one drops), when its server can;
  the new link comes as a delivery;
- «⬅️ بازگشت به لیست سرویس‌ها».

### Renewing

A service is renewed on its own plan, at its price today: the checkout shows the service **as the renewal would leave
it** — its new end, its traffic, what the period in use leaves —, then the ways to pay. The wallet renews it at once
(«📊 مشاهده سرویس» under the word); a card waits for its receipt. A service switched off by support, on a plan gone, or —
in an agent's bot — beyond the agent's traffic, cannot be renewed; one whose renewal is already with support or being
delivered says so. A service bought at a [first-purchase price](#first-purchase-prices) renews at the plan's price — an
agent's or a colleague's at its [agent price](#agent-prices). The plan's checkout note is under the renewal's checkout
too.

### Extra traffic and time

While you sell them ([Bot settings](Bot-Settings.md)), a service's screen offers «🔋 حجم اضافه» and «⏳ زمان اضافه» —
each bought on its own, at the prices of the service's plan when it has its own, else the shop's:

1. **The kind's screen** — the service's traffic left and its end, and a button per amount you offer with its price. With
   a price per GB (or per day) the customer may type any amount within your bounds right there instead («۲۰ گیگ», «۷ روز»
   and Persian digits are fine); one you do not sell is said so, and another awaited.
2. **The checkout** — what it costs and the service **as it would leave it** — its traffic left, its end —, then the
   ways to pay. Nothing is ordered until one is picked.
3. **The wallet** adds it at once («📊 مشاهده سرویس» under the word); **a card** waits for its receipt, as a purchase does.

Traffic brings a service whose traffic ran out back to life; days for one whose time ran out count from its next
connection, as a renewal's do (its screen says so). Neither is offered for a service switched off by support, nor for one
that ended on both counts — that is a renewal's —; traffic is not offered for an unlimited one, nor time for one that
never ends. A service whose traffic or time ran out is not in «🛍️ سرویس‌های من», which lists the running ones: the
menu's «♻️ تمدید سرویس» list reaches it, and its renewal's checkout offers the extra it may take beside the renewal. In an
agent's bot no more traffic is sold than the agent still has, and what is sold comes out of it. A button kept from
before, for a service that can no longer take it, is answered in a popup and orders nothing.

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
| A card payment approved — or retried, or delivered after a restart | Their service (a QR card), its renewal or the traffic or time it bought, the wallet charged or the agent's traffic added — or that the delivery failed and support will finish it |
| A receipt rejected, an order cancelled, a payment refunded | A word, with your note, as a reply to their receipt |
| A payment reminder from the panel | A nudge, with «📎 ارسال رسید» and «🔄 پرداخت دوباره» |
| A service switched off or on, deleted (while running), moved, given days or traffic | A word — a moved one with its new link |
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
- **The menu's buttons for referrals, the agency, gift codes and the wheel** show only while their programs run.
