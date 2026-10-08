# Customers

«فروش › کاربران», in both panels: the shop's customers, their groups, and each customer's own page.

A customer is one account in one shop, whichever way they came in: the bot (their Telegram account) or the shop's
website (an email, a Google account, or Telegram there too). One person who talks to two bots is two customers. A
customer who signed up on the website alone has no Telegram account: they are shown by their email, the bot cannot write
to them, and the shop's notices reach them by email — when the shop's email goes out — and on their website.

## The list

«کاربران» lists everyone who ever started the bot or signed up on the website, newest first; sort it by «کیف پول»,
«سرویس‌ها» (running), «سفارش‌ها», «آخرین بازدید» or «عضویت» from their headers.

- **The search** finds a first or a last name (each on its own — not «first last» together), a handle with or without
  `@`, a piece of an email, an exact Telegram id, a phone number (4 digits or more) or a customer's number; `#12` is
  customer 12 alone.
- **Filters**: «وضعیت» (active or banned), «نقش» — «مدیران ربات» or «مشتری‌ها» — and, once you have groups, «گروه».
- **A row**: the name — a link to the customer's page; «بدون نام» when Telegram shows none —, the handle (or the email of
  a website customer), their number, Telegram id and phone on wider screens, the wallet («… تومان بدهی» in red for a
  debt), «n فعال از m» services, orders, groups, status, when they were last seen (in the bot or on the website) and
  when they joined. A bot admin carries «مدیر».

The row's menu (⋮):

