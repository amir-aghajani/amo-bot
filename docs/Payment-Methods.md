[فارسی](fa/Payment-Methods.md)

# Payment methods

«فروشگاه › روش‌های پرداخت», in both panels: the ways a customer pays. Each row is a method: a kind of payment, with a
label the checkout's button shows («کارت به کارت (ملت)»), the amounts it is offered for, and its settings. The checkout
offers every method that is switched on and takes the amount to pay, in the list's order — in the bot and on the shop's
website alike.

## The least and most amount

Every method — the wallet too — has «کمترین مبلغ (تومان)» and «بیشترین مبلغ (تومان)»: whole Toman, blank for no bound.
The form says back what you typed («= ۵۰٬۰۰۰ تومان؛ مبلغ‌های کمتر این روش را نمی‌بینند.»). A checkout offers the method
only when the amount to pay — after its discounts — is within them, both ends included: a card for 50,000 Toman and up,
say, or the wallet up to 5,000,000 Toman. Something free is not held to them: the wallet settles it, whatever is picked.

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

«کیف پول» is part of every shop: one row, never added or deleted, with nothing to set but its label, its bounds and its
switch. It pays at once from the customer's balance — an agent buying from the main bot may go below zero by their
credit —, and the service is delivered there and then. It is never offered to top the wallet itself up: a top-up goes
by another method.

Switched off, nothing is paid from any wallet. The balances stay but cannot be spent, «تمدید خودکار» is no longer
offered (and stops renewing), and in the main shop agents cannot pay for traffic from their wallet or credit.

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
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for (above) |
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
amount (a top-up's with the few Toman it may add — [below](#a-random-sum-on-card-to-card-top-ups)). What came to a card
today is its payments made since the shop's midnight that are paid, refunded or still waiting (pending, or with a
receipt in review); a rejected or cancelled one does not count.

- With no card left that has room, the method is not offered for that amount, as with its bounds; a smaller amount may
  still fit.
- A customer's own payment still waiting for its transfer stays theirs: the method stays on their checkout of the same
  thing and shows them that payment again — even when that payment took its card's last room, or its card was switched
  off since.
