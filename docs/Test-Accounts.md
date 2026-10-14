# Test accounts

A free test account («اکانت تست») is a small service a customer takes in the bot to try the shop before buying: a few
hundred megabytes for a day, say, on a server they pick, made at once and sent like a bought service. «فروشگاه › اکانت
تست», in both panels. Every shop gives its own — the main bot and each agent's —, with its own rules; what a test gets
is each server's, which the owner sets on the server's page.

## Setting it up

Tests are given only while **the shop's switch is on and at least one server gives them**. Both are off at first.

### What a test gets: each server's terms

A server's page ([Servers](Servers.md#a-servers-page)) has the card «اکانت تست», the owner's alone:

| Field | What it takes |
|---|---|
| «اکانت تست روی این سرور» | Off at first. On, customers may take a test on this server while the shop's switch is on too; its hint links to «اکانت تست › تنظیمات», where that switch is |
| «حجم (مگابایت)» | A test's traffic, 1 to 102400 MB; 500 by default |
| «مدت (ساعت)» | A test's term, 1 to 720 hours; 24 by default |
| «شروع از اولین اتصال» | On at first: the hours count from the customer's first connection. Off: from the moment the test is made — a fixed deadline, whether they connect or not |

Servers belong to the installation, so **every shop's tests on a server get these terms**, an agent's too. An agent
cannot change them: their tests page shows them, read-only. A change applies to the tests made from then on; a test
given keeps the terms it was made with.

