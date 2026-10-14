# Bot settings

«تنظیمات ربات»: the bot's switches and rules — every shop its own, the same page in your panel and an agent's —, a
section each in the settings column. Each card is saved on its own («ذخیره», «بازگردانی تغییرات» — both held while
nothing differs from what is saved), every refusal under its field at once, and the bot follows a saved change within
seconds; leaving a card with a change unsaved asks first. The agency's
rules are the main shop's, on the agents page ([Agency](Agency.md)).

Some programs keep their part of these settings on their own pages: discount codes ([Discounts](Discounts.md)),
cashback and the nightly draw ([Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md)), gift codes and the lucky
wheel ([Gift codes and the lucky wheel](Gift-Codes-And-Lucky-Wheel.md)), and free test accounts, on «اکانت تست ›
تنظیمات» ([Test accounts](Test-Accounts.md#the-shops-rules)).

## General

«عمومی»:

- **«ربات فعال باشد»** (on) — off, every customer gets «ربات خاموش» (`⛔️ ربات فعلا غیرفعال است. …`, a text you may reword)
  to whatever they send, the menu taken away, **and the shop's website takes no orders** (a purchase, a renewal, a
  top-up, a receipt — «فروشگاه فعلا سفارش نمی‌گیرد؛ …»). The bot's admins still pass, to try it. The page says the bot is
  off above every section. For a pause, or when the servers are down.
- **«تایید شماره موبایل»** (off) — on, a customer must send their own Telegram number — one tap of «📱 ارسال شماره موبایل»
  — before anything else: a number typed, or someone else's card, is refused. Asked once; the number shows on their
  customer page.
- **«راه ارتباط با پشتیبانی»** — a handle or link, 190 characters at most, shown on the bot's «☎️ پشتیبانی» above its
  ticket buttons («نیاز به کمک دارید؟ با ما در تماس باشید: …»); empty, the screen offers the tickets alone. The shop's
  website gets it as a link when it is one: `@handle`, an `https://` address, `t.me/…`, or a phone number.

## Service usernames

«نام کاربری سرویس» — what a service's client is called on its panel: the name the panel's client list shows, and the
bot's service screen («👤 نام کاربری سرویس»; «نام سرویس» there is the plan's).

- **«الگوی نام کاربری سرویس»** (`{username}_{seq}`: amir_1, amir_2 …) — the pattern a sold service's name follows.
- **«الگوی نام کاربری اکانت تست»** (`test_{username}_{seq}`: test_amir_3) — a free test's.
- **«نام رندوم»** (off) — on, a purchase's checkout offers «🎲 رندوم»: six letters and digits drawn at random, another
  at each tap.
- **«نام دلخواه»** (off) — on, it offers «✏️ دلخواه»: the customer types the name — 3 to 32 English letters, digits,
  `_` and `-` —, one no service on that server has and its panel takes (PasarGuard refuses two of `_` and `-` side by
  side, so the bot asks for another).

A pattern is 48 characters at most: English letters, digits, `_ - . @` and the variables below. It must hold `{n}` or
`{random}`, or `{seq}` beside `{username}`, `{tg_id}` or `{user_id}` — `{seq}` alone counts each customer's services
from 1, so every customer's first service would be named alike. Blank is the default. Under each pattern, the name it
would give a sample customer as you type it; a tap on a variable writes it at the end of the pattern you are editing.

| Variable | Stands for |
|---|---|
| `{username}` | The customer's Telegram username, without the @; `USER` when they have none |
| `{seq}` | The customer's own count of services (1, 2, 3 …); customers without a username share one count |
| `{tg_id}` | The customer's Telegram id; `w` and their account number for a customer without Telegram |
| `{user_id}` | The customer's account number in the shop |
| `{n}` | The shop's count of services (1, 2, 3 …) |
| `{random}` | Six random lowercase letters and digits |
| `{date}` | The day it is made, `YYMMDD` on the shop's clock: `261013` |

