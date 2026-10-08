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

Only someone with the host's files can read the key; wrong keys are counted like wrong passwords
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
| «فروشگاه» | «پلن‌ها», «دسته‌بندی‌ها», «روش‌های پرداخت» ([Plans](Plans.md), [Payment methods](Payment-Methods.md)) |
| «فروش» | «کاربران», «سفارش‌ها» (stuck orders counted), «پرداخت‌ها» (receipts to review counted), «اشتراک‌ها», «زیرمجموعه‌گیری» — and the owner's «نمایندگان» ([Customers](Customers.md), [Orders and payments](Orders-And-Payments.md), [Services](Services.md), [Referrals](Referrals.md), [Agency](Agency.md)) |
| «ربات» | «کیبوردها», «متن‌های ربات», «ارسال همگانی» ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md), [Broadcasts](Broadcasts.md)) |
| «تنظیمات» (at the foot) | «تنظیمات ربات», «تنظیمات وب‌سایت» and «تنظیمات پنل» ([Bot settings](Bot-Settings.md), [Website](Website.md), [Panel settings](Panel-Settings.md)) |

The counts beside «پشتیبانی», «نظرات», «سفارش‌ها» and «پرداخت‌ها» are read again whenever those lists move, and every minute; a
folded group, or the icon rail, shows a dot for the most pressing one instead. A group folds when its heading is
pressed, and the browser remembers it; the group of the page on screen is always open.

**Pages made of sections** list their sections in the sidebar in the menu's place, each with an address of its own:
«کاربران» (the customers, «گروه‌ها»), «زیرمجموعه‌گیری» («معرف‌ها», «زیرمجموعه‌ها», «پورسانت‌ها»), the owner's
«نمایندگان» («درخواست‌ها» — with the number waiting —, «نماینده‌ها», «سطح‌ها», «تنظیمات») and «ارسال همگانی» («پیام همگانی»,
«هدیه همگانی»), and the settings. «منوی اصلی» shows the menu again without leaving the page; on a phone a «بخش» picker
under the page's title does it. Moving between a page's sections keeps what was typed in each.

**The icon rail**: the sidebar folds to icons with «جمع کردن سایدبار» or Ctrl+B (⌘+B on a Mac); a group's pages open
from its icon. The browser remembers the choice; until one is made, a window narrower than a tablet's shows the rail.
**On a phone** there is no sidebar: «باز کردن منو» in the top bar opens it as a drawer, beside the page's name and the
dark/light switch. Dialogs open as a full-width sheet from the bottom of the screen.

## The dashboard

What the shop did over a range and what waits on someone, read again every minute and as the shop moves.

- **The range** — «۷ روز گذشته», «۳۰ روز گذشته» (the default) or «۹۰ روز گذشته»: the shop's calendar days, today among
  them, compared with as many days before them. It is not remembered between visits.
- **The figures** — «درآمد»: the money that came in — card payments and the like, by when they were paid; a purchase
  from the wallet is not counted again (its money was counted when the wallet was charged), and a refunded wallet
  top-up is not counted at all. «سفارش‌ها»: every order placed, of any kind and in any state — unpaid and cancelled ones
  too, so it is not sales. «کاربران جدید»: customers who joined. «اشتراک‌های فعال»: services running now, with how many
  end within three days. Each of the first three shows its change against the period before («بدون مقایسه» when there
  was nothing then).
- **The chart** — revenue, orders or newcomers a day; hover over a day, or focus the chart and use the arrow keys, to
  read it.
- **«نیازمند توجه»** — what waits on a person now, whatever the range: «پرداخت‌های در انتظار بررسی», «سفارش‌های نیازمند
  رسیدگی», «تیکت‌های در انتظار پاسخ», «نظرات در انتظار بررسی», «سرورهای دارای خطا» (the main shop's dashboard alone), «اشتراک‌های رو به انقضا
  (۳ روز)» and «سفارش‌های در انتظار پرداخت» — each a link to its list, filtered. With nothing waiting it says
  «همه‌چیز مرتب است». An agent's bot whose traffic no longer covers its smallest plan is said first, with how to buy more.
- **«آخرین سفارش‌ها»** and **«مشتریان جدید»** — the latest eight of each; a number opens the order, a name the customer's
  page.
- **«وضعیت سیستم»** (the owner's) — the version and PHP's, the open shop's bot — «راه‌اندازی نشده» without a token,
  «خاموش» when it gets no updates (no webhook, and no poller seen in the last two minutes: «ثبت Webhook» leads to the
  fix), «غیرفعال از تنظیمات» while its switch is off, else «Webhook» or «bot:poll» with when its last message came —
  and the «Scheduler»: «فعال» with its last run, or «تنظیم نشده» with a way to its cron setting.

Watch out: «Webhook» means only that a webhook was registered — the last message's time says whether updates arrive. A
server stays among «سرورهای دارای خطا» until its panel answers again; switching the server off does not take it out.

## An agent's account

«حساب نمایندگی», an agent's alone: the traffic their bot may still sell («حجم باقی‌مانده»), their level's price per GB,
their wallet with the shop and the credit it may go below zero by; their bot («ربات شما»: its @username and state —
«فعال», «غیرفعال», «وصل نشده», «مشکل دارد» —, when it was first connected, and what to do when it is not); the traffic's
ledger («گردش حجم»: purchases, sales, renewals, traffic added to services, traffic given back, corrections by support,
each with the balance after it); and «ورود به پنل», whose «خروج از مرورگرهای دیگر» signs every other browser out of
their panel. Traffic is bought in the main bot («نمایندگی» › «💾 خرید حجم»), not here ([Agency](Agency.md)).

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
  browser's back and a link sent to someone show the same list.

Lists the owner orders by hand — plans, categories, payment methods, groups, levels, channels — have ▲▼ instead, and
no pages.

## Live, and safe to leave

- **The panels follow the shop** without a reload: a payment approved in the report group, a new order in the bot, a
  ticket answered in another tab show up within seconds — while the tab is in view (a tab in the background catches up
  as it comes back). What you are typing is never replaced.
- **Unsaved changes** are asked about — «تغییرات ذخیره نشده‌اند», «رها کردن تغییرات» or «ماندن» — before another page,
  signing out, another shop, or closing a dialog — or going back in «افزودن سرور» and «افزودن روش پرداخت» — and only
  when something was changed: a value changed and typed back as it was is no change. A reload or closing the tab gets
  the browser's own question.
- **Forms**: one that adds something — a plan, a server, a grant — has no «بازگردانی تغییرات»; an edit form has it, and
  both it and the save are held while nothing differs from where the form started.
- **Offline**, a strip over the page says so, and the lists wait; a save made meanwhile fails rather than waits — send it
  again once the strip is gone. The same strip says when the server stops answering.
- **A failure** says what failed and why, in plain words, with «تلاش دوباره» where trying again may help. A failure of
  the server's own ends with «کد پیگیری: …»: the shop's log has that request's lines under it
  ([health and logs](Running-In-Production.md#health-and-logs)). «جزئیات فنی» under a failure holds what support needs,
  and «کپی جزئیات» copies it.
- **After an upgrade**, a tab opened before it reloads itself once to the new panel.

## The look

Dark or light: «تنظیمات پنل › ظاهر», or the switch in a phone's top bar. Dark is the default; the choice is kept in the
browser, for both panels. Dates are Jalali and read in the shop's time zone (`APP_TIMEZONE`), whatever the browser's;
quantities, money and dates use Persian digits, while numbers that are identifiers — `#12`, a Telegram id, a port —
keep Latin ones.
