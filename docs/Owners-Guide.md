# Owner's guide

The shop is run from a panel: the owner's at `/admin/`, and each agent's — a reseller with a bot and a shop of their own
— at `/agent/` ([The panels](Panels.md)). These pages go through the panels a screen at a time: what each is for, how
the common tasks are done, and what to watch out for. The panels speak Persian; their words are quoted here in «» as
they show them, and a path such as «فروش › پرداخت‌ها» is a group of the sidebar and its page.

Everything here works the same in an agent's panel for the agent's own shop, except where a page says it is the owner's
alone: the servers, the agents, the mass gift and the installation's settings.

## Setting a shop up

A new shop sells once it has a bot, a server, a plan and a way to pay. In order:

1. **The bot** — its token goes in «تنظیمات پنل › ربات تلگرام» (the installer may have taken it already), and Telegram
   is pointed at the shop with «ثبت Webhook» on the same screen — or a poller runs the bot
   ([how the bot receives updates](Running-In-Production.md#how-the-bot-receives-updates)). The dashboard's system card
   says when it gets its updates.
2. **A server** — «سرورها» › «افزودن سرور»: a VPN panel's address and an API token, tested, then saved; then mark which
   of its inbounds the shop sells on ([Servers](Servers.md)).
3. **A plan** — «فروشگاه › پلن‌ها» › «افزودن پلن»: its name, price, traffic, days and devices, and the server it is sold
   on ([Plans](Plans.md)). Categories are optional.
4. **A way to pay** — the wallet is built in; for card-to-card payments add your card in «فروشگاه › روش‌های پرداخت»
   ([Payment methods](Payment-Methods.md)).
5. **The bot's rules and words** — the support contact, required channels, top-ups, renewals and reminders in
   «تنظیمات ربات» ([Bot settings](Bot-Settings.md)); the welcome, the texts and the menu in «ربات»
   ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)).
6. **The report group** — a Telegram group where sales, receipts, failed deliveries and tickets reach the shop's admins,
   with buttons to act on them ([Report group](Telegram-Group.md)). The buttons answer the bot's admins alone: start
   the bot from your own Telegram account, then «فروش › کاربران» › your row's menu › «مدیر ربات کردن».
7. **Then, as the shop grows**: the [referral program](Referrals.md), [agents](Agency.md) with bots of their own, and a
   [website](Website.md) of the shop's own.

Try it as a customer before telling anyone: `/start` the bot, buy the cheapest plan with a card, send a receipt, and
approve it in the panel.

## Day to day

| What | Where |
|---|---|
| What waits on you | The dashboard's «نیازمند توجه»: receipts to review, orders stuck, tickets waiting on an answer, reviews to decide, unpaid orders, servers whose panel failed, services ending soon — each a link to its list ([The panels](Panels.md#the-dashboard)) |
| Receipts | «فروش › پرداخت‌ها», the «در انتظار بررسی» tab — or «✅ تایید» and «❌ رد» under the receipt in the report group ([Orders and payments](Orders-And-Payments.md)) |
| Paid orders not delivered | «فروش › سفارش‌ها», the «نیازمند رسیدگی» tab: retry once the server is back ([Orders and payments](Orders-And-Payments.md#orders)) |
| Customers' questions | «پشتیبانی» — or a reply in the report group's «تیکت‌ها» topic ([Support tickets](Support-Tickets.md)) |
| Reviews to publish | «نظرات», the «در انتظار بررسی» tab ([Reviews](Reviews.md)) |
| One customer | Their page: their name anywhere in the panel leads there ([Customers](Customers.md)) |
| One service | «فروش › اشتراک‌ها»: its link, usage, days and traffic added, switched off, moved, deleted ([Services](Services.md)) |
| A server down | Its page says why; its services' days can be made good afterwards ([Servers](Servers.md)) |
| News for everyone | `/broadcast` in the bot ([Broadcasts](Broadcasts.md)) |

The menu counts what waits beside its entries — «پشتیبانی», «نظرات», «سفارش‌ها», «پرداخت‌ها» —, and every list follows the shop
live: there is no need to reload.

## The pages

| Page | What it covers |
|---|---|
| [The panels](Panels.md) | Signing in, the shop on screen, the menu, the dashboard, lists, live updates, failures |
| [Servers](Servers.md) | The VPN panels the shop sells on: adding one, its inbounds, its health, days and traffic for its services |
| [Plans](Plans.md) | Plans, the servers they are sold on, and their categories |
| [Payment methods](Payment-Methods.md) | The wallet, card-to-card, and the automatic approval of receipts |
| [Customers](Customers.md) | The customers, a customer's page, bans and the bot's admins, groups, the wallet |
| [Orders and payments](Orders-And-Payments.md) | Receipts, approvals, rejections, refunds, reminders, stuck orders |
| [Services](Services.md) | Customers' services: read, extended, switched, moved, deleted — and what runs by itself |
| [Support tickets](Support-Tickets.md) | Answering customers, from the panel and from the report group |
| [Reviews](Reviews.md) | Customers' reviews from the website: approved, rejected, deleted |
| [Referrals](Referrals.md) | Invite links and commissions |
| [Agency](Agency.md) | Agents with bots and shops of their own, their levels and their traffic |
| [Broadcasts](Broadcasts.md) | Messages to many customers, and gifts of days and traffic to all of them |
| [Report group](Telegram-Group.md) | The shop's Telegram group of reports, and acting from it |
| [Bot settings](Bot-Settings.md) | The bot's switches and rules |
| [Bot texts and keyboards](Bot-Texts-And-Keyboards.md) | Every word the bot says, and its menu ([reference](Bot-Texts-Reference.md)) |
| [Website](Website.md) | A shop's own website: its address, sign-ins, captcha, reviews, and the bot's admins working the shop from it |
| [Panel settings](Panel-Settings.md) | The installation's own settings, the owner's login, the look |

The customer's side of every screen — what the bot shows and says — is the [Bot guide](Bot-Guide.md).
