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

## Every online gateway

CubePay, Zarinpal, AtlasPay and AbanGateway — each below — are online gateways: the customer pays on the gateway's page
(or to the card it gives to show), the gateway confirms it, and the order is delivered with no receipt and nobody to
approve it. What follows holds for each of them.

**Its secret** — the token, code or key — is kept encrypted and never shown again: a blank field keeps it, a new one
replaces it, and it is never cleared. One that can no longer be read (the shop's `APP_KEY` changed) reads as none:
type it again.

**The page.** The checkout answers with the gateway's page — «💳 پرداخت», the exact sum it asks and until when it takes
money —, in the bot and on the website alike ([Bot guide](Bot-Guide.md#buying)). The sum may be more than the order's:
a few Toman the gateway adds to tell transfers apart, a fee passed on to the customer — said apart, «(شامل ۲٬۰۰۰ تومان
کارمزد درگاه)». The customer pays that exact sum, and the order is paid, delivered and credited at its own price — a
top-up credits its amount —; the rest is the gateway's. When the gateway gives a card to show, the bot shows it under
the page with the exact sum and copy buttons, protected as a card transfer's message is, and the website gets it too.

**One page a payment.** Tapping the method again while its page has more than three minutes left shows the same page —
two taps at the same moment too: the shop keeps one page a payment. After that, the gateway is asked about the old page
first — a bank's text can reach it minutes after the transfer —: paid there, the order is delivered and no new page
opens; otherwise a new payment opens a new one.

**The gateway's word, never a call's.** A gateway may call the shop's address the moment it confirms a payment; the
shop never takes the call as proof: it asks the gateway itself, then delivers the order and tells the customer — once,
however many times it is told. Calls about one payment make it ask six times a minute at most; one past that is
answered with what the shop already knows. It also asks when the customer taps «🔄 بررسی پرداخت» (a website can ask
too), when support presses «استعلام از درگاه» on the payments screen, and by itself: every few minutes while a page is
open, then every quarter of an hour for two hours after it closed — a failed or cancelled one too —, so a lost call
delays nothing for long (with the cron or the poller running:
[Running in production](Running-In-Production.md#the-scheduler)).

**A page that closed unpaid** fails its payment («مهلت پرداخت در درگاه تمام شد»), and the customer starts again; two
hours after its page closed, the shop stops asking about it. An order whose page still takes money never expires,
whatever a discount code's hold says.

**Money in, not settled yet.** A gateway may say money came in on a payment it has not settled yet — AtlasPay with a
receipt in review or an underpayment whose rest the customer said they would pay, AbanGateway with an invoice split
into parts and paid in part. The shop does not give up on it: it asks every quarter of an hour until 48 hours after its
page closed, and settles it as soon as the gateway does; still unsettled then, the payment is
[held for you](#a-payment-held-for-you).

**No money is lost.** Money a gateway takes for an order that is no longer waiting — paid another way, cancelled,
expired, or a page paid late — goes to the customer's wallet («پرداخت آنلاین #… پس از بسته شدن سفارش #…»), and they
and the report group's «شارژ کیف پول» are told
([Orders and payments](Orders-And-Payments.md#money-that-came-after-its-order-closed)). A refund works as a card's does:
a service's money goes to the customer's wallet, a top-up comes back out of it.

**A gateway that will not open a page** — out of reach, a secret it does not take, an account not ready — refuses the
checkout: the customer reads «درگاه پرداخت الان در دسترس نیست؛ …» and picks another way, the order left open, and the
log says what the gateway said. A gateway that cannot be asked about a payment changes nothing: the customer is told
to try again a little later («الان نمی‌شود از درگاه پرسید؛ …»), and the scheduler asks again.

**The page a customer comes back to.** A gateway that sends the customer's browser back to the shop (Zarinpal and
AbanGateway do) brings it to the shop's address. That visit too only makes the shop ask the gateway, and the browser
is shown a small page of what came of it, under the shop's name:

- «✅ پرداخت شما تایید شد» — the order paid; or «💰 مبلغ به کیف پول شما اضافه شد» — money that came once its order had
  closed, in their wallet;
- «⏳ پرداخت شما هنوز تایید نشده است» — nothing yet: once paid it is confirmed by itself within minutes;
- «❌ پرداخت انجام نشد» — not paid: should money have left their account, they message support with the order's
  number;
- «🎧 پرداخت شما به بررسی پشتیبانی رسید» — [held for support](#a-payment-held-for-you): not to pay again;
- «⏳ نتیجه پرداخت هنوز معلوم نیست» — the gateway not answering now: it is asked again by itself.

A customer with a Telegram account gets «بازگشت به ربات» under it, and is told the result comes in the bot (or, had they
bought on the website, to go back there); one of the website alone is never told of the bot: the page says to look at
their account on the shop's site.

### A payment held for you

A payment the shop cannot take at its gateway's word is **held for support**: one its gateway confirmed for another
order or amount (an underpayment AtlasPay accepted among them), and one on which the gateway kept money unsettled for
48 hours (above).

- The shop asks its gateway about it no more; the log says what the gateway said.
- The report group's «خطاها» hears of it once: the customer, the order, the method, the gateway's id for it and why
  ([Report group](Telegram-Group.md#the-topics)).
- While it still waits — pending, or failed with its page —, its order never expires by itself and is paid no other
  way: its page is no longer offered, and paying the order again — by any method — pays nothing, so the customer never
  pays twice.
- The customer who asks about it or pays its order again is sent to support: the bot says «پرداخت شما برای بررسی به
  پشتیبانی رسید؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.» (a text you may reword) with
  «💬 پیام به پشتیبانی», and the website and the page a gateway sends them back to the same words — the website's
  naming the order («پرداخت سفارش #… برای بررسی به پشتیبانی رسیده است؛ …»), which it also shows as the payment's note.
- The payments screen marks it and says why
  ([Orders and payments](Orders-And-Payments.md#a-payment-held-for-support)): check it at the gateway and settle it with
  the customer yourself — only «لغو پرداخت» is offered for it. Cancelled, it holds nothing more: the customer's check
  answers as for any closed page.

## CubePay

«افزودن روش پرداخت» › «CubePay»: an [online gateway](#every-online-gateway).
[CubePay](https://github.com/cubepy/cubepay-doc) confirms payments by itself — a card-to-card transfer from your bank's
text message (its app forwards them from your phone), a crypto one (USDT, TRX, TON) from the chain.

Setting it up:

1. In the bot ‎@cubepy_bot, open a merchant account and wait for it to be approved; there, add your cards («💳 مدیریت
   کارت‌ها»), connect your bank's text messages (CubePay's Android app or iPhone shortcut), and choose what it takes —
   card, crypto or both («🏪 فروشگاه من › 💳 روش‌های پرداخت»).
2. Copy your API token from «🔗 پنل من».
3. Here: «افزودن روش پرداخت» › «CubePay», a label («پرداخت آنلاین»), the token, and the bounds — set «کمترین مبلغ» to
   what CubePay takes at least (crypto has a minimum of its own).
4. The shop's address («آدرس سایت», `APP_URL` — [Panel settings](Panel-Settings.md#the-app)) must begin with
   `https://`: CubePay tells the shop about each payment there. Without it, every CubePay checkout is refused and the
   log says why.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ» / «بیشترین مبلغ» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «توکن API» | Your merchant token from ‎@cubepy_bot |
| «کارمزد درصدی بر عهده مشتری» | Optional: a percent of the amount added to every payment for the customer to pay — CubePay's percent fee passed on (below); up to two decimals, `3.1`; blank or 0, none |
| «کارمزد ثابت بر عهده مشتری» | Optional: Toman added to every payment the same way, on top of the percent — CubePay's fixed card-to-card fee passed on; 0, none |
| «فعال» | Whether the checkout offers it |

**Passing CubePay's fee on.** CubePay takes its fee from your fee wallet there: a card-to-card payment's is a fixed
sum, a percent one's (crypto's) a share of the whole sum. To have customers pay it, set the fee here: every payment
asks CubePay for the amount and the fee — the percent rounded up to a whole Toman, then the fixed sum —, the customer
pays that, and the order is still paid, delivered and credited at its own price. Pass a card payment's fixed fee on
with «کارمزد ثابت بر عهده مشتری». A percent fee is taken of the whole sum, the fee included, so to pass one on in full
set a little more: 3.1 for 3% (`P ÷ (100 − P) × 100`). The bot's page and the website say how much of the sum is the
fee («(شامل ۲٬۰۰۰ تومان کارمزد درگاه)»), and the methods list shows it on the method («کارمزد مشتری: ۳٪ + ۵۰۰ تومان»)
— say it in the label too («پرداخت آنلاین (+۳٪)»), since the checkout's buttons show only labels. Set it here or in
CubePay's own «سهم مشتری از کارمزد», never both: CubePay adds its share on top of what the shop asks.

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- The customer gets CubePay's page — a card's, a crypto one's, or a choice of the two, as your settings at CubePay say —
  for CubePay's time, half an hour to an hour. It asks the order's amount and the fee you pass on (above); a card
  payment a few Toman more, CubePay's way of telling transfers apart. With «🤖 نمایش کارت در ربات» on at CubePay, the
  bot and the website also show the card, its holder and the exact sum, so the customer can pay without leaving
  Telegram (a card payment only: with card and crypto both on, CubePay picks no card until the customer chooses).
- CubePay calls the shop the moment it sees the money, and may still take a payment shortly after its page closed — a
  late receipt you approve at CubePay —: settled all the same, or into the wallet once its order closed.
- CubePay takes its fee from your fee wallet there; when that runs short, when the token is wrong or the account not
  yet approved, CubePay opens no payment and the checkout is refused, the log saying what CubePay said.
- A payment CubePay confirms for another order or amount than it was asked is [held for you](#a-payment-held-for-you):
  check it at CubePay.

## Zarinpal

«افزودن روش پرداخت» › «زرین‌پال»: an [online gateway](#every-online-gateway) for Iran's bank cards. The customer pays
with any card on Zarinpal's page and comes back to the shop.

Setting it up:

1. Open a gateway in your Zarinpal panel and register the shop's domain for it — the domain of the shop's address
   («آدرس سایت», `APP_URL`, [Panel settings](Panel-Settings.md#the-app)): Zarinpal sends the customer back there and
   refuses any other. If you held the gateway to server IPs at Zarinpal, the shop's host must be one of them.
2. Copy its merchant code («مرچنت کد», 36 characters).
3. Here: «افزودن روش پرداخت» › «زرین‌پال», a label, the code, and the bounds — Zarinpal takes 100 to 100,000,000 Toman
   a payment.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ» / «بیشترین مبلغ» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «مرچنت کد» | Your gateway's code from the Zarinpal panel |
| «حالت آزمایشی (sandbox)» | Zarinpal's test service: any code in its shape is taken and no money moves — but a payment there still delivers its order. Only for trying it out; the list says «حالت آزمایشی: پولی جابه‌جا نمی‌شود» while it is on. A payment is always asked about where it was opened: switching it leaves the payments already opened as they were |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- The customer gets Zarinpal's page and pays there; the shop asks Zarinpal to confirm the payment with the bank by
  itself, so the money is never handed back for want of the shop's answer. The shop keeps a page for 15 minutes
  (Zarinpal says nothing of its own limit).
- Zarinpal sends the customer's browser back to the shop, paid or not: the shop asks Zarinpal (inquiry, then verify
  for the amount asked) and shows the browser [what came of it](#every-online-gateway). A customer who closes the page
  before coming back loses nothing: the scheduler asks Zarinpal too.
- Zarinpal's fee is yours by default. When you set Zarinpal to have the buyer pay it («کارمزد بر عهده خریدار» in its
  panel), the customer is shown the sum with it and the fee apart.
- A payment the bank refused, or the customer cancelled, fails at once; one Zarinpal confirmed for another amount than
  asked is [held for you](#a-payment-held-for-you). A code Zarinpal does not take — a wrong code, a domain not
  registered, a gateway not active yet — refuses the checkout, the log saying what Zarinpal said.

## AtlasPay

«افزودن روش پرداخت» › «اطلس‌پی»: an [online gateway](#every-online-gateway) of card-to-card payments AtlasPay confirms
by itself from your bank's text message, paid in AtlasPay's mini app inside Telegram. AtlasPay settles the money to you
in TRX.

Setting it up:

1. Open a merchant account at AtlasPay and take its API key (from AtlasPay's bot).
2. Here: «افزودن روش پرداخت» › «اطلس‌پی», a label, the key, and the bounds.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ» / «بیشترین مبلغ» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کلید API» | Your merchant key from AtlasPay |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens AtlasPay's mini app in Telegram — the card to pay to, and a receipt sent there should the bank's text
  not come —, for AtlasPay's 20 minutes. The order is opened at AtlasPay for the customer's own Telegram account when
  they have one; a customer of the website needs Telegram to pay too.
- AtlasPay asks a sum of its own: the order's amount, any markup of AtlasPay's, and a last digit that tells transfers
  apart; the bot and the website show it, and how much of it AtlasPay added. A sum below the order's is never taken:
  the checkout is refused.
- When AtlasPay shows you the card («نمایش مستقیم اطلاعات پرداخت», which AtlasPay's support switches on for a shop that
  asks), the bot and the website also show the card, its holder and the exact sum — and «💳 پرداخت» stays, since a
  receipt goes through the mini app. The card is kept encrypted and shown only while the page takes money.
- AtlasPay calls the shop the moment it confirms or rejects an order — when the shop's address («آدرس سایت»,
  `APP_URL`) begins with `https://`; a shop on http waits for the scheduler's asking, a few minutes at most.
- A receipt in review at AtlasPay, or the rest of an underpayment the customer said they would pay, is
  [money in, not settled yet](#every-online-gateway): waited for, up to 48 hours after the page closed. A rejected or
  expired order fails its payment, and the customer starts again.
- **An underpayment AtlasPay accepted is held for you.** A customer who paid less than asked may have AtlasPay take the
  smaller sum: AtlasPay then confirms the order for less, and the shop delivers nothing by itself — the payment is
  [held](#a-payment-held-for-you), and the log says how much came of how much. The money is yours at AtlasPay; settle
  the rest with the customer.
- A key AtlasPay does not take, or AtlasPay not taking payments just now, refuses the checkout, the log saying what
  AtlasPay said.

## AbanGateway

«افزودن روش پرداخت» › «آبان گیت وی»: an [online gateway](#every-online-gateway) of card-to-card payments to your own
cards, which AbanGateway confirms by itself from your bank's text message (its app on your phone forwards them). The
money goes straight to your card.

Setting it up:

1. Open an account at abangateway.ir, register your cards there, and top up its fee wallet — AbanGateway takes each
   payment's fee from it when the payment is opened.
2. Make an API key in its panel (Settings › API keys) and copy it whole when it is shown: `ag_live_…`, or `ag_test_…`
   for its test mode.
3. Here: «افزودن روش پرداخت» › «آبان گیت وی», a label, the key, and the bounds.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ» / «بیشترین مبلغ» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کلید API» | Your key from AbanGateway's panel. A test key (`ag_test_…`) moves no money — but an invoice paid by AbanGateway's simulation still delivers its order; the list says «حالت آزمایشی: پولی جابه‌جا نمی‌شود» while it is set |
| «نمایش کارت در ربات و سایت» | On (the default): the card, its holder and the exact sum come under the page with copy buttons, so the customer can pay without opening AbanGateway's page |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- The customer gets AbanGateway's page — your card, the exact sum and a countdown — for 30 minutes, and with the card
  on, the card and the sum in the bot and on the site too. The sum is a few Rial more than the order's (AbanGateway
  tells transfers apart by it, since the bank's text does not say whose card paid): the customer must transfer exactly
  that sum.
- AbanGateway calls the shop the moment the money comes and sends the customer's browser back to it — when the shop's
  address («آدرس سایت», `APP_URL`) begins with `https://` and the internet reaches it —, and the browser is shown
  [what came of it](#every-online-gateway); a shop on http waits for the scheduler's asking, a few minutes at most.
- A paid invoice is claimed once (AbanGateway's verify), so no order is ever delivered twice; one AbanGateway says was
  paid for another order or amount than asked is [held for you](#a-payment-held-for-you), and never claimed.
- A sum above a card's daily ceiling (about 15 million Toman) is split by AbanGateway into parts on its page, each
  transferred apart; the bot and the site show the card for sums up to 10 million Toman — the shop's own limit —, only
  the page above it. A split invoice paid in part is [money in, not settled yet](#every-online-gateway): waited for, up
  to 48 hours after its page closed, AbanGateway giving it more time.
- An invoice that expired or was cancelled fails its payment, and the customer starts again.
- An empty fee wallet, a key AbanGateway does not take, no card registered there, or no distinct sum free on your cards
  just now (add another card) refuses the checkout, the log saying what AbanGateway said.

## The list

- **▲▼** — the order of the checkout's buttons.
- **«جزئیات»** — a card method's cards, each masked with its holder and bank, «(خاموش)» when switched off and what it
  took today against its cap («امروز ۲۵۰٬۰۰۰ تومان از ۳۰۰٬۰۰۰ تومان»; «پر شده» once full); under them the review window,
  when there is one, and the amounts it is offered for; an online method's «پرداخت در صفحه CubePay، بدون رسید و با
  تایید خود درگاه» (its own gateway's name).
- **«نوع»** — «تسویه آنی» (the wallet), «تایید دستی» (a card), «درگاه آنلاین» (CubePay, Zarinpal, AtlasPay,
  AbanGateway), or «درایور نصب نیست» for a method of a kind this installation no longer has: no checkout offers it,
  switched on or not («به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.»), and it can only be switched off or deleted.
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
- **CubePay, Zarinpal, AtlasPay and AbanGateway are the online gateways so far** ([Roadmap](Roadmap.md)); another way
  to pay is a driver ([Drivers](Drivers.md#a-payment-gateway)).
