# Agency

An agent is a reseller with **a bot of their own** — its own customers, plans, payment methods, texts, menu, settings,
report group and website, built on the shop's servers —, who buys the traffic their bot sells from the main bot at their
level's price per GB. An agent is a customer of the main bot; their wallet with the shop may go below zero by the credit
you give them. «فروش › نمایندگان» is the owner's: «درخواست‌ها», «نماینده‌ها», «سطح‌ها» and «تنظیمات».

## Setting it up

1. **«سطح‌ها»** › «افزودن سطح»: a level is a name the customers and agents read («برنزی», 32 characters at most, unique)
   and **a price per GB** in whole Toman. ▲▼ is the order the bot lists them in. A level agents are on is not deleted:
   move them to another first.
2. **«تنظیمات»** — «برنامه نمایندگی»:

   | Field | What it is |
   |---|---|
   | «نمایندگی فعال باشد» | Off by default. Off, no new request is taken and the menu's «نمایندگی» is hidden from customers; agents keep their account and their bots run |
   | «اعتبار اولیه نماینده (تومان)» | How far below zero an agent's wallet may go, given by an approval from the report group (the panel asks it per approval); 0 by default |
   | «کمترین خرید حجم (گیگابایت)» | The least an agent may buy, 10 by default |
   | «حجم‌های پیشنهادی (گیگابایت)» | The amounts offered as buttons, apart by commas: 50، 100، 200، 500 by default — 8 at most, none below the least |
   | «مدت نمایندگی (روز)» | How long an agency approved now lasts, from its approval — its bot's selling stops then ([below](#the-terms-of-selling-an-end-and-a-monthly-minimum)); 0 (the default) for no end |
   | «کف خرید ماهانه (تومان)» | The traffic, in Toman, an agent approved now buys each Jalali month at least; 0 (the default) for none |
   | «هشدار پیش از توقف (روز)» | How many days before an agency's end, or before a month ends with its minimum unmet, the agent is warned; 3 by default |

   The term and the minimum are an approval's starting point — the panel's approval asks both, the report group's takes
   them as they are —; each agent's are changed on their own row afterwards.

3. The main bot's menu needs its **«🤝 نمایندگی»** button — the page warns while it is missing, with a link to «کیبوردها».

## Becoming an agent

A customer presses «🤝 نمایندگی» in the main bot: the terms and each level's price per GB, and «📝 درخواست نمایندگی»,
which asks a few words about them (500 characters) — one request at a time. It arrives in two places:

