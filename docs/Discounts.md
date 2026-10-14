# Discounts

Four ways a shop takes something off what its customers pay: discount codes («کدهای تخفیف») a customer types at the
checkout, timed sales («فروش ویژه») on the shop's plans, a customer's own discount («تخفیف اختصاصی») support gives them for
good, and the discount of their loyalty tier ([loyalty tiers](Loyalty-Cashback-And-Draws.md#loyalty-tiers)). Codes and
sales are under «تخفیف و جوایز» in either panel; every shop runs its own — the owner's, and each agent's in their bot.

## How they stack

What a purchase, a renewal, extra traffic, extra time or extra devices cost a customer is worked out the same way in the
bot, on the website and for an automatic renewal:

1. **The price** — the plan's price for this customer: its first-purchase price while they have bought nothing, an agent's
   price for an agent ([plans](Plans.md)); extra traffic, time and devices, the amount's own price.
2. **The automatic discount** — of a sale running on that plan, the customer's own discount and their loyalty tier's
   discount, only the largest is taken. They are never added together.
3. **The code** — a code the customer typed applies to what step 2 left.

Amounts are whole Toman, rounded down; nothing ever costs less than zero. An order that comes to zero is free — the
customer takes it with one button («🎁 دریافت رایگان»), nothing to pay.

A wallet top-up and an agent's traffic never take a discount, of any kind.

An automatic renewal («تمدید خودکار») and the renewal's price shown before paying take the automatic discount; a code is
only ever typed by the customer, at a checkout.

## Discount codes

### The list

«کدهای تخفیف» › «کدها» lists the shop's codes, the newest first, each with what it takes off, what it is for, its window,
how many times it was used and its state: «فعال», «شروع نشده» (its window has not begun), «تمام‌شده» (its window is over),
«ظرفیت پر» (its uses are all taken) or «غیرفعال» (switched off). The switch on each row turns a code on or off at once.
The search finds a code by its text or its note.

A code that was used in an order is never deleted — the order keeps what it was charged and why; switch it off instead.
One nobody used is deleted from its row's menu.

### A code's fields

«ساخت کد تخفیف»:

- «کد» — what the customer types: 3 to 32 letters, digits, dashes or underscores, one code a shop. Case does not matter,
  and Persian digits are read as digits. «ساخت کد تصادفی» fills in one.
- «نوع تخفیف»:
  - «درصدی» — «درصد تخفیف» (1 to 100) of the amount, held to «سقف تخفیف (تومان)» when one is given;
  - «مبلغ ثابت» — «مبلغ تخفیف (تومان)» off the amount, never more than the amount itself;
  - «رایگان» — the whole amount: the order is free.
- «برای چه سفارش‌هایی» — every kind of order, or the ones ticked: «خرید», «تمدید», «حجم اضافه», «زمان اضافه»,
  «کاربر اضافه».
- «شروع» and «پایان» — a day and an hour each, in the shop's time zone (`APP_TIMEZONE`); empty, from now and without end.
  A code that should end at a given hour of a given day — an "hourly" code — is one whose «پایان» says so: from that
  moment it is refused.
- «سقف کل استفاده» — how many orders may be made with it in all; empty, without limit.
- «سقف استفاده هر مشتری» — how many by one customer; 1 makes it once a customer.
- «محدودیت‌های بیشتر», folded:
  - «پلن‌ها» — every plan, or the ones ticked. A renewal's and extra traffic's, time's or devices' plan is their
    service's.
  - «سرورها» — every server, or the ones ticked. A purchase's server is the one the customer picks; a renewal's and an
    extra's is their service's.
  - «حداقل مبلغ سفارش (تومان)» — the least an order must come to, after the automatic discount, for the code to hold.
  - «فقط برای خرید اول» — only a customer who has never bought a service from the shop.
  - «یادداشت» — for the shop alone (a campaign's name); the customer never sees it.
- «فعال» — a code switched off is refused to everyone.

### When a code holds

At a checkout a code holds when it is the shop's, switched on, inside its window, for that kind of order, plan and server,
for a customer who has bought nothing when it asks that, for an order of at least its least, and while it has uses left —
in all and for that customer. Otherwise the customer is told why, in their own words — «این کد تخفیف برای این پلن نیست.»,
«مهلت استفاده از این کد تخفیف تمام شده است.», «ظرفیت استفاده از این کد تخفیف تمام شده است.» and so on — and nothing is
ordered. A code is checked as it is typed and again as the customer pays.

A customer may get 20 codes wrong in an hour, in the bot and on the website together; after that every code is refused
until the hour is over — guessing codes leads nowhere.

### Uses, and giving them back

A use is an order made with the code that is not cancelled — paid or still to be paid. So a code with one use left is
taken by the first customer who orders with it: of two paying at the same moment, the other is told it is used up, and
nothing is ordered for them.

An order nobody pays gives its use back: cancelled by support, or dropped as unpaid. An order made with a code is dropped
sooner than any other unpaid order — after the hold set on «کدهای تخفیف» › «تنظیمات»:

- «مهلت (دقیقه)» — 0 to 1440, 60 by default. An unpaid order made with a code that nothing happened to for that long, and
  whose receipt is not with support, is cancelled with the note «مهلت استفاده از کد تخفیف تمام شد», and the code's use is
  free for someone else. 0 — such an order waits as long as any unpaid one (48 hours).

The check runs every ten minutes, so an order may wait up to ten minutes past its hold.

A customer coming back to the same checkout — the same plan, server and price with the same code — gets their own order
back, not a second one, and is not counted twice against the code.

## Timed sales

«فروش ویژه» lists the shop's sales, each with its percent, what it is for, its window and its state («فعال», «شروع نشده»,
«تمام‌شده», «غیرفعال»), and its switch. «ساخت فروش ویژه»:

- «نام» — what the customer sees beside the price, 64 characters at most: «فروش ویژه نوروز».
- «درصد تخفیف» — 1 to 100.
- «برای چه سفارش‌هایی» — every kind of order, or the ones ticked (a purchase, a renewal, extra traffic, extra time,
  extra devices).
- «پلن‌ها» — every plan, or the ones ticked; a renewal's and an extra's plan is their service's.
- «شروع» and «پایان» — as a code's.
- «فعال».

While a sale runs, the bot's plan list shows its plans at their new price, and a plan's details say the sale and the
price without it; the website's catalogue says it as each plan's `offer_price` and `discount`. Two sales on one plan never
add up: the larger one is taken. A sale is deleted whenever the shop likes; orders made during it keep what they were
charged.

## A customer's own discount

On a customer's page, the card «تخفیف اختصاصی» sets their own percent (0 to 100; 0 takes it away) off everything they buy
for good — a colleague, a reseller, a friend of the shop. Set it with «تعیین» or «تغییر»; who set it is logged. It is one of
the automatic discounts: a sale or their loyalty tier's discount that is larger takes its place, never adds to it.

Two accounts of one customer merged keep the larger of their two percents.

## In the bot

- **Plans** — a plan's button shows the customer's price after the automatic discount, and its details say which
  discount it is (a sale's, their own, their tier's) and the price without it. The words are bot texts the admin may
  reword or empty («فروش ویژه پلن», «تخفیف اختصاصی مشتری», «تخفیف سطح وفاداری»).
- **The checkout** — the amount after every discount, a line for each discount taken («🏷 فروش ویژه نوروز ۲۰٪: ۲۴٬۰۰۰ تومان
  تخفیف») and the amount before them. While the shop has a code switched on that has not ended, the checkout carries
  «🏷 کد تخفیف دارم»: the bot asks for the code, and the next message is it. A code that holds puts the checkout back with
  its line; one that does not says why and waits for another, «بازگشت» leading back to the checkout. With a code on,
  the button is «❌ حذف کد تخفیف».
- **Paying** — the code is checked again as a way to pay is picked. One that stopped holding meanwhile (its last use
  went to someone else, its window ended) is said so and nothing is ordered; the checkout comes back without it.

Every word is a bot text — the plan's parts in «خرید اشتراک», the checkout's in «پرداخت و رسید»
([bot texts](Bot-Texts-Reference.md)).

## On the website

The shop's website sends the code a customer typed as `discount_code` with their order, and may quote it first with
`POST /discount-codes/check`; the catalogue says each plan's price after the automatic discount (`offer_price`) and which
discount it is (`discount`). See [discount codes](Store-API-Buying.md#discount-codes) and
[discounts](Store-API-Shop.md#discounts) in the Store API's guide.

## What the shop sees of it

- **Orders** — an order's amount says what was taken off and the code under it; its dialog lists each discount. The
  payments' dialog says the same of the order paid.
- **The report group** — a purchase's, a renewal's and an extra's report carry a line «🏷 تخفیف: …» naming each
  discount taken.
- **Codes** — each code's uses on its row.

## An agent's shop

An agent's codes and sales are their shop's alone, made in their panel: they apply to their bot's customers and their
website's. What an agent's customer buys still draws its plan's traffic from the agent's pool whatever it cost the
customer — a free order too.
