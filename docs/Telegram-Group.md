[فارسی](fa/Telegram-Group.md)

# Report group

A Telegram supergroup with topics where the shop reports to its admins — a topic for each kind of report, with buttons to
act on them —, so support works from Telegram as well as from the panel. Every shop has its own: the main bot's, and
each agent's bot's. It is set up from «تنظیمات ربات › گروه گزارش‌ها».

## Connecting it

1. In Telegram, make a group (or take an empty one) and turn **Topics** on in its settings.
2. On the screen, «ساخت لینک اتصال», then «افزودن ربات به گروه»: Telegram asks which group, and adds the bot as an admin
   that may manage topics («مدیریت تاپیک‌ها»).
3. The screen sees the group connect — the bot makes every topic itself, each with a first message saying what it is
   for.

The link works once, for an hour; «لینک تازه» replaces it. **When the link does not open in Telegram** («لینک در تلگرام
باز نشد؟»): make the bot an admin of the group yourself, with «مدیریت تاپیک‌ها», and send the command the screen shows
(`/start@<bot> reports_<code>`, «کپی پیام اتصال») in the group. The link needs the bot's @username: on a fresh shop, press
«بررسی توکن» under «تنظیمات پنل › ربات تلگرام» and save first; an agent's bot has its own once the agent hands it over
(its token sent in the main bot, «نمایندگی» › «ربات من»).

A group that will not do is refused, in the group and on the screen, with why: topics off, the bot not an admin, or
without the right to manage topics.

**Connected**, the screen shows the group, any problem it has now (the bot removed or demoted — fix it, then «بررسی
دوباره»), the reports waiting to be sent, each topic, and:

- **«پیام تست»** — a test message in every topic that is on.
- **«بررسی دوباره»** — checks the bot's rights again, and makes any topic missing.
- **«اتصال گروه دیگر»** — the connect steps again; the reports then go to the new group.
- **«قطع اتصال»** — asks first: no more reports, those waiting are dropped; the bot stays in the group with its messages
  (remove it from Telegram if you like).

## The topics

