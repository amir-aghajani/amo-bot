[فارسی](fa/Bot-Management.md)

# Managing the shop from the bot

The shop can be run from its bot's private chat as well as from the panel. «⚙️ مدیریت» opens the panel's sections as
the bot's own screens — receipts and payments, orders, services, customers, tickets, plans, discounts, the bot's settings,
the servers, the panel's own settings — on a phone, with no browser and nothing to install. Every screen reads the
panel, and every change goes through the panel's own rules: the same checks and refusals, the same notices to
customers, the same log. Nothing is a web page: cards, lists, buttons and questions in the chat, laid out alike on every
screen, so a section never seen before reads like the last one.

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

«👑 حساب تلگرام مالک», in the bot, lists the linked accounts — yours marked «(شما)» — each with a red «🗑 برداشتن …»,
and makes a link for another: «➕ افزودن حساب دیگر», then open the link from that other account («📋 کپی لینک» copies
it). The bot asks no password there: only an account linked already has the screen. Letting your own account go warns
that the bot's management closes for it until the panel links it again.

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
- **«کارهای بیشتر»** — a switch per grant, all off by default, the website's «مدیران سایت» list with switches of its own.
  They can be set while «کار مدیران از ربات» is off, ready for when you turn it on:

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

**Each screen keeps where you left it** — its page, its filter, its search, a half-made form —, so going to the menu, or
using the bot as a customer, loses nothing. Two taps at the same moment are done one after the other, the second seeing
what the first did: nothing is done twice. **A button of an older window** — a screen since changed, or no longer
yours — opens the home, saying «⚠️ این دکمه قدیمی است؛ از همین‌جا ادامه دهید.», never an error.

## The home

**«🏠 مدیریت فروشگاه»**: the shop and your seat — «👑 مالک», «🤝 نماینده» or «🛡 مدیر ربات» —, then **«📅 امروز»**, a
card of today's «درآمد», «سفارش‌ها» and «کاربران تازه». Under it, what waits on a human now — **«🔔 در انتظار · ۴ کار»**,
or «✅ کاری در انتظار نیست.» — and a button straight to each queue that has something: «🧾 رسیدها», «🧯 سفارش گیرکرده»,
«🎧 تیکت‌ها», «⭐️ نظرات», «✋ پرداخت نگه‌داشته» and «🖥 سرور با خطا», each with its count. A queue's button opens its
list at the tab it was counted on, any search or narrowing left there cleared, so every row counted is on it. Then the
sections you may open, two a row; **«🔎 جستجو»**; the owner's «🏪 تعویض فروشگاه» ([another shop](#another-shop)); and
«🔄 تازه‌سازی». An agent has «💼 حساب نمایندگی» there too. A section you may not open is not there.

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
| «⚙️ تنظیمات» | «🔎 جستجوی تنظیمات», «🤖 تنظیمات ربات», «🛡 مدیران ربات», «🌐 تنظیمات وب‌سایت», «📱 مینی‌اپ تلگرام», «🧰 تنظیمات پنل», «👑 حساب تلگرام مالک» | The owner and an agent (each their part) |

A menu says in a line what is in it, and counts a queue beside the entry that holds it («💳 پرداخت‌ها (۲)»). **Each
section is reached from one place**, and a feature's own settings live with the feature, not in «🤖 تنظیمات ربات»: the
channels and the report group in «🤖 ربات»; the referral rules in «🔗 زیرمجموعه‌گیری»; the test accounts', the custom
services', the discount codes', the gift codes', the cashback's, the wheel's and the draw's settings on their own
screens (each list's «⚙️ تنظیمات», or its card's own button); the automatic deposit approval with the payment methods;
«🛡 مدیران ربات» in «⚙️ تنظیمات». Any of them is also found by its words ([below](#the-settings-search)).

## Finding something

### The search

**«🔎 جستجو»** on the home asks for one value — a number or «#number», a @username, a phone number (as `0912…` or
`+98 912…`), a service's link or name — and looks for it at once among the customers, the orders, the payments and the
services, each through its list's own search: «#12» is row 12 alone, a bare number any row it names. What it finds is
counted by kind («🔎 «ali» · ۱ مشتری · ۲ سفارش»), each row a button to its own page — five of a kind, and past that
«📋 همه …» opens that list with the search in it. «🔎 جستجوی دیگر» asks again. A list you may not open is not
searched. For the owner and an agent, «⚙️ جستجوی تنظیمات» sits beside the question and under the results: the other
mode, a setting found by its words.

### The settings search

**«🔎 جستجوی تنظیمات»** (the owner's and an agent's, first on «⚙️ تنظیمات») takes a word or two from a setting's name,
in Persian or English, however it is typed — Arabic letters and digits, half-spaces and Latin digits are all read alike
— and finds every setting of the panel's whose name, hint or place holds every word typed, and the words owners use
for it («بکاپ», «backup», «کرون»). It is the panel's own index («جستجوی تنظیمات» there), so whatever the panel can set
is found. The best first, twenty at most, ten a page, counted («۵ تنظیم · «کیف پول»»); each a button with its place
(«کیف پول مشتری · مدیریت فروشگاه از ربات»):

- **a setting** opens straight to it: a typed value is asked at once, a choice offers its choices, and a switch shows
  the card — or the part of it — it is on, never switched by the search itself; a setting another switch hides for now
  opens its card, saying which switch shows it;
- **«🗂» a section** — a card or a list — opens it;
- **«🧰» what the panel alone sets** is listed under «🧰 فقط در پنل» with why — the panel's own username and password,
  which only the panel changes since saving them signs other devices out, and the panel's light or dark look, a
  browser's —, its button the screen that says so.

You find your own seat's settings alone: an agent, what their panel has.

## How the screens read

Every screen is laid out the same way, top to bottom:

- **The title**, and under it **where it is**, small: «مدیریت › فروش › پرداخت‌ها». After an action, what came of it is
  said first, in bold («✅ پله ذخیره شد»).
- **A summary card** — on a row's page or a settings card — the key facts in one quote, a fact a line, «label: value»:
  money in Toman with Persian digits, dates in the Jalali calendar, «—» for none. A number, a code, a link or an address
  is shown to copy at a tap.
- **Sections**, each under its own heading («💳 کارت‌ها», «🖥 سرورها», «📋 شرایط فروش»).
- **Help folded away**: «❔ …» under the rest, closed until tapped; a short hint stays one line in italics.
- **States as chips**, said the same way everywhere: «🟢 فعال» / «⏸ غیرفعال» for a row, «🟢 روشن» / «⏸ خاموش» for a
  setting, «🟡 در انتظار», «🔴 خطا» or «🔴 ردشده», «✅ انجام‌شده».
- **A fact is said once**: in the card or on a button, not both. A list's rows are its buttons; a card says the values,
  and its buttons only what they do («✏️ حداقل مبلغ شارژ»).

**The buttons** read right to left in rows, as the customers' keyboards do:

- **Colours**, where your Telegram app draws them: **green** for what confirms, saves, creates, turns on or approves;
  **red** for what deletes, turns off, rejects or disconnects — and a yes that does one of those; **blue** for the
  screen's one main action («✏️ ویرایش» on a plan, «✍️ پاسخ» on a ticket, «📥 دریافت فایل پشتیبان»). Moving around,
  picking and the rest have no colour. On a row's page, what it does most comes first and what ends it — a delete, a ban,
  a role taken away — last, in red.
- **Copy buttons** — «📋 کپی لینک», «📋 کپی آدرس», «📋 کپی کلید», «📋 کپی کد», «📋 کپی شماره کارت» — put a link, an
  address, a key, a code or a card number on your clipboard at a tap, to paste elsewhere.
- **One row to move around**, always last: «⬅️ بازگشت» to the screen above and «🏠 خانه» to the home — and «🔄 تازه‌سازی»
  beside them where the figures move: the home, the dashboard, a list, a queue.

### Lists and pages

A list's first line says how many and what narrows them: «۲ پرداخت · 🧾 در انتظار بررسی · تاریخ: امروز». What the list
adds up to follows («💰 ۶ پرداخت موفق · جمع ۹۳۰٬۰۰۰ تومان»), then its rows, 8 a page — each a button with its state's
chip, its name and its key figure («🟢 یک‌ماهه · ۱۲۰٬۰۰۰ تومان») —, the list's own actions («➕ افزودن پلن» in green),
the panel's tabs as chips with «●» on the one in force, «🔎 جستجو» beside what else narrows or orders it («🎛 فیلتر و
ترتیب», «↕️ ترتیب», «✖️ حذف فیلترها»), and the pages: «‹ قبلی» · «۲ / ۵» · «بعدی ›». The middle button says «صفحه ۲ از
۵» at a tap. «✖️ پاک کردن جستجو» drops a search. A long list of choices — plans, servers, inbounds, shops, customer
groups — goes 40 a page, the one in force marked «✅». A row's page shows what the panel's row and dialog show, then the
buttons its state allows — a paid order has no «لغو» —, and «⬅️ بازگشت» to the list, as you left it.