A server gives a test only while it could take a sale too: switched on, checked and serving subscription links, with
room left in its «ظرفیت» and, on a panel with inbounds, an inbound marked «فروش»
([whether it can sell](Servers.md#whether-it-can-sell)). In an agent's shop the agent's traffic must also hold one
test's ([below](#agents-shops)).

### The shop's rules

«اکانت تست › تنظیمات», the card «قوانین اکانت تست» — every shop its own (a group of its [bot settings](Bot-Settings.md)
kept on this page):

- **«اکانت تست فعال باشد»** (off) — on, the bot's menu shows «🧪 اکانت تست» and customers take tests; off, the button is
  taken away and nobody takes a new test, while the tests given run on — the clean-up still removes them once they end.
- **«سهمیه»** ([the quota](#the-quota)):
  - «تعداد اکانت تست هر مشتری» — 1 to 100, 1 by default;
  - «دوره سهمیه (روز)» — 0 to 365, 0 by default: `0` is for good — a customer gets that many in all —; any other number
    is a window — at most that many within any so many days;
  - «سقف روزانه فروشگاه» — the most tests the shop gives in a day of its clock, every customer together, the bot's
    admins' tests too; `0` (the default) is no cap;
  - «فقط مشتری‌هایی که خرید نکرده‌اند» (off) — on, a customer who bought a service with a price takes no test.
- **«حذف خودکار»** ([the clean-up](#the-clean-up)):
  - «اکانت‌های تمام‌شده حذف شوند» (on);
  - «فاصله حذف (ساعت)» — 0 to 720, 24 by default: how long a test that ended is kept, from when it ended, before it is
    removed; `0` removes it at once;
  - «حذف اکانت وصل‌نشده (روز)» — 0 to 365, 7 by default: a test still waiting for its first connection so many days
    after it was made is removed; `0` never.

### The menu button

Customers find tests on the bot's menu: «🧪 اکانت تست», shown only while the switch is on. The shop's own layout has it
in a row of its own under «خرید اشتراک»; a menu saved on «کیبوردها» without it lacks it — the tests page warns while
the switch is on and the menu lacks the button, with a link to «کیبوردها»
([Bot texts and keyboards](Bot-Texts-And-Keyboards.md#the-menu)).

### Sharing a link

`https://t.me/<your bot>?start=usertest` opens the test screen: the greeting and the menu, then the servers under them.
Put it in a channel post or an advert. `/free`, typed in the bot, opens the same screen.

## What the customer sees

«🧪 اکانت تست», `/free` or the link opens the test screen: «🧪 اکانت تست رایگان», a line for each server giving a test now
— its name, traffic and term, and «(از اولین اتصال)» when the term counts from the first connection («🌍 آلمان: ۵۰۰
مگابایت، ۱ روز (از اولین اتصال)») —, and a button for each («🌍 آلمان»), in the servers' order.

A tap makes the test there and sends it in place of the screen, as a bought service comes — a QR card of its link, or
the link as text —: «🧪 اکانت تست رایگان شما آماده است», with its name, location, traffic, end and link, and «📊 مشاهده
سرویس» under it. From then on it is one of their services («🛍️ سرویس‌های من»), its plan named «اکانت تست».

A newcomer the bot holds back first — the phone asked for, a channel to join — still lands on the test screen once
they get through: the link waits in the chat for an hour, and the number sent, «✅ عضو شدم», `/start` or `/menu` opens
it.

When a test cannot be had — or was made but could not be sent —, the bot says so instead, each a text of its own under
«متن‌های ربات» › «اکانت تست» ([the reference](Bot-Texts-Reference.md#group-test_accounts)):

| When | The bot text | «🔐 خرید اشتراک» under it |
|---|---|---|
| The switch is off | «اکانت تست خاموش» | No |
| Newcomers only, and they have bought | «اکانت تست فقط برای کاربران جدید» | Yes |
| Their quota is used for good | «اکانت تست گرفته شده» | Yes |
| Their quota is used for now — it says when the next one is possible | «اکانت تست بعدی» | Yes |
| The shop's cap for today is reached | «سقف روزانه اکانت تست» | Yes |
| No server gives a test now — in an agent's shop, none whose test the agent's traffic covers | «سروری برای اکانت تست نیست» | No |
| The server's panel failed to make it | «ساخت اکانت تست ناموفق» — nothing of the quota is spent | No |
| The test was made, but Telegram would not take its message | «اکانت تست ارسال نشد» — with «📊 مشاهده سرویس» under it; the test counts | No |

A tap refused at that moment — the quota used meanwhile, a server that stopped giving tests since the screen was drawn,
an agent's traffic run short — draws the screen again as it is now: the servers still giving a test, or why none can be
had; «سروری برای اکانت تست نیست» only when no server is left.

## The quota

- **Each customer** takes «تعداد اکانت تست هر مشتری» tests: in all, or within any window of «دوره سهمیه» days — the
  next one is possible once the oldest of the window leaves it, and the bot says when.
- **The shop** gives at most «سقف روزانه فروشگاه» a day, counted from midnight on the shop's clock — the bot's admins'
  tests among them.
- **Newcomers only** refuses a customer with a purchase that had a price — paid at some point, whatever came of it
  since. A free plan, a gift and a test make no buyer.
- **The bot's admins** are held to the switch alone — no quota, no newcomers' rule, and the cap never refuses them —, to
  try it as often as they like; their tests still count toward the cap.
- **Two taps at once** never take more than the quota: the test is judged and counted before the panel is asked. A
  panel that fails gives it back.
- **Every test given counts**, a removed one too: the clean-up and a delete take the service away, never the record.
  Two accounts of one customer merged on the website keep both accounts' tests.

## What a test is

A test is a service marked as one, with no plan: one client on every inbound the server sells, named by the shop's test
pattern ([Bot settings](Bot-Settings.md#service-usernames)) — by default `test_` and the customer's usual name on the
panel (`test_amir_3`, or `test_USER_17` for a customer without a username) —, with their Telegram id in its comment, no
limit on devices, and the server's traffic and term. Moved to a server that has its name already, it gets the next name
the test pattern gives there.

It is not a purchase, and gets nothing a purchase gets:

- **No renewal** — no «♻️ تمدید سرویس» on its screen, not in the menu's renewals list —, **no «تمدید خودکار»**, and
  **no extra traffic, time or devices**.
- **No reminders** of its end or its traffic.
- **No gifts**: a server's «افزودن زمان و حجم» and the [mass gift](Broadcasts.md#the-mass-gift) pass it by, and the
  lucky wheel's and the nightly draw's prizes of traffic or days never go to it.
- **No purchase**: it has no order, so a customer who took one is still a newcomer for first-purchase prices, the lucky
  wheel's buyers-only rule, referral gifts and «فقط مشتری‌هایی که خرید نکرده‌اند».
- **Not counted as a sold service**: the services list's «رو به انقضا» and the dashboard's «اشتراک‌های فعال» and
  «اشتراک‌های رو به انقضا» leave it out.

It still takes room on its server: «ظرفیت» counts every active service, tests too.

In the panels' services list ([Services](Services.md#test-services)) it carries a «تست» badge where a service shows its
plan, and the filter «نوع» — «همه», «اکانت تست», «سرویس عادی», and in the main shop «افزوده‌شده با لینک» — shows the
tests alone with «اکانت تست»; «سرویس عادی» is the services sold or given, neither a test nor added by their link. Support may
still work it like any service: read it, switch it off or on, move it, give it days and traffic, delete it. On the
shop's website its service says `is_test` ([Store API](Store-API-After-Buying.md#services)).

## The clean-up

Every 15 minutes, right after the services are read from their panels, in every shop that has tests — an agent's bot
that stopped running too, since its tests sit on your panels —, each by its own shop's rules, while that shop's
«اکانت‌های تمام‌شده حذف شوند» is on, whether or not it still gives tests. The same run removes the shop's other ended
services, by their own rule ([Services](Services.md#what-runs-by-itself)); a test goes by this one alone.

- **A test that ended** — its time or traffic ran out, or its panel no longer has it — is removed once it has been over
  «فاصله حذف» hours, counted from when it ended: its deadline, else when its panel said its traffic was used up or its
  client gone.
- **A test never connected** is removed «حذف اکانت وصل‌نشده» days after it was made (one whose hours count from its
  first connection: a fixed term ends by itself).
- **Each is read from its panel again** just before, and removed only if it is still over — or still never connected:
  a test support extended, or the customer connected to, since the last read is kept.
- **Removing** deletes the client from its panel and the service from the shop. **The customer is not told** when it
  goes; its screen in the bot says beforehand when it will — «🗑️ این اکانت تست … حذف می‌شود.», once it ended or while
  it waits for a first connection the shop's days will end, a bot text you may reword or empty —, the shop's website
  reads the moment as `removes_at`, and the service's dialog in the panels shows it as «حذف خودکار». A panel that does
  not answer, or that the shop leaves alone after a failure, waits for a later run; one whose panel no longer has the
  client goes without asking it.
- **A run takes at most 100 of each** — the ended, the never connected — in a shop, the oldest first; the rest wait
  for the next.
- **A test running again** — support gave it days or traffic — starts its wait anew when it ends again.
- **A test switched off by support** is never removed by the clean-up: delete it, or switch it on.

The record of every test stays: the tests list shows it «حذف‌شده», and the quota still counts it. With the clean-up
off, ended tests stay until you delete them from «اشتراک‌ها». A test a process left half-made — it died before the panel
answered — is given back to the quota by the first run 30 minutes after it was claimed, the clean-up on or off.

## Agents' shops

An agent's bot gives tests by the agent's own rules, on the owner's servers and terms. **Each test's traffic comes out
of the agent's pool** as it is made, written with the test itself: their «گردش حجم» shows a «فروش» line «اکانت تست ·
سرویس …» with the test's name, and a test that falls through draws nothing. A server whose test traffic the pool cannot
cover is not offered; a pool that runs short at the moment a test is made refuses it, and the screen is drawn again
with what the pool still covers. Traffic support adds to a test later comes out of the pool as any extension; a removed
test gives nothing back ([Agency](Agency.md)).

## The tests page

«فروشگاه › اکانت تست», in both panels, in three sections; it follows the shop live.

### The tests

«اکانت‌ها»: every test the shop gave, the newest first: the customer — «خرید کرد» beside them when they made a purchase
with a price after it (delivered after the test, or, one never delivered, ordered after it) —, the server («سرور حذف
شده» once deleted), the terms it was given («۵۰۰ مگابایت · ۱ روز», «از اولین اتصال» or «از لحظه ساخت»), the traffic
used, where it stands, and when it was taken. A running test says the time left, and its service's name leads to it on
«اشتراک‌ها».

| «وضعیت» | Means |
|---|---|
| «در حال ساخت» | Its panel is being asked for it |
| «در انتظار اولین اتصال» | Made; its hours start at the customer's first connection |
| «فعال» | Its clock running — from the first connection, or for a fixed term from the moment it was made |
| «غیرفعال» | Switched off by support |
| «تمام‌شده» | Its time or traffic ran out, or its panel no longer has it; the clean-up removes it in time |
| «حذف‌شده» | Removed from its panel and the shop; still counted in the quota |

Search by the customer's name, username or Telegram id; «وضعیت» narrows the list to one state.

### The servers

«سرورها»: every server with what a test on it gets and whether it gives one now in this shop: «اکانت تست می‌دهد»,
«فعلا نمی‌دهد» (switched on for tests, but the server cannot take a client now — or, in an agent's shop, the pool is
short), or «خاموش». In the owner's panel a server's name leads to its «اکانت تست» card; an agent reads them only.

### The rules

«تنظیمات»: the card «قوانین اکانت تست» ([above](#the-shops-rules)).

### Warnings

Above the sections — the first two hidden on the section they link to:

- «اکانت تست خاموش است: …» — the shop's switch is off, with «روشن کردن در تنظیمات اکانت تست»;
- «الان هیچ سروری اکانت تست نمی‌دهد، …» — the switch is on and no server gives a test now, with «دیدن سرورها»;
- «دکمه «اکانت تست» در منوی ربات نیست، …» — the switch is on and the menu lacks the button, with «افزودن دکمه در صفحه
  کیبوردها».

## Watch out for

- **Two switches**: nothing is given until the shop's «اکانت تست فعال باشد» is on **and** at least one server's «اکانت
  تست روی این سرور» is — and that server can sell.
- **A menu without the button** shows no «🧪 اکانت تست»: add it on «کیبوردها»; until then only `/free` and the link
  reach the screen.
- **Tests take room on a server**: its «ظرفیت» counts them as any active service.
- **A deleted test still counts** for its customer's quota, whoever deleted it.
- **A test support extended runs until it ends again**, then the clean-up takes it as any.
- **Removals are quiet**: the customer gets no word when the clean-up removes a test; only its screen in the bot (and
  `removes_at` on the website) says when beforehand.
- **A fixed term runs from the moment it is made**: with «شروع از اولین اتصال» off, a customer who connects late gets
  what is left of it.
