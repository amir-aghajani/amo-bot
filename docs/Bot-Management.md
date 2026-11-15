[فارسی](fa/Bot-Management.md)

# Managing the shop from the bot

The shop can be run from its bot's private chat as well as from the panel. «⚙️ مدیریت» opens the panel's sections as
the bot's own menus — receipts and payments, orders, services, customers, tickets, plans, discounts, the bot's settings,
the servers, the panel's own settings — on a phone, with no browser and nothing to install. Every screen reads the
panel, and every change goes through the panel's own rules: the same checks and refusals, the same notices to
customers, the same log. Nothing is a web page: lists, cards, buttons and questions in the chat.

## Who manages

The bot asks it on every message: a role taken away, a ban, a switch turned off or an account let go ends it at once.

| Who | In which bot | What they get |
|---|---|---|
| You, the owner | The main bot, from a Telegram account you linked ([below](#your-own-telegram-account)) | Every section of your panel, in any shop |
| An agent | Their own bot, from their own Telegram account | Their panel's sections, in their shop |
| The shop's admins | That shop's bot, while «مدیران ربات» lets them in | The daily work, and what you grant them ([below](#the-shops-admins)) |

Whoever manages the shop passes the bot's own conditions — its off switch, the phone, the channels —, so nobody who
manages is ever locked out of the management. A banned account manages nothing. Nobody else sees any of it: `/admin`
from a customer is an unknown command.

### Your own Telegram account

Your panel's login stays as it is. To manage from the bot, tell the shop which Telegram account is yours:

1. In the panel, «تنظیمات پنل › ورود به پنل», card **«مدیریت از ربات»** › «ساخت لینک», then type the panel's
   password under «رمز عبور فعلی» and confirm with «ساخت لینک» again.
2. The link shows once, with «کپی لینک» and «باز کردن در تلگرام». Open it **from the Telegram account that should be
   the owner**: the main bot opens and says «👑 این حساب تلگرام حالا مالک فروشگاه است: «⚙️ مدیریت» را بزنید، یا /admin
   بفرستید.».

A few seconds later, the card in the panel lists the account.

- **The link works once, for 10 minutes**, and a new one ends the last. It is shown only when it is made: left the page
  without it, make another. It works while the main bot is switched off, asks for the phone or asks to join channels. A
  link used, out of time or opened in an agent's bot says «این لینک دیگر کار نمی‌کند …».
- **The panel's password comes first**, so a panel left signed in, or a session someone else got hold of, links no
  account on its own. A wrong password counts as a failed sign-in to the panel.
- **The main bot's @username must be known**: until its token has been checked in «تنظیمات پنل › ربات تلگرام», «ساخت
  لینک» says so.
- **Linking makes the account the bot's admin too**, as an agent is in their own bot: the report group's buttons answer
  it.
- **Up to five accounts** stay linked: a sixth ends the oldest one's link, and the admin role its link gave it.
- **A linked account has your login's whole power** — every section, every shop. Link only your own accounts, and keep
  them safe (Telegram's two-step verification).
- **To let one go**, «برداشتن» beside it on the card (asked first), or in the bot «⚙️ تنظیمات › 👑 حساب تلگرام مالک».
  It is the owner no more from its next message, and the admin role its link gave it goes with it. An account that was
  the bot's admin before you linked it keeps that role ([Customers](Customers.md#the-bots-admins)).
- **Changing the panel's username or password lets every account go**, and ends a link not opened yet — from «ورود به
  پنل» or through the sign-in page's recovery alike: whoever had the old login keeps nothing in the bot either. The
  admin roles the links gave go too; a role you set by hand in «کاربران» stays. Link your accounts again afterwards.

«👑 حساب تلگرام مالک», in the bot, lists the linked accounts — yours marked «(شما)» — and makes a link for another:
«➕ وصل کردن حساب دیگر», then open the link from that other account. The bot asks no password there: only an account
linked already has the screen. Letting your own account go warns that the bot's management closes for it until the
panel links it again.

### An agent

An agent manages their shop from their own bot, from the Telegram account the agency is theirs by — nothing to set up.
They are their bot's admin by themselves, and get their panel's sections — everything but the servers, the agents, the
mass gift and the installation's settings — and their account with the main bot ([Agency](Agency.md)).

### The shop's admins

The shop's admins are its customers given the role — «کاربران», the row's menu, «مدیر ربات کردن»
([Customers](Customers.md#the-bots-admins)). They manage it from its bot while **«تنظیمات ربات › مدیران ربات»** lets them
in — in the panel, or in the bot's «⚙️ تنظیمات › 🛡 مدیران ربات» ([Bot settings](Bot-Settings.md#the-bots-admins)):

- **«کار مدیران از ربات»** (on) — the daily work: the dashboard, receipts and payments, orders, services, customers,
  tickets, reviews, referrals and broadcasts; the plans, categories and customer groups to read. Off, they have no
  «⚙️ مدیریت» and the bot takes no management from them but `/broadcast` and `/emoji`, theirs as before; the report
  group works for them as before.
- **«کارهای بیشتر»** — a switch per grant, all off by default, the website's «مدیران سایت» list with switches of its own:

| Switch | What it opens in the bot |
|---|---|
| «پلن‌ها و دسته‌بندی‌ها» | Plans and categories made, changed, reordered, switched and deleted; the customer groups, and a customer put in a «همکار» group |
| «کیف پول مشتری» | A customer's wallet credited or debited |
| «بازپرداخت» | A payment given back |
| «رد یکجای رسیدها» | The receipts waiting for review rejected together — one at a time is always open |
| «افزایش زمان و حجم سرویس» | A service given days and traffic |
| «افزودن سفارش برای مشتری» | A service made for a customer: free, from their wallet, or paid outside the shop |
| «حذف یا انتقال سرویس» | A service deleted for good, or handed to another customer |
| «امنیت حساب مشتری» | A customer's website account: two-factor sign-in turned off, every device signed out |

The shop's configuration is never theirs: the payment methods, the bot's settings, texts and keyboards, the discounts and
rewards, the test accounts, the website and the mini app, a customer's role and own discount, and who its admins are.
Each shop has its own switches: an agent sets their own bot's admins. Letting admins in on the website is a separate
thing ([Store API: the shop's admins](Store-API-Admins.md#letting-admins-in)).

## Opening it

**«⚙️ مدیریت»** comes under the menu for whoever manages the shop, and nobody else: the last row of a reply keyboard, or
the last button of an inline menu. **`/admin`** (or `/panel`) opens it too.

**One message is the management's window.** A tap changes it in place; `/admin`, «⚙️ مدیریت» or a typed answer sends it
again at the foot of the chat and takes the last one away (Telegram lets a bot delete its messages for two days: an
older one stays). A receipt's picture or the mini app's logo is the screen itself; another picture or a file — a
ticket's picture, the unused gift codes, a backup — comes as a message of its own under it.

**Every screen** says where it is (مدیریت › فروش › کاربران) and ends with «⬅️ بازگشت» to the one above it and
«🏠 مدیریت» to the home. **Each keeps where you left it** — its page, its filter, its search, a half-made form —, so going
to the menu, or using the bot as a customer, loses nothing. Two taps at the same moment are done one after the other,
the second seeing what the first did: nothing is done twice.

## The home

**«⚙️ مدیریت فروشگاه»**: the shop and your seat — «👑 مالک», «🤝 نماینده» or «🛡 مدیر ربات» —, then what waits on a
human now («🔔 در انتظار: ۳ رسید · ۱ تیکت» — receipts, stuck orders, tickets, reviews — or «✅ کاری در انتظار نیست.»),
then the sections you may open, a count beside the ones with something waiting («🛍 فروش» counts the receipts to review
and the stuck orders), and «🔄 تازه‌سازی». An agent has «💼 حساب نمایندگی» there too; the owner has «🏪 فروشگاه: …»
under them ([another shop](#another-shop)). A section you may not open is not there.

| Section | What is in it | Who |
|---|---|---|
| «📊 داشبورد» | The panel's dashboard | Everyone |
| «💼 حساب نمایندگی» | The agent's account with the main bot | An agent |
| «🎧 پشتیبانی», «⭐️ نظرات» | Tickets; reviews | Everyone |
| «🛍 فروش» | «💳 پرداخت‌ها», «🛒 سفارش‌ها», «👥 کاربران», «📡 اشتراک‌ها», «🔗 زیرمجموعه‌گیری», «📩 واریزهای پیامکی» | Everyone |
| «📦 فروشگاه» | «📦 پلن‌ها», «🗂 دسته‌بندی‌ها», «👫 گروه‌های کاربران», «🧪 اکانت تست», «🛠 سرویس دلخواه», «🏦 روش‌های پرداخت» | Everyone (an admin sees the first three; «🛠 سرویس دلخواه» is the main shop's) |
| «🎉 تخفیف و جوایز» | «🏷 کدهای تخفیف», «🔥 فروش ویژه», «🏅 سطوح وفاداری», «💸 کش‌بک», «🎟 کدهای هدیه», «🎡 گردونه شانس», «🎲 قرعه‌کشی» | The owner and an agent |
| «🤖 ربات» | «📣 ارسال همگانی», «🎁 هدیه همگانی», «📝 متن‌های ربات», «⌨️ کیبوردها», «📢 کانال‌های اجباری», «📋 گروه گزارش‌ها» | Everyone (an admin sees the broadcasts) |
| «🖥 سرورها», «🤝 نمایندگان» | Servers; the agency | The owner |
| «⚙️ تنظیمات» | «🤖 تنظیمات ربات», «🛡 مدیران ربات», «🌐 تنظیمات وب‌سایت», «📱 مینی‌اپ تلگرام», «🧰 تنظیمات پنل», «👑 حساب تلگرام مالک» | The owner and an agent (each their part) |

The menus follow the panel's sidebar, and each screen what the panel's page has — its tabs, its fields, its warnings, in
its words. What is in each, in short:

### The dashboard

«📊 داشبورد» — the last 7, 30 or 90 days' revenue, orders and new customers, each beside the period before; every
customer, the active services and those ending soon; «📈 نمودار روزانه», a figure day by day (week by week over 90 days)
with its total, daily average and best day; «🛒 آخرین سفارش‌ها» and «👥 مشتریان جدید». Its «نیازمند توجه» counts what
waits — receipts to review, payments held for support, stuck orders, tickets, reviews, servers whose panel failed,
services ending soon —, each a button to its list at the right tab, its search and filters cleared, or says all is well.
An agent sees where their agency stands (selling suspended, its end or the month's minimum near, traffic short) with a
way to their account; the owner sees the system's state — how the bots get their updates, the scheduler, a newer
version, an unsafe PHP.

### The agent's account

«💼 حساب نمایندگی», an agent's alone, as their panel's account page: their bot and how it stands, why it sells nothing
when it does not, the traffic left, their level's price per GB, their wallet with the shop, its debt and credit, the
terms of selling, and «📊 گردش حجم», the traffic's ledger ten lines a page. Traffic is bought in the main bot:
«🛒 خرید حجم در ربات اصلی» opens it ([Agency](Agency.md#their-traffic)).

### Support and reviews

- **«🎧 پشتیبانی»** — the tickets, those waiting on an answer first, the panel's tabs, a search. A ticket: its facts with
  the customer's rating and note, the conversation five messages a page, the newest first («📄 پیام کامل» shows a long
  one in parts, a picture comes at a tap); **«✍️ پاسخ»** — words, or a picture with its caption, sent once however often
  it is sent again —, «🔒 بستن تیکت» and «🔓 باز کردن دوباره». Opening a ticket reads it, as the panel does
  ([Support tickets](Support-Tickets.md)).
- **«⭐️ نظرات»** — the reviews by the panel's tabs: «✅ تایید», «🚫 رد», «🗑 حذف» (asked) ([Reviews](Reviews.md)).

### Sales

- **«💳 پرداخت‌ها»** — the tabs, «در انتظار بررسی» and «نگه داشته شده» counted, a search, dates and order
  («🎛 فیلتر و ترتیب»). A payment's receipt is the screen: the picture, its facts under it (one sent as a file comes as a
  file). Then what its state allows: approve (a bot admin only while the receipt waits for review),
  «🔎 استعلام از درگاه», «🚫 رد کردن» with or without a reason, «🚫 لغو پرداخت», «🔔 یادآوری به مشتری», retry, refund
  — and, while the shop's Rikhtam is connected, **«📎 پیوست واریز ریختم»**: the deposits Rikhtam holds that no payment
  took, the closest sum first — «🎯» marks the payment's exact sum, «⚠️» one short of the order's own price, which is
  not attached (picking it says why) —, one picked and confirmed, which approves the payment
  ([Payment methods](Payment-Methods.md#through-rikhtam)). The tap is answered at once («⏳ واریز در ریختم پیوست
  می‌شود…»), and what came of it is said over the payment as it now stands; a confirm from a list that changed since —
  stale, or tapped twice — attaches nothing it was not asked to, and shows the deposits afresh. **«⏭ رسید بعدی»** walks
  the receipts waiting, one after another. «☑️ انتخاب رسیدها برای رد» rejects those ticked, or a page; «🚫 رد همه
  رسیدهای در انتظار» every one that was waiting as you confirmed.
- **«🛒 سفارش‌ها»** — the tabs with «نیازمند رسیدگی», the type, dates and order; an order with its payments, its service
  and its customer; «🔁 تلاش دوباره برای تحویل», «🚫 لغو سفارش».
- **«👥 کاربران»** — a search (`#12` is customer 12), the status and role chips, the group and the order. A customer's
  page has what their page in the panel has: their facts, referral and loyalty, their agency; «👛 کیف پول» — the ledger,
  «➕ افزایش موجودی» and «➖ کاهش موجودی» —; «👫 گروه‌ها»; their website account; «✉️ ارسال پیام»; **«➕ افزودن سفارش»** —
  a plan or numbers of your own, free, from the wallet or paid outside, a summary, one order —; their services, orders,
  payments and tickets; a ban, the excuse from the phone number. The owner and an agent also set the role («🛡 مدیر ربات
  کردن», «🛡 برداشتن مدیریت ربات») and the customer's own discount ([Customers](Customers.md)).
- **«📡 اشتراک‌ها»** — the tabs with «رو به انقضا», a search, the server, type and order. A service: its link to copy, its
  usage, end and server; «🔄 به‌روزرسانی از پنل», «⏸ غیرفعال کردن» (asked) and «▶️ فعال کردن», «🔀 انتقال به سرور
  دیگر», «🎁 افزایش زمان و حجم» (7 or 30 days, 10 or 50 GB, or typed), «📤 انتقال به مشتری دیگر», «🗑 حذف سرویس». «🔀
  انتقال چند سرویس به سرور دیگر» moves several at once ([Services](Services.md)).
- **«🔗 زیرمجموعه‌گیری»** — the figures, the commission's terms, and the referrers, invitees, commissions and alerts — an
  alert cleared, asked first ([Referrals](Referrals.md)).
- **«📩 واریزهای پیامکی»** — the bank texts «تایید خودکار واریز» got, each with the payment it approved or why none.

### The shop

- **«📦 پلن‌ها»** — by category; a plan's page: its prices, servers — and why one cannot sell —, sales and options. To
  change: **«➕ افزودن پلن»** asks field by field, then shows the whole plan as the panel's dialog groups it, «✏️» beside
  each field, and saves it once; a plan's «✏️ ویرایش», «⏸ غیرفعال کردن» / «▶️ فعال کردن», «📄 ساخت کپی», «🔼 بالاتر» /
  «🔽 پایین‌تر», «🗑 حذف پلن». **«💹 تغییر گروهی قیمت»** previews every plan's prices before and after, eight plans a
  page, the ones that cannot take it first; «اعمال تغییر» applies exactly what the preview showed — a plan whose prices
  moved since is refused, «⛔️ اعمال نشد» with why, and «🔄 پیش‌نمایش دوباره» comes before another try
  ([Plans](Plans.md#changing-several-prices-at-once)).
- **«🗂 دسته‌بندی‌ها»** — added, renamed, given a premium emoji, switched, reordered, deleted.
- **«👫 گروه‌های کاربران»** — added, renamed, «همکار» switched, reordered, deleted; «👥 مشتری‌های این گروه».
- **«🧪 اکانت تست»** — the tests taken and each one's state; each server's terms (the owner changes them — the same card
  as the server page's «🧪 اکانت تست»); the rules card ([Test accounts](Test-Accounts.md)).
- **«🛠 سرویس دلخواه»** — the main shop's: its price tiers (removing one asks first), settings and «🧮 ماشین حساب
  قیمت» ([Custom services](Custom-Services.md)).
- **«🏦 روش‌های پرداخت»** — each method by its driver: added, edited, switched, reordered, deleted (a choice whose
  options are long, as Rikhtam's coins are, shows one option to a row, so none is cut); a card method's cards; «⚡️ تایید
  خودکار واریز» — its switch, the ForApp steps, the webhook addresses, the key and «🔑 ساخت کلید تازه»; and Rikhtam: its
  steps, «🔌 اتصال ریختم» (the gateway's «کلید امنیتی» asked, the message taken away once read), and once connected its
  gateway, the shop's address there, «🔁 ثبت دوباره آدرس در ریختم», **«🔑 تعویض کلید ریختم»** (a new key pasted while
  connected: it replaces the old one and the address is registered again) and «✂️ قطع اتصال ریختم» (asked). A refused
  re-registration says why, and points to «🔑 تعویض کلید ریختم» should the key have changed at Rikhtam
  ([Payment methods](Payment-Methods.md#through-rikhtam)). A Rialit method's screen has a **«🔗 وب‌هوک ریالیت»** line,
  read from Rialit as the screen opens: 🟢 the shop's address registered; ⚪️ not registered, with «🔗 ثبت آدرس در
  ریالیت»; 🟠 another address — shown, copied at a tap —, with «🔁 جایگزین کردن با آدرس فروشگاه» (asked first, saying
  the other site would get no more of Rialit's webhooks); or ⚠️ why it could not be read, with «🔄 دوباره امتحان». After
  a save, what the shop did with the address is said over the screen ([Payment methods](Payment-Methods.md#rialit)).

### Discounts and rewards

«🎉 تخفیف و جوایز» — every list, form and settings card of the panel's pages: discount codes («➕ ساخت کد تخفیف», a code's
page, its «⚙️ تنظیمات تخفیف‌ها»), sales, loyalty tiers, cashback rules and their settings, gift codes (batches;
«➕ ساخت کدها» sends the new codes as a file; «📄 فایل کدهای استفاده‌نشده»; one code's page), the lucky wheel (its prizes,
spins and settings) and the nightly draw (its draws and winners, its settings) ([Discounts](Discounts.md),
[Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md), [Gift codes and the lucky wheel](Gift-Codes-And-Lucky-Wheel.md)).
A start or an end is typed as a Jalali day — «۱۴۰۵/۰۷/۲۰», an hour after it if you like («۱۴۰۵/۰۷/۲۰ ۱۸») —, in
the shop's time.

### The bot

- **«📣 ارسال همگانی»** — the runs, newest first, each with its progress; a run's page with «⏸ توقف», «▶️ ادامه»,
  «🚫 لغو ارسال» and «📌 لغو پین» as it allows (the last two asked). **«➕ پیام همگانی تازه»** starts the bot's own
  composing: send the message, and its draft card comes under it; «⚙️ بازگشت به مدیریت» leads back
  ([Broadcasts](Broadcasts.md)).
- **«🎁 هدیه همگانی»** (the owner's) — the gifts; «➕ هدیه جدید» asks to which services (with how many it reaches), the
  days and the traffic (7 or 30 days, 10 or 50 GB at a tap, or typed) and a reason, then sums it up with «سرویس‌های در
  انتظار اولین اتصال» and «پیام به مشتری‌ها» to switch, and «✔️ شروع هدیه»; a gift's parts and progress, «🔄 پیشبرد»,
  «🚫 لغو هدیه» (asked) ([Broadcasts](Broadcasts.md#the-mass-gift)).
- **«📝 متن‌های ربات»** — by group, «همه» or «تغییر یافته», a search. A text: its words, its variables, its length;
  **«✏️ ویرایش»** — send the new text as a customer should see it: bold, italics, links and premium emoji are kept as
  sent —, «🧾 ویرایش با کد HTML» (the current code shown), «👁 پیش‌نمایش», «📄 متن پیش‌فرض», «↩️ بازگشت به پیش‌فرض»
  (asked). «✨ ایموجی‌های پرمیوم» lists the premium emoji the bot knows — one taken off after a question — and adds more,
  as `/emoji` does ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)).
- **«⌨️ کیبوردها»** — each layout drawn as customers see it; a button moved («➡️ به راست», «⬅️ به چپ», «🔼 ردیف بالا»,
  «🔽 ردیف پایین»), renamed, coloured, given an icon, given another action, removed; «➕ افزودن دکمه», rows moved and
  deleted, «🔀 نوع» (reply or inline), «↩️ بازگشت به چیدمان پیش‌فرض». Each change is saved at once.
- **«📢 کانال‌های اجباری»** — the join switch (turning it on asks first); a channel added by its link, «🔄 بررسی دوباره»,
  moved, deleted.
- **«📋 گروه گزارش‌ها»** — «🔗 ساخت لینک اتصال» and its steps; connected: its state, «📨 پیام تست», «🔄 بررسی دوباره»,
  «🔔 بخش‌های گزارش», «🔗 اتصال گروه دیگر», «✂️ قطع اتصال» (asked) ([Report group](Telegram-Group.md)).

### Servers and agents

- **«🖥 سرورها»** (the owner's) — each server's state; a server's page: its status, «🔌 بررسی اتصال», its inbounds («🔄
  به‌روزرسانی از پنل», a switch for sale each), «✏️ تنظیمات اتصال» (its connector's own form), «🧪 اکانت تست», «🎁 افزودن
  زمان و حجم» to its services, «🔀 انتقال سرویس‌ها» to another server, «🗑 حذف سرور». **«➕ افزودن سرور»**: the
  connector, its form field by field — a secret typed is never shown back —, «🧪 تست اتصال», «✔️ افزودن سرور»
  ([Servers](Servers.md)).
- **«🤝 نمایندگان»** (the owner's) — the requests (approved with a level, credit, end and monthly minimum; rejected),
  the agents (terms, traffic added or taken, hidden servers and plans, «⛔️ توقف فروش» / «▶️ ادامه فروش», «🚫 لغو
  نمایندگی», «🏪 باز کردن فروشگاه»), the levels and «⚙️ تنظیمات نمایندگی» ([Agency](Agency.md)).

### Settings

- **«🤖 تنظیمات ربات»** (the owner and an agent) — every card of the panel's page, in its order, «🛡 مدیران ربات» last,
  then the cards other sections keep on their own pages; a card goes back to where it was opened from. A card lists its
  fields with their values, the panel's hints, warnings and notes with them — «❔ راهنما» for the rest —: a switch flips
  at a tap, a choice is picked among buttons, the rest is typed. Switching the bot off, asking for the phone or for the
  channels asks first; the other way is one tap. An extra's offered amounts are typed on one line, a price after `=`
  where it has its own (`10=30000، 20=55000، 50`); a plan, as the referrals' reward, is picked among the plans;
  renewal's «⏱ تمدید خودکار» is a card of its own; the QR card shows its background, takes a picture sent as a new one —
  a photo, or an image file for its full quality —, and puts the shop's back (asked) ([Bot settings](Bot-Settings.md)).
- **«🛡 مدیران ربات»** (the owner and an agent) — [above](#the-shops-admins).
- **«🌐 تنظیمات وب‌سایت»** (the owner and an agent) — how the website stands, then its cards, a field at a time with the
  panel's hints: «🔌 اتصال» (on or off, its address and other origins, the API's address and store key, «🔑 ساخت کلید
  جدید» asked first), «✈️ ورود با تلگرام» (the BotFather steps, the bot's id, Client ID, and a Client Secret typed, never
  shown back, that can be cleared), «🔵 ورود با گوگل» (the steps, Client ID), «📧 ورود با ایمیل» (a warning while no email
  goes out, a way to the email settings, and «🛡 تایید امنیتی»: none, Turnstile or ALTCHA with its form), «📱 ورود با
  موبایل» (the panel's SMS warnings), «⭐️ نظرات» and «🛡 مدیران سایت» ([Website](Website.md)).
- **«📱 مینی‌اپ تلگرام»** (the owner and an agent) — «🔌 اتصال» (on or off, the menu button and its words, the app's
  address and the bot, the https warning, «🔄 تنظیم دوباره دکمه منو», the BotFather steps; Telegram refusing the menu
  button is said in a popup), «🎨 ظاهر» (name, colour, full screen, the announcement and its link, reviews), «🖼 لوگو»
  (the screen is the logo: send a photo or an image file; «🗑 حذف لوگو» asked) and «👁 پیش‌نمایش» (a customer picked or
  searched for, a link that opens the app as them, light or dark) ([Mini app](Mini-App.md)).
- **«🧰 تنظیمات پنل»** — where `config.php` is and whether it can be written, then the panel's sections in its order
  ([Panel settings](Panel-Settings.md)). The owner has them all in the main shop; with an agent's shop picked, the
  update, «📡 دریافت پیام‌ها» and that shop's backups; an agent, their own shop's backups. «ظاهر» is a browser's: the bot
  is in your Telegram's own theme. A secret typed into any of these cards is deleted from the chat, never shown back, and
  kept encrypted with the app's key until it is saved; the cron address and your panel password are never kept:
  - `config.php`'s groups, each a draft saved once with «💾 ذخیره», a refusal under its field: «🤖 ربات تلگرام» (the
    token — «🧪 بررسی توکن» fills the username in —, the API's address, the poll's timeout, the webhook secret and a new
    one; your current password asked only when the API's address moves), «🗄 دیتابیس» (its driver's form, «🧪 تست
    اتصال»), «🏷 برنامه» (name, address, a time zone found by typing part of its name, debug), «📧 ایمیل» (a driver or
    none, the sender, «📧 ارسال ایمیل تست»), «📨 پیامک» (a driver or none, the agents' switch, «💰 اعتبار حساب»,
    «📱 ارسال پیامک آزمایشی» showing its code) and «🛠 پیشرفته» (log level, session length, timeout, secure cookie, the
    cron token — the cron address and cPanel's line at a tap).
  - «⬆️ به‌روزرسانی» — the version, the last check, the newest release with its notes, what holds it back and the update
    by hand; «⬇️ به‌روزرسانی به نسخه …» after the panel's question, then its steps with their progress, one a tap; retry,
    «✖️ لغو به‌روزرسانی», «↩️ بازگرداندن نسخه قبلی» (asked), «🔄 بررسی دوباره».
  - «⏱ زمان‌بندی کارها» — the tasks in the order they run, last and next run, a warning while the cron never ran; each
    timing beside its default, «↩️ پیش‌فرض: …», and «↩️ بازگشت به همه پیش‌فرض‌ها» (asked).
  - «💾 پشتیبان‌گیری» — «📥 دریافت فایل پشتیبان» (made now, sent as a file); the report group's switch, how often, the
    last sent or why it failed; «♻️ بازگردانی از فایل»: send the `.json.gz`, see what it holds, «📋 ردیف‌های هر جدول»,
    its warnings and what is lost, then «✔️ می‌دانم؛ بازگردانی شود»; afterwards «📥 دریافت حالت قبل», and the states kept
    from before each restore, each sent as a file.
  - «🔀 انتقال از ربات دیگر» — the imports so far; «➕ انتقال تازه»: the old bot, then its backup sent here or
    «🔌 اتصال به دیتابیس»; reading and writing go a step a tap (about 15 seconds each); the review — what was found,
    «🖥 پنل‌ها», «✅ چه چیزهایی منتقل شود», «📋 آنچه نیامد», the warnings —; «▶️ شروع انتقال» or «🛑 لغو انتقال» after
    the panel's questions; the report, the problems by reason, «🗑 حذف گزارش» ([Importing](Importing.md)).
  - «📡 دریافت پیام‌ها» — the version and PHP's (an unsafe one warned of), the bot's state, the cron, the webhook's mode
    and address, the https warning, «🔗 ثبت Webhook» and «⛓ برداشتن Webhook» (asked), and what became of each bot.
  - «🔐 ورود به پنل» — changes nothing here: the panel's username and password change in the panel alone (saving there
    signs other browsers out, which a chat cannot stand in for); it leads to «👑 حساب تلگرام مالک».
- **«👑 حساب تلگرام مالک»** (the owner's) — [above](#your-own-telegram-account).

## Lists and pages

A list shows 8 rows a page, each a button to its own page, and a line of how many there are; a long list of choices —
plans, servers, inbounds, shops, customer groups — goes 40 a page. The panel's tabs are chips over the rows, «●» on the
one in force; «🔎 جستجو» asks what to look for, «✖️ پاک کردن جستجو» drops it; «بعدی ›» and «‹ قبلی» turn the pages. A
row's page shows what the panel's row and dialog show, then the buttons its state allows — a paid order has no «لغو» —,
and «⬅️ بازگشت» to the list, as you left it.

The words are the same everywhere: a row is «▶️ فعال کردن» or «⏸ غیرفعال کردن», «🟢 فعال» or «⏸ غیرفعال»; a setting
is روشن or خاموش; «🔼 بالاتر» and «🔽 پایین‌تر» move a row; «💾 ذخیره تغییرات» saves an edit, «✔️ ساختن» or «✔️ افزودن» a
new one. **A switch's button sets the value it shows**: tapped twice, or from an old message, it changes nothing — «…
همین حالا روشن است».

## Answering a question

A value to type is asked as a question: «✍️ …», what may be typed and the value now («الان: …»), quick choices for the
common ones (7 or 30 days, 10 or 50 GB, a plan's price), and «✖️ لغو».

- **The next message you send is the answer.** A menu button leaves the question unanswered — its words are never sent
  as an answer, to a customer's ticket say —, except where the answer may be any words: a keyboard button's new label,
  a bot text's new words. A command (`/start`, `/admin`), «⚙️ مدیریت» or a tap on any button leaves any question.
- **Your message is deleted once read**, whatever came of it: a token, a password or a card number typed into a card
  does not stay in the chat.
- **Numbers** in Persian or Latin digits, separators allowed; **amounts** as you would say them — «۵۰ هزار تومان»,
  «۲۰ گیگ», «۱۲ ساعت»; a **date** in the Jalali calendar. A picture or a file is sent where one is asked for (a QR
  background, a ticket's answer, a backup); words alone where words are asked: «پاسخ را به صورت متن بفرستید.»
- **A refused answer** asks the question again as it was — its hint, its quick choices —, «⚠️» and the panel's reason
  over it, «دوباره بفرستید، یا لغو کنید.».
- **A longer form** — a plan, a server, a discount code, a manual order — asks field by field, «⏭ رد شدن» on those it
  can do without, then shows the whole with «✏️» beside each field and saves it once. A refusal keeps what you typed and
  says which field.

## Confirmations and what follows a change

- **What cannot be undone asks first** — a delete, a refund, a cancellation, a rejection in bulk: «❓» and the question,
  the panel's own warning under it, the button that does it («🗑 بله، حذف شود», «🚫 بله، لغو شود», «✔️ …») and
  «✖️ انصراف».
- **A decision that may carry a note** — a payment or a receipt rejected, an agency request rejected, an agent's selling
  stopped or agency ended, their traffic changed — offers «✍️ با توضیح», «⏭ بدون توضیح» and «✖️ انصراف».
- **After a change** comes a short word — «✅ …», in a popup on the button you pressed, or atop the next screen after a
  typed answer — and the screen as the panel has it now. Nothing says done before the panel has answered.
- **A refusal** is the panel's words in a popup; the screen stays as it was. A decision someone made meanwhile, in the
  panel, the report group or another chat, is refused as the panel refuses it, and a credit, a refund, an order made by
  hand or a ticket's answer is never made twice by a double tap or an answer sent again.

## Another shop

The owner works any shop, as the panel's shop switcher does: «🏪 فروشگاه: …» on the home lists the main shop and every
agent's — a page at a time, «🔎 جستجو» by name or @username —, «●» on the one on screen. Picking one opens its home, and
every section then works there: its payments, its customers, its bot's settings. The pick is the chat's — it stays until
you pick another —, and the screens keep nothing of the shop before (a page, a filter, a half-made form). The
installation's own sections — the servers, the agency, the panel's settings — are the same in any.

A broadcast goes out from its shop's own bot: with another shop on screen, «📣 ارسال همگانی» follows and controls its
runs, and a new one is composed in that shop's bot.

## What an admin may not do

An admin of the shop sees the buttons the daily work and your grants give, and no other; a button they kept from before a
grant was taken away is refused at the door: «مالک فروشگاه این کار را به مدیران ربات نسپرده است.» Beyond the grants,
the panel's guards hold them as they do on the website:

- **Never about themselves** — their own payment approved or refunded, their wallet, their service changed, their review,
  a referral alert on them, an order made for themselves, a service handed to their own account: another admin decides.
  On their own page they have no wallet or order buttons.
- **Never an admin's or an agent's account** — no ban, groups, excuse from the phone number, message, two-factor sign-in
  or devices: such a page is to look at, «🔒 حساب یکی از مدیران فروشگاه است؛ فقط مالک فروشگاه آن را تغییر می‌دهد.»
- **A customer into a «همکار» group** needs «پلن‌ها و دسته‌بندی‌ها», since its members pay the agents' prices: «مالک
  فروشگاه گذاشتن مشتری در گروه «همکار» را به مدیران ربات نسپرده است.»

These guards are each admin's own: two admins can do those things for each other. Make admins only of people you
trust.

## The panel's rules and its log

- **Every screen is the panel's API**, run inside the shop for the one who manages: the same checks, limits and
  refusals, the customer told the same way, the report group hearing what it would. An open panel shows a change from the
  bot within seconds, and the bot shows the panel's.
- **Decisions keep who made them**: the owner's as the panel's login (which everyone else reads as «پشتیبانی»), an
  agent's as their bot, an admin's by their name. A ticket's answer from the bot reads «مدیریت ربات» in the panel and
  «از مدیریت ربات» in the report group; the customer reads «🎧 پشتیبانی», as always.
- **The shop's log has a line for every change asked from the bot** — who, as what, which change, and the panel's
  answer — beside the panel's own lines.
- **The Telegram account is the sign-in**: the bot asks no password, and no recent sign-in before an approval, a
  Rikhtam attach or a grant's work, as the website's admin API asks of its staff — whoever holds the account in
  Telegram manages as it. Take an admin's role away, or unlink an owner account, the moment a phone is lost.

## Watch out for

- **A linked account is your whole panel.** Anyone holding that Telegram account manages every shop: link your own
  accounts alone, turn on Telegram's two-step verification, and let an account go the day you lose it.
- **Taking the admin role from your own account** asks first: you and an agent keep «⚙️ مدیریت» and still pass the bot's
  conditions, but the report group's buttons answer admins alone; an admin of the shop loses the management with it.
- **Telegram's file limits**: a bot is handed files of up to 20 MB and sends files of up to 50 MB. A larger backup, or
  a larger file to import, goes through the panel; the screens say so, and a file Telegram refuses is said in a popup.
- **The management is the private chat's**: the report group keeps its own buttons and `/manage`, which «مدیران ربات»
  does not touch ([Report group](Telegram-Group.md)).
