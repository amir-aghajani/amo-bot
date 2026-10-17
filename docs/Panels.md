[فارسی](fa/Panels.md)

# The panels

Two panels, one for each kind of person who runs a shop:

- **The owner's panel**, `/admin/` — the main shop and every agent's, the servers, the agents and the installation's
  settings. Signed in with the username and password `config.php` keeps.
- **An agent's panel**, `/agent/` — their own bot's shop and nothing else, and their account with the main bot. Signed
  in with a one-time link the main bot gives them.

The shop's screens are the same in both. Where the owner has more, this guide says so.

## Signing in

**The owner** signs in at `/admin/` with «نام کاربری» and «رمز عبور». A wrong pair is «نام کاربری یا رمز عبور اشتباه
است.»; after 10 failures from one address in 15 minutes — or 50 against the username from everywhere together — the
page waits, with a countdown, before it takes another try. A page asked for while signed out is opened again after the
sign-in, and a session ended mid-work says so: «زمان ورود شما تمام شد؛ دوباره وارد شوید تا به همان صفحه برگردید.» A
session left unused for two hours (`SESSION_LIFETIME`) ends; an open tab keeps it alive.

**A lost login** comes back without a shell: «رمز را فراموش کرده‌اید؟» on the sign-in page →

1. «ساختن کلید» writes a one-time key to `storage/recovery-key.txt` on the host. It works for an hour (asked again
   meanwhile, it is the same key).
2. Open that file in the host's File Manager, copy its text into «کلید بازیابی», and type the new «نام کاربری», «رمز
   عبور تازه» (8 characters at least) and «تکرار رمز عبور».
3. «ذخیره و ورود» sets the new login, signs this browser in under it and every other browser out, and deletes the key.