### Settings cards

A settings card mirrors the panel's card. Its summary lists **every field with its value** — a switch as its chip, an
empty one «—», those its conditions hide left out —, its warnings over it, and under it, where the panel explains what is
set, that line. Its buttons:

- **A switch** says the state a tap sets: «▶️ روشن کردن «عنوان پیام پشتیبانی»», «⏸ خاموش کردن «ربات فعال باشد»». Tapped
  twice, or from an old message, it changes nothing. Turning the bot off, asking for the phone or for the channels asks
  first; the other way is one tap. A card of many alike switches — the report group's topics — shows each as a chip
  («🟢 خریدها»), a tap the other state.
- **Any other field** is «✏️ …», two a row where the labels are short: a choice offers its options, the one in force
  marked «✅»; the rest is asked ([below](#answering-a-question)).
- **«❔ راهنما»** shows every field's hint and the card's notes.

A long card is in **parts**, as the panel's card is: the fields of no part stay on the card, and each part is a button —
«🎲 دکمه‌های مبلغ تصادفی», «⏳ آستانه مجاز تمدید», «🔀 روش‌های تمدید», «📶 حجم اضافه», «🔢 سهمیه»… — opening a screen
of its own whose way back is the card. Each change is saved at once, as the panel saves the card; a value the panel
refuses is said, and nothing is kept. A warning that a setting holds something back links straight to it — «⚡️ تایید
خودکار واریز» from the bank texts, «🔗 قوانین زیرمجموعه‌گیری» from the referrals while they are off.

`config.php`'s groups in «🧰 تنظیمات پنل» (and a few forms of the panel's alike) are drafts instead: a change is kept as
«✏️ تغییرها هنوز ذخیره نشده‌اند.» until «💾 ذخیره» saves the whole, or «↩️ دور انداختن تغییرها» drops it.

## Answering a question

A value to type is asked as a question: «✍️» and the field, where it is asked from, what may be typed, then **«مقدار
فعلی»** (the value now, to copy), **«مثال»** and **«محدوده»** (its least and most); quick choices for the common
answers (7 or 30 days, 10 or 50 GB, a plan's price); **«🗑 خالی کردن»** where the field may be left empty and **«↩️
پیش‌فرض»** where it has a value it comes with; and «✖️ انصراف», the one way out of every question, confirmation and form.

- **The next message you send is the answer.** A menu button leaves the question unanswered — its words are never sent
  as an answer, to a customer's ticket say —, except where the answer may be any words: a keyboard button's new label,
  a bot text's new words. A command (`/start`, `/admin`), «⚙️ مدیریت» or a tap on any button leaves any question.
- **Your message is deleted once read**, whatever came of it: a token, a password or a card number typed into a card
  does not stay in the chat.
- **Numbers** in Persian or Latin digits, separators allowed; **amounts** as you would say them — «۵۰ هزار تومان»,
  «۲۰ گیگ», «۱۲ ساعت»; a **date** in the Jalali calendar — «۱۴۰۵/۰۷/۲۰», an hour after it if you like
  («۱۴۰۵/۰۷/۲۰ ۱۸») —, in the shop's time. A picture or a file is sent where one is asked for (a QR background, a
  ticket's answer, a backup); words alone where words are asked: «پاسخ را به صورت متن بفرستید.»
- **A refused answer** asks the question again as it was — its hint, its quick choices —, «⚠️» and the panel's reason
  over it, «دوباره بفرستید، یا انصراف دهید.».

### Longer forms

- **Something new is made step by step**, then reviewed once. The discounts and rewards — a code, a sale, a loyalty
  tier, a cashback rule, gift codes, a wheel prize — say where you are, «گام ۳ از ۹» with a bar («▰▰▰▱▱▱▱▱▱»), offer
  «⬅️ گام قبل», and «⏭ رد شدن» on a step it can do without — «⏭ همین بماند» where you came back to a step already
  answered. Then a **review card** lists the whole with «✏️» for each field, a field the panel refused marked «⚠️» with
  its reason, and «✔️ ساختن». Editing opens on the same card, and «💾 ذخیره» comes once something has changed.
- **The owner's forms** — a server, a payment method, a card, days and traffic for a server's services, an agency level —
  ask a field at a time («⏭ رد شدن» on one it can do without, «📋 خلاصه» to jump to the summary), then show the whole as
  a card with «✏️» beside each field, switches as «▶️ روشن کردن …» / «⏸ خاموش کردن …», and save it once. A secret typed
  is never shown back. One way out: «⬅️ بازگشت» while nothing has changed, else «🗑 کنار گذاشتن», asked first — so are
  an agent's terms and a server's move.
- **A plan** is edited as the panel's dialog groups it: its tabs as chips over the fields («🏷 مشخصات», «💰 قیمت و
  سهمیه», «🖥 سرورها», «🧩 خرید اضافه»), «💾 ذخیره» saving the whole and «🗑 کنار گذاشتن» dropping the draft.

A refusal keeps what you typed and says which field.

## Confirmations and what follows a change

- **What cannot be undone asks first** — a delete, a refund, a cancellation, a rejection in bulk: «❓» and the question,
  the panel's own warning under it, the button that does it («🗑 بله، حذف شود», «✂️ بله، قطع شود», «✔️ بله، …») — red
  where it destroys, green where it does not — and «✖️ انصراف».
- **A decision that may carry a note** says so on its yes: a payment rejected, cancelled or refunded, an order cancelled,
  a service switched off or deleted — «✍️ بله، با توضیح» or «✔️ بله، بدون توضیح» —; an agency request rejected, an
  agent's selling stopped or agency ended — «✍️ رد با توضیح» / «⏭ رد بدون توضیح», and so on —; an agent's traffic
  changed — «✍️ با توضیح» / «⏭ بدون توضیح».
- **After a change** comes a short word — «✅ …», in a popup on the button you pressed, or atop the next screen after a
  typed answer — and the screen as the panel has it now. Nothing says done before the panel has answered.
- **A refusal** is the panel's words in a popup; the screen stays as it was. A decision someone made meanwhile, in the
  panel, the report group or another chat, is refused as the panel refuses it, and a credit, a refund, an order made by
  hand or a ticket's answer is never made twice by a double tap or an answer sent again.

## Section by section

The menus follow the panel's sidebar, and each screen what the panel's page has — its tabs, its fields, its warnings, in
its words.

### The dashboard

«📊 داشبورد» greets you, then the last 7, 30 or 90 days — chips «۷ روز», «۳۰ روز», «۹۰ روز» — in one card: «درآمد»,
«سفارش‌ها», «کاربران جدید», «اشتراک‌های فعال» and «کل کاربران», each with its change from the period before (or a line
that the period before had nothing to compare). **«🔔 در انتظار»** has the home's queue buttons, and apart what waits on
customers: «🕒 در انتظار پرداخت» (orders not paid yet) and «⌛️ رو به پایان» (services ending in the next 3 days). The
owner sees **«🛰 وضعیت سیستم»** only when something there needs fixing — the bots receiving nothing, the scheduler never
run, a newer version, an unsafe PHP —, each with the button that fixes it («🛰 ثبت Webhook», «🔧 راه‌اندازی Cron»); an
agent sees where their agency stands when it needs an eye (selling suspended, its end or the month's minimum near,
traffic short), with a way to their account — an end passed or near sends them to its «🔄 درخواست تمدید نمایندگی».
**«📈 نمودار روزانه»** draws a figure day by day (week by week over 90
days) — its total, daily average and best day in a card, a bar under them, the days listed folded away —, chips for the
figure («درآمد», «سفارش‌ها», «کاربران جدید») and the range. «🛒 آخرین سفارش‌ها» and «👥 کاربران جدید» are the latest
eight, each a button, with the whole list a tap away.

### The agent's account

«💼 حساب نمایندگی», an agent's alone, as their panel's account page: a card of the traffic left, their level and its price
per GB, their wallet with the shop and their credit; «🤖 ربات شما» (the bot, how it stands, why it sells nothing when it
does not); «📋 شرایط فروش» (the agency's end and, under it, their latest renewal request — «درخواست تمدید»: «🟡 در
انتظار بررسی · از …», «✅ تایید شد · …» with its «پایان تازه», or «🔴 رد شد · …» with «دلیل رد» when support gave one
—, this month's traffic bought against the minimum, selling open or not); and «📒 گردش حجم», the traffic's ledger.
Traffic is bought in the main bot: «➕ خرید حجم در ربات اصلی» opens it ([Agency](Agency.md#their-traffic)).

While their agency has an end and no renewal waits, **«🔄 درخواست تمدید نمایندگی»** (blue) sends one at a tap — «✅
درخواست تمدید ثبت شد؛ نتیجه در ربات اصلی به شما اعلام می‌شود» — and the account shows it waiting, the button gone until
it is decided. One refused — a request sent meanwhile from the main bot, or no end any more — says why in a popup over
the account as it stands. The warning said first, for an end passed or near, points at the button
([Agency](Agency.md#renewing-an-agency)).

### Support and reviews

- **«🎧 پشتیبانی»** — the tickets waiting on an answer first, chips «🟡 در انتظار پاسخ», «💬 پاسخ‌داده‌شده»,
  «✅ بسته‌شده» and «همه», a search. A ticket: its facts in a card with the customer's rating and note, the conversation
  five messages a page, the latest page first («📄 پیام کامل …» shows a long one whole, «🖼 تصویر پیام …» sends a
  picture); **«✍️ پاسخ»** (blue) — words, or a picture with its caption, sent once however often it is sent again —, with
  three ready answers sent at a tap: «⏳ در حال بررسی», «✅ برطرف شد», «📸 توضیح بیشتر», their words listed in full over
  them. «🔒 بستن تیکت» (red) and «🔓 باز کردن دوباره» (green) ask first. Opening a ticket reads it, as the panel does
  ([Support tickets](Support-Tickets.md)).
- **«⭐️ نظرات»** — the reviews by the panel's tabs, those waiting first. A review: its stars, signature, author, the
  moment it was decided and, where the panel names one, by whom; «✅ تایید» (green), «🚫 رد» and «🗑 حذف» (red, asked),
  and «⏭ نظر بعدی در انتظار» to the next one waiting ([Reviews](Reviews.md)).

### Sales

- **«💳 پرداخت‌ها»** — the tabs, «🧾 در انتظار بررسی» and «✋ نگه داشته شده» counted, a search, and «🎛 فیلتر و ترتیب»:
  the order (newest, oldest, the largest or smallest amount) and the days (today, yesterday, the last 7 or 30 days, this
  month or last, or «📅 بازه دلخواه»), all at once with «✔️ دیدن نتیجه»; «✖️ حذف فیلترها» drops them. A payment's receipt
  is the screen: the picture, its facts under it (one sent as a file comes with «📄 فایل رسید»). Then what its state
  allows, each under its icon: «✅» approve (a bot admin only while the receipt waits for review), «❌ رد کردن» with or
  without a reason, «🚫 لغو پرداخت», «🔍 استعلام از درگاه», «📨 یادآوری به مشتری», «🔁 تلاش دوباره برای تحویل», «↩️»
  refund — and, while the shop's Rikhtam is connected, **«📎 پیوست واریز ریختم»**: the deposits Rikhtam holds that no
  payment took, the closest sum first — «🎯» marks the payment's exact sum, «⚠️» one short of the order's own price,
  which is not attached (picking it says why) —, one picked and confirmed, which approves the payment
  ([Payment methods](Payment-Methods.md#through-rikhtam)). The tap is answered at once («⏳ واریز در ریختم پیوست
  می‌شود…»), and what came of it is said over the payment as it now stands; a confirm from a list that changed since —
  stale, or tapped twice — attaches nothing it was not asked to, and shows the deposits afresh.
- **The receipt queue.** On the review tab, **«▶️ بررسی رسیدها یکی‌یکی»** (blue) opens the first receipt waiting, and
  any receipt opened from the shop's payments is in the queue: **«✅ تایید و تحویل، بعدی»** (or «✅ تایید و شارژ کیف
  پول، بعدی» for a top-up) approves it and opens the next, a rejection moves on the same way, and «⏭ رسید بعدی» skips
  one — a receipt skipped comes round again at the end. A delivery that fails stays on its payment, its retry right
  there. When none is left, the list says so. With the grant, **«☑️ رد چند رسید»** rejects the receipts ticked, or a
  page of them, and «❌ رد همه رسیدهای در انتظار» every one that was waiting as you confirmed.
- **«🛒 سفارش‌ها»** — the tabs with «🆘 نیازمند رسیدگی», a search, and «🎛 فیلتر و ترتیب» with the type besides; an
  order with its payments, its service and its customer; «🔁 تلاش دوباره برای تحویل», «🚫 لغو سفارش».
- **«👥 کاربران»** — a search (`#12` is customer 12), chips for the status and the role, «👫 گروه», and «↕️ ترتیب» —
  newest or oldest member, the latest or earliest visit, the most or least in the wallet, orders or active services. A
  row is «👤» and the name with the wallet («⛔️» for a banned one, «🛡» for an admin). A customer's page is one card —
  status, role, username, Telegram id, wallet, services, orders, groups, own discount — and «📇 حساب» (their number,
  mobile, joining, last visit), their referrer and invitees where they have any, their agency. Its buttons, the quick ones
  first: «✉️ ارسال پیام» (blue: the words, a preview, «✔️ ارسال پیام»), «👛 کیف پول», «➕ افزودن سفارش», «📡 سرویس‌ها»,
  «🛒 سفارش‌ها», «💳 پرداخت‌ها», «🎧 تیکت‌ها», «👫 گروه‌ها», «🔐 ورود به وب‌سایت» (their ways in, two-factor sign-in and
  devices: «🔓 خاموش کردن ورود دو مرحله‌ای», «🚪 خروج از همه دستگاه‌ها»), «✏️ تخفیف اختصاصی», the excuse from the
  phone number as a switch, «💬 گفتگو در تلگرام»; the admin's last — «🛡 مدیر ربات کردن», «✅ رفع مسدودی», and in red
  «🛡 برداشتن مدیریت ربات» and «⛔️ مسدود کردن». **The wallet** lists its ledger, each line with the balance after it;
  «➕ افزایش موجودی» (green) or «➖ کاهش موجودی» asks the amount, then shows it with a note for the customer and the
  balance before and after — a decrease past the balance with a ⚠️ line of the debt it leaves; one past the deepest
  debt only that refusal, with no balance after it and no «✔️» —, «✔️ افزایش موجودی» in green or «✔️ کاهش موجودی» in
  red doing it ([Customers](Customers.md#the-wallet)).
- **«➕ افزودن سفارش»** — in four steps: the kind («📦 یکی از پلن‌ها» or «🛠 سرویس دلخواه»); the plan, or the numbers
  of your own; the server, picked for you where there is one; the payment — «🎁 رایگان», «👛 از کیف پول مشتری» (with
  their balance) or «💵 پرداخت خارج از فروشگاه» — and its amount («💰 قیمت پلن: …» at a tap). Then one card: the
  service, server, payment and amount, the wallet's balance and what it is after the order — or, short, a warning of how
  much it lacks —, in an agent's shop the traffic it takes from the bot's and what is left; the service's username and a
  note offered there («✏️ نام کاربری سرویس», «✏️ یادداشت»), «✏️» for the rest, and «✔️ ثبت و تحویل سفارش». The service
  is made at once and the customer told; «➕ افزودن سفارش دیگر» starts another.
- **«📡 اشتراک‌ها»** — the tabs with «⏳ رو به انقضا», a search, and «🎛 فیلتر و ترتیب»: the server, the type (a test, a
  service added by its link) and six orders — newest, oldest, ending soonest or latest, used most or least. A service: a
  card of its customer, plan, server and devices, its traffic and its time as bars («▰▰▱▱▱▱▱▱▱▱ ۲۰٪»), its link with
  «📋 کپی لینک»; «🎁 افزایش زمان و حجم» (blue: days and GB — «۷ روز», «۳۰ روز», «۱۰ گیگ», «۵۰ گیگ» or typed —, a note
  for the customer and whether they are told, as one card within the server's limits, «✔️ بله، اضافه شود»), «🔀 انتقال
  به سرور دیگر», «📥 به‌روزرسانی از پنل», «📤 انتقال به مشتری دیگر», «▶️ فعال کردن» (green), and in red «⏸ غیرفعال
  کردن» and «🗑 حذف سرویس» (asked). «🔀 انتقال چند سرویس به سرور دیگر» moves several at once ([Services](Services.md)).
- **«🔗 زیرمجموعه‌گیری»** — a card of the commission and the figures; «🧲 معرف‌ها», «🙋 زیرمجموعه‌ها» and «💰 پورسانت‌ها»,
  each with its sort and its direction as chips («بیشترین اول» or «کمترین اول», «تازه‌ترین اول» or «قدیمی‌ترین اول»),
  and «⚠️ هشدارها», an alert cleared after a question; «🔗 قوانین زیرمجموعه‌گیری», the program's rules card. While the program is off, a warning says so, the rules'
  button in blue ([Referrals](Referrals.md)).
- **«📩 واریزهای پیامکی»** — the bank texts «تایید خودکار واریز» got, each with the payment it approved or why none, by
  their outcome; while the approval is off a warning says so, with «⚡️ تایید خودکار واریز» to its settings.

### The shop

- **«📦 پلن‌ها»** — a row a plan, «🟢» on sale, «⏸» switched off, «⚠️» not shown in the bot; the categories as chips, a
  search by name. A plan's page says first why it is not shown, where it is not, then its card and its servers;
  «✏️ ویرایش» (blue), «⏸ غیرفعال کردن» / «▶️ فعال کردن», «📄 ساخت کپی», «🔼 بالاتر» / «🔽 پایین‌تر», and «🗑 حذف پلن» in
  red, last — a plan with orders or services is not deleted, and the screen offers switching it off. **«➕ افزودن پلن»**
  asks step by step («گام ۱ از ۱۰»), then shows the whole plan by the dialog's tabs to save once. **«💹 تغییر گروهی
  قیمت»** is one card of choices — the plans, «⬆️ افزایش» or «⬇️ کاهش», a percent or a fixed amount, the rounding,
  which prices — and «👁 پیش‌نمایش» shows every plan's price before and after; «✔️ اعمال تغییر» applies exactly what
  the preview showed — a plan whose prices moved since is refused, «⛔️ اعمال نشد» with why, and «🔄 پیش‌نمایش دوباره»
  comes before another try ([Plans](Plans.md#changing-several-prices-at-once)).
- **«🗂 دسته‌بندی‌ها»** — a row a category with how many plans it has; added, renamed, given a premium emoji, switched,
  reordered, deleted (asked); «📦 پلن‌های این دسته».
- **«👫 گروه‌های کاربران»** — added, renamed, «همکار» switched, reordered, deleted; «👥 مشتری‌های این گروه».
- **«🧪 اکانت تست»** — the tests taken, newest first, each one's state, customer and server, chips by state, a search; a
  test's page leads to its service, its customer and «🧪 اکانت‌های تست این مشتری». While tests are off, «▶️ روشن کردن «اکانت تست»» turns them on at a tap. «🖥 سرورهای اکانت تست»:
  each server's terms (the owner changes them — the same card as the server page's «🧪 اکانت تست»); «⚙️ قوانین اکانت
  تست»: the switch, then «🔢 سهمیه» and «🧹 حذف خودکار» ([Test accounts](Test-Accounts.md)).
- **«🛠 سرویس دلخواه»** — the main shop's: a card of how it stands and what a customer may build; «✏️ پله‌های حجم» and
  «✏️ پله‌های مدت» (a tier added, «✏️ همه پله‌ها با هم», a tier removed after a question), «🧮 ماشین حساب قیمت», and
  «⚙️ تنظیمات سرویس دلخواه» in three parts — «📶 حجم», «📅 مدت», «🖥 سرور و کاربر» ([Custom services](Custom-Services.md)).
- **«🏦 روش‌های پرداخت»** — «۳ روش · ۳ فعال», a row a method with how it settles («تسویه آنی», a card method's cards, a
  gateway's driver), and «⚡️ تایید خودکار واریز» with its state on its button. A method: its card, «✏️ ویرایش» (its
  driver's own form — a choice whose options are long, as Rikhtam's coins are, shows one option to a row, so none is cut),
  «⏸ غیرفعال کردن» / «▶️ فعال کردن», «🔼 بالاتر» / «🔽 پایین‌تر», «🗑 حذف روش پرداخت». «➕ افزودن روش پرداخت» lists the
  drivers by how they settle — «🏧 تایید دستی», «💳 درگاه آنلاین» — each with a line of what it does. A card method's
  «💳 کارت‌ها»: each card's page with «📋 کپی شماره کارت», a card added, edited, switched, reordered.
- **«⚡️ تایید خودکار واریز»** — its state first, as a card (the approval, ForApp, Rikhtam), and the switch. Its sections,
  their steps folded away: **«📲 اتصال فوراپ»** — the webhook address and the key, «📋 کپی آدرس وب‌هوک», «📋 کپی کلید»,
  «🔑 ساخت کلید تازه», «📲 دانلود فوراپ» —, and **«🔗 اتصال ریختم»**: «🔌 اتصال ریختم» (the gateway's «کلید امنیتی» asked,
  the message taken away once read), and once connected its gateway, the shop's address there with «📋 کپی آدرس ریختم»,
  «🔁 ثبت دوباره آدرس در ریختم», **«🔑 تعویض کلید ریختم»** (a new key pasted while connected: it replaces the old one and
  the address is registered again) and «✂️ قطع اتصال ریختم» (asked). A refused re-registration says why, and points to
  «🔑 تعویض کلید ریختم» should the key have changed at Rikhtam ([Payment methods](Payment-Methods.md#through-rikhtam)).
- **A Rialit method's screen** has a **«🔗 وب‌هوک ریالیت»** line, read from Rialit as the screen opens: 🟢 the shop's
  address registered; ⚪️ not registered, with «🔗 ثبت آدرس در ریالیت»; 🟠 another address — shown, copied at a tap —,
  with «🔁 جایگزین کردن با آدرس فروشگاه» (asked first, saying the other site would get no more of Rialit's webhooks); or
  ⚠️ why it could not be read, with «🔄 دوباره امتحان». After a save, what the shop did with the address is said over the
  screen ([Payment methods](Payment-Methods.md#rialit)).

### Discounts and rewards

«🎉 تخفیف و جوایز» — every list, form and settings card of the panel's pages ([Discounts](Discounts.md),
[Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md), [Gift codes and the lucky wheel](Gift-Codes-And-Lucky-Wheel.md)):

- **«🏷 کدهای تخفیف»** — chips by state («🕒 شروع نشده», «🟢 فعال», «⏸ غیرفعال», «🟠 ظرفیت پر», «⚪️ تمام‌شده»), a
  search; «➕ افزودن کد تخفیف» (the steps, «🎲 ساخت کد تصادفی» on the first, then the review card); a code's page with
  «📋 کپی کد»; «⚙️ تنظیمات» — «⚙️ تنظیمات تخفیف‌ها».
- **«🔥 فروش ویژه»**, **«🏅 سطوح وفاداری»** — a row each, made and edited through the steps and the review card.
- **«💸 کش‌بک»**, **«🎟 کدهای هدیه»**, **«🎡 گردونه شانس»**, **«🎲 قرعه‌کشی»** — each list says first whether its program
  is on, and offers «▶️ روشن کردن «…»» at a tap while it is off; its rules are its «⚙️ تنظیمات». Gift codes come in
  batches: «➕ افزودن کدهای هدیه» sends the new codes as a file; a batch has «🎟 کدهای این سری», «📄 فایل کدهای
  استفاده‌نشده», «📋 کپی کدهای استفاده‌نشده» and «🗑 حذف کدهای استفاده‌نشده»; a code has «📋 کپی کد». The wheel says
  the chance of winning on a spin («شانس بردن در هر چرخش») and of each prize, and lists its spins; the draw shows its
  nightly settings in a card while it is on, and its draws with their winners.

A start or an end is typed as a Jalali day, an hour after it if you like, in the shop's time — or picked: «۲۴ ساعت
دیگر», «۷ روز دیگر», «۳۰ روز دیگر».

### The bot

- **«📣 ارسال همگانی»** — the runs, newest first, each with its progress; a run's page with «⏸ توقف», «▶️ ادامه»,
  «🚫 لغو ارسال» and «📌 لغو پین» as it allows (the last two asked). **«➕ پیام همگانی تازه»** starts the bot's own
  composing, its three steps said: send the message, and its draft card comes under it — the message itself is the
  preview —; pick the audience, copy or forward, the pin and the buttons there; then **«✅ ارسال به N نفر» asks once
  more** — «❓ ارسال به N نفر شود؟», what goes, to whom, pinned or not, and that a message once received stays —, and
  only «✔️ بله، ارسال شود» starts the run («✖️ انصراف» back to the card). «⚙️ بازگشت به مدیریت» leads back
  ([Broadcasts](Broadcasts.md)).
- **«🎁 هدیه همگانی»** (the owner's) — the gifts; «➕ هدیه تازه» asks in four steps («گام ۱ از ۴», «⬅️ گام قبل»): to which
  services (with how many it reaches), the days and the traffic (7 or 30 days, 10 or 50 GB at a tap, or typed, «⏭ رد
  شدن» for none) and a reason; then a review card — «✏️ مخاطب», «✏️ روز», «✏️ حجم», «✏️ دلیل», the services awaiting their first
  connection and the message to customers as «▶️ روشن کردن …» / «⏸ خاموش کردن …» — before «✔️ شروع هدیه». A gift's parts
  and progress, «⏩ پیشبرد», «🚫 لغو هدیه» (asked) ([Broadcasts](Broadcasts.md#the-mass-gift)).
- **«📝 متن‌های ربات»** — the groups as a short list, two a row, «✏️» on a group where a text was reworded; chips «همه» and
  «تغییر یافته» under them, a search. A text: a card of its kind, its state, its length against its most and its
  variables, then the words; **«✏️ ویرایش»** (blue) — send the new text as a customer should see it: bold, italics, links
  and premium emoji are kept as sent —, «🧾 ویرایش با کد HTML» (the current code shown), «👁 پیش‌نمایش», «🔣 متغیرها»,
  «📄 دیدن متن پیش‌فرض», and «↩️ پیش‌فرض», asked first, its yes red. «✨ ایموجی‌های پرمیوم» lists the premium emoji the bot
  knows — one taken off after a question — and adds more, as `/emoji` does
  ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)).
- **«💎 ایموجی پرمیوم متن‌ها»** (the owner's and an agent's), under «📝 متن‌های ربات» and from «✨ ایموجی‌های پرمیوم» —
  the panel's card of the same name ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md#premium-emoji-in-every-text)),
  each change saved at once; «🔎 جستجوی تنظیمات» finds it as «ایموجی پرمیوم» or «premium emoji». Its card says whether the
  bot can send premium emoji («✅ می‌تواند ایموجی پرمیوم بفرستد», «⚠️ نمی‌تواند ایموجی پرمیوم بفرستد» with why, «❔
  نامشخص، هنوز بررسی نشده») and «آخرین بررسی», the switch's state, how many emoji the texts carry and how many have a
  premium one («ایموجی‌های متن‌ها: ۸۵», «پرمیوم شده: ۳ از ۱۰۰»):
  - **«🔍 بررسی دوباره»**, in the shop's own bot only: the bot sends its short test message to this chat, reads it and
    deletes it, then says what it found. In another shop's bot — the owner on an agent's shop — it is not offered: that
    bot has no chat with you, and the agent checks from their own bot or panel.
  - **The switch** says the state a tap sets («▶️ روشن کردن «ایموجی پرمیوم متن‌ها»», «⏸ خاموش کردن …»). Until the bot is
    found able it reads «🔒 روشن کردن، بعد از بررسی موفق», and a tap is answered with why; one already on goes off at a tap.
  - **The emoji of the texts**, 40 a page: a button for every emoji the bot's texts carry — the start menu's too while it
    is «دکمه‌های شیشه‌ای» —, the most used first, «💎» beside one that has a premium emoji. A tap opens its page (one no
    text carries any more since the list was shown says «این ایموجی دیگر در فهرست نیست» and shows the list): «استفاده»
    («در ۲۹ متن», «در ۲ متن و ۱ دکمه منو», «فعلا در هیچ متنی نیست») and its «ایموجی پرمیوم» («ندارد» while none), with:
    - «💎 انتخاب ایموجی پرمیوم», or «🔁 تغییر ایموجی پرمیوم»: the bot's premium emoji that stand for no other emoji yet,
      numbered, 24 a page; the one picked is saved at once. Not offered while 100 emoji have one and this one has none.
    - «🗑 برداشتن» asks first — «ایموجی پرمیوم این ایموجی برداشته شود؟», the texts back to the plain emoji, the premium
      one kept in «✨ ایموجی‌های پرمیوم».
  - **«➕ ایموجی دیگر»** (gone once 100 emoji have one), for an emoji no text carries yet: the bot's premium emoji that
    stand for no other, numbered, 24 a page; picked, it asks which plain emoji it stands for — «✔️ همین … بماند» keeps
    its own, or send another. What is not one emoji is refused with the panel's words, the question kept; one that has a
    premium emoji already opens its page, to change it there. While the bot knows none, it says how to show it some with `/emoji` («✨ افزودن ایموجی
    تازه» in the shop's own bot).
- **«⌨️ کیبوردها»** — the keyboards, the start menu one tap in. A keyboard: a card of its type, layout and size, its
  buttons drawn as customers see them, each a tap to its own card (its action, words, icon, colour and place): «✏️ متن
  دکمه», «🎨 رنگ دکمه» (each colour shown as itself), «✨ آیکون دکمه», «🔁 کار دیگر», moved («➡️ به راست», «⬅️ به چپ»,
  «🔼 ردیف بالا», «🔽 ردیف پایین»), «🗑 حذف دکمه». «➕ افزودن دکمه», «↕️ ردیف‌ها» (a row moved or deleted), «✏️ نوع
  کیبورد» (reply or inline), «↩️ پیش‌فرض» back to the default layout, asked. Each change is saved at once.
- **«📢 کانال‌های اجباری»** — a card of the join switch and the channels, «🟢» on a channel where the bot is its admin,
  «⚠️» where it is not; the join switch (turning it on asks first); a channel added by its link; a channel's page with
  «🔍 بررسی دوباره», «📋 کپی لینک», moved, deleted.
- **«📋 گروه گزارش‌ها»** — «🔗 ساخت لینک اتصال» and its steps, «📋 کپی پیام اتصال»; connected: a card of its state, its
  topics by their state, «📨 پیام تست», «🔍 بررسی دوباره», «🧵 بخش‌های گزارش» (each topic a chip, switched at a tap),
  «🔗 اتصال گروه دیگر», «✂️ قطع اتصال» (asked) ([Report group](Telegram-Group.md)).

### Servers and agents

- **«🖥 سرورها»** (the owner's) — a row a server: its state, its services and its inbounds for sale («🟢 آلمان · ۵
  سرویس · ۲/۲ اینباند»). A server's card — its state, connector, panel address, inbounds, services —: «🔌 بررسی اتصال»
  (blue), «🔄 خواندن اینباندها از پنل», «📡 اینباندها» (each a button «🟢» for sale or «⏸» not, a tap the other),
  «✏️ تنظیمات اتصال» (its connector's own form), «🧪 اکانت تست», «🎁 افزودن زمان و حجم» to its services, «🔀 انتقال
  سرویس‌ها» to another server, «📋 کپی آدرس پنل», and «🗑 حذف سرور» in red. **«➕ افزودن سرور»**: the connector, its form
  a field at a time — a secret typed is never shown back —, «🧪 تست اتصال», «✔️ افزودن سرور» ([Servers](Servers.md)).
- **«🤝 نمایندگان»** (the owner's) — a card of the program, its «درخواست‌های در انتظار» saying how many are renewals
  («۳ (۱ تمدید)»); «📥 درخواست‌ها» (by state, by kind — «هر دو نوع», «🤝 نمایندگی», «🔄 تمدید» —, «↕️ تازه‌ترین اول»
  or «قدیمی‌ترین اول»; a request to become an agent approved with a level, credit, end and monthly minimum — «✔️ تایید
  و نماینده کردن» —, or rejected), «🤝 نماینده‌ها» (by level, «↕️» — joined, balance, the bot's traffic or sales, each
  both ways), «🏷 سطح‌ها» and «⚙️ تنظیمات نمایندگی». An agent: a card and «📋 شرایط فروش»; «✏️ سطح، اعتبار و شرایط
  فروش» (blue), «📦 حجم ربات» (traffic added or taken), «🙈 پنهان از فروشگاهش» (servers and plans), «🏪 باز کردن
  فروشگاه», «▶️ ادامه فروش», and in red «⏸ توقف فروش» and «🚫 لغو نمایندگی» ([Agency](Agency.md)).
  - **A renewal request** — «🔄» on its row before the name. Its screen, «🔄 درخواست تمدید نمایندگی #…»:
    «نماینده» («دیگر نماینده نیست» once their agency ended), «پایان نمایندگی» — their end now, «(۵ روز مانده)» or
    «(تمام شده)» —, while it waits «تمدید با مدت نمایندگی» (the end the program's term gives), once approved «تمدید تا».
    «✅ تایید» opens «🔄 تمدید نمایندگی …»: «پایان فعلی» and «پایان تازه», at first the program's end («· با مدت
    نمایندگی»). «✏️ پایان تازه» asks another as an agent's end is asked — «🔁 …» back to the program's, «بدون پایان»,
    «۳۰ روز دیگر», «۹۰ روز دیگر», «۱۸۰ روز دیگر», «۳۶۵ روز دیگر», or days or a Jalali day typed —, and «✔️ تایید و
    تمدید» (green) approves; «🗑 کنار گذاشتن» leaves a changed end, asked first. With no term in the program («مدت
    نمایندگی» 0) the card says to pick the end yourself, «⚠️ انتخاب نشده» — no approve until you do —; an agent left
    with no end is said to have none, to reject or give one. «🚫 رد» asks «درخواست تمدید … رد شود؟»: «✍️ رد با توضیح»
    (a note the agent reads) or «⏭ رد بدون توضیح» ([Agency](Agency.md#renewing-an-agency)).

### Settings

- **«🤖 تنظیمات ربات»** (the owner and an agent) — the bot's own cards, two a row: «⚙️ عمومی», «🪪 نام کاربری سرویس»,
  «👛 کیف پول», «💱 انتقال موجودی», «🏧 پرداخت کارت به کارت», «🔳 کد QR», «🔁 تمدید سرویس», «🧩 حجم، زمان و کاربر
  اضافه», «⏰ یادآوری به مشتری», «🧹 حذف سرویس‌های منقضی‌شده», «🔀 انتقال سرویس» and «📎 افزودن سرویس با لینک» (the main
  shop's). The bot switched off is said first. A feature's card lives with the feature ([above](#the-home)); the menu's
  folded help says where. An extra's offered amounts are typed on one line, a price after `=` where it has its own
  (`10=30000، 20=55000، 50`); a plan, as the referrals' reward, is picked among the plans; renewal's «🔂 تمدید خودکار»
  is a card of its own, a button on «🔁 تمدید سرویس»; the QR card shows its background («👁 دیدن تصویر»), takes a
  picture sent as a new one («📤 آپلود تصویر جدید» — a photo, or an image file for its full quality), and puts the
  shop's back (asked) ([Bot settings](Bot-Settings.md)).
- **«🛡 مدیران ربات»** (the owner and an agent) — [above](#the-shops-admins): «کار مدیران از ربات», and its grants in
  the part «➕ کارهای بیشتر».
- **«🌐 تنظیمات وب‌سایت»** (the owner and an agent) — how the website stands, as a card, then its cards: «🔌 اتصال» (on
  or off, its address and other origins, the API's address and store key with «📋 کپی آدرس» and «📋 کپی کلید», «🔑 ساخت
  کلید جدید» asked first), «✈️ ورود با تلگرام» (the BotFather steps, the bot's id, Client ID, and a Client Secret typed,
  never shown back, that can be cleared), «🔵 ورود با گوگل» (the steps, Client ID), «📧 ورود با ایمیل» (a warning while no
  email goes out, «📧 تنظیمات ایمیل پنل», and «🛡 تایید امنیتی»: none, Turnstile or ALTCHA with its form), «📱 ورود با
  موبایل» (the panel's SMS warnings), «⭐️ نظرات» and «🛡 مدیران سایت» ([Website](Website.md)).
- **«📱 مینی‌اپ تلگرام»** (the owner and an agent) — «🔌 اتصال» (on or off, the menu button and its words, the app's
  address with «📋 کپی آدرس», the bot, the https warning, «🔁 تنظیم دوباره دکمه منو», the BotFather steps; Telegram
  refusing the menu button is said in a popup), «🎨 ظاهر» (name, colour, full screen, the announcement and its link,
  reviews), «🖼 لوگو» (the screen is the logo: «📤 آپلود لوگو», a photo or an image file; «🗑 حذف لوگو» asked) and «👁
  پیش‌نمایش» (a customer picked or searched for, a link that opens the app as them, light or dark)
  ([Mini app](Mini-App.md)).
- **«🧰 تنظیمات پنل»** — where `config.php` is and whether it can be written, then the panel's sections in its order
  ([Panel settings](Panel-Settings.md)). The owner has them all in the main shop; with an agent's shop picked, the
  update, «🛰 دریافت پیام‌ها» and that shop's backups; an agent, their own shop's backups. «ظاهر» is a browser's: the bot
  is in your Telegram's own theme. A secret typed into any of these cards is deleted from the chat, never shown back, and
  kept encrypted with the app's key until it is saved; the cron address and your panel password are never kept:
  - `config.php`'s groups, each a draft saved once with «💾 ذخیره», a refusal under its field: «🤖 ربات تلگرام» (the
    token — «🧪 بررسی توکن» fills the username in —, the API's address, the poll's timeout, the webhook secret and a new
    one; your current password asked only when the API's address moves), «🗄 دیتابیس» (its driver's form, «🧪 تست
    اتصال»), «🏷 برنامه» (name, address, a time zone found by typing part of its name, debug), «📧 ایمیل» (a driver or
    none, the sender, «📧 ارسال ایمیل تست»), «📨 پیامک» (a driver or none, the agents' switch, «💰 اعتبار حساب»,
    «📱 ارسال پیامک آزمایشی» showing its code) and «🔧 پیشرفته» (log level, session length, timeout, secure cookie, the
    cron token — the cron address and cPanel's line at a tap).
  - «⬆️ به‌روزرسانی» — the version, the last check, the newest release with its notes, what holds it back and the update
    by hand; «⬇️ به‌روزرسانی به نسخه …» after the panel's question, then its steps with their progress, «▶️ ادامه
    به‌روزرسانی» a step a tap; «🔁 تلاش دوباره», «⏹ لغو به‌روزرسانی», «↩️ بازگرداندن نسخه قبلی» (asked), «🔍 بررسی نسخه
    تازه».
  - «⏱ زمان‌بندی کارها» — the tasks in the order they run, last and next run, a warning while the cron never ran with
    «🔧 راه‌اندازی Cron»; each timing changed from its default marked, «↩️ پیش‌فرض» on its question, and «↩️ بازگشت به
    همه پیش‌فرض‌ها» (asked).
  - «💾 پشتیبان‌گیری» — «📥 دریافت فایل پشتیبان» (made now, sent as a file); the report group's switch, how often, the
    last sent or why it failed; «♻️ بازگردانی از فایل»: «📤 فرستادن فایل پشتیبان», then see what it holds, «📋 ردیف‌های
    هر جدول», its warnings and what is lost, then «✔️ بله، می‌دانم؛ بازگردانی شود»; afterwards «📥 دریافت حالت قبل», and
    the states kept from before each restore, each sent as a file.
  - «🚚 انتقال از ربات دیگر» — the imports so far; «➕ انتقال تازه»: the old bot, then its backup sent here or
    «🔌 اتصال به دیتابیس»; reading and writing go a step a tap (about 15 seconds each); the review — what was found,
    «🖥 پنل‌ها», «✅ چه چیزهایی منتقل شود», «📋 آنچه نیامد», the warnings —; «▶️ شروع انتقال» or «⏹ لغو انتقال» after
    the panel's questions; the report, the problems by reason, «🗑 حذف گزارش» ([Importing](Importing.md)).
  - «🛰 دریافت پیام‌ها» — the version and PHP's (an unsafe one warned of), the bot's state, the cron, the webhook's mode
    and address, the https warning, «🔗 ثبت Webhook» and «⛓ برداشتن Webhook» (asked), and what became of each bot.
  - «🔐 ورود به پنل» — changes nothing here: the panel's username and password change in the panel alone (saving there
    signs other browsers out, which a chat cannot stand in for); it leads to «👑 حساب تلگرام مالک».
- **«👑 حساب تلگرام مالک»** (the owner's) — [above](#your-own-telegram-account).

## Another shop

The owner works any shop, as the panel's shop switcher does: «🏪 تعویض فروشگاه» on the home lists the main shop and every
agent's — a page at a time, «🔎 جستجو» by name or @username —, «✅» on the one on screen. Picking one opens its home, and
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
- **Every setting the panel has, the bot has** — or says why the panel alone sets it, as the settings search does
  ([above](#the-settings-search)).
- **Decisions keep who made them**: the owner's as the panel's login (which everyone else reads as «پشتیبانی»), an
  agent's as their bot, an admin's by their name. A ticket's answer from the bot reads «مدیریت ربات» in the panel and
  «از مدیریت ربات» in the report group; the customer reads «🎧 پشتیبانی», as always.
- **The shop's log has a line for every change asked from the bot** — who, as what, which change, and the panel's
  answer — beside the panel's own lines.
- **The Telegram account is the sign-in**: the bot asks no password, and no recent sign-in before an approval, a
  Rikhtam attach or a grant's work, as the website's admin API asks of its staff — whoever holds the account in
  Telegram manages as it. Take an admin's role away, or unlink an owner account, the moment a phone is lost.

## What it does not do

The first three, the panel does not do either:

- **A broadcast is sent when you confirm it**: there is no scheduling one for later.
- **Tickets are not assigned** to one admin: every admin of the shop sees and answers every ticket.
- **The payments are not filtered by their method**: by their state, their days and a search alone.
- **Telegram's file limits** hold: a bot is handed files of up to 20 MB and sends files of up to 50 MB ([below](#watch-out-for)).

## Watch out for

- **A linked account is your whole panel.** Anyone holding that Telegram account manages every shop: link your own
  accounts alone, turn on Telegram's two-step verification, and let an account go the day you lose it.
- **Taking the admin role from your own account** asks first: you and an agent keep «⚙️ مدیریت» and still pass the bot's
  conditions, but the report group's buttons answer admins alone; an admin of the shop loses the management with it.
- **Telegram's file limits**: a bot is handed files of up to 20 MB and sends files of up to 50 MB. A larger backup, or
  a larger file to import, goes through the panel; the screens say so, and a file Telegram refuses is said in a popup.
- **Colours and copy buttons are Telegram's**: an older Telegram app shows every button plain, and copies nothing at a
  tap — the value is still on the card, to copy by hand.
- **The management is the private chat's**: the report group keeps its own buttons and `/manage`, which «مدیران ربات»
  does not touch ([Report group](Telegram-Group.md)).