- Two payments asking for a card's last room at the same moment are made one after the other: the second is held to
  what the first took.

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
website shows the same. They send the receipt in the bot — a photo, or a picture sent as a file — or upload it on the
website. It waits under «فروش › پرداخت‌ها» › «در انتظار بررسی», and in the report group's «رسیدها» with «✅ تایید» and
«❌ رد» ([Orders and payments](Orders-And-Payments.md#payments)).

## Approving receipts by themselves

With a review window of, say, 30 minutes, a receipt **sent in the bot** — or uploaded in the mini app — that nobody
approved or rejected in that time is approved by itself, and its order delivered — as if support had approved it; the
payments list marks it «تایید خودکار». The customer is told only the longest wait — «حداکثر تا ۳۰ دقیقه دیگر» —, never
that it is automatic.

- A receipt **uploaded on the website is approved by itself only when the customer's account is a Telegram account**
  (they signed in with Telegram), as every mini app customer's is. One whose account only an email or a mobile number
  vouches for never is: it may have been made a minute ago. The report group's line says so under it.
- It approves only while the order is still waiting: a receipt whose order was paid another way meanwhile stays with
  support. And it approves only a receipt still in review: one support rejects or cancels in the same moment stays
  rejected or cancelled.
- It runs with the scheduler, every minute by default ([the task schedule](Running-In-Production.md#the-task-schedule)):
  without the cron (or the poller) nothing is approved by itself.
- The window read is the method's as it is when the scheduler looks: raising it delays receipts already waiting, and
  setting it to `0` stops approvals even for customers already told a wait.
- It applies to a method switched off too, for the receipts it already has.

A window trades checking for speed: a fake receipt is approved too. Keep it for a card whose statements you watch.

## Confirmed by the bank's SMS

The bank texts you every deposit to your card. An app on the phone that gets those SMS reads them and tells the shop of
each deposit — which then approves the card payment it pays, by itself, in seconds: the customer transfers the exact
sum and is done, with no receipt to send and nobody to wait for. Unlike a review window, nothing is approved that the
bank did not say came in. Two apps do it, each its own way in, alone or together: **ForApp** («فوراپ»,
[github.com/erfjab/forapp](https://github.com/erfjab/forapp)), a free open-source Android app that forwards each SMS to
the shop, and **Rikhtam** («ریختم», rikhtam.com), a paid service whose app tells Rikhtam and Rikhtam the shop
([below](#through-rikhtam)).

**Setting up ForApp** — «روش‌های پرداخت» › the card «تایید خودکار واریز»: switch it on and save. The card «اتصال فوراپ» then
shows the steps: download ForApp («دانلود فوراپ») on the phone that receives the bank's SMS and let it read them; add a
webhook destination; paste the shop's **webhook address** and **API key** from the card (each with «کپی»); send the
app's test message. The card says when ForApp's last test and last SMS came — «هنوز پیامی نرسیده است.» until then;
Rikhtam's deposits do not count there. While ForApp's first message is awaited, the card reads it again every few
seconds, so the test shows without a reload. Each shop has its own address and key (an agent's too); «ساخت کلید تازه» makes a new key, and the old one stops
working at once — give the app the new one.

**How a payment is matched** — while it is on, every card-to-card payment (a purchase, a renewal, an extra, a top-up,
in the bot, on the website and in the mini app) asks the price plus 1 to 999 Toman at random, a sum no other card
payment of the shop may still be paid at asks: an open one, one whose receipt you refused while its order is open, and
one refused, dropped or approved by hand in the last three days (its customer may still transfer the sum shown). The
customer is told to pay exactly that sum, to the last digit, and that it is confirmed by itself, usually within a
minute. A method whose «بیشترین مبلغ (تومان)» leaves no room above the price asks the price itself, and its customer is
asked for the receipt as usual. When a deposit — an SMS ForApp recognised as a bank's deposit, or one Rikhtam reports —
has the sum of exactly one open payment — waiting for the transfer, for its receipt to be reviewed, or refused while its
order is open — made before the SMS came, the payment is approved as support would: the service is delivered and the
customer told. The payments list
shows «تایید با پیامک بانک» on it, and so does its report in the report group. The few Toman are part of what the
customer paid: a top-up credits them, and a refund gives back the whole payment.

**Never on a guess** — nothing is approved when:

- **no open payment asks the sum** (a customer who rounded it, a transfer from someone else, an SMS from before the
  payment was made), or **ForApp did not recognise the SMS as a bank's deposit**: «💰 واریز بدون پرداخت» in the report
  group's «رسیدها», with the SMS and why — match it to the customer's receipt by hand;
- **more than one does** (payments made before you switched it on ask the price itself): «⚠️ واریز با چند پرداخت
  هم‌مبلغ», naming them — none is approved, you pick the right one;
- **the SMS is a withdrawal**, has no sum ForApp could read in Rial, or is more than three days old: kept, and nothing
  else.

A payment you or the review window approved before its SMS came is only confirmed by it: nobody is told twice. Every SMS
that came — and what came of it — is listed in «پرداخت‌ها» › «واریزهای پیامکی», with its tabs («بدون پرداخت», «چند
پرداخت», «تایید شده», «نادیده», «آزمایشی») and its text; it is kept 90 days. The same SMS sent twice counts once.

A customer's receipt still works as before: one who sent it anyway is approved by the SMS all the same. Only a card
whose SMS reach the phone is confirmed this way — a payment to another card waits for its receipt as usual.

### Through Rikhtam

Rikhtam's app on your phone reads the bank's deposit SMS and tells Rikhtam, and Rikhtam tells the shop of each deposit
at the address the shop registered with it. Rikhtam charges a fee for every deposit it confirms (below); ForApp is
free. The two may run side by side: a transfer both tell approves its payment once, whichever tells it first — the
other's word only confirms that payment (listed «تایید شده», nothing for support, Rikhtam told it was taken), never
another payment that asks the same sum since.

**Setting it up** — the card «اتصال ریختم», under «تایید خودکار واریز», shows the steps:

1. In Rikhtam's panel (rikhtam.com), «درگاه‌ها», make a gateway for the shop's domain — the domain of the shop's address
   («آدرس سایت», `APP_URL`, [Panel settings](Panel-Settings.md#the-app)), which the card names; a subdomain of it is
   fine — and copy its «کلید امنیتی».
2. Paste it in «کلید امنیتی درگاه ریختم» and press «اتصال». The shop registers its own address with that gateway
   (`https://<the shop's address>/webhooks/rikhtam/<the shop's number>`): there is nothing to paste back at Rikhtam.
   Connecting switches «تایید خودکار واریز» on, if it was off.
3. In Rikhtam's app, attach the bank account of the cards your customers pay to to this gateway — its account number
   as the bank's SMS writes it, not the card's number nor its Sheba.
4. Check the connection with «تست سلامت درگاه», in Rikhtam's panel or its app.

The shop's address must begin with `https://`: Rikhtam sends deposits only to an https address on the gateway's
domain, and the card says so before asking Rikhtam. Anything Rikhtam refuses is said under the key, and nothing is
kept: a key that is no gateway's at Rikhtam; a key another shop of this site has connected already — Rikhtam sends a
gateway's deposits to one address alone, so make each shop a gateway of its own —; an address on another domain than the
gateway's — make the gateway with the shop's domain, or put the address the refusal names in the gateway's «آدرس
کال‌بک» at Rikhtam —; an address that is not a public one; Rikhtam not answering just now.

Connected, the card shows the gateway's domain, its number at Rikhtam and since when, and the shop's address there (to
copy, for reference). «ثبت دوباره آدرس» registers the address again — after the shop's address changed; a refusal says
why, and when the key was changed or deleted in Rikhtam's panel, points to «تعویض کلید». **«تعویض کلید»** takes the
gateway's new key while connected: it replaces the old one, and the address is registered again with it (refused, the
old key stays). «قطع اتصال», asked first, forgets the key: what Rikhtam reports from then on approves nothing, and the
gateway stays in Rikhtam's panel. Each shop connects its own (an agent too). The same key serves Rikhtam's
[crypto payments](#rikhtams-crypto-payments).

**How its deposits are matched** — as ForApp's (above): every card payment asks a sum of its own, and a deposit with the
sum of exactly one open payment approves it, «تایید با پیامک بانک» on it. Rikhtam sends no SMS, only the money that came
in: «واریزهای پیامکی» shows its sum, the account's last four digits and its time, from «ریختم». **Rikhtam confirms no
card payment under 10,000 Toman**: set your card methods' «کمترین مبلغ (تومان)» to 10,000 or more.

- A deposit that pays no open payment, or more than one, is told back to Rikhtam as taken by none: Rikhtam offers it to
  the next gateway on the same bank account, and once none takes it, leaves it to you to attach by hand. The report
  group hears of it as of ForApp's («💰 واریز بدون پرداخت», «⚠️ واریز با چند پرداخت هم‌مبلغ»).
- While «تایید خودکار واریز» is off, Rikhtam's deposits approve nothing and are not kept here — Rikhtam keeps them, and
  one may still be attached by hand.
- One sent while the shop was out of reach comes again later: Rikhtam tries again a minute, then minutes, then hours
  apart. The same deposit sent twice counts once.

**Attaching a deposit by hand** — a customer who rounded the sum, or paid before their payment was made, is not
approved by itself. Attach their deposit to the payment:

- in Rikhtam's app or panel, naming the payment's number (the «#…» the payments screen shows); or
- here: a card payment waiting for its transfer or its receipt offers **«پیوست واریز ریختم»** in its review («فروش ›
  پرداخت‌ها») while Rikhtam is connected — the deposits of the last 20 days no payment took (40 at most), the sum closest
  to the payment's first, each one's difference said («همین مبلغ» on the exact sum); one short of the order's own price
  is marked «کمتر از مبلغ سفارش» and cannot be picked. Pick one and «پیوست و تایید». It asks for a recent sign-in, as an
  approval does, and the shop's admins attach only to a payment whose receipt is in review. The bot's management has it
  as «📎 پیوست واریز ریختم».

Either way Rikhtam sends that deposit to the shop again, named for the payment, and the shop approves the payment as
support would — even while «تایید خودکار واریز» is off, since you chose the deposit — when it is one of the shop's card
payments, still open, its order open, and the deposit is at least the order's own price (a customer who left out the
few Toman is paid all the same). A payment you or the review window approved already is only confirmed by it, as by a
deposit of its sum that came by itself: nothing is delivered twice. Otherwise nothing is approved, saying why: no card
payment of that number, one another deposit paid already, one cancelled or expired, or a deposit short of the order's
price. Rikhtam takes its usual fee for the deposit; the notice says how much. Should Rikhtam's answer be lost, or come
as a refusal, after the shop did approve the payment with that deposit, the notice says so — approved, Rikhtam unheard
(its panel may still show the deposit as not attached) —; and an attach that failed or got no answer reads the payment
again, so the review shows it as it now stands.

**Its fees** — Rikhtam charges for each deposit it confirms, an attached one too, and invoices you once what you owe
reaches 10,000 Toman; while its fees are unpaid it attaches nothing. Its crypto payments have a fee of their own.

**If it does not work**, check that: the shop's address begins with `https://` and is on the domain the Rikhtam gateway
was made for; «تست سلامت درگاه» passes (a wrong key fails it); the bank account is attached to this gateway in Rikhtam's
app, its number as the SMS writes it; «تایید خودکار واریز» is on; after the shop's address changed, «ثبت دوباره آدرس»
was pressed; and after the gateway's key was changed at Rikhtam, the new one was given with «تعویض کلید».

## Every online gateway

The online gateways are CubePay, Zarinpal, AtlasPay, AbanGateway, Plisio, AqayePardakht, Variza, Bluepal, Rialit and
Rikhtam's crypto payments — each below. The customer pays on the gateway's page (or to the card it gives to show), the
gateway confirms it, and the order is delivered with no receipt and nobody to approve it. What follows holds for each of
them.

**Its notes.** The method's form lists the gateway's notes under its fields — when you add the method and when you edit
it: what the gateway needs of the shop (an address on `https://`, a domain registered with it), how long its page takes
money, its least and most amount, and, for Variza and Rialit, the webhook address to give it.

**Its secret** — the token, code or key — is kept encrypted and never shown again: a blank field keeps it, a new one
replaces it, and it is never cleared. One that can no longer be read (the shop's `APP_KEY` changed) reads as none:
type it again.

**The page.** The checkout answers with the gateway's page — «💳 پرداخت», the exact sum it asks, and until when it takes
money —, in the bot and on the website alike ([Bot guide](Bot-Guide.md#buying)). The sum may be more than the order's:
a few Toman the gateway adds to tell transfers apart, or a fee passed on to the customer, said apart («(شامل ۲٬۰۰۰
تومان کارمزد درگاه)»). The customer pays that exact sum. The order is paid, delivered and credited at its own price (a
top-up credits its own amount); the rest is the gateway's. When the gateway gives a card to show, the bot shows it under
the page with the exact sum and copy buttons, protected as a card transfer's message is, and the website gets it too.

**One page a payment.** Tapping the method again while its page has more than three minutes left shows the same page —
two taps at the same moment too: the shop keeps one page a payment. After that, the gateway is first asked about the old
page, since a bank's text can reach it minutes after the transfer. Paid there, the order is delivered and no new page
opens; otherwise a new payment opens a new page.

**The gateway's word, never a call's.** A gateway may call the shop's address the moment it confirms a payment. The shop
never takes the call as proof: it asks the gateway itself, then delivers the order and tells the customer — once,
however many times it is told. Calls about one payment make it ask six times a minute at most; a call past that is
answered with what the shop already knows. Rialit's webhook, which nothing signs, and Rikhtam's word that a crypto
invoice was paid are such calls too: each only names a payment for the shop to ask about. The shop also asks:

- when the customer taps «🔄 بررسی پرداخت» (a website can ask too);
- when support presses «استعلام از درگاه» on the payments screen;
- by itself: every few minutes while a page is open, then every quarter of an hour for two hours after it closed — the
  defaults of [«زمان‌بندی کارها»](Running-In-Production.md#the-task-schedule); a
  payment failed or cancelled meanwhile too. So a lost call delays nothing for long (with the cron or the poller
  running: [Running in production](Running-In-Production.md#the-scheduler)).

**A gateway that answers no question.** Variza cannot be asked about a payment. Its one word is the webhook it signs with
a secret only it and the shop know, sent to the shop's one address for it ([below](#variza)). The shop believes a
webhook only when the signature is one of its methods', keeps what it said on the payment, and acts on it as on an
answer — once, however many times it is sent. A webhook no method signs is refused and changes nothing. «🔄 بررسی
پرداخت», the customer's return, support's «استعلام از درگاه» and the scheduler read what the webhook said.

**A page that closed unpaid** fails its payment («مهلت پرداخت در درگاه تمام شد»), and the customer starts again. Two
hours after its page closed (by default), the shop stops asking about it. An order whose page still takes money never expires,
whatever a discount code's hold says.

**Money in, not settled yet.** A gateway may say money came in on a payment it has not settled yet: AtlasPay with a
receipt in review, or an underpayment whose rest the customer said they would pay; AbanGateway with an invoice split
into parts and paid in part; Plisio with a coin seen on its chain and awaiting its confirmations. The shop does not give
up on such a payment: it asks every quarter of an hour until 48 hours after its page closed (by default), and settles it
as soon as the gateway does. Still unsettled then, the payment is [held for you](#a-payment-held-for-you). Should the gateway close
it unsettled before — AtlasPay rejecting the receipt, AbanGateway's invoice paid in part expiring —, it is held for you
at once: money may have left the customer's account.

**No money is lost.** Money a gateway takes for an order that is no longer waiting — paid another way, cancelled,
expired, or a page paid late — goes to the customer's wallet («پرداخت آنلاین #… پس از بسته شدن سفارش #…»). The customer
and the report group's «شارژ کیف پول» are told
([Orders and payments](Orders-And-Payments.md#money-that-came-after-its-order-closed)). A refund works as a card's does:
a service's money goes to the customer's wallet, a top-up comes back out of it.

**A gateway that will not open a page** — out of reach, a secret it does not take, an account not ready — refuses the
checkout. The customer reads «درگاه پرداخت الان در دسترس نیست؛ …» and picks another way, the order is left open, and the
log says what the gateway said. A gateway that cannot be asked about a payment changes nothing: the customer is told to
try again a little later («الان نمی‌شود از درگاه پرسید؛ …»), and the scheduler asks again.

**The page a customer comes back to.** Zarinpal, AbanGateway, Plisio, AqayePardakht, Variza and Bluepal — and
Rikhtam's crypto payments, on a shop whose address begins with `https://` — send the customer's browser back to the
shop's address, by a link or by a form their page posts (Rialit sends no one back: the customer returns to the bot or
the website themselves). That visit, too, only makes the shop ask the gateway (or, for Variza, read what its webhook said). The browser is shown a small page of
what came of it, under the shop's name:

- «✅ پرداخت شما تایید شد» — the order paid; or «💰 مبلغ به کیف پول شما اضافه شد» — money that came once its order had
  closed, in their wallet;
- «⏳ پرداخت شما هنوز تایید نشده است» — nothing yet: once paid it is confirmed by itself within minutes;
- «❌ پرداخت انجام نشد» — not paid: should money have left their account, they message support with the order's
  number;
- «🎧 پرداخت شما به بررسی پشتیبانی رسید» — [held for support](#a-payment-held-for-you): not to pay again;
- «⏳ نتیجه پرداخت هنوز معلوم نیست» — the gateway not answering now: it is asked again by itself.

A customer with a Telegram account gets «بازگشت به ربات» under it, and is told the result comes in the bot (or, had they
bought on the website, to go back there). A customer of the website alone is never told of the bot: the page says to
look at their account on the shop's site.

### A payment held for you

A payment the shop cannot take at its gateway's word is **held for support**: one its gateway confirmed for another
order or amount (an underpayment AtlasPay accepted, among them), one on which the gateway kept money unsettled for 48
hours (by default: [«زمان‌بندی کارها»](Running-In-Production.md#the-task-schedule)), and one the gateway closed after it
said money had come in on it (above).

- The shop asks its gateway about it no more; the log says what the gateway said.
- The report group's «خطاها» hears of it once: the customer, the order, the method, the gateway's id for it and why
  ([Report group](Telegram-Group.md#the-topics)).
- While it still waits — pending, or failed with its page —, its order never expires by itself and is paid no other
  way: its page is no longer offered, and paying the order again — by any method — pays nothing, so the customer never
  pays twice.
- The customer who asks about it or pays its order again is sent to support. The bot says «پرداخت شما برای بررسی به
  پشتیبانی رسید؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.» (a text you may reword) with
  «💬 پیام به پشتیبانی». The website and the page a gateway sends them back to say the same, naming the order
  («پرداخت سفارش #… برای بررسی به پشتیبانی رسیده است؛ …»); the website also shows it as the payment's note.
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
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «توکن API» | Your merchant token from ‎@cubepy_bot |
| «کارمزد درصدی بر عهده مشتری» | Optional: a percent of the amount added to every payment for the customer to pay — CubePay's percent fee passed on (below); up to two decimals, `3.1`; blank or 0, none |
| «کارمزد ثابت بر عهده مشتری (تومان)» | Optional: Toman added to every payment the same way, on top of the percent — CubePay's fixed card-to-card fee passed on; 0, none |
| «فعال» | Whether the checkout offers it |

**Passing CubePay's fee on.** CubePay takes its fee from your fee wallet there: a card-to-card payment's fee is a fixed
sum, a percent payment's (crypto's) a share of the whole sum. To have customers pay it, set the fee here. Every payment
then asks CubePay for the amount and the fee — the percent rounded up to a whole Toman, then the fixed sum —, and the
customer pays that; the order is still paid, delivered and credited at its own price.

- Pass a card payment's fixed fee on with «کارمزد ثابت بر عهده مشتری».
- A percent fee is taken of the whole sum, the fee included, so to pass one on in full set a little more: 3.1 for 3%
  (`P ÷ (100 − P) × 100`).
- The bot's page and the website say how much of the sum is the fee («(شامل ۲٬۰۰۰ تومان کارمزد درگاه)»), and the
  methods list shows it on the method («کارمزد مشتری: ۳٪ + ۵۰۰ تومان»). Say it in the label too («پرداخت آنلاین
  (+۳٪)»): the checkout's buttons show only labels.
- Set it here or in CubePay's own «سهم مشتری از کارمزد», never both: CubePay adds its share on top of what the shop
  asks.

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
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
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
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
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
  `APP_URL`) begins with `https://`; a shop on http waits for the scheduler's asking, a few minutes at most by default.
- A receipt in review at AtlasPay, or the rest of an underpayment the customer said they would pay, is
  [money in, not settled yet](#every-online-gateway): waited for, up to 48 hours (by default) after the page closed. A
  rejected or expired order fails its payment, and the customer starts again — but one AtlasPay had said money came in on is
  [held for you](#a-payment-held-for-you) at once.
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
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
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
  [what came of it](#every-online-gateway); a shop on http waits for the scheduler's asking, a few minutes at most by default.
- A paid invoice is claimed once (AbanGateway's verify), so no order is ever delivered twice; one AbanGateway says was
  paid for another order or amount than asked is [held for you](#a-payment-held-for-you), and never claimed.
- A sum above a card's daily ceiling (about 15 million Toman) is split by AbanGateway into parts on its page, each
  transferred apart; the bot and the site show the card for sums up to 10 million Toman — the shop's own limit —, only
  the page above it. A split invoice paid in part is [money in, not settled yet](#every-online-gateway): waited for, up
  to 48 hours (by default) after its page closed, AbanGateway giving it more time.
- An invoice that expired or was cancelled fails its payment, and the customer starts again — but one paid in part is
  [held for you](#a-payment-held-for-you) at once.
- An empty fee wallet, a key AbanGateway does not take, no card registered there, or no distinct sum free on your cards
  just now (add another card) refuses the checkout, the log saying what AbanGateway said.

## Plisio

«افزودن روش پرداخت» › «Plisio»: an [online gateway](#every-online-gateway) of crypto payments. The customer picks a coin
on Plisio's page — Tether, TRON, Bitcoin, TON and the others you switch on at Plisio — and sends it; once Plisio sees it
confirmed on its chain, the order is delivered with no receipt and nobody to approve it. The coins go to your wallet at
Plisio.

Setting it up:

1. Open an account at plisio.net and a shop under API › API settings: verify the shop's domain there, add your host's
   IP to «Request IP» (Plisio answers no other), switch on the coins you take, and copy the shop's secret key.
2. Here: «افزودن روش پرداخت» › «Plisio», a label, the key, the dollar's rate and, if you wish, the coins.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)); each coin has a least Plisio takes, and a checkout below it is refused — set a least amount |
| «کلید مخفی (Secret key)» | Your shop's secret key from Plisio's API settings |
| «نرخ هر دلار (تومان)» | How many Toman a US dollar is: each order is asked of Plisio in dollars at this rate, a cent rounded up (a 123,456-Toman order at 100,000 is $1.24), and Plisio turns the dollars into the coin the customer picks at that coin's own price. Plisio does not price Toman at the market's rate, so keep this up with the market; the list shows it («هر دلار ۱۰۰٬۰۰۰ تومان») |
| «رمزارزهای مجاز» | Optional: the coins offered, by Plisio's ids, comma-separated (`USDT_TRX,TRX,TON`); blank for every coin switched on at Plisio |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- The customer gets Plisio's page («💳 پرداخت») for 60 minutes: they pick a coin, see its amount and address, and send
  it. The bot and the website show the order's price in Toman; the coin's amount is on Plisio's page.
- Plisio calls the shop and sends the customer's browser back to it when the shop's address begins with `https://`
  ([above](#every-online-gateway)). A coin seen on the chain and awaiting its confirmations is
  [money in, not settled yet](#every-online-gateway); completed — or paid more than asked — is paid, and the order is
  credited at its own price.
- A customer who switches coins on Plisio's page makes Plisio open another invoice of the same order: the shop finds
  the paid one by the order's number, and it is that invoice's id that the payment shows.
- An invoice that expired with nothing on it, or was cancelled, fails its payment. One that expired with money on it —
  paid short, or too late —, one Plisio says erred with money on it, and (a white-label shop at Plisio, which Plisio
  answers in full) an answer naming another order or fewer dollars are [held for you](#a-payment-held-for-you).
- Plisio's fee comes out of the coins — yours or the customer's to pay, as you set at Plisio.
- A key Plisio does not take (a wrong key, a domain not verified, your host's IP not in «Request IP»), a coin of
  «رمزارزهای مجاز» Plisio does not take, a sum below a coin's least, or a method saved without a rate refuses the
  checkout, the log saying what Plisio said.

## AqayePardakht

«افزودن روش پرداخت» › «آقای پرداخت»: an [online gateway](#every-online-gateway) for Iran's bank cards, through Shaparak.
The customer pays with any card on the bank's page, comes back to the shop, and the order is delivered with no receipt
and nobody to approve it.

Setting it up:

1. In your AqayePardakht panel, open a gateway and register the shop's domain for it: the domain of the shop's address
   («آدرس سایت», `APP_URL`, [Panel settings](Panel-Settings.md#the-app)). The customer comes back to that address after
   paying, and Shaparak opens the bank's page only for a visitor who comes from that domain.
2. Copy its pin («کد پین درگاه»).
3. Here: «افزودن روش پرداخت» › «آقای پرداخت», a label, the pin, and the bounds — AqayePardakht takes 1,000 to 400,000,000
   Toman a payment.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کد پین درگاه» | Your gateway's pin from the AqayePardakht panel; `sandbox` for its test service, where no money moves — but a payment there still delivers its order. The list says «حالت آزمایشی: پولی جابه‌جا نمی‌شود» while it is set |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens a short page on the shop's own address — the order and its sum, and «ورود به درگاه پرداخت» —, and
  that button opens the bank's page. The short page is needed because Shaparak opens the bank's page only for a visitor
  who comes from the domain registered for the gateway (the shop's), and a button in Telegram or a page of the shop's
  website does not come from there. The shop keeps a page for 20 minutes (AqayePardakht says nothing
  of its own limit); tapping the method again before that shows the same page.
- The bank sends the customer's browser back to the shop; that visit — whatever its address says — only makes the shop
  ask AqayePardakht to verify the payment for the amount it was created for, and the browser is shown the page of what
  came of it ([above](#every-online-gateway)). A customer who closes the bank's page before coming back loses nothing:
  the scheduler asks too, and so can «🔄 بررسی پرداخت».
- Not paid yet is waited for while the page takes money — the customer may still be at the bank —, and closes its
  payment once the page closed; a payment AqayePardakht verifies for another amount than asked is
  [held for you](#a-payment-held-for-you).
- A pin AqayePardakht does not take, a gateway not approved yet or switched off there, or a shop address on another
  domain than the one registered for the gateway refuses the checkout, the log saying what AqayePardakht said.

## Variza

«افزودن روش پرداخت» › «واریزا»: an [online gateway](#every-online-gateway) of card-to-card payments Variza confirms by
itself. The customer transfers the exact sum Variza's page shows to one of your cards — or to Variza's —, Variza reads
the bank's text message on your phone, and the order is delivered with no receipt and nobody to approve it.

Setting it up:

1. In Variza's panel, register your cards and set up its Android app or iPhone shortcut on the phone that gets the
   bank's texts: that is how it sees a transfer.
2. In its profile, set `callback_url` to the shop's address for Variza —
   `https://<the shop's address>/payments/webhooks/variza`, the same for every Variza method. The method's form shows it
   in its notes, ready to copy, when you add the method and when you edit it. Copy the API key and the webhook secret
   from the same profile.
3. Here: «افزودن روش پرداخت» › «واریزا», a label, the API key, the webhook secret, the destination card, and the
   bounds — Variza takes 1,000 Toman a payment at least.

The shop's address («آدرس سایت», `APP_URL`, [Panel settings](Panel-Settings.md#the-app)) must start with `https://`:
Variza sends the customer back there.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کلید API» | Your API key from Variza's profile |
| «کلید امضای وب‌هوک (webhook_secret)» | The secret Variza signs each webhook with, from its profile: the shop believes only a webhook signed with it |
| «کارت مقصد» | Where a payment goes — type one of: «تصادفی» (or `random`): of your active cards, the one with the fewest payments today (Variza's «کارت تصادفی» subscription, and two cards at least); «واریزا» (or `variza`): Variza's own cards, settled to your wallet there and on in Tether (its «کارت‌های واریزا» subscription; 2,000,000 Toman a link at most); or the last four digits of one of your cards there (Persian digits are fine). The list says which («کارت مقصد: تصادفی», «کارت مقصد: •••• 6037») |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens Variza's page, which shows the card and the exact sum — a few Toman above the order's, to tell
  transfers apart; the order is paid at its own price ([above](#every-online-gateway)). The page takes money for 30
  minutes.
- Variza answers no question about a payment: its one word is the webhook it sends once the bank's text matched the
  sum. The shop believes it only when it is signed with the webhook secret of one of its Variza methods, keeps what it
  said on the payment, and delivers the order — once, however many times Variza sends it. A webhook no method signs is
  refused and changes nothing, the log saying so: a secret mistyped here shows up there.
- The customer's browser comes back to the shop afterwards; that visit, «🔄 بررسی پرداخت» and the scheduler read what
  Variza's webhook said — paid, or nothing yet. Nothing heard is waited for while the page takes money, and closes the
  payment after; a webhook that comes later still settles it (or, its order closed by then, credits the customer's
  wallet).
- A webhook naming another sum than the payment's is [held for you](#a-payment-held-for-you).
- Two methods on one Variza account — two destination cards, say — share its secret: the payment is the one of them
  that has the link.
- Variza tries a webhook the shop does not take five times, over a few minutes. Should the shop be out of reach for all
  of them, the payment closes unpaid though the money came: Variza's panel shows the transfer, and you settle it with
  the customer yourself.
- A key Variza does not take, a card not registered or not active there, no `callback_url` in its profile, or a
  subscription's limit refuses the checkout, the log saying what Variza said.

## Bluepal

«افزودن روش پرداخت» › «بلو پال»: an [online gateway](#every-online-gateway) for Iran's bank cards. The customer goes from
Bluepal's page to the bank's, pays there with any card, and is sent back to the shop; the order is delivered with no
receipt and nobody to approve it.

Setting it up:

1. Open an account at bluepal.ir and a gateway in its dashboard. The merchant must be active there — its account
   verified — before Bluepal opens a payment.
2. Copy the gateway's merchant id («مرچنت آیدی», `merchant_id`) and its API key (`sk_live_…`) from the dashboard.
3. Here: «افزودن روش پرداخت» › «بلو پال», a label, the merchant id, the key, and the bounds — the least and most amount
   you set for the gateway in Bluepal's dashboard (its least is 1,000 Toman by default).

Nothing is copied the other way: the shop gives Bluepal each payment's return address itself, and asks Bluepal what came
of each payment, so the dashboard's callback and webhook addresses need nothing of the shop's. Two of its settings do
concern the shop:

- **«دامنه‌های مجاز»** (allowed domains): when filled, the domain of the shop's address («آدرس سایت», `APP_URL`,
  [Panel settings](Panel-Settings.md#the-app)) must be one of them.
- **The IP whitelist**: when on, the outgoing IP of the shop's server must be on it — ask your host's support for it.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «مرچنت آیدی (merchant_id)» | The gateway's id in Bluepal's dashboard: Latin letters, digits, `-` and `_` |
| «کلید API» | The gateway's key in Bluepal's dashboard (`sk_live_…`). To try it out, give the key of the gateway's sandbox — a switch on the gateway in Bluepal's dashboard, with a key of its own: no money moves, but a payment there still delivers its order. The shop cannot tell a sandbox key from a live one, so the list says nothing of it: put the live key back when you are done |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens Bluepal's page, which leads on to the bank's; it asks the order's own sum. Bluepal's page counts
  down 10 minutes; the shop keeps it 15, since a customer who paid in its last seconds may still be on the bank's page,
  or on the way back.
- The customer's browser comes back to the shop, paid or not. Whatever its address says, that visit only makes the
  shop ask Bluepal (verify, by the payment's track id — its reference on the payments screen), and the browser is
  shown [what came of it](#every-online-gateway). A customer who closes the page before coming back loses nothing: the
  scheduler asks Bluepal too, and so can «🔄 بررسی پرداخت».
- Paid — now, or verified before — for the amount asked, the order is delivered, once; a payment Bluepal verifies for
  another amount is [held for you](#a-payment-held-for-you).
- A failed payment took nothing from the customer: they may try again while its page is open, and once it closed, the
  payment closes.
- A missing merchant id, a merchant not active at Bluepal, a wrong or revoked key, or the shop's domain or server not
  on the gateway's lists refuses the checkout; the log gives Bluepal's result and what it means.

Bluepal is young and writes little down — its developer page says nothing of what its return address carries, nor of
everything verify answers. The shop reads only what it documents, its results written as numbers or as text alike, and
believes nothing but verify's word.

## Rialit

«افزودن روش پرداخت» › «ریالیت»: an [online gateway](#every-online-gateway). The customer pays on Rialit's page, Rialit
confirms it by itself, and the order is delivered with no receipt and nobody to approve it. Rialit may add its fee for
the customer to pay, as you set in its dashboard.

Setting it up:

1. Open a merchant account at rialit.io and make an API key in its dashboard.
2. Nothing to paste for its webhook: when you save the method, the shop registers its own address with Rialit where
   the account has none (below). Putting the address — `https://<the shop's address>/payments/webhooks/rialit`, the
   same for every Rialit method, shown in the method's notes — in the dashboard's **Webhook** tab by hand still works.
   The tab's test delivery is answered and changes nothing.
3. If you set allowed IPs in the dashboard, add the outgoing IP of the shop's server to them (ask your host's support
   for it): Rialit opens no payment from any other.
4. Decide in the dashboard whether the customer pays Rialit's fee (below).
5. Here: «افزودن روش پرداخت» › «ریالیت», a label, the key, and the bounds — Rialit takes 50,000 to 10,000,000 Toman a
   payment: set «کمترین مبلغ» to 50,000 and «بیشترین مبلغ» to 10,000,000, so no checkout outside them offers it.

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کلید API» | Your API key from Rialit's dashboard |
| «فعال» | Whether the checkout offers it |

**Its webhook address.** Rialit keeps **one** webhook address for the whole merchant account, and another site of yours
may be using the same account — so the shop reads it before it changes anything, and never overwrites another site's
by itself. Each time the method is saved (added, or its form saved again — not its on/off switch), the shop asks Rialit
where its webhooks go:

- **nowhere** — the shop registers its own address. Rialit tells the account's owner of the change on Telegram and by
  email, as it does of every security change;
- **the shop's address already** — nothing changes;
- **another address** — nothing is overwritten: you are told, and may replace it yourself (below), when the account is
  this shop's alone — the other site then gets no more of Rialit's webhooks;
- **not known** — Rialit could not be read: the key refused, the shop's server not among the allowed IPs, the account
  not active or blocked, more than 20 security changes in the last hour (Rialit's limit, the dashboard's own changes
  counted), or Rialit out of reach; or registering failed — the shop's address not on `https://`, or refused by Rialit.

The method is saved whatever came of it; a notice says what did, and why when it did not. The method's dialog in the
panels keeps a **«وب‌هوک ریالیت»** block with where the webhooks go now («آدرس فروشگاه ثبت است», «ثبت نشده», «به آدرس
دیگری می‌رود» with that address, or «معلوم نیست» with why) and the way on: «ثبت آدرس فروشگاه» while there is none,
«جایگزین کردن با آدرس فروشگاه» (asked first) for another site's, «دوباره امتحان» when it is not known — and, until the
address is the shop's, the shop's address to copy into Rialit's Webhook tab by hand. A save that finds another site's
address opens the dialog on that block. In the bot's management the method's screen has the same line, «🔗 وب‌هوک
ریالیت» ([Managing the shop from the bot](Bot-Management.md#the-shop)).

**Its fee on the customer.** Rialit's dashboard may pass its fee on to the payer: a percent Rialit adds on top and
rounds — 113,650 Toman for a 100,000-Toman order at 12%. Rialit says the sum when the payment is made; the bot's page and
the website show it with the fee apart («(شامل ۱۳٬۶۵۰ تومان کارمزد درگاه)»), and the customer pays that on Rialit's
page. The order is paid, delivered and credited at its own price (a top-up credits its own amount); the fee is Rialit's.
Say it in the label too («ریالیت (+۱۲٪)»): the checkout's buttons show only labels. With no fee passed on, the page asks
the order's own sum.

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens Rialit's page, open 30 minutes. The payment is made at Rialit under the shop's own id for it —
  opened again, it is the same payment there — and for the customer's Telegram account: Rialit knows its payers by
  Telegram, so a customer with no Telegram account (one signed in to the website by SMS) is not offered Rialit: they
  pick another method, and nothing is ordered with it.
- Rialit sends no one back: the customer returns to the bot or the website themselves, and «🔄 بررسی پرداخت» asks.
- **Rialit's webhook is never believed.** Nothing signs it, so anyone could send one: it only makes the shop ask Rialit
  about the payment it names — an unpaid payment of one of the shop's Rialit methods, six times a minute at most;
  anything else it names asks nothing. A body that is not Rialit's is refused and logged. Without the webhook nothing
  is lost, only slower: the shop asks Rialit by itself every few minutes while the page is open, and support's
  «استعلام از درگاه» asks too.
- Paid — for the payment's own order and sum, which Rialit's answer must name —, the order is delivered, once; an
  answer naming another order or sum is [held for you](#a-payment-held-for-you). Not paid yet waits while the page
  takes money and closes the payment after; expired or cancelled closes it.
- Rialit may approve a payment late, after its page expired: it is settled all the same — while the shop still asks,
  two hours after the page closed by default, or whenever the webhook comes —, or goes to the customer's wallet once
  its order closed.
- A wrong or revoked key, the shop's server not among the allowed IPs («IP not allowed»), a merchant account not
  active, an amount outside 50,000 to 10,000,000 Toman, or too many requests refuses the checkout; the log says what
  Rialit said and what it means.

**How it was built.** Rialit's API documentation is open only to its merchants, behind a sign-in to its dashboard; the
shop's Rialit follows Rialit's own OpenAPI description there — its `send` and `status` calls, its payer's fee, its
webhook's headers and body. Rialit asks merchants to take webhooks only from the IP addresses its Webhook tab lists; the
shop asks Rialit about every webhook instead, so one from anywhere else changes nothing (and a shop behind a proxy would
not see Rialit's address anyway). Before switching the method on, pay a small order with it, and send the Webhook tab's
test delivery once (the log says when a webhook is refused as not Rialit's).

## Rikhtam's crypto payments

«افزودن روش پرداخت» › «ریختم (ارز دیجیتال)»: an [online gateway](#every-online-gateway) of crypto payments. The customer
pays on Rikhtam's page in a coin — TON's Gram (GRAM), Tether on TON, Tether on TRON (TRC20) or TRON's TRX — straight to
your own wallet; once Rikhtam sees it on its chain, the order is delivered with no receipt and nobody to approve it.
Rikhtam's other side, card to card, confirms card payments in «تایید خودکار واریز» ([above](#through-rikhtam)).

Setting it up:

1. Open an account at rikhtam.com. In its panel, «درگاه‌ها», make a gateway with a name and the shop's domain — the
   domain of the shop's address («آدرس سایت», `APP_URL`); a subdomain of it is fine — and copy its «کلید امنیتی». One
   gateway serves both of Rikhtam's sides, with the same key.
2. In the same panel, «درگاه‌ها» › «پرداخت با ارز دیجیتال», set the wallet the coins go to: a personal TON or TRON
   wallet of yours, never an exchange's deposit address. Rikhtam takes 24 hours to change it, and refuses a testnet one.
3. Here: «افزودن روش پرداخت» › «ریختم (ارز دیجیتال)», a label, the key, the coin, and the bounds.
4. For Rikhtam to tell the shop of a paid invoice the moment it is paid, connect it in «تایید خودکار واریز» with the
   same key ([above](#through-rikhtam)): the shop then registers its address with Rikhtam. Connecting switches the
   automatic approval of card payments on; switch it off afterwards if you only take crypto through Rikhtam — its word
   on crypto still comes. Without it, the shop asks Rikhtam every few minutes while the page is open, and whenever the
   customer comes back or taps «🔄 بررسی پرداخت».

| Field | What it is |
|---|---|
| «نام روش» | What the checkout's button says |
| «کمترین مبلغ (تومان)» / «بیشترین مبلغ (تومان)» | The amounts it is offered for ([above](#the-least-and-most-amount)) |
| «کلید امنیتی درگاه» | The gateway's «کلید امنیتی» from Rikhtam's panel, «درگاه‌ها» — the same key you give «اتصال ریختم» |
| «ارز پرداخت» | «انتخاب مشتری در صفحه ریختم» (the default): the customer picks the coin on Rikhtam's page; or one coin — «گرم (GRAM)», «تتر روی شبکه TON», «تتر روی شبکه ترون (TRC20)» or «ترون (TRX)». The list shows it («ارز: تتر روی شبکه TON») |
| «فعال» | Whether the checkout offers it |

How a payment goes ([every online gateway](#every-online-gateway) has the rest):

- «💳 پرداخت» opens Rikhtam's page. The invoice is asked in Toman for the payment's order number, and Rikhtam prices it
  in the coin: the bot and the website show the order's price in Toman, and the coin's amount and address are on
  Rikhtam's page. A transfer on TON must carry the comment the page gives, and one on TRON the exact amount it asks:
  that is how Rikhtam tells payments apart. The page takes money until the end Rikhtam gives it — an hour, while the
  customer has not picked a coin yet.
- Opened again, a payment is its open invoice again, never a second one.
- On a shop whose address begins with `https://`, the customer's browser comes back to it after paying and is shown
  [what came of it](#every-online-gateway); otherwise they return to the bot or the website themselves.
- Rikhtam's word that an invoice was paid, to the shop's Rikhtam address, only makes the shop ask Rikhtam about it.
  Paid, the order is delivered, once; not paid yet waits while the page takes money and closes the payment after;
  expired or cancelled closes it. An answer naming another order or invoice is [held for you](#a-payment-held-for-you).
- **Less than asked, or too late, is never confirmed by itself**: Rikhtam reports such a transfer in its panel, and you
  settle it with the customer there.
- Rikhtam's fee for crypto payments is apart from its card-to-card fee; its panel shows it in the same part.
- A wrong key, a Rikhtam account or gateway disabled there, its fees unpaid, an order Rikhtam says was paid already, or
  a coin it would not take refuses the checkout; the log says what Rikhtam said and what it means.

## The list

- **▲▼** — the order of the checkout's buttons.
- **«جزئیات»** — for a card method, its cards: each masked with its holder and bank, «(خاموش)» when switched off, and
  what it took today against its cap («امروز ۲۵۰٬۰۰۰ تومان از ۳۰۰٬۰۰۰ تومان»; «پر شده» once full); under them, the
  review window when there is one, and the amounts it is offered for. For an online method: «پرداخت در صفحه CubePay،
  بدون رسید و با تایید خود درگاه» (with its own gateway's name), and its summary — the fee passed on, the test mode,
  the dollar's rate, the destination card or the coin.
- **«نوع»** — «تسویه آنی» (the wallet), «تایید دستی» (a card), «درگاه آنلاین» (any online gateway), or «درایور نصب
  نیست» for a method of a kind this installation no longer has. No checkout offers that one, switched on or not («به
  مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.»), and it can only be switched off or deleted.
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

«تنظیمات ربات › کیف پول» › «مبلغ تصادفی افزوده به شارژ کارت به کارت (تومان)» (0: off, up to 9,999): a wallet top-up
paid by card asks 1 to that many Toman more, at random. The sum is one no other waiting payment to the same card asks,
and never past the method's «بیشترین مبلغ» (a top-up of the method's most asks nothing more). So every transfer is a
different amount, and its receipt is told apart at a glance.

- The customer is shown, and pays, the whole sum («لطفا مبلغ ۱۰۰٬۰۳۷ تومان را … واریز کنید»), and the whole sum is
  credited to their wallet once approved — as a refund takes the whole sum back out.
- The order keeps the amount asked for (100,000): the same top-up asked again finds it, with its payment's sum.
- Purchases, renewals and extras are paid at their price.
- While card payments go to support's private chat, a top-up still asks its few Toman more — a sum no other waiting
  payment there asks —, so support tells the receipts sent to their chat apart the same way. Should the shop show
  cards again before it is paid, the customer tapping the method again is shown a card and keeps the same sum — unless
  another waiting payment to that card asks it: then it gets a fresh sum of its own.

## Watch out for

- **Labels are not checked for duplicates**, nor a card's number across methods: give each method a label a customer
  can tell apart.
- **A daily cap is a guard, not a bank's limit**: it counts what the shop asked for on the card today — by the day each
  payment was made, so one made before midnight and paid after it counts for the day before —, not what the bank
  received.
- **CubePay, Zarinpal, AtlasPay, AbanGateway, Plisio, AqayePardakht, Variza, Bluepal, Rialit and Rikhtam's crypto
  payments are the online gateways so far** ([Roadmap](Roadmap.md)); another way to pay is a driver
  ([Drivers](Drivers.md#a-payment-gateway)).
