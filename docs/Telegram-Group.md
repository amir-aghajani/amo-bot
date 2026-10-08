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
| «خریدها» | A service sold and delivered: the customer, the plan, the location, the service, the amount and the way it was paid, the referrer's commission |
| «تمدیدها» | A renewal, and the new end |
| «شارژ کیف پول» | A wallet top-up, and the balance |
| «رسیدها» | A copy of each card receipt with what it pays for, «✅ تایید» and «❌ رد»; then its verdict as a reply to it |
| «کاربران جدید» | A newcomer, and who brought them |
| «خطاها» | A paid order whose delivery failed, with why and «🔁 تلاش دوباره برای تحویل»; in the main bot's group also a server's panel that stopped answering («🔴») and answers again («🟢») |
| «نمایندگی» | The main bot's group alone: a request to become an agent with its buttons, then the verdict; a purchase of traffic |
| «تیکت‌ها» | A ticket opened, then every later message, its closing, opening again and rating — [Support tickets](Support-Tickets.md#from-the-report-group) |
| «نظرات» | A review written on the website: its number, name, stars, where they use the service from, who wrote it, the words — no buttons: it is decided on the panel's «نظرات» ([Reviews](Reviews.md)) |

«بخش‌های گزارش», on the same screen, has a switch for each (all on). A topic switched off sends nothing, and its topic
stays in the group.

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
  credit; «❌ رد» rejects it, without a note. An admin never approves their own request.
- **A ticket** — your reply to any of a ticket's reports, in words or a photo with a caption, is the answer; «🔒 بستن
  تیکت» closes it ([Support tickets](Support-Tickets.md#from-the-report-group)).

**Write as yourself**: a message sent anonymously, as the group ("Remain anonymous"), is refused — the bot cannot tell
who wrote it.

## Pace

While a group is connected and its topic is on, a report is kept with what it reports — a sale, a receipt and its
verdict, a delivery, a ticket's turn, a server going down or coming back, a newcomer —, in one write: the change is never
kept without its report waiting to go, and a report the database will not keep fails the change with it.

Telegram takes about 20 messages a minute in a group; the shop sends 18 at most — receipts and failed deliveries
first, then the rest, reviews last, the oldest first. A burst of one ticket's messages folds into one report. While
Telegram asks to wait, or the group has a problem, the reports wait and nothing is lost; a report not sent within a day
is given up. The reports go out after each update the bot handles and every minute by the scheduler.

## Watch out for

- **Nothing reported while no group was connected**, or a topic was off, is sent later.
- **A deleted topic** is made again with its next report; a closed one is opened again.
- **An agent's group** hears its own shop's sales, receipts, failed deliveries, tickets and reviews — not of servers,
  nor of agency requests.
