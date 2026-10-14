# Loyalty tiers, cashback and the nightly draw

Three ways a shop rewards the customers who buy from it: loyalty tiers («سطوح وفاداری») — a tier by how much a customer
has spent, each with a discount and an extra cashback —, cashback («کش‌بک») — a share of what a delivered order paid, back
in the customer's wallet —, and the nightly draw («قرعه‌کشی شبانه») — every night, a prize for a few of the day's buyers.
All three are under «تخفیف و جوایز» in either panel, and every shop runs its own: the owner's, and each agent's in their
bot.

## Loyalty tiers

### What a customer has spent

A customer's spending is what their purchases, renewals and extra traffic, time and devices came to — paid, and not given back:
an order being delivered, delivered, or waiting for support's retry counts; one refunded, cancelled or never paid does
not, and neither does a wallet top-up, an agent's traffic, or a service given free (a prize, a gift code's).

### The tiers

«سطوح وفاداری» lists the shop's tiers by their threshold, the lowest first, each with its discount, its extra cashback and
how many customers are on it now. «افزودن سطح»:

- «نام سطح» — what the customer sees in the bot's wallet and on the website, 32 characters at most, one name a shop;
- «حد خرید (تومان)» — what a customer must have spent to be on it, in whole Toman; two tiers never share one. 0 — every
  customer, from the start (customers who have bought nothing are counted on it too);
- «درصد تخفیف» — 0 to 100, taken off what the customer buys;
- «درصد کش‌بک اضافه» — 0 to 100, added to the cashback rules' percent.

A customer is on the highest tier whose threshold they reached. A shop with no tier has no loyalty program. Deleting a
tier moves its customers down to the tier below it (or none); nothing they bought changes.

### The discount

A tier's discount is one of the automatic discounts at every checkout, the bot's and the website's: of a sale («فروش
ویژه»), the customer's own discount and their tier's, only the largest applies — never added together — and a discount
code, when one is used, applies after it.

### What the customer sees

- The bot's «کیف پول» shows the tier under the balance — «🏅 سطح شما: طلایی — ۲۵۰٬۰۰۰ تومان تا سطح الماس» —, or, below the
  first tier, what is left to reach it. Both lines are bot texts («سطح وفاداری در کیف پول», «تا سطح بعدی», «هنوز بدون
  سطح») the admin may reword or empty.
- When a delivery moves them up to a higher tier they are told once — «رسیدن به سطح بالاتر» — with the tier's perks a line
  each. The tier is the one their delivered orders reach, counted in the order the deliveries were made: a second
  delivery of the same moment never tells it again.
- The website's `GET /me` says it as `loyalty` — `{tier: {id, name, discount_percent, cashback_percent} | null, spent,
  next: {name, min_spent, missing} | null}` —; PATCH /me answers the same.
- Support sees it on the customer's page: the card «سطح وفاداری» (the tier and its perks, what they spent, the next tier).

## Cashback

### Switching it on

The «کش‌بک» page opens with its switch, «کش‌بک فعال باشد» — off until switched on. Off, no order earns cashback, a tier's
extra cashback neither.

### The rules

Each rule is:

- «درصد کش‌بک» — 1 to 100;
- «نوع سفارش» — the kinds of order it is for: «خرید», «تمدید», «حجم اضافه», «زمان اضافه», «کاربر اضافه» (one at least;
  purchases and renewals ticked to start with). A wallet top-up and an agent's traffic never earn cashback;
- «گروه مشتری» — one of the shop's customer groups, or «همه مشتری‌ها»;
- «روش پرداخت» — the way the order was paid (the wallet is one), or «همه روش‌ها»;
- its switch, in the list.

### What an order earns

When an order of a kind cashback covers is delivered — however it was paid, and when support's retry delivers it later
too —, it earns the highest percent of the rules switched on that match it, plus the customer's tier's cashback percent,
100 at most: that percent of what the order paid, rounded down to a Toman. An order at no price earns nothing.

The cashback is credited to the customer's wallet right after the delivery — a ledger line «کش‌بک سفارش #12» — and they
are told («کش‌بک»). An order earns it once, whatever asks again.

### A refund

An order given back after it earned cashback takes the cashback back with it: the refund gives the customer what they
paid less the cashback (its wallet line says «(منهای کش‌بک)», and the refund's message says so — «کسر کش‌بک از مبلغ
برگشتی»). The wallet never goes below what it was before the refund.

## The nightly draw

### Its rules

The «قرعه‌کشی» page opens with the card «قرعه‌کشی شبانه» — off until switched on:

- «ساعت قرعه‌کشی» — the hour of the shop's day (its time zone, `APP_TIMEZONE`), 22:00 by default;
- «تعداد برنده‌ها» — 1 to 20, a customer once;
- «جایزه هر برنده» — «شارژ کیف پول» (Toman, 1,000 or more), «حجم» (GB, to their newest running service that takes it,
  never a free test), «زمان» (days, the same), or «سرویس رایگان» (a plan's service given free, sent to them as a bought
  one);
- «شانس شرکت‌کننده‌ها» — «هر خرید یک شانس» (every paid order an entry) or «هر خریدار یک شانس» (every buyer once);
- «نماینده‌ها شرکت داده نشوند» — on by default, the main shop only.

### Each night

Once the shop's clock has passed the hour, the day's draw is made — once a day, however many runs come (the scheduler
looks every 10 minutes, so a draw comes within minutes of its hour). Its entries are the orders paid in the 24 hours
before the hour — the purchases, renewals and extras paid and not given back, at a price —; the bot's admins and banned
customers never take part, and agents only while the settings let them. Winners are drawn by their entries, each given
the prize («جایزه قرعه‌کشی شبانه» on a wallet's line) and told («برنده قرعه‌کشی شبانه»). A winner who cannot be given it
— no running service takes the traffic or the days — is passed over, and the next one drawn wins. The shop's report group
hears of every draw with entries under «خریدها»: the day, the entries, the prize and the winners. A night without entries
is kept as a draw with none, and nobody is told.

The page lists every draw, the newest first, with its entries, its prize and its winners (each the way to their page and
to the service a prize went to).

## Settings, for developers

| Bot settings group | Fields |
| --- | --- |
| `cashback` | `cashback_enabled` |
| `lottery` | `lottery_enabled`, `lottery_hour`, `lottery_winners`, `lottery_prize`, `lottery_wallet`, `lottery_traffic_gb`, `lottery_days`, `lottery_plan_id`, `lottery_entries`, `lottery_exclude_agents` |

The tiers are `/api/{panel}/loyalty/tiers`, the cashback rules `/api/{panel}/cashback/rules` and the draws
`/api/{panel}/lottery/draws` — both panels', never the website's admins' ([Panels' API reference](Panels-API-Reference.md)).