- **«درخواست‌ها»** (it opens on those waiting): «تایید» asks the level, the credit, the agency's end and the monthly
  minimum (the program's by default) and approves — «تایید و نماینده کردن» —; «رد» asks an optional note the customer
  reads.
- **The main bot's report group, «نمایندگی»**: «✅ تایید» offers a button per level and approves with the program's
  credit; «❌ رد» rejects without a note. For the bot's admins alone.

Whichever decides first stands; the other says it was decided. The customer is told — approved, with their level, price
and credit, and their agency's end and monthly minimum when they have them. **Approval opens their shop**: their bot's
place, with its wallet as a way to pay. A returning agent starts their terms of selling afresh, a suspension of before
gone.

## The agent's side

In the main bot, «🤝 نمایندگی» is then their account — level and price per GB, the traffic their bot may still sell,
their wallet and credit, their bot and what it sold, their agency's end, this month's traffic bought against their
minimum, and why their bot sells nothing when it does not — with:

- **«💾 خرید حجم»** — a preset or a typed amount (from the least), paid through the checkout at their level's price; the
  wallet counts their credit. The traffic is added as it is paid (a card's, once you approve its receipt).
- **«🤖 ربات من»** — the token of a bot they made in @BotFather, sent here; the message is deleted at once. A new token of
  the same bot replaces it; another bot is refused (its customers know the first) — moving an agent to another bot is
  not done from the panel.
- **«🔐 ورود به پنل»** — a link to their panel, good once and for ten minutes; its code never reaches a server's logs. A
  new link replaces the last.
- **«➕ افزایش موجودی»**.

Their panel ([Panels](Panels.md#an-agents-account)) is their shop: plans, payment methods, orders, customers, texts,
settings, website — and their account, with the traffic's history and their terms of selling («شرایط فروش»). They
cannot buy traffic there: it is the main bot's. While their bot's selling is suspended, or its end or a short month is
near, their account and their dashboard's «نیازمند توجه» say so, and why — and a strip over every other page of their
panel leads to the account.

## Their traffic

Every purchase and renewal their bot delivers draws its plan's traffic from their pool, once per order; a failed delivery
gives it back, and so does a purchase, a renewal or extra traffic refunded — the refund takes what it gave back off
the service. **A pool too short fails the order**: it waits for a retry («نیازمند رسیدگی» in their shop), and the
agent is told in the main bot, with «💾 خرید حجم». The GB their shop gives a service by hand («افزایش زمان و حجم») comes
out of it too, and so does the extra traffic their customers buy («حجم اضافه»), which is never offered beyond
what the pool holds; the extra days cost it nothing. So in their shop a plan needs traffic (unlimited plans are not sold
there), and a plan their pool cannot cover is not offered — an order made by hand («افزودن سفارش») is refused up front,
in the words a failed delivery gets —; their dashboard says when their traffic sells nothing.

**Free tests** their bot gives draw their traffic too, as each is made — a «فروش» line «اکانت تست · سرویس …» in their
traffic's history, written with the test itself, so a test that falls through draws nothing —, and a server whose test
their pool cannot cover is not offered for one. The terms are each server's, yours; the switch, the quota and the
clean-up are theirs ([Test accounts](Test-Accounts.md#agents-shops)).

Your own gifts — a server's, the mass gift — draw nothing from it.

## The terms of selling: an end and a monthly minimum

Each agent may have **an end** («پایان نمایندگی», a day and an hour on the shop's clock; none: never) and **a monthly
minimum** («کف خرید ماهانه»: the Toman of traffic they buy in the main bot each Jalali month — traffic orders delivered,
a refunded one not counted; 0: none, as in the program's setting). Past either, **their bot's selling is suspended — never the bot**: their
shop sells nothing new — no purchase, renewal (automatic renewal waits too), extra traffic, time or devices, top-up,
free test, gift of a service from the wheel, a gift code or a referral reward, and their staff extend no service by
hand nor make an order for a customer by hand (you neither) —, its customers told politely in the bot and on the
website («فروش این فروشگاه فعلا متوقف است…»: the website's API answers an order with a `503`, and a renewal's preview or
a discount code's check with a `422` on `status` — [buying](Store-API-Buying.md#the-flow)). Everything else goes on:
the bot answers, their panel works, their customers' services keep running, syncing and being reminded, a receipt for an
order made before is still reviewed, and what was paid is delivered. **They still buy traffic** in the main bot — that is
how a minimum is met.

- **The end** — warned of once, «هشدار پیش از توقف» days ahead, in the main bot. The moment it passes their shop stops
  selling; within the hour they hear it, and so does your report group («نمایندگی»). A later end (or none) given on their
  row lifts it at once.
- **The minimum** — judged once a Jalali month on the shop's clock is over; the month in which you set or changed it is
  not judged. They are warned once when the month nears its end short of it, with «💾 خرید حجم». A month that ended below
  it suspends their selling — and **as soon as the month they are in reaches the minimum** (their traffic purchase
  delivered), it sells again by itself. Setting the minimum to 0 lifts it too.
- **By hand** — «توقف فروش» on their row, with an optional note they read; only «ادامه فروش» lifts it. It stands over an
  end or a minimum, which «ادامه فروش» also lifts — but an end that has passed, which you extend instead.

The agent hears every suspension and every lifting in the main bot, with the reason; your group hears them under
«نمایندگی».

## Servers and plans kept from an agent's shop

«سرورها و پلن‌های پنهان» on an agent's row ticks:

- **Servers** — their plans are not sold on them (a plan on no other server is not offered at all), their free tests are
  not given there, they move or make no service there by hand, and the servers are none of their plan form's, their
  discount codes' or their website's locations. What they already have there keeps working: those services are renewed
  and take extras, and an order paid before is delivered there, since nothing new is sold. Your own moves still carry
  their services onto it: a server's [«انتقال سرویس‌ها»](Servers.md#moving-its-services), and one service's «انتقال»
  from their shop opened in your panel.
- **Their plans** — not sold, made by hand nor given as a prize, whatever their own switch says. The agent sees them marked «پنهان‌شده توسط مدیر» on their
  plans page and cannot show them again; a copy of one stays hidden. Services sold on one renew.

Your own shop and other agents' are untouched.

## The agents list

«نماینده‌ها»: each agent with their bot («@bot» or «بدون توکن»; «فعال», «وصل نشده», «مشکل دارد» or «غیرفعال», the
traffic left, how many servers and plans you keep from it, and «فروش متوقف», «پایان: …» or «کف ماهانه نرسیده» when
their selling is suspended or near it — press it for why), their level and price, their wallet («… تومان بدهی» in red,
and their credit) and what their bot sold. Filter by level; sort by traffic, balance or sales. The name leads to their
customer page while the main shop is open. The row's menu:

- **«سطح، اعتبار و شرایط فروش»** — another level or credit, end or monthly minimum (this month's purchases shown above
  them); the agent is told (the toast says whether it reached them) — saved unchanged, nothing is sent.
- **«حجم»** — set their traffic right: «افزایش» or «کاهش» in GB, with an optional note they read in their traffic's
  history; never below zero. **The agent is not told.**
- **«سرورها و پلن‌های پنهان»** — [above](#servers-and-plans-kept-from-an-agents-shop).
- **«توقف فروش»** / **«ادامه فروش»** — their selling suspended by hand, with a note they read, or let go
  ([above](#the-terms-of-selling-an-end-and-a-monthly-minimum)).
- **«باز کردن فروشگاه»** — opens their shop in your panel (`/admin/s/<bot id>/`; Ctrl-click for a tab of its own): their
  plans, orders, customers and settings, as they see them — and your decisions there read «پشتیبانی» to them. Its
  dashboard's «نیازمند توجه» says their selling stopped, or its end or a short month near, in this list's words, with
  a link back to «نمایندگان».
- **«لغو نمایندگی»** — asks first, with an optional note. Their level and credit go (a debt stays owed), their bot stops,
  their panel signs out and their website's API closes; their shop and traffic are kept. They are told.

**Giving an agency back**: switch the program on; the customer asks again («🤝 نمایندگی»), and an approval brings their
shop and bot back as they were.

## What an agent's shop has not

No «نمایندگی» of its own — no menu button, texts or report topic —, no «نماینده‌ها» broadcast audience, no servers (they
sell on yours; their «خطاها» topic hears of their own failed deliveries, not of servers), and none of the installation's
settings.

## Watch out for

- **An agency ended leaves its customers' services running** on your servers: move or delete them from that shop, if
  they must go.
- **An agent's sales draw their traffic at delivery**: approving their customers' receipts is theirs, but a delivery
  their pool cannot cover waits for them to buy more.
- **A suspended agent's services still end**: their customers cannot renew in the bot or on the website, and automatic
  renewal waits — a service that reaches its end meanwhile ends; its customer is reminded of the end as any service is
  («تمدید خودکار» on or not). Opening their shop in your panel, you may still extend one by hand.
- **Ending an agency is not suspending it**: «لغو نمایندگی» stops the bot, the panel and the website; «توقف فروش» stops
  only what is sold.
