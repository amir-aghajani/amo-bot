# Payment methods

«فروشگاه › روش‌های پرداخت», in both panels: the ways a customer pays. Each row is a method — a kind of payment with a
label the checkout's button shows («کارت به کارت (ملت)») and its settings —, and the checkout offers every method that is
switched on, in the list's order, in the bot and on the shop's website alike.

## The wallet

«کیف پول» is part of every shop: one row, never added or deleted, nothing to set but its switch. It pays at once from
the customer's balance — an agent buying from the main bot may go below zero by their credit — and the service is
delivered there and then. It is never offered to top the wallet itself up: a top-up goes by another method.

Switched off, nothing is paid from any wallet: the balances stay, but cannot be spent, «تمدید خودکار» is no longer offered
(and stops renewing), and in the main shop agents cannot pay for traffic from their wallet or credit.

## Card to card

«افزودن روش پرداخت» › «کارت به کارت»: the customer transfers the amount to your card and sends a picture of the receipt;
support approves it, and the order is delivered. Make one method a card — two cards are two methods, told apart by
their labels.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says, 60 characters at most: «کارت به کارت (ملت)» |
| «شماره کارت» | 16 digits, checked (Luhn), shown in groups of four as you type — a phone opens its number pad; pasted with spaces, dashes or Persian digits, it keeps the digits |
| «نام صاحب کارت» | As the bank shows it — the customer checks it before transferring |
| «توضیحات برای مشتری» | Optional, plain text under the card. The bot always adds «بعد از واریز، عکس رسید را همین‌جا بفرستید.» after it, so do not repeat it |
| «تایید خودکار (دقیقه)» | The review window: blank or `0`, only by hand; up to 10080 (a week) — below |
| «فعال» | Whether the checkout offers it |

The customer sees the amount, the card's 16 digits (a tap copies them), the holder and your words; the shop's website
shows the same. They send the receipt — a photo, or a picture sent as a file — in the bot, or upload it on the website;
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
- **«جزئیات»** — the card, masked, and its holder; under it the review window, when there is one.
- **«نوع»** — «تسویه آنی» (the wallet), «تایید دستی» (a card), or «درایور نصب نیست» for a method of a kind this
  installation no longer has: no checkout offers it, switched on or not («به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.»),
  and it can only be switched off or deleted.
- **«پرداخت‌ها»** — how many payments were made with it, of any state.
- **«فعال»** — the switch, at once. Switched off, a method leaves the next checkout; a customer who already has its card
  may still send the receipt.

## Editing and deleting

Editing a card is possible at any time — but the payments screen shows every payment by its method's card as it is
now, and a customer who opens an unpaid checkout again is shown the new card. To change a card, a new method and the
old one switched off keeps the history readable.

A method is deleted only while it has no payment at all: once a customer **picked** it at a checkout — even an order
never paid and long expired —, it is kept for the history, and switching it off is the way to retire it. The wallet is
never deleted.

## Watch out for

- **Labels and cards are not checked for duplicates**: give each method a label a customer can tell apart.
- **Online card gateways are not built yet** ([Roadmap](Roadmap.md)); a new way to pay is a driver
  ([Drivers](Drivers.md#a-payment-gateway)).
