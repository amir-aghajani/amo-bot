# Orders and payments

«فروش › سفارش‌ها» and «فروش › پرداخت‌ها», in both panels. An **order** is what a customer buys — a service («خرید»), a
renewal («تمدید»), extra traffic, time or devices for a service they have («حجم اضافه», «زمان اضافه», «کاربر اضافه»
— [Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)), a top-up of their wallet («شارژ کیف پول») or, in the main
shop, an agent's traffic («خرید حجم نمایندگی») —, and a **payment** one attempt to pay it, by one method.

Nothing is ordered until the customer picks a way to pay. The wallet pays at once and the order is delivered there and
then; a card transfer waits for its receipt and your review; an online gateway's payment
([Payment methods](Payment-Methods.md#every-online-gateway)) is paid on the gateway's page and settles on the gateway's
word alone. An order and its payments move on once, however many people press at the same moment — two tabs, the
report group, the timer —, and only the decision that stood tells the customer.

| Order status | Means |
|---|---|
| «در انتظار پرداخت» | Waiting to be paid — its receipt may be with you |
| «پرداخت‌شده» | Paid; its delivery has not started |
| «در حال ساخت» | Being delivered — the panel is being asked |
| «تکمیل‌شده» | Delivered |
| «ناموفق» | Paid, but its delivery failed: it waits for a retry |
| «لغوشده» | Cancelled — by you, or unpaid for 48 hours |
| «بازپرداخت‌شده» | Refunded |

A payment is «در انتظار پرداخت» (no receipt yet — an online one: not paid on its page yet), «در انتظار بررسی» (its
receipt waits for you), «پرداخت‌شده», «ناموفق» (its receipt was rejected, the wallet refused it, or an online gateway's
page closed unpaid or would not open), «لغوشده» or «بازپرداخت‌شده».

## Payments

«پرداخت‌ها»: every payment customers started. The tabs are «همه», **«در انتظار بررسی»** — the receipts to review, counted
beside it and in the menu —, «پرداخت‌شده», «ناموفق» and «در انتظار پرداخت»; cancelled and refunded payments are under
«همه». Search by a payment's or an order's number or a customer (`#12` is payment 12 alone), filter by «تاریخ» (the days
it was started) — and by a customer, from their page —, sort by «مبلغ» or «زمان». The line above the list says how many
of the payments on screen were paid and their sum.

**A payment's dialog** — «بررسی» on a receipt waiting, «جزئیات» on the rest — shows the receipt («نمایش در اندازه کامل»,
«دانلود رسید» for one the browser cannot draw) and its facts: the customer, what it pays for, its order (a link), the
method and card, the customer's caption, when it was started, sent and paid, who decided and why. An online payment has
no receipt; it shows its gateway («درگاه CubePay», «درگاه زرین‌پال», «درگاه اطلس‌پی», «درگاه آبان گیت وی» under the
method), its id there («شناسه در درگاه»: a CubePay card payment's authority, Zarinpal's authority and then its bank
reference once paid, AtlasPay's tracking code, AbanGateway's invoice id), until when its page takes money while it
waits («مهلت پرداخت در درگاه») and when the gateway was last asked («آخرین استعلام») — and, [held for
support](#a-payment-held-for-support), that it is and why. Then what may be done with it now:

| Operation | Offered | What it does |
|---|---|---|
| «تایید و تحویل» («… شارژ کیف پول», «… افزودن حجم», «… افزودن زمان», «… افزودن کاربر») | A card payment not paid yet, its order still open — with a receipt, or without one («تایید دستی و …») | **At one press.** The order is paid and delivered at once — the service made or renewed, its extra traffic, time or devices added, the wallet charged, the agent's traffic added —, the order's other attempts to pay are cancelled (but a receipt waiting for review, which stays yours to reject), a referrer's commission is paid, and the customer gets their service or word of it |
| «رد کردن» | A receipt waiting for review | Asks for a reason the customer reads («دلیل رد کردن»). The payment fails; the order stays open for them to pay again |
| «لغو پرداخت» | Any payment not paid | Asks for an optional note. Cancels this payment, and — while nothing else paid it — its order with every other attempt to pay it; the customer is told when it cancelled the order. **But while another payment of the order has a receipt waiting for review**, this payment is cancelled alone: that receipt may be the money, so the order and the receipt stay for you to decide, and the customer is told nothing |
| «یادآوری به مشتری» | A card payment waiting for its receipt, or rejected, its order open | At one press: a reminder with «ارسال رسید» and «پرداخت دوباره» (a rejected one, «پرداخت دوباره» alone; one taken in [support's private chat](Bot-Settings.md#card-to-card-payments) — shown no card —, the way there instead of «ارسال رسید»). Nothing changes; the toast says whether it reached them |
| «استعلام از درگاه» | An online payment opened on its gateway and not paid — waiting, its page closed, or cancelled (the gateway may still take money on it) —, never one [held for support](#a-payment-held-for-support) | At one press: asks the gateway what came of it, as its callback, the customer's «🔄 بررسی پرداخت» and the scheduler do. Paid, it settles as an approval does — the order delivered, the customer told once —, or, its order closed meanwhile, [into the wallet](#money-that-came-after-its-order-closed); still waiting, nothing changes; its page closed unpaid, it fails («مهلت پرداخت در درگاه تمام شد»). The toast says which. An answer that does not fit the payment holds it, and a gateway that cannot be asked changes nothing: the dialog says so above its operations («درگاه این پرداخت را با سفارش یا مبلغ دیگری تایید کرد؛ …», «درگاه پرداخت الان جواب نمی‌دهد و چیزی تغییر نکرد؛ …»), and the log says why |
| «تلاش دوباره برای تحویل» | A paid payment whose order's delivery failed, or stalled for ten minutes | At one press: the delivery again ([Orders](#orders)) |
| «بازپرداخت به کیف پول» («بازپرداخت شارژ» for a top-up) | A paid payment whose order is not being delivered right now — never one [gone to the wallet](#money-that-came-after-its-order-closed) | Asks first, with an optional note: [refunds](#refunds) |

An online payment is never approved, rejected or reminded by hand: it has no receipt, and only its gateway's word
settles it. «لغو پرداخت» drops one still waiting with its order, as it drops any; should the customer pay its page after
all, the money goes to their wallet.

An approval whose delivery failed says why — the panel's words — and stays open, its retry in reach; the order waits under
«سفارش‌ها» › «نیازمند رسیدگی». A note is 300 characters at most — a refund's 190, since it is the line the customer reads in
their wallet. A decision the payment no longer allows — someone decided
it a moment ago, here, in the report group or by the timer — is refused with what happened («این پرداخت تایید شده است.»,
«سفارش این پرداخت با پرداخت دیگری پرداخت شده است.»), and the dialog shows the payment as it is now.

**«بعدی»** opens the next payment of the list as you see it, so receipts are reviewed one after another: after a
decision the dialog stays on the decided payment with «بعدی» ready.

**The reminder's outcome**: «یادآوری فرستاده شد», «… با ایمیل فرستاده شد» (a customer Telegram cannot reach), or
«یادآوری فرستاده نشد» with why — they blocked the bot, Telegram or the mail server was out of reach, or the customer has
no Telegram account and no email went.

**What the customer reads** of a decision is a reply to the receipt they sent in the bot (a plain message for one
uploaded on the website; an email for a customer Telegram cannot reach), with no buttons; your note is its «توضیح
پشتیبانی: …» line. Every notice is kept on their website too.

**Who decided** is recorded on the payment: your login, an agent's «@bot», a bot admin's handle (or `tg:<id>`, or
`user#<id>` for an account of the website alone) from the report group or the shop's website, or «تایید خودکار» for
the review window. Anyone but you reads your login as «پشتیبانی».

Receipts are also decided from the report group's «رسیدها», by the bot's admins: «✅ تایید» and «❌ رد» — with or without a
reason — do what this dialog does ([Report group](Telegram-Group.md)). The bot's admins may work this screen from the
shop's website too, while it lets them in ([Website](Website.md#the-shops-admins)) — there they approve only a payment
whose receipt waits for review, and refund only when the website grants it; they ask an online payment's gateway as you
do. **An admin never approves or refunds their own payment**, in the group or on the website: another admin, or you,
does. A card's review window approves a receipt sent in the bot that nobody decided in time
([Payment methods](Payment-Methods.md#approving-receipts-by-themselves)).

### Receipts

A card transfer's receipt is a picture the customer sends in the bot after the card's message — or uploads on the shop's
website —, and it waits for you under «در انتظار بررسی». The payment's row, its review and the report group's line name
the card it was shown — one of its method's cards, picked at random ([Payment
methods](Payment-Methods.md#its-cards-and-their-rotation)) —, so you know which account to look in. How the card is
shown and receipts are taken is the bot
settings' «پرداخت کارت به کارت» ([Bot settings](Bot-Settings.md#card-to-card-payments)): the card's message protected
from forwarding and screenshots, with buttons that copy the card number and the amount; **one receipt at a time** — while
one of a customer's is with you, they send no other and start no other card payment, told that the receipt of that order
is being reviewed —; or **no card at all**, the customer sent to support's private chat for it — then no receipt comes
here, only the payment, waiting for you to approve it by hand («تایید دستی و …») once you see the money — it never
expires, so cancel one never paid («لغو پرداخت»); it names no card but «کارت در پیوی پشتیبانی داده شد», since none of
the method's cards was picked for it.

**Rejecting receipts in bulk.** Tick the receipts waiting for review — a row's box, or the header's for the page's — and
press «رد رسیدهای انتخاب‌شده» on the bar that appears; on the «در انتظار بررسی» tab, with no search or filter, «رد همه
رسیدهای در انتظار» takes every one waiting, the oldest first — those that had come when you confirmed: one sent while it
runs is left for you to see. After you confirm, with an optional reason the customers read, each receipt is
rejected as «رد کردن» rejects one: the payment fails, its order stays open for the customer to pay again (or to expire on
its own), the customer is told with your reason, and the receipt's report in the group loses its buttons. A picture
uploaded from the website is deleted too (one sent in the bot stays Telegram's), and the group's «رسیدها» gets one line
for each hundred, the most one request rejects — how many, and who. A receipt decided by someone else meanwhile is skipped; the toast says how many were
rejected and how many were not. The shop's website admins may do it only while the website grants «رد یکجای رسیدها»
([Website](Website.md#the-shops-admins)).

### Money that came after its order closed

A customer may pay an online gateway's page after its order was paid another way, cancelled or expired — or the gateway
may take money on a page shortly after it closed. That money is theirs: the payment is «پرداخت‌شده» with «به کیف پول
مشتری رفت» under it (in the list, on the customer's page and among its order's payments), its dialog's «کیف پول» says
«سفارش پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ به کیف پول مشتری رفت», and its amount is in their wallet as «پرداخت
آنلاین #12 پس از بسته شدن سفارش #7». The customer and the report group's «شارژ کیف پول» are told. It paid nothing of the
order — a cancelled order stays unpaid —, so it is neither delivered again nor refunded here: a refund would give the
money twice; to give it back, take it out of their wallet ([Customers](Customers.md)).

### A payment held for support

An online payment the shop would not settle on its gateway's word — confirmed for another order or amount, an
underpayment AtlasPay accepted, or money the gateway kept unsettled on it for 48 hours
([Payment methods](Payment-Methods.md#a-payment-held-for-you)) — is held for you. It keeps its state — «در انتظار
پرداخت», as a rule —, marked «نگه داشته شده برای پشتیبانی» in the list, on the customer's page and among its order's
payments; its dialog's «بررسی پشتیبانی» says why («درگاه این پرداخت را با سفارش یا مبلغ دیگری تایید کرد؛ …», or «درگاه
پولی را که برای این پرداخت رسیده بود دو روز پس از بسته شدن صفحه هم تسویه نکرد؛ …»), and the report group's «خطاها»
heard of it once. Nothing asks its gateway about it any more — «استعلام از درگاه» is not offered. While it still waits
— pending, or failed with its page —, its order does not expire and the customer cannot pay it another way: they are
told to message support.

Look at the payment at its gateway (its «شناسه در درگاه»), settle it with the customer — what they paid, what they are
owed —, then «لغو پرداخت»: it is the one operation a held payment offers, and it cancels the order with it unless
something else paid it. Cancelled, it holds nothing more: the customer's check answers as for any closed page. Give the
customer what is theirs by hand: an order you make for them ([below](#orders-support-makes)), or their wallet
([Customers](Customers.md)).

## Orders

«سفارش‌ها»: every order. The tabs are «همه», **«نیازمند رسیدگی»** — paid and not delivered: the delivery failed, or
nobody has been delivering it for ten minutes, while the payment that paid it stands; counted beside it and in the menu
—, «در انتظار پرداخت», «تکمیل‌شده», «لغوشده» and «بازپرداخت‌شده». Filter by «نوع», «تاریخ» (the days it was placed) and a
customer; search by an order's number, a customer, a plan or a service's name (`#12` is order 12 alone); sort by «مبلغ»
or «زمان». The line above it says how many were sold and their sum.

**An order's dialog** — «رسیدگی» on one to deliver again, «جزئیات» on the rest — shows what it bought (the plan, the
server, the service — a link to it —, and how much a service's extra traffic, time or devices, or an agent's traffic,
comes to: «حجم اضافه ۱۰ گیگابایت», «زمان اضافه ۷ روز», «کاربر اضافه ۲ کاربر»; a renewal's «روش تمدید», such as «ریست
حجم + افزایش روز» — [renewal methods](Bot-Settings.md#renewal)), why it failed or was cancelled, when it
was placed, paid and delivered, and its payments, each a link to the payments screen, where receipts, refunds and
reminders are. The list's «بابت» says the same: a service's extra as its amount over the service's name. Two operations
belong to the order:

- **«تلاش دوباره برای تحویل»** — at one press: the delivery again, once whatever stopped it is fixed (the server back, the
  agent's traffic bought). The customer hears of it only when it works; failing again, the dialog says why and stays. A
  delivery another process is doing right now is not started twice.
- **«لغو سفارش»** — an order not paid yet, a receipt in review included: asks for an optional note — the question counts
  the receipts waiting for review it will drop —, cancels every attempt to pay it, then the order. The customer is told
  when they had started paying it, nobody otherwise.

**Why a delivery fails** is the order's «خطای تحویل»: a panel's failure — the full diagnosis for you (its address, its
answer, in any shop), a short word for anyone else who reads the order (an agent, the shop's admins on its website) —, a
rule's refusal (no server can sell the plan now, the agent's traffic is short — the agent is told in the main bot —, or
a renewal or an extra of a service [handed to another customer](Services.md#handing-a-service-to-another-customer) since
it was ordered: nothing is added to the new customer's service, and the note asks you to refund it), or «تحویل با خطای
داخلی برنامه متوقف شد؛ …» for a fault of the shop's own, whose details are in the log. The report group's «خطاها» says
it the same way: the diagnosis in the main bot's group, the short word in an agent's.

## Orders support makes

**«افزودن سفارش»**, in the menu at the top of a customer's page ([Customers](Customers.md)), makes them a service by hand
— one they paid you for in cash, a gift, a replacement — and delivers it at once. Its dialog asks:

- **what**: «پلن» — one of the shop's plans, switched on or off — or «سرویس دلخواه»: your own «حجم (گیگابایت)», «مدت
  (روز)» and «تعداد کاربر», each 0 for no limit (an unlimited, never-ending service is 0, 0, 0);
- **«سرور»**: for a plan, one of its servers that can deliver it now (picked for you when there is one); for your own
  numbers, any server that can take a service now. One that cannot says why beside it;
- **«نام کاربری سرویس»** (optional): the name its client gets on the panel, held to the rules a buyer's own name is — 3 to
  32 Latin letters, digits, `_` and `-`, one the server's panel takes and no service there has. Blank, the shop's
  pattern names it;
- **«پرداخت»**: «رایگان», «از کیف پول مشتری» — the amount you set comes out of their wallet, refused while it holds less
  (an agent's credit counting), and while the shop's wallet is switched off ([Payment methods](Payment-Methods.md#the-wallet)),
  but never for the wallet's «کمترین مبلغ» or «بیشترین مبلغ», which hold the customers' own checkouts
  ([Payment methods](Payment-Methods.md#the-least-and-most-amount)) —, or «پرداخت خارج از فروشگاه» — the amount they paid you outside the shop (cash, another card), recorded as paid;
- **«یادداشت»** (optional), kept on the order and said in the report group; the customer does not see it.

A box under the form says what it will deliver before you press **«ثبت و تحویل سفارش»**. The service is made there and
then: the customer gets it as any purchase («✅ سرویس با موفقیت ایجاد شد» with its link), it shows on their page, and the
report group's «خریدها» reports the purchase with «🎧 ثبت دستی توسط پشتیبانی» — an admin or an agent by name, you without
your login — and how it was paid. A delivery that fails (the panel refused) leaves the order paid and «ناموفق» under
«نیازمند رسیدگی»; its «تلاش دوباره برای تحویل» tells the customer once it works. Pressing again after the browser got no
answer never makes a second order.

What each way of paying counts as:

| | «رایگان» | «از کیف پول مشتری» | «پرداخت خارج از فروشگاه» |
|---|---|---|---|
| The order | «هدیه», no price | Its amount, from the wallet | Its amount, by the built-in «خارج از فروشگاه» method |
| A purchase of the customer's (first-purchase prices, a newcomers' code, a referral's first-purchase gift) | No | Yes | Yes |
| The shop's revenue, the referrer's commission | No | No — the wallet's money counted when it was topped up | Yes |
| Cashback, loyalty tiers, the nightly draw | No | Yes | Yes |

The order's row in «سفارش‌ها» and its dialog say «سفارش دستی» with how it was paid, who made it («ثبت دستی») and your note.
Refunding it is [as any order's](#refunds): its service is removed and what was paid goes to the customer's wallet. The
«خارج از فروشگاه» method is built into every shop and never offered to customers; it is not listed under «روش‌های پرداخت».

**In an agent's shop** the agent makes one the same way, and the service's traffic comes out of their bot's traffic like
any sale: a service with no traffic quota cannot be made there, nor one with more than is left, nor one on a server or of
a plan you keep from that shop ([Agency](Agency.md#servers-and-plans-kept-from-an-agents-shop)). While its selling is
suspended none is made there — by you neither, from its page in the shop switcher: open its selling again first. **On
the shop's website** its admins make one only while the website grants «افزودن سفارش برای مشتری»
([Website](Website.md#the-shops-admins)), with a recent sign-in. **Nobody makes an order for themselves**: another
admin, or you, does.

## Refunds

«بازپرداخت» gives back what a payment paid, by what its order bought:

- **A service** — the service the purchase made goes first: its client off its panel and the service off the shop,
  quietly (the refund's message says so). Then the amount goes into the customer's wallet («بازگشت وجه پرداخت #12»),
  less the cashback the order earned, if any ([Cashback](Loyalty-Cashback-And-Draws.md#a-refund)). In an agent's shop the
  traffic the sale drew goes back into the agent's pool («بازگشت حجم سفارش بازپرداخت‌شده #12»). Refused, with nothing
  refunded, while:
  - its panel does not answer — the refusal says what the panel said; delete the service from
    [its dialog](Services.md#when-the-panel-fails-a-delete) with «حذف فقط از فروشگاه», then refund;
  - another order of the service is paid or still open — a renewal, extra traffic, time or devices —, named in the
    refusal: it paid for that service too. Refund or cancel it first, or delete the service yourself and then refund.

  A purchase whose service is gone already (deleted, or its delivery failed) is refunded with nothing to remove.
- **A renewal, or a service's extra traffic, time or devices** — once delivered, what it gave the service is taken back
  first, on its panel: a renewal's term and traffic as it gave them (even if its plan changed since), an extra's days,
  traffic or devices. A renewal that reset the time or the traffic gave only what it added to what it replaced — 30
  days in place of 10 left gave 20, 30 GB in place of 10 left gave 20 —, and one that replaced more than it gave gave
  less than nothing — a week in place of 25 days left, 10 GB in place of 40 left —, which its refund gives back: either
  way the refund leaves the service as the renewal found it. The deadline moves back by them — to now, ending the
  service, when that is past —, the quota shrinks by them (never to nothing: a service that used more than it keeps
  ends by traffic), and the device limit by the devices bought (never below one) while their period lasts. What a later
  order started afresh is no longer there to take back, and nothing of it is taken: the days of an earlier renewal or
  extra time once a later renewal reset the time (or renewed a service whose time had run out), or once extra days
  bought after its time ran out started a new term, their traffic once a renewal reset the traffic count, devices once
  one brought its plan's device limit — that later order's own refund takes them back with its own. A renewal's device
  limit goes back as it was — the one it set, or queued for the end of the period in use —, with the devices bought or
  refunded since still counted; while a later renewal stands, it keeps that one. A renewal that gave a service without
  an end its first term, or an unlimited one a quota (one made by hand on its panel and added by its link), leaves it
  never ending, or unlimited, again; one onto a plan that never ends or has no quota puts back the end and the traffic
  left it replaced (the traffic used while it was unlimited is not counted) — each once no later renewal of the service
  stands. A service that never ends, is unlimited or has no device limit otherwise keeps that part. A renewal queued
  behind the current period loses its traffic. A service's renewals and extras end it where it was, refunded in any
  order. Then
  the amount goes into the customer's wallet, less the cashback the order earned; in an agent's shop the traffic it
  drew goes back to the agent's pool. It is taken back once: a refund refused after it (decided meanwhile) takes nothing
  more the next time. A service switched off stays off. Refused, with nothing refunded, while its panel does not answer
  (refund again once it does). One never delivered (its delivery failed) gave nothing, and nothing is taken back; a
  service gone already has nothing to take back either.
- **A service handed to another customer since** ([a transfer](Services.md#handing-a-service-to-another-customer)) —
  a purchase, a renewal or an extra of it is refused, with nothing refunded or taken: it is someone else's now. Take it
  back by hand first — delete it, or hand it back to the buyer — and refund again, or give the money back yourself
  through the buyer's wallet. Two accounts merged into one stay the same customer: their orders go with their services.
- **A top-up of the wallet** — give the money back yourself, outside the shop: the refund takes the same amount out of
  their wallet, and is refused once they spent it (an agent's credit counting). A top-up never delivered moves nothing
  in the wallet, and the customer is told their balance did not change.
- **An agent's traffic** — the amount into the agent's wallet, and the traffic out of their bot's pool — refused, all of
  it, once they sold from it.

It is refused while the order is being delivered — and when it was delivered in the moment between the refund's
check and the refund itself: refund it again, and what it gave is taken back then. The customer is told, as a reply to
their receipt — with a line saying the purchase's service was removed
(«🗑️ سرویس … که با این خرید ساخته شده بود حذف شد.»), or what the renewal or extra gave was taken back
(«↩️ زمان و حجمی که این سفارش به سرویس … داده بود پس گرفته شد.»; none when nothing of it was left to take) — bot
texts you may reword.
A referrer's commission stays paid.

## What runs by itself

- **Deliveries left behind** — an order paid while the bot was restarting, untouched for ten minutes, is delivered by
  the scheduler (every minute, the longest-waiting first). A failed one waits for you.
- **Unpaid orders expire** — an order nobody paid, with nothing done to it or its payments for **48 hours**, is
  cancelled within ten minutes after (an order made with a discount code sooner —
  [Discounts](Discounts.md#uses-and-giving-them-back)): quietly, its rows kept, a rejected receipt's reason kept, the
  receipts uploaded for it on the website deleted. **An order whose receipt is with you never expires** — nor one whose
  card payment went to support's private chat ([Bot settings](Bot-Settings.md#card-to-card-payments)), one whose
  online gateway's page still takes money, or one whose online payment is [held for you](#a-payment-held-for-support)
  and still waits.
- **Receipts approved by themselves** — a card's review window ([Payment methods](Payment-Methods.md)).
- **Online payments asked about** — every payment on an online gateway (CubePay, Zarinpal, AtlasPay, AbanGateway) not
  paid yet — failed with its page or cancelled with its order too — is asked about every few minutes while its page is
  open and every quarter of an hour for two hours after, should its callback never come; one still waiting then is
  given up on — unless its gateway said money came in on it, which is asked about until 48 hours after its page closed,
  then held for you. A held payment is asked about no more
  ([Payment methods](Payment-Methods.md#every-online-gateway)).

## Watch out for

- **«تایید», «یادآوری», «استعلام از درگاه» and «تلاش دوباره» run at one press**: no second look.
- **Cancelling a payment of an unpaid order cancels the order** — and every other attempt to pay it —, unless another of
  its payments may be the money — a receipt waiting for review, or a card payment that went to support's private chat —:
  then that payment alone goes, and the other is still yours to approve or cancel. «لغو سفارش» drops everything,
  receipts in review included.
- **A failed delivery of a paid order is yours**: nothing retries it by itself. The dashboard's «نیازمند توجه» and the
  menu's count say when one waits; the report group's «خطاها» hears each, with «🔁 تلاش دوباره برای تحویل».
- **Working an agent's shop**, their orders and payments are theirs to read too: they see your decisions as
  «پشتیبانی».