| Topic | What it hears |
|---|---|
| «خریدها» | A service sold and delivered: the customer, the plan, the location, the service, the amount and the way it was paid, the referrer's commission; a service a customer added to their account by its link: the customer, the service and its location ([Services added by their link](Service-Claims.md#who-gets-it)); a service handed to another customer: from whom, to whom, the service, its location, and whether its customer or support did it ([Services](Services.md#handing-a-service-to-another-customer)); under an order support made by hand, «🎧 ثبت دستی توسط پشتیبانی» and how it was paid ([Orders and payments](Orders-And-Payments.md)) |
| «تمدیدها» | A renewal — by which method, unless «تمدید پلن فعلی» — and the new end; extra traffic, time or devices bought for a service («حجم، زمان و کاربر اضافه»), and what the service has now |
| «اکانت‌های تست» | A free test taken — on the bot, the website or the mini app: the customer, the service, its location, its traffic and end, and how many tests the customer has taken in the shop ([Test accounts](Test-Accounts.md#what-a-test-is)) |
| «حذف سرویس‌ها» | A service support deleted («🗑»): the customer, the service and its plan («اکانت تست» for a test), its location, the traffic and time it still had (or that its panel had lost it already), who of support and their note, and a client left on its panel when «حذف فقط از فروشگاه» was chosen; a purchase's service a refund took back, with its order ([Services](Services.md#a-services-dialog)); and the ended services «حذف خودکار سرویس‌های منقضی‌شده» removed («🧹»), one report a run: each by its client, customer and location, as many as fit a message, then how many more. A test the clean-up removes is not reported — its taking was |
| «شارژ کیف پول» | A wallet top-up, and the balance; money one customer sent another («انتقال موجودی»), with both balances; an online payment that came after its order closed, its amount gone to the customer's wallet («💳 پرداخت آنلاین پس از بسته شدن سفارش») |
| «رسیدها» | A copy of each card receipt with what it pays for and the card it was shown («🏦 کارت: …», or «🏦 کارت در پیوی پشتیبانی داده شد» for one taken in support's private chat), «✅ تایید» and «❌ رد»; then its verdict as a reply to it; and receipts support dismissed at once («🗑»): how many, and support's note |
| «کاربران جدید» | A newcomer, and who brought them, with «⚙️ مدیریت کاربر» — their card, to manage them right there ([below](#a-customers-card)) |
| «خطاها» | A paid order whose delivery failed, with why and «🔁 تلاش دوباره برای تحویل»; an online payment held for support, once («⚠️ پرداخت آنلاین برای بررسی پشتیبانی نگه داشته شد»): the customer, what it pays for, the amount and the method, its id at the gateway, why and what the gateway said, and — when support's «استعلام از درگاه» is what found it — who asked; and, for one still waiting, that its order takes no other payment and is never cancelled by itself until you look at it ([Payment methods](Payment-Methods.md#a-payment-held-for-you)); in the main bot's group also a server's panel that stopped answering («🔴») and answers again («🟢»), and a move of a server's services to another starting («🚚») and ending — done («✅»), stopped because the target could take no more («⛔️»), or cancelled («⏹») — with its numbers ([Servers](Servers.md#moving-its-services)) |
| «نمایندگی» | The main bot's group alone: a request to become an agent with its buttons, then the verdict; a purchase of traffic; an agent's selling suspended («⛔️», with why and what opens it again) and opened again («✅») ([Agency](Agency.md)) |
| «تیکت‌ها» | A ticket opened, then every later message, its closing, opening again and rating — [Support tickets](Support-Tickets.md#from-the-report-group) |
| «نظرات» | A review written on the website: its number, name, stars, where they use the service from, who wrote it, the words — no buttons: it is decided on the panel's «نظرات» ([Reviews](Reviews.md)) |
| «پشتیبان‌گیری» | The shop's backup, as a file, once a day by default: in the main bot's group the whole installation's — every shop, the servers, the agents —, in an agent's group its own shop's; with what it is of, the date, its size and rows, and where it is restored. One larger than Telegram takes from a bot (50 MB) is said in a line instead, to download from the panel. It is sent at its time, apart from the queue below ([Backups](Backups.md#in-the-report-group)) |

«بخش‌های گزارش», on the same screen, has a switch for each (all on) — «پشتیبان‌گیری»'s is on «تنظیمات پنل ›
پشتیبان‌گیری» too. A topic switched off sends nothing, and its topic stays in the group.

Who of support did something — handed a service on, dismissed receipts, made an order by hand, began moving a server's
services, asked a gateway about a payment that was then held («🎧 استعلام توسط پشتیبانی») — is said as «🎧 توسط
پشتیبانی» and, for an admin or an agent, their name after it. What you did carries no name: never your panel login
(the group's members are not all you).

## Acting from the group

The buttons and replies work for **the bot's admins** alone — customers given the role on «کاربران», recognised by the
Telegram account that pressed; being in the group is not enough ([Customers](Customers.md#the-bots-admins)). Anyone else
is told so in a popup. They do what the panel does — the same rules, the customer told the same way, whoever decided
first standing; a decision made elsewhere takes the buttons off.

- **A receipt** — «✅ تایید» approves it and delivers the order; a delivery that fails says why in a popup, and «خطاها»
  hears it with its retry. «❌ رد» offers «❌ رد بدون توضیح», «✍️ رد با نوشتن دلیل» and «⬅️ بازگشت»: with a reason, the bot
  asks under the receipt, and your **reply to that question** (words, 300 characters at most) is the customer's reason.
  An admin never approves their own payment: another admin does.
- **A failed delivery** — «🔁 تلاش دوباره برای تحویل» delivers it again; failing again, the popup says why, and the new
  report takes the button over. A delivery that works later, from anywhere, is said under the failure.
- **An agency request** (the main bot's group) — «✅ تایید» offers a button per level and approves with the program's
  credit; «❌ رد» rejects it, without a note. An admin never approves their own request. An approval the shop refuses
  as the request stands — the agency would end after 17 January 2038 («۲۸ دی ۱۴۱۶»), say — says why in a popup and
  keeps the request's buttons, for another try once the program's terms are put right.
- **A ticket** — your reply to any of a ticket's reports, in words or a photo with a caption, is the answer; «🔒 بستن
  تیکت» closes it ([Support tickets](Support-Tickets.md#from-the-report-group)).
- **A customer** — see below.

### A customer's card

«⚙️ مدیریت کاربر», under every newcomer's report in «کاربران جدید», posts the customer's card under it; for anyone else,
write `/manage` and who in the group, in any topic: their Telegram id (`/manage 123456789`), their username
(`/manage @ali`) or their number in the shop (`/manage #12`), in Persian or Latin digits. It does from the group what
the panel's customer page does ([A customer's page](Customers.md#a-customers-page)).

**The front** says who they are: their phone, since when and when last seen, their wallet, their services (running, of
all) and paid orders, their own discount, their role, whether they are banned (and whether they blocked the bot), their
groups and who brought them. Its buttons open the card's sections — each rewrites the card in place, with «⬅️ بازگشت»
back to the front:

- **«🛍 سرویس‌ها»** — their services, newest first, eight a page: its state (🟢 running, 🔴 ended, ⏸ switched off,
  ⚫️ gone from its panel), the client's name, the plan, the server and its end. A service opens with its usage, end,
  devices and link, and what its state allows ([A service's dialog](Services.md#a-services-dialog)): «🔄 همگام‌سازی»;
  «➕ زمان و حجم» — 7 or 30 days, 10 or 50 GB, or «✍️ مقدار دلخواه» (reply «30 10» for 30 days and 10 GB) —, asked
  first, the customer told, and given once: its «✔️ بله» pressed twice, or by two admins, says it was done already (in
  an agent's shop, their traffic is drawn once); «⏸ غیرفعال» (asked first) / «▶️ فعال»; «🗑 حذف», asked first: the
  client goes off its panel too. While an order of the service is open, the service says which one holds its delete.
- **«👛 کیف پول»** — the balance, an agent's credit, the ledger's latest five lines and who wrote them. «➕ افزایش
  موجودی» / «➖ کاهش موجودی» ask for the amount — any digits, «۵۰ هزار تومان» too — and, on a second line, a note the
  customer reads in their wallet's history ([The wallet](Customers.md#the-wallet)).
- **«➕ سرویس دستی»** — a service made for them, as the panel's «افزودن سفارش» makes one: a plan (those that can be
  delivered now), a server, how it is paid — «🎁 رایگان», «👛 از کیف پول» or «💵 پرداخت بیرون از فروشگاه» at the plan's
  price, or «✍️ مبلغ دیگر» —, then the summary and «✔️ بله، ساخته شود». It is delivered at once and the customer told; a
  delivery that fails waits for its retry in «خطاها». «بله» pressed twice makes one order
  ([Orders support makes](Orders-And-Payments.md#orders-support-makes)).
- **«🏷 تخفیف»** — their own discount: «بدون تخفیف» or 5% to 50% at a press, or «✍️ درصد دیگر»
  ([A customer's own discount](Discounts.md#a-customers-own-discount)).
- **«👥 گروه‌ها»** — a switch per customer group, ✅ for the groups they are in, «همکار» beside a group with agent
  prices; each press puts them in or takes them out at once — as the switch showed it, so a double tap, or two admins
  at once, leaves it so ([Groups](Customers.md#groups)). The front lists the first groups that fit, then how many more.
- **«🧾 سوابق»** — their latest ten orders, payments and tickets, and their referrals — who brought them, whom they
  brought and who of those bought, the commission it earned them —, each with its total. The purchases' total leaves
  wallet top-ups out, and the payments' the wallet's own payments: money is counted once.
- **«🔐 ورود به سایت»** — how they sign in to the website: Telegram, email, a password, Google, two-factor, the devices
  signed in. «🔓 خاموش کردن ورود دو مرحله‌ای» (they are told) and «🚪 خروج از همه دستگاه‌ها» (nobody is told), each asked
  first.
- **«🎖 مدیر ربات شود» / «🎖 برداشتن نقش مدیر»** — the bot's admin role, and **«⛔️ مسدود شود» / «✅ رفع مسدودی»** — a
  ban, each asked again first ([the bot's admins](Customers.md#the-bots-admins), [Banning](Customers.md#banning)).
- **«🔄 تازه‌سازی»** — the front again, as the account now stands.

**Typed answers** — an amount, a percent, days and traffic — are asked by the bot in a message under the card, naming
the admin who pressed: write the answer as **a reply to that message**. A good answer rewrites the card and the
question goes; a wrong one is told why, and the question stays for another reply. Any bot admin may answer it, once: a
second reply to a question already answered — before it is gone, or by another admin — does nothing and says so; press
the card's button again for another change.

Every press rewrites the card as the account now stands, so whoever reads the group sees the same; the panel shows the
change as well, and the log keeps who made it. From the group an admin never changes **their own account** nor **an
agent's** — the card shows them with «🔒» and why, and only what reads: services, wallet, history, website sign-in —,
never bans an admin (take the role first), puts one in a «همکار» group, changes an admin's own discount or website
sign-in, or makes an admin of someone with their own discount or in a «همکار» group (take those off first, or make
them an admin from the panel): those are the panel's. `/manage` works in the connected report group alone — written in another group it does
nothing — and, like the buttons, for the bot's admins alone. An agent's group manages that shop's customers only.

**Write as yourself**: a message sent anonymously, as the group ("Remain anonymous"), is refused — the bot cannot tell
who wrote it.

**A busy group** never holds up an admin. A chat that sends the bot more than 20 updates in 10 seconds is flooding it,
and what it sends is left unanswered while it keeps on; in the group the bot counts only what is meant for it — a press
of its buttons, a command, a reply to one of its messages —, never the members' talk among themselves.

## Pace

While a group is connected and its topic is on, a report is kept with what it reports — a sale, a receipt and its
verdict, a delivery, a ticket's turn, a server going down or coming back, a newcomer —, in one write: the change is never
kept without its report waiting to go, and a report the database will not keep fails the change with it.

Telegram takes about 20 messages a minute in a group; the shop sends 18 at most — receipts and failed deliveries
first, then the rest, reviews last; within each, the oldest first. A burst of one ticket's messages folds into one report. While
Telegram asks to wait, or the group has a problem, the reports wait and nothing is lost; a report not sent within a day
is given up. The reports go out after each update the bot handles and every minute by the scheduler. The day and the
minute are defaults, set in [«زمان‌بندی کارها»](Running-In-Production.md#the-task-schedule) — as is how long a sent report is
kept (a week), so a reply to it is still known.

**A picture that cannot go with its report** holds nothing up: the report goes as text, its buttons working as ever,
with a line under it that says why, and the picture is on the panel:

| The picture | The line under the report |
|---|---|
| A receipt whose customer deleted their message, or blocked the bot | «🖼 تصویر کپی نشد؛ در پنل مدیریت دیده می‌شود.» |
| One uploaded on the website or a panel, which Telegram would not take | «🖼 تصویر فرستاده نشد؛ در پنل مدیریت دیده می‌شود.» |
| One gone from the shop's files | «🖼 فایل این تصویر روی سرور پیدا نشد.» |

## Watch out for

- **Nothing reported while no group was connected**, or a topic was off, is sent later.
- **A deleted topic** is made again with its next report; a closed one is opened again.
- **An agent's group** hears its own shop's reports alone — its sales, renewals, top-ups, receipts, newcomers, failed
  deliveries, tickets and reviews, and its own shop's backup —, never a server's, nor the agency's: those are the main
  bot's group's.
- **Everyone in the group can download a backup**, and a backup holds the shop's customers: keep the group to your team,
  or switch «پشتیبان‌گیری» off.