Only someone with the host's files can read the key. Wrong keys have a count of their own, apart from wrong passwords:
after 10 from one address in 15 minutes, the page waits before it takes another
([Running in production](Running-In-Production.md#the-panels-login)).

**An agent** signs in with the link the main bot gives them: «نمایندگی» › «🔐 ورود به پنل». It works once, for 10
minutes, and a new one replaces the last; its code rides in the address's `#` part, which never reaches a server's
logs. Without a link the page says where to get one, and a used or expired link says so the same way. When another
agent is signed in in the same browser, the page asks first («ورود به پنل نمایندگی دیگر») — the link is spent only on
«ورود با این لینک». An agent's session ends by itself when their agency ends.

**Signing out** is the icon button «خروج از حساب» at the foot of the sidebar, beside who is signed in («مالک فروشگاه», or
«نماینده» with the agent's bot). It signs that panel out in every tab of the browser; the owner's and an agent's panels
are separate sessions, so signing out of one leaves the other. To sign every *other* browser out, the owner changes
their login («تنظیمات پنل › ورود به پنل»); an agent presses «خروج از مرورگرهای دیگر» on «حساب نمایندگی».

## The shop on screen

The owner's panel works in one shop at a time, and the shop is in the address: `/admin/…` is the main shop,
`/admin/s/<id>/…` an agent's. So each browser tab keeps its own shop, and a bookmark or a reload stays in it.

Once an agent has a shop, the **shop picker** sits under the brand: «فروشگاه‌ها» lists the main shop («فروشگاه اصلی») and
every agent's, by their bot's name — «نماینده: …» while they have not handed their bot over yet —, and a shop whose
agency ended is marked «غیرفعال» (it still opens, but its bot does not run and its agent cannot sign in). Picking one
asks about unsaved changes, then opens that shop's dashboard. The agents list's «باز کردن فروشگاه» does the same, and
opens in a new tab with the browser's own new-tab click. The brand at the top of the sidebar and the tab's title name the
shop on screen.

While an agent's shop is open, everything in the sidebar is that shop's — its plans, customers, orders, settings and
website — but for what is the whole installation's, which stays the same whatever shop is open: «سرورها» (every shop's
services), «نمایندگان», «هدیه همگانی» and «تنظیمات پنل». A decision the owner makes in an agent's shop is recorded under
the owner's login, which the agent sees as «پشتیبانی».

## The menu

The sidebar, top to bottom:

| Entry | What it is |
|---|---|
| «داشبورد» | The shop at a glance ([the dashboard](#the-dashboard)) |
| «پشتیبانی» | Support tickets, with the number waiting on an answer ([Support tickets](Support-Tickets.md)) |
| «نظرات» | Customers' reviews from the shop's website, with the number waiting on you ([Reviews](Reviews.md)) |
| «سرورها» — the owner's | The VPN panels the shop sells on ([Servers](Servers.md)) |
| «حساب نمایندگی» — an agent's | Their account with the main bot ([below](#an-agents-account)) |
| «فروشگاه» | «پلن‌ها», «اکانت تست», the main shop's «سرویس دلخواه», «دسته‌بندی‌ها», «روش‌های پرداخت» ([Plans](Plans.md), [Test accounts](Test-Accounts.md), [Custom services](Custom-Services.md), [Payment methods](Payment-Methods.md)) |
| «فروش» | «کاربران», «سفارش‌ها» (stuck orders counted), «پرداخت‌ها» (receipts to review counted), «اشتراک‌ها», «زیرمجموعه‌گیری» — and the owner's «نمایندگان» ([Customers](Customers.md), [Orders and payments](Orders-And-Payments.md), [Services](Services.md), [Referrals](Referrals.md), [Agency](Agency.md)) |
| «تخفیف و جوایز» | «کدهای تخفیف», «فروش ویژه», «سطوح وفاداری», «کش‌بک», «کدهای هدیه», «گردونه شانس», «قرعه‌کشی» ([Discounts](Discounts.md), [Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md), [Gift codes and the lucky wheel](Gift-Codes-And-Lucky-Wheel.md)) |
| «ربات» | «کیبوردها», «متن‌های ربات», «ارسال همگانی» ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md), [Broadcasts](Broadcasts.md)) |
| «تنظیمات» (at the foot) | «تنظیمات ربات», «تنظیمات وب‌سایت» and «تنظیمات پنل» ([Bot settings](Bot-Settings.md), [Website](Website.md), [Panel settings](Panel-Settings.md)) |

The counts beside «پشتیبانی», «نظرات», «سفارش‌ها» and «پرداخت‌ها» are read again whenever those lists move, and every minute; a
folded group, or the icon rail, shows a dot for the most pressing one instead. A group folds when its heading is
pressed, and the browser remembers it; the group of the page on screen is always open.

**Pages made of sections** list their sections in the sidebar in the menu's place, each with an address of its own:
«کاربران» (the customers, «گروه‌ها»), «زیرمجموعه‌گیری» («معرف‌ها», «زیرمجموعه‌ها», «پورسانت‌ها», «هشدارها»), the owner's
«نمایندگان» («درخواست‌ها» — with the number waiting —, «نماینده‌ها», «سطح‌ها», «تنظیمات»), «کدهای تخفیف» («کدها»,
«تنظیمات»), «گردونه شانس» («جوایز», «چرخش‌ها», «تنظیمات»), «اکانت تست» («اکانت‌ها», «سرورها», «تنظیمات»), «ارسال
همگانی» («پیام همگانی», the owner's «هدیه همگانی»), and the settings. «منوی اصلی» shows the menu again without leaving
the page; on a phone a «بخش» picker under the page's title does it. Moving between a page's sections keeps what was
typed in each.

**The icon rail**: the sidebar folds to icons with «جمع کردن سایدبار» or Ctrl+B (⌘+B on a Mac); a group's pages open
from its icon. The browser remembers the choice; until one is made, a window narrower than a tablet's shows the rail.

**The top bar** stays at the top of every page while it scrolls: the page's name, and the dark/light switch («تم روشن» /
«تم تاریک»). The sign-in screens and the installer have it too, with the switch alone. **On a phone** there is no
sidebar: «باز کردن منو» in the top bar opens it as a drawer. Dialogs open as a full-width sheet from the bottom of the
screen, without the keyboard: you see the whole sheet first, and the keyboard comes when you tap a field. Only the bot
text editor, where writing is the whole point, opens with its field ready to type. The sheet keeps only its title and ✕
at its top — its description scrolls with the rest —, and a form's buttons, like a receipt review's decisions, stay at
its foot.

## The dashboard

What the shop did over a range and what waits on someone, read again every minute and as the shop moves.

- **The range** — «۷ روز گذشته», «۳۰ روز گذشته» (the default) or «۹۰ روز گذشته»: the shop's calendar days, today among
  them, compared with as many days before them. It is not remembered between visits.
- **The figures** — «درآمد»: the money that came in — card payments and the like, by when they were paid; a purchase
  from the wallet is not counted again (its money was counted when the wallet was charged), and a refunded wallet
  top-up is not counted at all. «سفارش‌ها»: every order placed, of any kind and in any state — unpaid and cancelled ones
  too, so it is not sales. «کاربران جدید»: customers who joined. «اشتراک‌های فعال»: services running now, free tests
  aside, with how many end within three days. Each of the first three shows its change against the period before
  («دوره قبل رقمی برای مقایسه نداشت» when there was nothing then).
- **The chart** — revenue, orders or newcomers a day; hover over a day, or focus the chart and use the arrow keys, to
  read it.
- **«نیازمند توجه»** — what waits on you now, whatever the range, each a link to its list, filtered. Its line counts
  your own queues alone («۳ مورد منتظر اقدام شماست»); with none of them waiting, the card says «همه‌چیز مرتب است»
  instead:
  - «پرداخت‌های در انتظار بررسی», «پرداخت‌های نگه داشته شده» (online payments
    [held for you](Orders-And-Payments.md#a-payment-held-for-support)), «سفارش‌های نیازمند رسیدگی», «تیکت‌های در
    انتظار پاسخ», «نظرات در انتظار بررسی» and «سرورهای دارای خطا» (the main shop's dashboard alone);
  - first, an agent's selling suspended — or its end, or a month short of its minimum, near — and their bot's traffic
    no longer covering its smallest plan: to the agent, with what to do; to the owner in an agent's shop, of the agent,
    with a link to «نمایندگان»;
  - under them, apart and not counted, «در انتظار مشتری» — what waits on customers: «اشتراک‌های رو به انقضا (۳ روز)»
    (free tests aside) and «سفارش‌های در انتظار پرداخت».
- **«آخرین سفارش‌ها»** and **«مشتریان جدید»** — the latest eight of each; a number opens the order, a name the customer's
  page.
- **«وضعیت سیستم»** (the owner's) — the version and PHP's, the open shop's bot — «وصل نشده» without a token,
  «مشکل دارد» when it gets no updates (no webhook, and no poller seen in the last two minutes: «ثبت Webhook» leads to the
  fix), «غیرفعال از تنظیمات» while its switch is off, else «Webhook» or «پولینگ» with when its last message came —
  and «کارهای زمان‌بندی‌شده» (the scheduler): «فعال» with its last run and how many tasks it ran, or «تنظیم نشده» with
  «راه‌اندازی Cron», a link to its cron setting.

Watch out: «Webhook» means only that a webhook was registered — the last message's time says whether updates arrive. A
switched-on server stays among «سرورهای دارای خطا» until its panel answers again. Switching a server off takes it out
of the count; switched on again, it counts until its panel answers.

## An agent's account

«حساب نمایندگی», an agent's alone: the traffic their bot may still sell («حجم باقی‌مانده»), their level's price per GB,
their wallet with the shop and the credit it may go below zero by; their bot («ربات شما»: its @username and state —
«فعال», «غیرفعال», «وصل نشده», «مشکل دارد» —, when it was first connected, and what to do when it is not); the traffic's
ledger («گردش حجم»: purchases, sales — each free test their bot made among them, «اکانت تست · سرویس …» —, renewals,
traffic added to services, traffic given back, corrections by support, each with the balance after it); and «ورود به
پنل», whose «خروج از مرورگرهای دیگر» signs every other browser out of their panel. Traffic is bought in the main bot
(«نمایندگی» › «💾 خرید حجم»), not here ([Agency](Agency.md)).

«شرایط فروش» beside their bot: their agency's end, this month's traffic bought against their monthly minimum (and
whether this month is held to it), and whether their bot sells. While the main shop has their bot's selling suspended —
or its end, or a month short of its minimum, is near — the page and their dashboard's «نیازمند توجه» say so, why, and
what lifts it, and a strip over every other page of their panel leads here ([Agency](Agency.md#the-terms-of-selling-an-end-and-a-monthly-minimum)).
A plan the main shop keeps from sale is marked «پنهان‌شده توسط پشتیبانی» on «پلن‌ها». A server it keeps from their shop
is not among their plan form's choices, and a plan already on it says the server is hidden.

## Lists

Most lists are read a page of 25 rows at a time, and work the same way:

- **Tabs** by status, «همه» first; the one that waits on someone carries its count («در انتظار بررسی» of the payments,
  «نیازمند رسیدگی» of the orders, «در انتظار پاسخ» of the tickets).
- **Filters** as pills — «وضعیت همه», «سرور همه» … —, set to «همه» for none. Orders and payments take «تاریخ»: «امروز»,
  «دیروز», «۷ روز اخیر», «۳۰ روز اخیر», «این ماه», «ماه قبل» (Jalali months) or «بازه دلخواه…», a calendar where the first
  press picks the first day and the second the last, and the takings of the list's rows show above it. A list opened
  from a customer's page carries «مشتری … ✕».
- **One search box**: names, handles, numbers — each list says what it searches in its placeholder. `#12` is the row
  numbered 12 and nothing else; a bare `12` also finds the 12 inside other fields.
- **Sorting** by a column's header: a first press sorts, a second turns it around, a third gives the list its own order
  back.
- **The address keeps the view**: the tab, the filters, the search, the order and the page are in it, so a reload, the
  browser's back and a link sent to someone show the same list. What the address gets wrong is put right in place: a
  filter the list does not have — a server or a customer whose id is not a number, say — is dropped, and a page past
  the end opens the last page.

Lists the owner orders by hand — plans, categories, payment methods, groups, levels, channels, the wheel's prizes —
have ▲▼ instead, and no pages.

## Live, and safe to leave

- **The panels follow the shop** without a reload: a payment approved in the report group, a new order in the bot, a
  ticket answered in another tab show up within seconds — while the tab is in view (a tab in the background catches up
  as it comes back).
- **What you are typing is never replaced.** When a settings card, a keyboard or a server's «تنظیمات اتصال» you are
  changing is saved somewhere else meanwhile — in another tab, say —, your changes stay as you typed them, and the card
  says so: «این بخش جای دیگری هم ذخیره شد…». Save, and your version takes the place of the other; or press «دیدن نسخه تازه» to put your changes
  aside and see the saved one.
- **Unsaved changes** are asked about — «تغییرات ذخیره نشده‌اند», «رها کردن تغییرات» or «ماندن» — before another page,
  signing out, another shop, or closing a dialog — or going back in «افزودن سرور» and «افزودن روش پرداخت» — and only
  when something was changed: a value changed and typed back as it was is no change. A reload or closing the tab gets
  the browser's own question.
- **Forms**: one that adds something — a plan, a server, days or traffic given to many services (on a server, or
  «هدیه همگانی») — has no «بازگردانی تغییرات»; an edit form has it, and
  both it and the save are held while nothing differs from where the form started.
- **Secrets** — a password, a token, an API key — are never shown again once saved. The field shows only a hint of the
  kept value, and says «خالی بماند، همان مقدار ذخیره‌شده می‌ماند.»: leave it blank to keep it, type to replace it, or
  press «پاک کردن مقدار ذخیره‌شده» to empty it on save (where the form can go without it).
- **Confirmations**: a press on a dialog's confirm button is held until its request is answered, so a double press
  sends it once.
- **Offline**, a strip over the page says so («اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها
  ذخیره نمی‌شوند.»). The lists wait for the connection, and every change made meanwhile — a save, an approval, a retry —
  fails at once instead of going out later: send it again once the strip is gone. When the server stops answering, the
  strip says so too («سرور پاسخ نمی‌دهد؛ …»).
- **A failure** says what failed and why, in plain words, with «تلاش دوباره» where trying again may help. A failure of
  the server's own ends with «کد پیگیری: …»: the shop's log has that request's lines under it
  ([health and logs](Running-In-Production.md#health-and-logs)). «جزئیات فنی» under a failure holds what support needs,
  and «کپی جزئیات» copies it.
- **After an upgrade**, a tab opened before it reloads itself once to the new panel.

## The look

Dark or light: «تنظیمات پنل › ظاهر», or the switch in the top bar of every screen. Dark is the default; the choice is
kept in the browser, for both panels. Dates are Jalali and read in the shop's time zone (`APP_TIMEZONE`), whatever the browser's;
quantities, money and dates use Persian digits, while numbers that are identifiers — `#12`, a Telegram id, a port —
keep Latin ones.
