# Payment methods

«فروشگاه › روش‌های پرداخت», in both panels: the ways a customer pays. Each row is a method — a kind of payment with a
label the checkout's button shows («کارت به کارت (ملت)»), the amounts it is offered for and its settings —, and the
checkout offers every method that is switched on and takes the amount to pay, in the list's order, in the bot and on the
shop's website alike.

## The least and most amount

Every method — the wallet too — has «کمترین مبلغ» and «بیشترین مبلغ» (whole Toman; blank: no bound). A checkout offers it
only when the amount to pay — after its discounts — is within them, both ends included: a card for 50,000 Toman and up,
the wallet up to 5,000,000 Toman. Something free is not held to them: it is settled by the wallet whatever is picked.

- When the shop has methods switched on but none takes the amount, the bot says «برای این مبلغ روش پرداختی نیست…» under
  the checkout (its words are a text of the bot, «روش پرداختی برای این مبلغ نیست»), and the website's checkout gets no
  method for it.
- A customer who taps a method whose bounds changed since the checkout was drawn is shown the checkout again, nothing
  ordered; the website is refused under `method_id` in the method's own words («… برای مبلغ‌های بین ۵۰٬۰۰۰ تومان و
  ۵۰۰٬۰۰۰ تومان است؛ روش دیگری انتخاب کنید.»).
