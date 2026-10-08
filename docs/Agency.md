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

3. The main bot's menu needs its **«🤝 نمایندگی»** button — the page warns while it is missing, with a link to «کیبوردها».

## Becoming an agent

A customer presses «🤝 نمایندگی» in the main bot: the terms and each level's price per GB, and «📝 درخواست نمایندگی»,
which asks a few words about them (500 characters) — one request at a time. It arrives in two places:

- **«درخواست‌ها»** (it opens on those waiting): «تایید» asks the level and the credit (the program's by default) and
  approves — «تایید و نماینده کردن» —; «رد» asks an optional note the customer reads.
- **The main bot's report group, «نمایندگی»**: «✅ تایید» offers a button per level and approves with the program's
  credit; «❌ رد» rejects without a note. For the bot's admins alone.

Whichever decides first stands; the other says it was decided. The customer is told — approved, with their level, price
and credit. **Approval opens their shop**: their bot's place, with its wallet as a way to pay.

## The agent's side

In the main bot, «🤝 نمایندگی» is then their account — level and price per GB, the traffic their bot may still sell,
their wallet and credit, their bot and what it sold — with:

- **«💾 خرید حجم»** — a preset or a typed amount (from the least), paid through the checkout at their level's price; the
  wallet counts their credit. The traffic is added as it is paid (a card's, once you approve its receipt).
- **«🤖 ربات من»** — the token of a bot they made in @BotFather, sent here; the message is deleted at once. A new token of
  the same bot replaces it; another bot is refused (its customers know the first) — moving an agent to another bot is
  not done from the panel.
- **«🔐 ورود به پنل»** — a link to their panel, good once and for ten minutes; its code never reaches a server's logs. A
  new link replaces the last.
- **«➕ افزایش موجودی»**.

Their panel ([Panels](Panels.md#an-agents-account)) is their shop: plans, payment methods, orders, customers, texts,
settings, website — and their account, with the traffic's history. They cannot buy traffic there: it is the main bot's.

## Their traffic

Every purchase and renewal their bot delivers draws its plan's traffic from their pool, once per order; a failed delivery
gives it back. **A pool too short fails the order**: it waits for a retry («نیازمند رسیدگی» in their shop), and the
agent is told in the main bot, with «💾 خرید حجم». The GB their shop gives a service by hand («افزایش زمان و حجم») comes
out of it too. So in their shop a plan needs traffic (unlimited plans are not sold there), and a plan their pool cannot
cover is not offered; their dashboard says when their traffic sells nothing.

Your own gifts — a server's, the mass gift — draw nothing from it.

## The agents list

«نماینده‌ها»: each agent with their bot («@bot» or «بدون توکن»; «فعال», «وصل نشده», «مشکل دارد» or «غیرفعال», and the
traffic left), their level and price, their wallet («… تومان بدهی» in red, and their credit) and what their bot sold.
Filter by level; sort by traffic, balance or sales. The name leads to their customer page while the main shop is open.
The row's menu:

- **«سطح و اعتبار»** — another level or credit; the agent is told (the toast says whether it reached them) — saved
  unchanged, nothing is sent.
- **«حجم»** — set their traffic right: «افزایش» or «کاهش» in GB, with an optional note they read in their traffic's
  history; never below zero. **The agent is not told.**
- **«باز کردن فروشگاه»** — opens their shop in your panel (`/admin/s/<bot id>/`; Ctrl-click for a tab of its own): their
  plans, orders, customers and settings, as they see them — and your decisions there read «پشتیبانی» to them.
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
