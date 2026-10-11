# Internals: commerce

Plans and prices, discounts and rewards, orders, the checkout, payments and settlement, the wallet, referrals, the agency
and the customers themselves — `app/Modules/{Catalog,Orders,Payments,Discounts,Rewards,Referrals,Agency,Users}`. This
page is how they fit together and why; what an owner sees of each is on its own page ([Plans](Plans.md),
[Orders and payments](Orders-And-Payments.md), [Discounts](Discounts.md), [Referrals](Referrals.md), …).

## The modules

| Module | What it owns | Open first |
| --- | --- | --- |
| Catalog | plans, categories, what can be sold right now | `PlanService`, `ServerSelector`, `PlanCategoryService::catalogue()` |
| Orders | the order lifecycle, the one pricing, request keys | `OrderService`, `Pricing`, `DTO\Quote` |
| Payments | payment methods and gateway drivers, settlement, the checkout | `Checkout`, `PaymentService`, `PaymentActions` |
| Discounts | codes, sales, a customer's own percent, loyalty tiers | `DiscountCodes`, `Discounts`, `Loyalty` |
| Rewards | giving a prize, gift codes, the lucky wheel, cashback, the nightly draw | `Rewards::give()`, `Cashback`, `LuckyWheel` |
| Referrals | invite codes, commissions, start gift, plan reward, fake-referral alerts | `ReferralService`, `ReferralGifts` |
| Agency | agent levels and requests, agents' bots, their prepaid traffic | `AgencyActions`, `TrafficPool`, `AgentBots` |
| Users | customers, the wallet ledger, groups, uploaded pictures | `Customers`, `WalletService`, `UserActions` |

Every row of these modules belongs to one shop (`BelongsToBot`: the main bot's or an agent's), so the same services
serve every shop; servers, agency levels and the agents' traffic ledger are the installation's. Which module may name
which is an exact map in `tests/Unit/Core/LayersTest`. Two choices worth knowing:

- **The checkout lives in Payments, not Orders.** Paying is the wallet's shortfall and a gateway's next step, over
  `PaymentService`, which hands paid orders to `OrderService`. Orders calls no Payments service.
- **Loyalty lives in Discounts.** A tier's discount is one of the automatic discounts; its extra cashback is read by
  `Rewards\Services\Cashback`. Rewards depends on Discounts, never the other way.

## The purchase path

```
catalogue ── PlanCategoryService::catalogue() → ServerSelector::offers()     what can be sold today
price ────── Pricing::purchase() → Quote                                    base, automatic discount, code
checkout ─── Checkout::pay()                                                one for the bot and the website
  order ──── OrderService::openPurchase() → open()                          find or make, under the user's row lock
  payment ── PaymentService::createForOrder()
               wallet: settle() in one transaction (debit, payment → paid, order → paid), then settled()
               card:   a pending payment; the receipt later → approve() → the same settle()
settled() ── ReferralService::reward() · OrderService::deliver() · ReferralService::purchased()
deliver() ── claim (paid → processing) → ProvisioningService → fulfilled() in the same transaction
               then OrderRewards::delivered(): cashback and loyalty, after the commit
```

The bot (`Telegram\Handlers\CheckoutScreen`) and the website (`Store\Services\CustomerCheckout`) only render the
`CheckoutResult`; neither has rules of its own. Renewals, top-ups, an agent's traffic and extras take the same road
through their own `open*()`.

## Pricing

`Orders\Services\Pricing` is the only place a price is worked out — the bot, the website and automatic renewal all ask it:

1. **The base** — the plan's price for this customer (`Buyer`): its price, its first-purchase price while they have bought
   nothing, its agent price for an agent or a «همکار» group, whichever is lowest; an extra's price as offered.
2. **The automatic discount** — the largest of a running sale, the customer's own percent and their tier's
   (`AutomaticDiscounts::best()`). Never added together — the owner's decision: one automatic discount, then a code.
3. **The code** — applied to what step 2 left (`DiscountCodes::apply()`).

Whole Toman, never below zero; an order at zero is "free" and the shop's wallet row settles it whatever method was
picked. Wallet top-ups and agents' traffic are never discounted (`OrderType::FOR_THEMSELVES` lists the kinds a discount,
loyalty, cashback and the draw care about). The resulting `Quote` is written onto the order (`pricing`, `discounts`,
`discount_amount`, `discount_code_id`), so an order keeps the price it was made at whatever changes later.