- «تمدید خودکار» renews from the wallet only a price within the wallet's bounds — the customer's own renewal price, an
  agent price or an automatic discount counted. A service whose renewal costs more (or less) than the wallet takes is
  not renewed by itself: its switch is not offered (the bot's service screen has none, the website reads it not
  offered), its customer is reminded of its end as of any service's
  ([Bot settings](Bot-Settings.md#reminders)), and it is renewed by hand.
- An order support makes for a customer by hand from their wallet («افزودن سفارش») is not held to the wallet's bounds:
  they are for the customers' own checkouts, and support's order is deliberate
  ([Orders and payments](Orders-And-Payments.md#orders-support-makes)).

## The wallet

«کیف پول» is part of every shop: one row, never added or deleted, nothing to set but its label, its bounds and its
switch. It pays at once from
the customer's balance — an agent buying from the main bot may go below zero by their credit — and the service is
delivered there and then. It is never offered to top the wallet itself up: a top-up goes by another method.

Switched off, nothing is paid from any wallet: the balances stay, but cannot be spent, «تمدید خودکار» is no longer offered
(and stops renewing), and in the main shop agents cannot pay for traffic from their wallet or credit.

## Paid outside the shop

«خارج از فروشگاه» is part of every shop too, but support's alone: it records money a customer paid you outside the shop
(cash, another card) for an order you make for them by hand ([Orders and payments](Orders-And-Payments.md#orders-support-makes)).
It is never offered at checkout and not listed here; what it took counts in the shop's revenue, as a card's does.

## Card to card

«افزودن روش پرداخت» › «کارت به کارت»: the customer transfers the amount to one of your cards and sends a picture of the
receipt; support approves it, and the order is delivered. One method holds several cards; two methods are two buttons at
checkout, told apart by their labels.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says, 60 characters at most: «کارت به کارت (ملت)» |
| «کمترین مبلغ» / «بیشترین مبلغ» | The amounts it is offered for (above) |
| «توضیحات برای مشتری» | Optional, plain text under the card — in the bot and on the website, and under the way to support's private chat while card payments go there. The bot always adds «بعد از واریز، عکس رسید را همین‌جا بفرستید.» after a card, so do not repeat it |
| «تایید خودکار (دقیقه)» | The review window: blank or `0`, only by hand; up to 10080 (a week) — below |
| «کارت‌ها» | Its cards, one to 20 — below |
| «فعال» | Whether the checkout offers it |

### Its cards and their rotation

Each card of the method has:

| Field | What it is |
|---|---|
| «شماره کارت» | 16 digits, checked (Luhn), shown in groups of four as you type — a phone opens its number pad; pasted with spaces, dashes or Persian digits, it keeps the digits. No number twice in one method |
| «نام صاحب کارت» | As the bank shows it — the customer checks it before transferring |
| «بانک» | Optional: the card's bank («ملت»), shown to the customer under the card's number — in the bot and on the website —, in the panels and in the report group |
| «سقف روزانه (تومان)» | Optional: the most it takes in a day, on the shop's clock (its time zone); blank, no cap |
| switch | Off: no new payment is shown it |
| ▲▼ | The cards' order in the form (the pick does not follow it) |

Each new payment is shown **one of the cards at random**, among those switched on whose daily cap leaves room for its
amount (a top-up's with the few Toman it may add, below) — what came to a card today being its payments made since
the shop's midnight that are paid, refunded or still waiting (pending, or with a receipt in review); a rejected or
cancelled one does not count. With no card left that has room, the method is not offered for that amount (as with its
bounds); a smaller amount may still fit. A customer's own payment still waiting for its transfer stays theirs: the
method stays on their checkout of the same thing — even when that payment took its card's last room, or its card was
switched off since — and shows them that payment again. Two payments asking for a card's last room at the same moment
are made one after the other: the second is held to what the first took.

The list says when no card is left to show: «کارتی ندارد», «همه کارت‌ها خاموش‌اند», or every card switched on full for
today — each with «به مشتری پیشنهاد نمی‌شود».

While card payments go to support's private chat ([Bot settings](Bot-Settings.md#card-to-card-payments)), no card is
picked at all: the method is offered whatever its cards' switches and caps, no cap fills, and the list says «پرداخت کارت
به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند …» in place of the caps. Those payments name «کارت در
پیوی پشتیبانی داده شد» where a card would be.

A payment is shown **the same card every time**: tapping the method again, sending the receipt later, the website asking
again with its key — even after the card was switched off or filled its cap. The payments screen, the receipt's review
and the report group's line («🏦 کارت: 6037 •••• •••• 1119 · holder · bank») name the card each payment was shown, so you
know where to look for the money.

The customer sees the amount, the card's 16 digits (a tap copies them), the holder, its bank and your words; the shop's
website shows the same. They send the receipt — a photo, or a picture sent as a file — in the bot, or upload it on the website;
it waits under «فروش › پرداخت‌ها» › «در انتظار بررسی», and in the report group's «رسیدها» with «✅ تایید» and «❌ رد»
([Orders and payments](Orders-And-Payments.md#payments)).

## Approving receipts by themselves

With a review window of, say, 30 minutes, a receipt **sent in the bot** that nobody approved or rejected in that time is
approved by itself, and its order delivered — as if support had approved it; the payments list marks it «تایید
خودکار». The customer is told only the longest wait — «حداکثر تا ۳۰ دقیقه دیگر» —, never that it is automatic.

- A receipt **uploaded on the website is never approved by itself**: its account may be an email made a minute ago. The
  report group's line says so under it.
- It approves only while the order is still waiting: a receipt whose order was paid another way meanwhile stays with
  support.
- It runs with the scheduler, every minute: without the cron (or the poller) nothing is approved by itself.
- The window read is the method's as it is when the scheduler looks: raising it delays receipts already waiting, and
  setting it to `0` stops approvals even for customers already told a wait.
- It applies to a method switched off too, for the receipts it already has.

A window trades checking for speed: a fake receipt is approved too. Keep it for a card whose statements you watch.

## The list

- **▲▼** — the order of the checkout's buttons.
- **«جزئیات»** — a card method's cards, each masked with its holder and bank, «(خاموش)» when switched off and what it
  took today against its cap («امروز ۲۵۰٬۰۰۰ تومان از ۳۰۰٬۰۰۰ تومان»; «پر شده» once full); under them the review window,
  when there is one, and the amounts it is offered for.
- **«نوع»** — «تسویه آنی» (the wallet), «تایید دستی» (a card), or «درایور نصب نیست» for a method of a kind this
  installation no longer has: no checkout offers it, switched on or not («به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.»),
  and it can only be switched off or deleted.
- **«پرداخت‌ها»** — how many payments were made with it, of any state.
- **«فعال»** — the switch, at once. Switched off, a method leaves the next checkout; a customer who already has its card
  may still send the receipt.

## Editing and deleting

A method's label, bounds, note, window and cards can be edited at any time; the wallet's label, bounds and switch too
(its name in the list opens its form). A card a payment was shown keeps its number — the record of where that money
went — and is never removed: to change a card, add the new one and switch the old one off. A card no payment was shown
can be changed or removed (left out of the list) freely.

A method is deleted only while it has no payment at all: once a customer **picked** it at a checkout — even an order
never paid and long expired —, it is kept for the history, and switching it off is the way to retire it. Its cards go
with it. The wallet is never deleted.

## A random sum on card-to-card top-ups

«تنظیمات ربات › کیف پول» › «مبلغ تصادفی افزوده به شارژ کارت‌به‌کارت» (0: off, up to 9,999 Toman): a wallet top-up paid
by card asks 1 to that many Toman more, at random — a sum no other waiting payment to the same card asks, and never
past the method's «بیشترین مبلغ» (a top-up of the method's most asks nothing more) —, so every transfer is a different
amount and its receipt is told apart at a glance. The customer is shown, and pays, the whole sum
(«لطفا مبلغ ۱۰۰٬۰۳۷ تومان را … واریز کنید»), and the whole sum is credited to their wallet once approved — as a refund
takes the whole sum back out. The order keeps the amount asked for (100,000): the same top-up asked again finds it, with
its payment's sum. Purchases, renewals and extras are paid at their price. While card payments go to support's private
chat, a top-up still asks its few Toman more — a sum no other waiting payment there asks —, so support tells the
receipts sent to their chat apart the same way.

## Watch out for

- **Labels are not checked for duplicates**, nor a card's number across methods: give each method a label a customer
  can tell apart.
- **A daily cap is a guard, not a bank's limit**: it counts what the shop asked for on the card today — by the day each
  payment was made, so one made before midnight and paid after it counts for the day before —, not what the bank
  received.
- **Online card gateways are not built yet** ([Roadmap](Roadmap.md)); a new way to pay is a driver
  ([Drivers](Drivers.md#a-payment-gateway)).