- **«گفتگو در تلگرام»** — opens a chat with them in Telegram (not for a customer without Telegram).
- **«کیف پول»** — [the wallet](#the-wallet).
- **«گروه‌ها»** — tick the groups they are in ([groups](#groups)).
- **«مدیر ربات کردن» / «برداشتن مدیریت ربات»** — the bot's admin role, asked first ([the bot's admins](#the-bots-admins)).
- **«مسدود کردن» / «رفع مسدودی»** — a ban, asked first; lifting it is at once.

## Banning

A banned customer gets one answer from the bot to whatever they send — «حساب شما مسدود شده است…», a text you may
reword —, and the shop's website refuses them (their sessions stay, and work again once the ban is lifted). Their
services keep running, but they get no automatic renewal and no reminders, no broadcast, earn no referral commission and
bring no referral. **They are not told** they were banned, nor when it is lifted. A ban in the main bot does not stop
an agent's own bot or panel: to end an agency, see [Agency](Agency.md).

The shop's admins may ban and unban from its website too — a customer, never an admin nor an agent: their accounts —
a ban, two-factor sign-in, the devices — are changed from the panel alone.

## The bot's admins

«مدیر ربات کردن» makes a customer an admin **of the bot** — nothing to do with the panel's login. A bot admin:

- may send `/broadcast` and `/emoji` in the bot ([Bot guide](Bot-Guide.md#for-the-bots-admins));
- passes the bot's gates — its switch, the phone, the channels — so they can try a bot that is switched off;
- acts in the report group: approves and rejects receipts, retries a failed delivery, answers and closes tickets — and,
  in the main bot's group, decides agency requests ([Report group](Telegram-Group.md));
- works the shop's daily work on its website, while the website lets its admins in ([Website](Website.md#the-shops-admins)).

The role is given and taken here alone — never from the website. To make yourself one, start the bot from your own
Telegram account first, then find yourself in the list; «نقش › مدیران ربات» lists the admins. An agent is made the
admin of their own bot by itself, the first time they write to it. The customer is not told.

An admin is still a customer: **another admin decides what is theirs** — their own payment approved or refunded, their
wallet, their service changed, their review decided —, in the report group and on the website alike; your panel is held
to none of it. That rule is about each admin's own account: two admins can do those things for each other, so make
admins only of people you trust.

## The wallet

«کیف پول» shows the balance — a debt as «… تومان بدهی» —, a credit or a debit, and the ledger's latest 50 lines (top-ups,
purchases, refunds, commissions, and support's own lines, each with who wrote it).

- **«افزایش» / «کاهش»** — an amount in whole Toman, and an optional note of 190 characters at most, which the customer
  reads in their wallet's history — in the bot's «کیف پول» and on the website; without one the line reads «افزایش
  موجودی توسط پشتیبانی» / «کاهش موجودی توسط پشتیبانی».
- A decrease never takes the balance below zero — an agent's credit included: it is refused («موجودی کاربر … است و از
  این بیشتر نمی‌شود کم کرد.»). An increase always lands, a debt or not.
- The customer is not sent a message about it: the line in their history is what they see.

An agent's credit — how far below zero their wallet may go when they buy traffic — is set on the agents page
([Agency](Agency.md)), and shown on their customer page's wallet card.

## Groups

«کاربران › گروه‌ها» — the shop's own groups of customers: VIP, colleagues, a campaign. A group is a name (32 characters at
most, unique in the shop) and a place in the order (▲▼); «تغییر نام» and «حذف» in its menu. A customer's groups are
ticked from their row's «گروه‌ها», or from the «گروه‌ها» card of their page.

A broadcast may go to one group ([Broadcasts](Broadcasts.md)). Deleting a group takes nothing from its customers — they
leave it —, but a broadcast aimed at it then reaches nobody.

## A customer's page

`/users/<id>` — the one place support answers a customer from. A name anywhere in the panel leads here.

**The header**: their name and status, «مدیر», «نماینده» for an agent; «مشتری #12»; their handle and Telegram id (or
«بدون تلگرام»), their email, their verified phone; when they joined and were last seen. «گفتگو در تلگرام», and the ⋮ with
the role and the ban.

**The main column** — their latest five of each, every row opening what the list itself opens:

- **«سرویس‌ها»** — a service's dialog: its link, usage, and everything [Services](Services.md) does to it.
- **«پرداخت‌ها»** — a payment's dialog, with «بعدی» through their payments ([Orders and payments](Orders-And-Payments.md)).
- **«سفارش‌ها»** — an order's dialog.
- **«تیکت‌ها»** — the ticket's own page ([Support tickets](Support-Tickets.md)).

«همه …» under each opens the whole list narrowed to them («مشتری … ✕»).

**The side column**:

- **«کیف پول»** — the wallet, as above.
- **«زیرمجموعه‌گیری»** — who brought them, whom they brought (a link to that list) and what it earned them
  ([Referrals](Referrals.md)).
- **«گروه‌ها»** — their groups, with «ویرایش».
- **«ورود به وب‌سایت»** — how they sign in to the shop's website: Telegram, Google, an email with or without a password,
  whether two-factor sign-in is on, how many devices are signed in, and the accounts of theirs merged into this one
  (the customer's own doing, from the website). Two things to do from here:
  - **«خاموش کردن ورود دو مرحله‌ای»** — for a customer who lost the phone their authenticator was on: from then on their
    email and password are enough. They are told — in Telegram and by email, whichever they have — and the notice stays
    on their website.
  - **«خروج از همه دستگاه‌ها»** — every device signed in to the website is signed out; they sign in again. The bot and
    their services are not touched, and **nobody is told**.
- **«نمایندگی»** — for an agent of the main bot: their level, bot, traffic and sales; in the owner's panel, «مدیریت
  نماینده» leads to their row on the agents page.

## Watch out for

- **Nothing tells the customer** of a ban or its end, a role, a wallet change made by hand, or «خروج از همه دستگاه‌ها».
  Say it yourself, from «گفتگو در تلگرام» or a ticket, when it matters.
- **A customer's number can change** when they merge two accounts of theirs on the website: the older account stays,
  with the other's orders, services, wallet, tickets and reviews.
- **«آخرین بازدید»** counts the website too: a customer who never writes to the bot may still be active.