A count only goes up: a deleted service's number is never given again, and a count moves only while a pattern uses it,
so a pattern changed back picks its count up where it was. A name a service on that server already has — any shop's —
is skipped for the next, 100 in a row at most: past that the delivery fails, saying to check the pattern. So is one
a client made on the panel by hand has, once the panel refuses it — a customer's own name, and the name a service
[moved to another server](Services.md#moving-services) keeps, too —, 10 in a row at most: past that the delivery (or
the move) fails for you to retry. A username too long for the 64 characters a name may have is shortened, never the part that tells the names apart. A name is
made to fit what its server's panel takes: on PasarGuard, two of `_ - . @` side by side become the first alone and a
name under 3 characters gets leading zeros (`{n}` gives `001`). `{n}` is each
shop's own count, so two shops selling on the same servers with one pattern of `{n}` would name alike, the one behind
skipping the other's names: give each its own letters (`a{n}`, `b{n}`). A new pattern names new services: those made
before keep their names. So does a service handed to another customer ([service transfers](#service-transfers)): by the
default pattern it still carries the previous owner's username (`ali_1`), with `{tg_id}` their id — a panel client is
never renamed.

While neither switch is on, the customer is asked nothing. A name they chose that a service — or a client made by hand
on the panel — took before their order was delivered gives way to the pattern ([Bot guide](Bot-Guide.md#buying),
[Store API: buying](Store-API-Buying.md#a-purchase)).

## Channels

«کانال‌ها» — channels or groups a customer must join before using the bot:

- **«عضویت اجباری در کانال»** (off) — the rule. Checked after the phone, for customers only. On with an empty list, it
  asks nothing.
- **«کانال‌های اجباری»** — the list, which changes at once: «افزودن کانال» takes a link (`https://t.me/mychannel`), an
  `@name`, or — for a private channel — its numeric id (`-1001234567890`; an invite link cannot be looked up). **Make the
  bot an admin there first**: it must see the members, and for a private channel make the invite link itself (the
  «افزودن اعضا» right). ▲▼ the order, «بررسی دوباره» to check a channel again, «حذف» to take it off the list (the bot
  stays in the channel).

A customer missing a channel gets a button for each and «✅ عضو شدم», which checks again. Membership is trusted five
minutes once confirmed. A channel the bot can no longer see into lets everyone through, flagged on the list («مدیر
نیست»): fix it and press «بررسی دوباره».

## Wallet

«کیف پول» — the bot's «💰 کیف پول + شارژ»:

- **«حداقل مبلغ شارژ (تومان)»** — 1,000 to 500,000,000, 10,000 by default — `10000`, `10,000` or `۱۰٬۰۰۰`.
- **«مبلغ‌های پیشنهادی (تومان)»** — the amounts offered as buttons, apart by spaces, «،» or commas: 50000، 100000،
  200000، 500000 by default; 8 at most, none below the least; empty, «✏️ مبلغ دلخواه» alone. An amount may set its
  thousands apart (`50,000, 100,000` is two amounts); a comma between digits that are not a group of three splits them.

A customer picks an amount, or types any other from the least to 500,000,000 (Persian digits, separators and «تومان»
are fine). A top-up is paid by any way to pay but the wallet.

## Renewal

«تمدید سرویس», two cards:

- **«تمدید سرویس»** — the rule of every renewal, the customer's and the automatic one, in three parts:
  - **«حجم باقی‌مانده به دوره جدید منتقل شود»** (off), for «تمدید پلن فعلی»: off, the traffic the paid period leaves
    unused stays usable until that period ends, then goes, and the new period starts with the plan's traffic; on, it is
    added to the new period. Renewing early never takes anything away before its time.
  - **«آستانه مجاز تمدید»** — from when a customer may renew a running service, in the bot and on the website: «روز
    مانده به پایان» (0 to 365) — once its end date is that near —, and «درصد حجم مانده» (0 to 100) — once that share of
    its traffic or less is left. With both, whichever comes first; with both 0 (the default), any time. An ended service
    may always be renewed; one waiting for its first connection, or that never ends, has no end date to come near and is
    let in by its traffic alone. Before then the bot's «♻️ تمدید سرویس» still lists it, and a tap says from when
    («⏳ تمدید این سرویس از … ممکن است.», a [bot text](Bot-Texts-And-Keyboards.md) you may reword). Automatic renewal
    goes by its own days.
  - **«روش‌های تمدید»** — how a renewal adds its plan, a switch each, one at least on:
    - **«تمدید پلن فعلی»** (on): the plan's days on top of the days left, its traffic on top of the service's — the
      traffic left as the switch above says;
    - **«ریست حجم + افزایش روز»**: the plan's days on top of the days left; the traffic counted afresh from the plan's,
      what was left lost;
    - **«ریست زمان + انتقال حجم»**: the term starts again from now with the plan's (the days left lost); the plan's
      traffic on top of what is left, whatever the switch above says.

    Every method brings the plan's device limit, the extra devices bought for the period before gone with it — by the
    first two at the end of the period in use, by the third at once ([Plans](Plans.md#devices-and-renewals)).

    With more than one on, the customer picks one on the renewal's checkout (a button each in the bot; the website asks
    the same) and sees the end date and traffic it leaves; the checkout opens on the first one on, and the price is the
    same by each. **«روش تمدید خودکار»** — one of those on — is the one automatic renewal uses; by «ریست زمان + انتقال
    حجم», which drops the days left, it waits for the last half hour before the service's end (below). Switching a
    method off takes it away from checkouts at once; a checkout drawn before it shows itself again before anything is
    paid. A renewal's order says the method it was by in its dialog («روش تمدید»,
    [Orders](Orders-And-Payments.md#orders)), and the report group's «تمدیدها» names it unless it was «تمدید پلن فعلی».
- **«تمدید خودکار»** — the customer's switch on a service's screen («🔁 تمدید خودکار»): «چند روز پیش از پایان سرویس» (1 to
  30, 2 by default) the plan's price is taken from their wallet and the service renewed. «برای سرویس‌های جدید روشن باشد»
  (off) starts services bought from then on with it on. The switch is offered on a service that is active, ends some
  day and still has its plan — a plan whose term is those days at least (an hourly plan's never is: a renewal adds its
  term, and a shorter one would leave the service due again at once) — **and only while the wallet is switched on**
  («روش‌های پرداخت»). A wallet short of it is
  told once, and tried every half hour until it can pay; the panel's word on the end is asked first, so nothing is
  charged while a panel does not answer. Each end date is renewed once: a renewal since its days began — automatic or
  the customer's own, refunded since or not — that left the end where it was is not followed by another. By
  «ریست زمان + انتقال حجم» the renewal waits for the last run before the end — at most 35 minutes ahead —, so the term
  it starts afresh throws away no more than that, while a wallet short of it is still told «چند روز پیش از پایان
  سرویس»; it then has to hold the price at that last run.

## Extra traffic, time and devices

«حجم، زمان و کاربر اضافه» («خرید اضافه» in the settings column) — what a customer buys on top of a service they have,
each on its own, from the service's screen in the bot ([Bot guide](Bot-Guide.md#extra-traffic-time-and-devices)) or on
the shop's website ([Store API](Store-API-Buying.md#extra-traffic-time-and-devices)): more traffic, by the GB, more
time, by the day, and more devices at once («کاربر اضافه» — the service's IP limit, «تعداد کاربر» on a plan), by the
device. Paid like a purchase — the wallet adds it at once, a card waits for its receipt —, each an order of its own
(«حجم اضافه», «زمان اضافه», «کاربر اضافه» on [the orders screen](Orders-And-Payments.md#orders)).

- **«فروش حجم، زمان و کاربر اضافه»** (off) — the switch, for all three. Off, nothing is sold: not at these prices, nor
  at a plan's own.
- **The shop's prices** — a block for each, «حجم اضافه», «زمان اضافه» and «کاربر اضافه», side by side where there is
  room:
  - «قیمت هر گیگابایت (تومان)» / «قیمت هر روز (تومان)» / «قیمت هر کاربر برای 30 روز (تومان)» — the price of one unit,
    whole Toman (`3000`, `3,000` or `۳٬۰۰۰`), up to 100,000,000. Empty, that extra is sold only as the amounts below
    with a price of their own, and none is typed.
  - «حجم‌های پیشنهادی» / «زمان‌های پیشنهادی» / «تعدادهای پیشنهادی» — the amounts the customer gets as buttons, 8 at most
    («افزودن مقدار», ✕ to take one away): an amount — 1 to 10,000 GB, 1 to 365 days, 1 to 20 devices, each once — and
    its own price. Left empty, its price is the unit price times the amount, which the field shows; without a unit
    price, every amount needs its own. A row left empty is nothing.
  - «حجم دلخواه» / «زمان دلخواه» / «تعداد دلخواه» — «کمترین» and «بیشترین»: with a unit price, the customer may type any
    amount between them instead (1 to 100 GB, 1 to 30 days and 1 to 5 devices by default; 10,000 GB, 365 days and 20
    devices at most). Without one, they are kept unused.
  - «پیش‌نمایش در ربات» — the buttons the prices make as they are typed, each with what it costs, and what may be typed —
    or that nothing of that extra is sold. An amount that would have no price is drawn «بدون قیمت» and said so: the save
    refuses it.
- **Devices are priced for 30 days** and each service pays its share for the days left of its period, which is how long
  they last: the price of the amount × days left ÷ 30, rounded up to whole Toman — a service 15 days from its end pays
  half, one 60 days away twice. The days are counted to its end date — or, renewed before then, to the end of the period
  it is in, when the renewal takes over (a part of a day is a day, at least 1); a service still waiting for its first
  connection counts its whole term, one that never ends a full 30. The bot, the website and a discount code's check all
  show and charge that same share. A renewal brings its plan's device limit — at the end of the period in use, or at
  once for a service whose period is over ([Plans](Plans.md#devices-and-renewals)) — and the customer buys devices again
  for the new period; a refund takes them back while their period lasts.
- **A plan's own prices** stand in for these, for its services alone
  ([Plans](Plans.md#extra-traffic-time-and-devices)); these are every other plan's.

What counts as a change is what the save would keep: an empty row, the rows' order or how a number is written is none.
Traffic is offered for a service with a quota, days for one with a term — neither for one switched off by support, nor
for one ended on both counts, which a renewal is for, nor for a free test ([Test accounts](Test-Accounts.md)). Devices
are offered for a service with a device limit (an unlimited one takes none) whose time and traffic have not run out (a
renewal of one ended would replace their period at once), on a server whose panel enforces the limit — 3x-ui does; PasarGuard has no IP limit, so its services are offered none. In an
agent's shop the extra traffic its customers buy comes out of the agent's traffic, and no more is offered than is left
([Agency](Agency.md)); extra days and devices cost it nothing.

## Reminders

«یادآوری» — both off until switched on, each said once while the service stays past its line, and again after a
renewal or more traffic:

- **«یادآوری نزدیک شدن تاریخ پایان»** — «چند روز پیش از پایان سرویس», 1 to 30, 3 by default. A service whose «تمدید
  خودکار» is on hears from the renewal instead, and one whose term is no longer than those days (an hourly plan's) is
  not reminded at all: it would be the moment its clock started.
- **«یادآوری تمام شدن حجم»** — «بعد از مصرف چند درصد حجم», 50 to 99, 80 by default. Unlimited services never.

Each comes with «📊 مشاهده سرویس». The services are read from their panels every 15 minutes, and the reminders follow.
A free test is never reminded.

## Ended services

«سرویس‌های منقضی‌شده», the card «حذف سرویس‌های منقضی‌شده» — whether a service that ended goes by itself:

- **«حذف خودکار سرویس‌های منقضی‌شده»** (off) — on, a service that ended — its time or traffic ran out, or its panel no
  longer has it — is removed from its panel and the shop once it has been over the hours below, quietly: the customer is
  not told, and the orders that sold it keep their record. Off, an ended service stays until the customer renews it or
  you delete it.
- **«فاصله حذف (ساعت)»** — 0 to 8760 (a year), 24 by default, counted from when the service ended: the deadline it
  passed, else when its panel said its traffic was used up or its client gone. `0` removes it at once. The field's hint
  says what the number means («۱ روز بعد از پایان حذف می‌شود.»); it is held while the switch is off.

Never removed:

- a service switched off by support;
- one with an order about it still open — waiting for its payment (a renewal whose receipt waits for review), being
  delivered, or failed and waiting for support's retry;
- one renewed or extended since the shop last read it: each is read from its panel again just before it goes. One that
  runs again starts its hours anew if it ends again.

The services are checked every 15 minutes, right after they are read from their panels; one whose panel the shop leaves
alone after a failure waits for a later check, and one its panel no longer has goes without asking it
([Services](Services.md#what-runs-by-itself)). While the rule is on, the customer sees when an ended service goes: its
screen in the bot and its renewal's checkout say «🗑️ اگر این سرویس تا … تمدید نشود، حذف می‌شود.» (a bot text you may
reword or empty), and the
shop's website reads the moment as `removes_at`. A service's dialog in the panels shows it as «حذف خودکار». Free tests
go by their own rule, on «اکانت تست › تنظیمات» ([Test accounts](Test-Accounts.md#the-clean-up)).

## Services added by their link

«افزودن سرویس با لینک», the main shop's alone — whether a customer may add a client you made by hand on a panel to their
account by sending its subscription link or one of its configs:

- **«مشتری‌ها بتوانند سرویس را با لینکش اضافه کنند»** (off) — on, the bot takes such a link anywhere it waits for
  nothing else, and «سرویس‌های من» shows «➕ افزودن سرویس»; the website may offer it too. The first customer to send a client's link gets it, and
  every one is reported in the report group's «خریدها». Off, a link sent to the bot is an unknown message.

The whole of it — what the customer sends, who gets it, putting a wrong one right, renewing one — is in [Services added
by their link](Service-Claims.md).

## Service transfers

«انتقال سرویس», in every shop — whether a customer may hand one of their services to another customer of the shop, free:

- **«مشتری‌ها بتوانند سرویسشان را به مشتری دیگری منتقل کنند»** (off) — on, a service's screen in the bot shows «🔁
  انتقال سرویس»: the customer sends the recipient's numeric Telegram id, sees their name, and confirms; the website may
  offer it too. Off, only support transfers a service, from its dialog
  ([Services](Services.md#handing-a-service-to-another-customer)) — which support may whatever this says.

The service goes as it is — its link, end and traffic, and its name on the panel, which by the default pattern carries
the previous owner's username ([service usernames](#service-usernames)) —, its «تمدید خودکار» off; the recipient is
told in the bot —
that the previous owner keeps the link until they tap «تغییر لینک» —, and every transfer is reported in the report
group's «خریدها». The recipient must have started the bot once. A free test, a service gone from its panel, and one with
an order the customer made still open (waiting for its payment or receipt, being delivered, or failed and waiting for
you) stay where they are. A customer looks up at most 10 ids in 10 minutes and hands on at most 5 services an hour.

## Referrals

«زیرمجموعه‌گیری» — the program's switch, and in four parts: its commissions (the share, a renewal's own, «فقط اولین پرداخت هر
زیرمجموعه»), «هدیه شروع», «جایزه دعوت» and «هشدار زیرمجموعه‌گیری فیک» ([Referrals](Referrals.md)).

## The report group

«گروه گزارش‌ها» — connecting the shop's Telegram group, and «بخش‌های گزارش», a switch per topic, all on by default
([Report group](Telegram-Group.md)).

## The QR card

«کد QR»:

- **«ساخت کد QR»** (on) — a delivered service, a moved one, a new link and «🔗 لینک اشتراک» come as a picture: the
  subscription link as a QR code the customer scans into their app, the service's text as its caption. Off — or without
  PHP's GD, or a caption too long for Telegram — the text goes alone, the link in it.
- **«پس‌زمینه کد QR»** — the picture the code is drawn on: a dark one with a white square in the middle ships with the
  shop. «آپلود تصویر جدید» takes a PNG, JPG or WebP of 5 MB at most (16 megapixels; a side over 2048 pixels is shrunk) —
  a square one, the white square in its middle, works best —, at once; «بازگشت به پیش‌فرض» puts the shipped one back.

## Watch out for

- **The bot's admins are customers given the role** on «کاربران» ([Customers](Customers.md#the-bots-admins)) — your panel
  login is not one of them.
- **Reminders, automatic renewals, the removal of ended services and approvals by themselves need the scheduler**
  running — the cron or the poller ([Running in production](Running-In-Production.md)).
- **A removed service is gone for good**: its customer can no longer renew it, and a new purchase gets a new link. Give
  «فاصله حذف» hours enough for a customer to come back and renew.
- **Viewing an agent's shop**, you edit that agent's bot.