**"Has bought" has two readings, each in one place on `Order`.** `boughtBy()` — a purchase ever paid, a free plan
included, never a service given free — decides first-purchase prices and first-purchase codes (the owner's earlier
decision). `paidPurchases()` — the same, but with a price above zero — is what money-like rewards read: a referral's
first purchase for the start gift, the plan reward's buyers, the «nobody buys» alert and the wheel's buyers-only rule,
since a free trial must not unlock them. A first purchase is decided at delivery too
(`OrderService::deliverPurchase()`): of two ordered at the first-purchase price before either was paid, the one paid
second fails for support to refund.

**Discount code uses.** A use is an order made with the code that is not cancelled, so an unpaid order gives its use
back when it expires — sooner for coded orders (`DiscountSettings::holdMinutes()`, `ExpireOrdersTask`). A quote counts
uses leaving out the customer's own pending orders (their checkout may be reopening one); the order that takes the use
counts again under the code's row lock (`DiscountCodes::reserve()`, inside `OrderService::open()`), so the last use goes
to one customer. Wrong codes are rate-limited per customer (`DiscountCodes::tried()`). Codes and sales share
`Models\Concerns\DiscountScope` (kinds, plans, window) and `Services\DiscountForm` (their forms' common fields).

## Orders and the checkout

`OrderService::open()` finds the customer's payable order of the same shape at the same price and code, or makes one,
under a lock on the customer's row — two taps make one order. The website sends an `Idempotency-Key` with every ordering
request: the order a key came to answers the same request made again, whatever happened to it since (paid, delivered,
cancelled, a new price), and the key remembers its payment method so a repeat is never paid another way. Two requests
with one key at once are settled by the `request_keys` unique row and the order's compare-and-swap; the loser describes
the winner's result (`Checkout::standing()`). A keyed request makes no new order while the customer has
`OrderService::UNPAID_MAX` unpaid ones (each may hold a receipt picture on the host).

Statuses (`OrderStatus`): pending → paid → processing → fulfilled | failed, or cancelled unpaid, or refunded. The
reading lists live on `Order`: `SOLD` (a sale), `KEPT` (paid and not given back — a sale or a failed delivery), and the
scopes `bought()`, `spending()` (what loyalty and the draw count), `payable()`, `stale()`, `stuck()` (support's queue).

**Every move is a compare-and-swap** (`Core\Database\Transitions`): one payment pays an order; only the process that
claims it (paid → processing) delivers it, so support's approval racing the auto-approve timer, or two retries, never
deliver twice. The order is marked fulfilled in the same transaction that writes what it delivered, and outcomes are
reported to the group in that transaction. A claim untouched for `STALE_PROCESSING_MINUTES` belongs to a dead process:
`ResumeDeliveriesTask` finishes it as a retry would — never a failed delivery, which waits for support. Unpaid orders
expire after `ExpireOrdersTask::EXPIRE_HOURS`, never while a receipt waits for review.

A delivery that a panel or a rule refuses fails the order with two notes: `notes`, safe for anyone reading the shop (an
agent, the website's admins), and `diagnosis`, the owner's detail of the panel (its address, its answer).
`OrderDirectory::notes()` picks by who reads.

## Payments

A payment method is a row: a gateway driver (`Payments\Drivers\*`, registered in `payment.drivers`) with its settings
and a label. Two drivers ship: the wallet (built in, one row per shop, settles at once) and card-to-card (a manual
gateway: shows a card, waits for a receipt). Adding a gateway is a driver class, its registration and its request shapes —
see [Drivers](Drivers.md#a-payment-gateway).

`PaymentService` moves payments by compare-and-swap and reports each move in the same transaction. Settling (`settle()`)
is the gateway's part (the wallet's debit), the payment's move and the order's claim in one transaction — losing either
compare-and-swap throws and rolls the debit back. The delivery runs after the commit, since it talks to a panel. A
receipt sent in the bot may be accepted by its method's review window (`AutoApproveReceiptsTask`); one uploaded from the
website always waits for support, because its account may be a throwaway email. Refunds go by what the order bought:
a service's money back to the wallet (less any cashback, the service stays), an agent's traffic out of their pool, a
top-up back out of the wallet — refused once spent or sold.

`PaymentActions` and `OrderActions` are support's decisions, wherever they are made (both panels, the website's admin
API, the report group's buttons, the timer). Each checks its rule (`allowed()`), who decides (an `Auth\Actor`: nobody
approves or refunds their own payment; the website's admins approve only a receipt in review), and tells the customer
only when this call decided — the loser of two decisions at once is refused with `DECIDED_MEANWHILE`.

## Ledgers

The wallet (`WalletService`) and an agent's traffic (`TrafficPool`) are append-only ledgers written through
`Core\Database\Ledger`: the owner's row is locked, the last line read under the lock, and each line carries the balance
after it — the last line *is* the balance (`User::balance()`, `Bot::trafficBalance()`), with no copy to drift. A debit
may go below zero only as far as the caller allows (an agent's credit). Lists read balances with their rows
(`User::balanceColumn()`), never a query per row.

## Rewards

`Rewards::give(user, Reward, reason)` is how every prize or gift reaches a customer — Toman as a wallet line, traffic
or days granted on their newest running service that takes them (from the agent's pool in an agent's shop), or a plan as
a free service: a purchase order at no price, `pricing` gift (so never their first purchase), settled by the wallet row.
`Eligibility` answers "can this reach them now?" before anything is spent (a wheel slice, a gift code).

- **Gift codes** — every try is counted before it is judged and given back when the code exists, so only wrong codes
  spend the budget; a code is taken by one conditional update.
- **The lucky wheel** — cooldown, eligible slices and the cost are checked and paid in one transaction under the
  customer's row lock; the prize is given after the commit, and a failure refunds the cost and leaves no cooldown.
- **Cashback** — earned once per order (`cashbacks.order_id` unique) after the delivery's commit
  (`OrderRewards::delivered()`), so the wallet's lock never holds a delivery up and a failure there never undoes it. A
  refund takes it back by giving that much less.
- **The nightly draw** — the day's row is inserted first (unique per shop and day), so two runs never draw twice; entries
  are the 24 hours before the scheduled hour, not before the run, so a late run leaves no gap.

## Referrals

Only a customer the shop has never seen becomes a referral (`ReferralService::attribute()`, called by
`Customers::register()` for a newcomer) — a known customer opening a link changes nothing. A commission is earned only on
money that came in (`Payment::moneyIn()`): once per payment by a unique row, the `first_only` rule decided under a lock
on the customer, and a refund leaves it standing. `referrer_id` is kept as earned, so an account merge never moves past
earnings.

`ReferralGifts` gives the start gift (on joining or on the first paid purchase) and the plan reward (each milestone of
referrals who bought, claimed by a unique row before giving), both through `Rewards`; "bought" is `paidPurchases()`.
While a fake-referral alert stands (`ReferralAlerts`: a burst of joins, or many referrals none of whom bought) both are
withheld and recorded as decided, so clearing the alert never pays them later. `ReferralService` resolves
`ReferralGifts` from the container when needed: `ReferralGifts → Rewards → PaymentService → ReferralService` would
otherwise be a constructor loop.

## Agency

An agent is a main-shop customer with a level (`users.agency_level_id`) and their own bot — a whole shop of its own. A
request is approved or rejected by compare-and-swap; approval opens the shop (bot row and wallet), ending the agency
switches the bot off and keeps its shop for a return. The agent buys traffic at their level's price per GB; every delivery
of their bot draws from that pool first, once per order, and gives it back if the delivery fails (`OrderService`'s
`withTraffic()`). A plan that the pool cannot cover is not offered (`ServerSelector::covers()`), and an unlimited plan
cannot be sold there at all. The program's settings live in the main bot's rows whichever shop reads them.

## Customers

`Customers` registers every newcomer one way — the bot, or the website's Telegram, email or Google sign-in — in one
transaction with their referrer and the report; a report the database refuses takes the newcomer back. A visit alone is
written at most once a minute and quietly (`ChangeFeed::quietly()`), so presence does not make every open panel reload.
`UserActions` holds support's decisions about an account (the website's admins never touch an admin's or an agent's).
`CustomerPictures` is the one rule for uploaded pictures (receipts, ticket pictures): judged by their bytes, re-encoded,
one budget per customer, refused with a 503 while the host's disk is nearly full.

## Tests

The feature tests are named after what they cover (`PaymentSettlementTest`, `DiscountsTest`, `CashbackTest`,
`ReferralGiftsTest`, `Admin*ApiTest` for each screen). Build rows with the `Tests\Support\Fixtures` helpers — `plan()`,
`sellingServer()`, `buy()` (paid from the wallet), `paidByCard()`, `wallet()`, `agent()`, `agentBot()`, `traffic()`,
`discountCode()`, `sale()`, `loyaltyTier()`, `cashbackOn()`, `giftCode()`, `wheelOn()`, `lotteryOn()`,
`referralProgram()` — and see [Testing](Testing.md) for the fakes (a fake panel, a fake Telegram).
