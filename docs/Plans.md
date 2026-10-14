# Plans

«فروشگاه › پلن‌ها» and «فروشگاه › دسته‌بندی‌ها», in both panels: what the shop sells. The bot's «خرید اشتراک» and the
shop's website offer exactly what these lists say, in their order.

## A plan

A plan is a package a customer buys: traffic, a term — in days, or in hours for a short one —, how many devices may
connect at once, a price (and, if you like, a lower one for a customer's first purchase, and one for your agents and
colleagues) — and the servers it can be bought on. «افزودن پلن» opens its form, in four tabs:

**«مشخصات»**

- «نام پلن» — as the customer sees it in the bot, 128 characters at most. Names need not be unique.
- «دسته» — optional; plans without one are listed under «سایر پلن‌ها» ([categories](#categories)).
- «توضیحات» — optional, shown under the plan's name.
- «یادداشت پیش‌فاکتور» — optional, 500 characters at most: a few lines the bot says under the plan's checkout, a
  purchase's and a renewal's, right above the ways to pay — the apps it was tested on, a caveat. The website reads it
  too ([the shop](Store-API-Shop.md#what-it-sells)). The line it goes in, «📌 …», is a bot text you may reword.
- «دکمه در ربات» — the plan's button in «خرید اشتراک»: its colour — blue, green or red, or «پیش‌فرض» for Telegram's
  own — and its width: a row of its own, or two or three to a row. Plans of the same width that follow each other in the
  list share rows; one of another width starts a new row.
- «قابل خرید» — the switch: off, the plan is not offered; the services already sold go on.

**«قیمت و سهمیه»**

- «قیمت (تومان)» — the plan's regular price, for every purchase and renewal: whole Toman (`120000`, `۱۲۰٬۰۰۰` and
  `120,000` are all fine; a fraction is refused). `0` makes the plan free for everyone: its checkout offers one
  «🎁 دریافت رایگان» instead of the ways to pay, and the service comes at once, nothing taken (the shop's wallet settles
  it, even while it is switched off). A free first plan for newcomers alone is a first-purchase price of `0` instead;
  a free test that is no purchase at all — on each server's own terms, by the shop's quota — is a
  [test account](Test-Accounts.md).
- «قیمت خرید اول (تومان)» — optional, beside it: what a customer who has never bought a service in the shop pays for
  the plan as their first purchase ([below](#first-purchase-price)). Whole Toman, typed as the price is, and less than
  it; `0` makes the first purchase free; blank is none — everyone pays the plan's price. Renewals are at the plan's
  price.
- «قیمت نماینده (تومان)» — optional: what your agents and the customers of a «همکار» group pay for the plan, its
  purchases and renewals ([below](#agent-price)). Whole Toman, less than the price; blank is none.
- «حجم (گیگابایت)» — decimals are fine (`1.5`); `0` is unlimited — but not in an agent's shop, where a plan's traffic is
  required, since every sale draws it from the agent's pool.
- «مدت اعتبار» — a number and, beside it, its unit: «روز» for a plan of days, «ساعت» for an hourly one (a one-hour test,
  a six-hour plan); at most 3650 days — 87600 hours —; `0` never ends. The term is kept in hours, so «۱ روز» and
  «۲۴ ساعت» are the same term: a plan of whole days opens in days, any other in hours.
- «تعداد کاربر» — how many devices may connect at the same time, 0 to 1000; `0` no limit. PasarGuard panels have no
  such limit, so it is not applied on a PasarGuard server. It is where a service starts, and where every renewal on the
  plan brings it back: extra devices («کاربر اضافه») raise the service's limit for the period they are bought for only
  ([below](#devices-and-renewals)).

**«سرورها»** — where the plan is sold; at least one. Pick a server, then how:

- **«کل سرور»** — every inbound the server's page marks for sale and the panel has switched on, as they are when each
  order is delivered: an inbound marked later is part of the plan from then on.
- **«انتخاب اینباندها»** — the inbounds ticked, whatever the server's page says about them (they need only be switched
  on on the panel).

An entry is changed by removing it (✕) and adding it again. The order of the entries is the order the customer's
server buttons come in.

**«خرید اضافه»** — the prices its services buy extra traffic, time and devices at
([below](#extra-traffic-time-and-devices)).

A refused save jumps to the tab that has the problem.

### The term starts at the first connection

A new service waits for the customer's first connection, and its days — an hourly plan's hours — count from then: the
bot says «⏳ در انتظار اولین اتصال (۳۰ روز)» — «(۶ ساعت)» for a six-hour plan —, and the end date appears once the
customer connects. A customer who buys and connects a week later still gets every day — or every hour — of the term.
Less than a day before the end, the bot and the panels give its hour too, and the hours left («۵ ساعت مانده»).

### First-purchase price

«قیمت خرید اول» is a price of the plan for a customer's first purchase in the shop, below its price — a newcomer's
discount, or a free first plan. Every customer sees the plan: a newcomer at this price, everyone else at the plan's
price.

- **Who gets it** — a customer who has never bought a service in the shop. A purchase of theirs paid at some point,
  whatever came of it since (delivered, failed, refunded), counts as having bought; an order never paid, or cancelled
  unpaid, does not, and neither does a wallet top-up. Each shop counts its own customers: a customer of the main bot is
  a newcomer in an agent's bot.
- **In the bot** — such a customer is shown the plan at its first-purchase price, with a line under its details,
  «🎁 این قیمت ویژه اولین خرید شماست؛ قیمت عادی این پلن … است.» — a bot text you may reword, or empty to drop it
  ([Bot texts and keyboards](Bot-Texts-And-Keyboards.md)) —, and the checkout charges it.
- **On the website** — the catalogue gives it as `first_purchase_price`
  ([the shop](Store-API-Shop.md#first-purchase-prices)), and the website's checkout charges it the same way.
- **Renewals** — the customer's, and «تمدید خودکار» — are at the plan's regular price.
- **`0`** makes the first purchase free: its checkout offers one «🎁 دریافت رایگان» instead of the ways to pay, and
  nothing is taken.
- **Two orders at the first-purchase price** made before either was paid — say one by card waiting for its receipt
  while the wallet buys another —: the one paid first is the first purchase, and one paid after another purchase fails,
  for you to give its money back ([Orders and payments](Orders-And-Payments.md#payments)).

The orders screen marks an order made at that price «قیمت خرید اول»: under its amount in the list (and in a customer's
latest orders, on their page), beside it in the order's dialog.

### Agent price

«قیمت نماینده» is a price of the plan for the shop's resellers, below its price:

- **Who gets it** — the shop's agents (a customer with an agency level — [Agency](Agency.md)), and the customers of
  every group marked «همکار» on the users page ([Customers](Customers.md#groups)): colleagues who are no agents. An
  agent's shop marks its own groups so, for its own plans.
- **Purchases and renewals** — both, by the customer or by «تمدید خودکار». A customer who leaves the group (or a group
  whose mark is taken off) pays the plan's price from then on.
- **With a first-purchase price** — a colleague who has bought nothing yet pays the lower of the two.
- **In the bot** — they are shown the plan at that price, with a line under its details, «🤝 این قیمت ویژه نمایندگان و
  همکاران است؛ قیمت عادی این پلن … است.» (a bot text you may reword or empty). **On the website** the catalogue gives it
  as `agent_price` to them alone ([the shop](Store-API-Shop.md#agent-prices)).

The orders screen marks an order made at it «قیمت نماینده», and the report group's sale says «🤝 قیمت نماینده».

### Extra traffic, time and devices

A service's customer may buy more traffic, more days or more devices at once («کاربر اضافه») for it, each on its own,
while the shop sells them ([Bot settings](Bot-Settings.md#extra-traffic-time-and-devices)). The plan's tab «خرید اضافه»
says at what prices:

- **«قیمت‌های فروشگاه»** — the shop's, set in the bot settings: what a plan starts with.
- **«قیمت‌های این پلن»** — a price list of the plan's own, the same fields as the shop's — for each extra a price of a
  unit, the amounts offered with their prices, the least and the most a customer may type — and the same preview of
  what the bot then offers. It stands in for the shop's whole, for this plan's services alone: what it does not price,
  they do not buy, even when the shop sells it. A new one starts with nothing priced, within the shop's least and most.
  Its devices are priced for 30 days too, each service paying its share for the days left of its period; a list of a
  plan's own saved before 0.4.0 prices no devices until it is saved with some.

Switching to «قیمت‌های فروشگاه» keeps the plan's own list on the form until it is saved, so switching back finds it as it
was; saved so, the plan's own prices are gone. Refusals are said under the field they are about, on this tab. The
shop's switch rules both: while it is off, nothing is sold, and the tab says so.

### Devices and renewals

The plan's «تعداد کاربر» is the device limit of every period sold on it: each renewal brings it back — the plan's as it
is then, whatever the service had: extra devices bought, an older plan's higher limit, or none at all (a service added
by its link) —, and the customer buys extra devices again for the new period. When it comes:

- **A running service renewed by «تمدید پلن فعلی» or «ریست حجم + افزایش روز»** goes on with the period it is in, and the
  devices paid for that period last until it ends — its end date when it was renewed —: the plan's limit takes over
  then. Extra devices bought meanwhile are for that period too, priced to its end.
- **One whose period is over** — ended by time or by traffic —, **one renewed by «ریست زمان + انتقال حجم»** (its term
  starts over now), and one that never ends have the plan's limit at once.
- **One still waiting for its first connection** has the plan's limit at once, with the extra devices bought for it on
  top: they were charged for its whole term, none of which has run, and the renewal's term follows on from it — so they
  last both.

Extra days — bought, or given by support — lengthen the period in use, its devices with it. A refund of the renewal
puts the limit back as it was ([Orders and payments](Orders-And-Payments.md#refunds)).

## How a customer buys one

1. The bot lists the plans the shop can deliver now — by category, when there are categories.
2. A plan's details, then its servers by name: the customer picks one. Name servers as customers should see them: the
   server's name is the «لوکیشن» of the delivery message and of the website's catalogue. Each server's button has the
   colour and width its server's form gives it ([Servers](Servers.md)).
3. The checkout: the plan's price — a newcomer's [first-purchase price](#first-purchase-price), an agent's or a
   colleague's [agent price](#agent-price), the lower when both —, the plan's checkout note, and the ways to pay
   ([Payment methods](Payment-Methods.md)). Nothing is ordered until one is picked.
4. Paid, the service is made on that server: **one client on the panel**, on every inbound of the plan's entry for it,
   and the customer gets **one subscription link** — as a QR card, or as text.

The client on the panel is named by the shop's pattern ([Bot settings](Bot-Settings.md#service-usernames)) — by
default after the customer: their Telegram username and a number of their own (`amir_1`, `amir_2` …, counted across
every server), or `USER_7` for a customer without a username —, or as the customer asked where the shop lets them
(random, or typed). Its comment holds their
Telegram id (`123456789 | amir`; a customer of the website alone, `web#42 | USER`; an agent's customer, with `| @agent_bot`
at the end), so the panel's list leads back to the customer.

## Whether the bot shows it

A plan is offered while it is switched on and can be delivered — and the bot and the website judge that the same way,
every time a customer looks. A plan that is switched on but cannot be delivered is marked «در ربات دیده نمی‌شود» on the
list; press it to read why:

- it is on no server (a server it was on was deleted);
- none of its servers can sell now — each server's own reason is on the plan's form and on its badge: switched off,
  never checked yet, not serving subscription links, full, or with no inbound for sale;
- in an agent's shop: the agent's traffic is less than the plan's.

It comes back by itself once the cause is gone. A server that cannot sell is shown to the customer for no plan; the
plan's other servers still sell.

## The list

- **▲▼** — the order the bot and the website list plans in. A new plan goes to the end.
- **«مدت»** — the term as the bot words it: «۳۰ روز», «۶ ساعت», «۱ روز و ۶ ساعت», or «نامحدود».
- **«قیمت»** — the plan's price, and under it «خرید اول: …» when it has a first-purchase price («خرید اول: رایگان» for
  a free one) and «نماینده: …» when it has an agent price.
- **«قابل خرید»** — the switch, at once.
- **«اشتراک»** — the services running now, and how many were sold (renewals included).
- **«ساخت کپی»** — a copy of the plan, its servers and inbounds — and its own prices of the extras — too, named «… (کپی)»,
  placed right after it and **switched off**; its form opens at once, to change what differs.
- **«حذف»** — only for a plan nobody ordered: once any order names it — even an unpaid one that was cancelled — or any
  service, it is kept for the history, and switching it off is the way to stop selling it.

## Changing several prices at once

«تغییر گروهی قیمت», above the list, raises or lowers the prices of the plans you pick at once:

- **The plans** — ticked in a list, all of them at first.
- **«افزایش» or «کاهش»**, by **«درصد»** (decimals are fine; a decrease is 100% at most) or by **«مبلغ ثابت»** in Toman.
- **«گرد کردن به»** — each new price rounded to the nearest 100, 1,000 or 10,000 Toman, or not at all.
- **Which prices** — the plans' prices, their first-purchase prices, their agent prices, any of them. A plan without a
  first-purchase or agent price still has none.

«پیش‌نمایش» shows every plan's prices before and after, and what keeps a plan from the change: a price that would go
below zero, or a first-purchase or agent price no longer below the price (change both, or empty it). Nothing is saved
until «اعمال تغییر», and then every plan changes at once — or none, while one of them cannot take it. Changing a price
changes the next renewal of the services sold on the plan, as any change of price does.

## Changing a plan

A change takes effect for what is bought next — and for **renewals**: a service is renewed on its plan as the plan is at
the moment of renewal, its price, term, traffic and devices then, whether the customer renews it or it renews itself. A
plan switched off still renews the services it sold. Its prices of the extras are read the same way, when a customer
looks: a service bought on it buys extra traffic, time and devices at the plan's prices of the day.

The traffic, days and inbounds of a purchase are read when the order is delivered: a card payment is delivered when
support approves the receipt. If the plan's server stopped selling meanwhile, the delivery fails and the order waits
under «نیازمند رسیدگی» ([Orders and payments](Orders-And-Payments.md#orders)) for a retry once it can sell again.

## Categories

«دسته‌بندی‌ها»: a name (64 characters at most, unique in the shop), a switch and an order. While any category is switched
on, the bot's «خرید اشتراک» shows the categories first — every one switched on, in this order, an empty one too, which
says it has nothing for sale now —, then «سایر پلن‌ها» for the plans of no category or of one switched off, when there
are any. Without a category switched on, the bot lists the plans at once. The shop's website groups its catalogue the
same way ([the shop](Store-API-Shop.md#what-it-sells)).

Deleting a category is always possible: its plans stay, without a category.

## Watch out for

- **A plan with history cannot be deleted.** Picking a card at the checkout already makes an order: test with a copy of
  a plan, not with the real one, if you mean to delete it later.
- **An agent's shop** sees every server of the installation in the form, but only the owner can mark inbounds for sale
  or fix a server; the agent asks support.
- **A pinned inbound switched off on its panel** makes the plan's form refuse to save: remove that entry and add it
  again.
- **Changing a price** changes the next renewal of every service sold on the plan — the first-purchase price changes
  none, renewals being at the plan's price (or at its agent price, for an agent's or a colleague's service).
