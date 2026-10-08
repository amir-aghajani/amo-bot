# Plans

«فروشگاه › پلن‌ها» and «فروشگاه › دسته‌بندی‌ها», in both panels: what the shop sells. The bot's «خرید اشتراک» and the
shop's website offer exactly what these lists say, in their order.

## A plan

A plan is a package a customer buys: traffic, a term in days, how many devices may connect at once, a price — and the
servers it can be bought on. «افزودن پلن» opens its form, in three tabs:

**«مشخصات»**

- «نام پلن» — as the customer sees it in the bot, 128 characters at most. Names need not be unique.
- «دسته» — optional; plans without one are listed under «سایر پلن‌ها» ([categories](#categories)).
- «توضیحات» — optional, shown under the plan's name.
- «قابل خرید» — the switch: off, the plan is not offered; the services already sold go on.

**«قیمت و سهمیه»**

- «قیمت (تومان)» — whole Toman (`120000`, `۱۲۰٬۰۰۰` and `120,000` are all fine; a fraction is refused). `0` is a price of
  nothing, not a way around the checkout: the customer still picks a way to pay.
- «حجم (گیگابایت)» — decimals are fine (`1.5`); `0` is unlimited — but not in an agent's shop, where a plan's traffic is
  required, since every sale draws it from the agent's pool.
- «مدت اعتبار (روز)» — 0 to 3650; `0` never ends.
- «تعداد دستگاه» — how many devices may connect at the same time, 0 to 1000; `0` no limit. PasarGuard panels have no
  such limit, so it is not applied on a PasarGuard server.

**«سرورها»** — where the plan is sold; at least one. Pick a server, then how:

- **«کل سرور»** — every inbound the server's page marks for sale and the panel has switched on, as they are when each
  order is delivered: an inbound marked later is part of the plan from then on.
- **«انتخاب اینباندها»** — the inbounds ticked, whatever the server's page says about them (they need only be switched
  on on the panel).

An entry is changed by removing it (✕) and adding it again. The order of the entries is the order the customer's
server buttons come in. A refused save jumps to the tab that has the problem.

### The term starts at the first connection

A new service waits for the customer's first connection, and its days count from then: the bot says «⏳ در انتظار اولین
اتصال», and the end date appears once the customer connects. A customer who buys and connects a week later still gets
every day of the term.

## How a customer buys one

1. The bot lists the plans the shop can deliver now — by category, when there are categories.
2. A plan's details, then its servers by name: the customer picks one. Name servers as customers should see them: the
   server's name is the «لوکیشن» of the delivery message and of the website's catalogue.
3. The checkout: the ways to pay ([Payment methods](Payment-Methods.md)). Nothing is ordered until one is picked.
4. Paid, the service is made on that server: **one client on the panel**, on every inbound of the plan's entry for it,
   and the customer gets **one subscription link** — as a QR card, or as text.

The client on the panel is named after the customer: their Telegram username and a number of their own (`amir_1`,
`amir_2` …, counted across every server), or `USER_7` for a customer without a username. Its comment holds their
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
- **«قابل خرید»** — the switch, at once.
- **«اشتراک»** — the services running now, and how many were sold (renewals included).
- **«ساخت کپی»** — a copy of the plan, its servers and inbounds too, named «… (کپی)», placed right after it and
  **switched off**; its form opens at once, to change what differs.
- **«حذف»** — only for a plan nobody ordered: once any order names it — even an unpaid one that was cancelled — or any
  service, it is kept for the history, and switching it off is the way to stop selling it.

## Changing a plan

A change takes effect for what is bought next — and for **renewals**: a service is renewed on its plan as the plan is at
the moment of renewal, its price, days, traffic and devices then, whether the customer renews it or it renews itself. A
plan switched off still renews the services it sold.

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
- **Changing a price** changes the next renewal of every service sold on the plan.
