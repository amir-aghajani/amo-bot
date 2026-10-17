[فارسی](fa/Internals-Commerce.md)

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
  payment ── PaymentService::createForOrder()                               within the method's bounds
               wallet: settle() in one transaction (debit, payment → paid, order → paid), then settled()
               card:   a pending payment shown a card (PaymentCards::pick()); the receipt later → approve() → settle()
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
   nothing (`Order::boughtBy()`, below), its agent price for an agent or a «همکار» group, whichever is lowest; an extra's price as offered (with step 2,
   `Pricing::extraOffer()`, which is also what the bot's extras buttons show); a service
   the customer builds, its GB and its days each priced whole at its tier's rate, the two added (`custom()`, below).
2. **The automatic discount** — the largest of a running sale, the customer's own percent and their tier's
   (`AutomaticDiscounts::best()`). Never added together — the owner's decision: one automatic discount, then a code.
3. **The code** — applied to what step 2 left (`DiscountCodes::apply()`).

Whole Toman, never below zero; an order at zero is "free" and the shop's wallet row settles it whatever method was
picked. Wallet top-ups and agents' traffic are never discounted (`OrderType::FOR_THEMSELVES` lists the kinds a discount,
loyalty, cashback and the draw care about). The resulting `Quote` is written onto the order (`pricing`, `discounts`,
`discount_amount`, `discount_code_id`), so an order keeps the price it was made at whatever changes later.

**A service the customer builds** («سرویس دلخواه», [Custom services](Custom-Services.md)) is a purchase without a plan.
`Catalog\Services\CustomServices` is its whole rule: offered in the main shop alone while switched on, both tier lists
sell and one of its servers can take a client (`ServerSelector::wholeServer()`); each quantity (`Enums\CustomQuantity`:
traffic, days — one set of methods for both: `tiers()`, `bounds()`, `options()`, `allowed()`, `price()`) within its
bounds — the least never below the first tier, so whatever is in bounds is priced — at `DTO\PriceTiers::priceOf()`, the
whole quantity at its tier's rate (`quote()` → `DTO\CustomPrice`). `Pricing::custom()` takes that as the base and treats
it as an extra of a plan-less service is treated: `AutomaticDiscounts::best(null, Purchase, …)` (a sale of every plan
only) and a code judged for `CodeTarget(Purchase, null, server)`. `OrderService::openCustomPurchase()` makes the order —
`plan_id` null, `server_id`, `traffic_bytes`, `days`, `client_name` in its shape, so the open one of the same is found
and a key's order must match; `devices` (the builder's) beside it, a setting rather than what the customer asked for, so
an admin changing it neither turns a request made again into another one nor leaves the open order behind (it takes the
devices now); `Order::package()` hands delivery its own GB, days and devices, and `ProvisioningService::provision()`
makes it on the server whole (`ServerSelector::builtOn()`). `Order::packageName()` is the one wording of what an order
buys wherever a plan's name is printed (the report group, the panels' payments and dashboard): the plan's name, or
«سرویس دلخواه · ۵۰ گیگ · ۳۰ روز». The bot's flow is `Telegram\Handlers\CustomServiceHandler`, the website's
`Store\Api\CustomServicesController` over `CustomerCheckout::customQuote()` and `buildService()` — a purchase named as a
plan's is (`CheckoutScreen::requestedName()`, `CustomerCheckout::clientName()`).

A service without a plan (`CustomerRenewal::picksPlan()`: built, or added by its link) renews on a plan its customer
picks or, while `CustomerRenewal::buildable()`, with a package built. `Pricing::renewal()` takes either what it renews
with — a `Plan` (its renewal price: an agent's, never a first purchase's) or a `CustomPrice` — and prices a package as
`custom()` does, but as a `Renewal`: `best(null, Renewal, …)` and `CodeTarget(Renewal, null, the service's server)`
(`Pricing::built()` is the path both share). `OrderService::openCustomRenewal()` is `openRenewal()`'s twin — `plan_id`
null, `traffic_bytes`, `days`, `renewal_method` in its shape, `devices` beside it —, both through `openRenewalOf()`: the
service's row locked and `renewalUnderWay()` asked in the order's transaction (its owner's renewals only: one a previous
owner left is never delivered onto it, so it holds nothing — `AutoRenewal::claim()` counts the same way). A checkout
orders what its preview shows with `openPreviewed()`, which picks the twin by what the `DTO\RenewalPreview` renews
with. `ProvisioningService::renew()` adds `Order::package()` and writes no plan onto the service, so it keeps picking;
`AutoRenewal` never offers it.

**"Has bought" has three readings, each in one place on `Order`.**

- `bought()` and `boughtBy()` — a purchase paid and not given back (`KEPT`), a free plan included, never a service
  given free — decide first-purchase prices and first-purchase codes. A refund undoes the purchase, so a customer whose
  only purchase was refunded is a newcomer again.
- `keptPurchases()` — the same, with a price above zero — is what money-like rewards read: a referral's first purchase
  for the start gift, the plan reward's buyers, the alert on many referrals none of whom bought, and the wheel's
  buyers-only rule. Neither a free plan nor a purchase refunded since unlocks them.
- `paidPurchases()` — every purchase with a price ever paid, refunded since or not — makes a customer no newcomer for
  the free test accounts: their newcomers-only rule and the tests list's «خرید کرد».

A first purchase is decided again at delivery: `OrderService::carryOut()` asks `assertFirstPurchase()` of every order
before it delivers anything. An order made at the first-purchase price, or with a code for a first purchase only (its
discount line's `DiscountLine::$firstOnly`), fails when another purchase of the customer's, still kept, was paid before
it — of two ordered so before either was paid, the one paid second fails for support to refund, its notes saying so
(`FirstPurchaseUsedException`). Its customer is told so in words of their own
([why a paid order failed](#why-a-paid-order-failed)).

**Discount code uses.** A use is an order made with the code that is neither cancelled nor refunded, so an unpaid order
gives its use back when it expires — sooner for coded orders (`DiscountSettings::holdMinutes()`, `ExpireOrdersTask`) —
and a paid one when it is refunded. A quote counts uses leaving out the customer's own pending orders (their checkout
may be reopening one); the order that takes the use counts again under the code's row lock (`DiscountCodes::reserve()`,
inside `OrderService::open()`), so the last use goes to one customer. When the only use left for the customer is held
by another unpaid order of their own, the refusal names that order (`DiscountCodes::HELD_BY_YOU`): paying it uses the
code there, and its payment time running out frees it. A code may be deleted while no order but a cancelled one was
made with it; one whose orders were all refunded is kept for their history, with a refusal of its own
(`DiscountInUseException::refunded()`). Wrong codes are rate-limited per customer (`DiscountCodes::tried()`). Codes and
sales share `Models\Concerns\DiscountScope` (kinds, plans, window) and `Services\DiscountForm` (their forms' common
fields).

## Orders and the checkout

`OrderService::open()` finds the customer's payable order of the same shape at the same price and code, or makes one,
under a lock on the customer's row — two taps make one order. The shape is what the order buys: the plan and its server
or its service, an amount, a built package (its GB and days), a client name, a renewal's method (`renewal_method`: a
renewal by another method is another order; an automatic renewal's is the admin's default,
`OrderService::createRenewal()`). An extra's is its service and what it adds; the service's plan rides beside it (shown
as the order's plan), so a request made again after the service got a plan is still the same one. A renewal or an extra
is opened under its service's row lock while the service is still the customer's (`OrderService::holding()`: refused as
`ServiceTransfers::MOVED_ON`, or `SERVICE_GONE` once its row is gone), so a transfer at the same moment sees the order
(its open-order check runs under the same lock) and a screen drawn before a transfer orders nothing. Support's transfer
cancels the giver's unpaid ones (`ServiceTransfers::cancelLeftOrders()`, through `PaymentService::expire()`); one it
left open — its receipt in review — is neither paid (`PaymentService::createForOrder()`) nor delivered
(`OrderService::carryOut()`, and again under the service's hold for a delivery under way as it is handed on,
`ProvisioningService::renew()`/`addExtra()`; `ServiceChangedHandsException`: it fails for support to refund, as a first
purchase paid second does, and its customer is told the service is no longer theirs) — `Order::serviceChangedHands()`.
The website sends an `Idempotency-Key` with every ordering request: the order a key came to answers the same request
made again, whatever happened to it since (paid, delivered, cancelled, a new price), and the key remembers its payment
method so a repeat is never paid another way. Two requests with one key at once are settled by
the `request_keys` unique row and the order's compare-and-swap; the loser describes the winner's result
(`Checkout::standing()`). A keyed request makes no new order while the customer has `OrderService::UNPAID_MAX` unpaid
ones (each may hold a receipt picture on the host).

Statuses (`OrderStatus`): pending → paid → processing → fulfilled | failed, or cancelled unpaid, or refunded. The
reading lists live on `Order`: `SOLD` (a sale), `KEPT` (paid and not given back — a sale or a failed delivery), and the
scopes `bought()`, `spending()` (what loyalty and the draw count), `payable()`, `expirable()`, `stale()`, `stuck()`
(support's queue).

**Every move is a compare-and-swap** (`Core\Database\Transitions`): one payment pays an order; only the process that
claims it (paid → processing) delivers it, so support's approval racing the auto-approve timer, or two retries, never
deliver twice. The order is marked fulfilled in the same transaction that writes what it delivered, and outcomes are
reported to the group in that transaction.

**A delivery left by a dead process** is found by the order's `updated_at` (`OrderService::isStale()`,
`Order::stale()`), and `ResumeDeliveriesTask` finishes it as a retry would — never a failed delivery, which waits for
support:

- a paid order nobody claimed for `STALE_PAID_MINUTES` (10): its process died before it began;
- a processing claim untouched for `STALE_PROCESSING_MINUTES` (18): its process died on the way. A delivery at work
  shows it is alive by its **heartbeat**: before a call to a panel, once the order has gone 60 seconds untouched,
  `ProvisioningService::beat()` touches it — quietly (`ChangeFeed::quietly()`), and only while it is still processing
  with the moment this delivery last wrote. The longest a live delivery leaves its order untouched is that minute and
  one call on the slowest panel a server may have (`ProvisioningService::LONGEST_SILENCE`, about 17½ minutes);
  `STALE_PROCESSING_MINUTES` is that in whole minutes, and one more. A delivery that went quiet longer and was taken
  over finds its beat refused: `DeliveryTakenOverException` stops it before its next panel call, `carryOut()` leaves
  the order to the process that has it, and an agent's traffic it drew stays drawn — it is that delivery's
  (`OrderService::withTraffic()`; `TrafficPool::draw()` never draws twice for one order).

Unpaid orders expire after `ExpireOrdersTask::EXPIRE_HOURS` (`Order::expirable()`), never while their money may be with
support (`Payment::isWithSupport()`): a receipt in review, or a card transfer taken in support's private chat — shown no
card, it stays support's to approve or cancel, whatever the setting says since.

A delivery that a panel or a rule refuses fails the order with two notes: `notes`, safe for anyone reading the shop (an
agent, the website's admins), and `diagnosis`, the owner's detail of the panel (its address, its answer).
`OrderDirectory::notes()` picks by who reads.

### Why a paid order failed

The customer reads neither note. What they are told comes from `Orders\Enums\OrderFailure::of()`, read off the order:

- `FirstPurchaseUsed` (`first_purchase_used`) — its notes are `FirstPurchaseUsedException`'s, by the price or by a
  code's text (`FirstPurchaseUsedException::failedSo()`);
- `ServiceChangedHands` (`service_changed_hands`) — its notes are `ServiceChangedHandsException::MESSAGE`
  (`ServiceChangedHandsException::failedSo()`);
- `Delivery` (`delivery`) — any other failure;
- null for an order that did not fail.

The first two are never delivered: support settles their payment with the customer. `Delivery` waits for support's
retry. `Telegram\Notifications\ServiceCard::failedText()` picks the customer's text by it — `BotText::PayFirstPurchaseUsed`,
`BotText::PayServiceChangedHands`, else the order type's own failure (`PayProvisionFailed`, `RenewFailed`, `ExtraFailed`,
`TopupFailed`, `AgencyTrafficFailed`) —, and that one text goes to the bot, the website's notifications and email alike
(`CustomerNotifier::paymentSettled()`, `renewalFailed()`; the bot's checkout, `CheckoutScreen`). The Store API sends
the value as `StoreOrder.failure` (`Store\Presenters\OrderPresenter`). A new reason a delivery may refuse for good
comes with an exception that has its own `failedSo()`, a case here, and a bot text.

### Orders support makes

`Orders\Services\ManualOrders::make()` is «افزودن سفارش» (`POST /users/{id}/orders`, both panels and — with the
`manual_orders` grant — the website's admins): a purchase for a customer of a plan, or of support's own numbers carried
on the order as a built one's are (`traffic_bytes`, `days`, `devices`, each 0 for no limit — `Order::isBuilt()`,
`Order::package()`), on a server checked up front (`ServerSelector::resolve()` / `builtOn()`), in an agent's shop against
their pool (`Bot::trafficRefusal()`: no unlimited quota, nor more than is left) and never on a server or of a plan
the owner keeps from it (`Bot::hidesServer()`, `Plan::$hidden_by_owner`), named by `ClientNaming::chosen()` (a buyer's
typed-name rules, whichever ways the shop offers), refused for the actor's own account (`ActorRefusedException::ownOrder()`), for an
agent's shop whose selling is suspended (`Bot::sells()`, on `status`, whoever asks — the owner too, who opens it
again first: `SellingSuspendedException::OWNER`) and for a wallet short of the amount (`Checkout::shortfall()`).
`OrderService::openManual()` makes it anew — never an open order of the same shape found — with `created_by` (the
actor's name) and `support_note`, its `Idempotency-Key` kept in `request_keys` as a website's is; a key that came to an
order answers with it before anything stateful is asked (a suspension since, the wallet it emptied, the name it took).
`PaymentService::payBySupport()` then settles it at once under the actor's name, as the wallet's checkout does — the
wallet row (charging the customer — refused up front while the wallet is switched off —; taking nothing for a free one)
or the built-in **outside** row (`Drivers\Outside`, money taken outside the shop) — and delivers it; a wallet spent in
the same moment cancels the order and refuses. The customer is told once it is fulfilled
(`CustomerNotifier::paymentSettled()`); `ShopReports::purchaseText()` adds who made it — the owner's login as «پشتیبانی»
(`Reviewers::shared()`) — and how it was paid.

How it counts falls out of the existing rules, by `pricing` and the payment's method: a free one is `pricing` gift at no
price — no purchase (`Order::bought()`), nothing earned; a wallet one is `regular` at support's amount — a purchase,
spending (cashback, loyalty, the draw, a referral's first-purchase gift), but no commission and no `moneyIn()` (a wallet
payment); an outside one is the same purchase and `moneyIn()` too, earning the referrer's commission. `Order::manualPayment()`
reads which it was (free by its price, else the payment's method) for `OrderDirectory`'s `manual` and the report. The
outside row is never offered at checkout (`PaymentMethods::offers()`) nor listed among the methods or drivers
(`PaymentMethods::all()`, `drivers()`); every shop has one (database/schema.php's rows, `createBuiltins()`, the 0.5.0
upgrade).

## Payments

A payment method is a row: a gateway driver (`Payments\Drivers\*`, registered in `payment.drivers`) with its settings
and a label. Ten drivers ship: the wallet (built in, one row per shop, settles at once), the outside row (built in
too, support's alone, above), card-to-card (a manual gateway: shows a card, waits for a receipt), and CubePay,
Zarinpal, AtlasPay, AbanGateway, Plisio, AqayePardakht and Variza (online gateways: the customer pays on their page,
and they are asked what came of it — Variza, which answers no question, tells it by a signed webhook —
[Drivers](Drivers.md#an-online-gateway)). Adding a gateway is a driver class, its registration and its request
shapes — see [Drivers](Drivers.md#a-payment-gateway).

Every method has bounds (`min_amount`, `max_amount`): `PaymentMethods::forOrder($type, $amount, $customer)` and
`payable()` offer only a method that takes the amount (`takes()`: within `within()`, and a card method with a card that
has room — `PaymentCards::offers()`, or the customer holds a pending payment of it on an open order of that type and
amount, which asking again reuses whatever its card's room or switch), a null amount meaning any (the website's list without
`amount`, the order's type checked before its price is known);
`allow()` throws the refusal (`MethodUnavailableException`, 422 on `method_id`, in the method's words). It is asked in
one place: `Checkout::pay($user, $method, $type, …)`, the bot's and the website's one checkout, before anything is
ordered (next to `ReceiptsInReview::allowCard()`), at the price — or a keyed order's own amount;
`PaymentService::createForOrder()` holds every payment to the bounds again, for its other callers — a free order is
within any. A payment that finds no card after all (the last room taken meanwhile, or the open payment `offers()`
counted another order's of the same type and amount) leaves nothing to pay: `Checkout::withdraw()` cancels the order
the request made (`OrderService::cancel()`, the refusal its notes) and forgets the key it kept. The bot's
`CheckoutScreen::show()` takes the amount from each caller, and `buy()` draws the checkout again on the refusal,
with the ways that are left; the callers ask `payable()` with no amount first (switched off, or not one for this kind
of order), as `CustomerCheckout` checks the method's type before the quote is known. `AutoRenewal` renews from the
wallet only a price within its bounds, and `AutoRenewal::renews()` asks the same of the customer's renewal price
(`Pricing::renewalOffers()`, each customer's read once for a page): a service it does not take is not offered the
switch, and `ServiceReminders` reminds it of its end. `PaymentService::payBySupport()` asks no bounds: an order support
makes by hand is deliberate.

A card method's cards are rows of their own (`Models\PaymentCard`, `payment_cards`), kept by the method's form as one
list (`cards`: `PaymentCards::read()` checks it whole, `keep()` saves it in the method's transaction); the driver's
form has only the note and the review window. `PaymentService::openPayment()` reuses the order's open payment of the
method — with its card, `payments.card_id`, so a payment always shows the same card — or makes one with a card
`PaymentCards::pick()` draws at random among the enabled cards whose `daily_cap` leaves room (for a top-up, room for the
most `amountFor()` may ask), under a lock on the method's cards and reading what they took with a locking read — of two
payments at once the second waits and sees the first; none: `MethodUnavailableException::noCard()`. What came to a card
today (`takenToday()`) is its payments made since the shop's midnight (`LocalTime::midnight()`) that are pending, in
review, paid or refunded — summed, as a card's payments are counted, from `payments(card_id, created_at, status,
amount)` alone, without the shop's `bot_id` filter (a card's payments are its shop's). `PaymentMethods::update()` locks the method's cards before `read()` judges the form, so a card a payment is
being shown at that moment keeps its number and stays. The gateway shows a
payment its card: `GatewayInterface::initiate(Payment)` — `ManualGateway` makes the `CardTransfer` from `$payment->card`
and the method's note, `Checkout::awaiting()` (a request made again) the same. A card a payment was shown keeps its
number and is never deleted (`payments.card_id` restricts it). The upgrade to 0.5.0 made each method's card of its
settings its first row and gave every payment of the method that card; a number its form would have read as none made
no row, and that method is offered to nobody until a card is added (its payments keep no card).

A card-to-card top-up asks more than its order (`PaymentCards::amountFor()`): 1 to `WalletSettings::uniqueMax()` Toman,
a sum no other open payment to the card asks — held within the method's `max_amount` (`extra()`: nothing more for an
amount at its most), and a card's room judged on that held sum. The order keeps the amount asked (`OrderService::open()` finds an order by
its amount); the payment's `amount` is what is paid, credited (`OrderService::carryOut()` credits the paid payment's
amount), counted (`Payment::moneyIn()`, referral commissions) and refunded.

`PaymentService` moves payments by compare-and-swap and reports each move in the same transaction. Settling (`settle()`)
is the gateway's part (the wallet's debit), the payment's move and the order's claim in one transaction — losing either
compare-and-swap throws and rolls the debit back. The delivery runs after the commit, since it talks to a panel. A
receipt sent in the bot may be accepted by its method's review window (`AutoApproveReceiptsTask`); one uploaded from the
website always waits for support, because its account may be a throwaway email. Refunds go by what the order bought:
what it gave a service taken back first, then its money back to the wallet (less any cashback; in an agent's shop the
traffic it drew goes back to the pool, `TrafficPool::giveBack(refunded: true)`), an agent's traffic out of their pool, a
top-up back out of the wallet — refused once spent or sold. A top-up's money leaves the shop (support returns it
outside), so its refund takes back the referral commission it earned too (`ReferralService::refundTakesBack()`,
`takeBack()`: out of the referrer's wallet, as a line of its own, in the refund's transaction), refused when the
referrer spent it (`RefundRefusedException::commissionSpent()`); the referrer is told after
(`CustomerNotifier::commissionTakenBack()`). Any other refund's money stays in the shop as wallet credit, and its
commission stands. Taking back what an order gave a service is the services side's: Payments owns
`Contracts\RefundedOrders` and Subscriptions' `RefundedDeliveries` implements it. A purchase's service goes through the
services screen's own delete (`SubscriptionActions::delete()`, no message of its own), refused while another order of
the service is unpaid or kept (`Order::KEPT`: paid, being delivered, delivered or failed —
`SubscriptionActions::orderHolding()`): `RefundRefusedException::serviceInUse()` tells support what to do first by that
order's status — cancel it, wait for its delivery, or refund it. A renewal's or an extra's gift is undone by
`ProvisioningService::undo()` (`ServiceTerms::takeBack()`): a renewal's term and traffic as `given_hours` and
`given_bytes` kept them at delivery and its device limit put back (`given_ip_limit`, `ip_limit_was`,
`ip_limit_queued`, `ip_limit_reset_was`: `ServiceTerms::limitPutBack()`), an extra's `days`, `traffic_bytes` or
`devices` — as far as the service still has them: what a later renewal started afresh, and what only the last renewal
standing undoes, is handed on to it ([the take-back](Internals-Services.md#renewals-extras-and-reminders)); once —
`taken_back_at` is written with the row and checked under the service's hold —, its client switched on or off as the
panel had it. Only a delivered order is taken back from, and never from a service another customer has now
(`RefundRefusedException::serviceChangedHands()`, asked first and again under the hold: a transfer since; a merge
moves the orders with the services). A refusal, or a panel that fails, leaves the payment unrefunded:
`PaymentActions::refund()` asks it before `PaymentService::refund()`, and hands that the order's state it judged by
(`$seen`) — an order delivered in between is refused under the order's lock, to be refunded again (and taken back).

`PaymentActions` and `OrderActions` are support's decisions, wherever they are made (both panels, the website's admin
API, the report group's buttons, the timer). Each checks its rule (`allowed()`), who decides (an `Auth\Actor`: nobody
approves or refunds their own payment; the website's admins approve only a receipt in review), and tells the customer
only when this call decided — the loser of two decisions at once is refused with `DECIDED_MEANWHILE`. An approval of a
payment judged as a receipt in review (always so on the website and by the review window's timer) moves it only while
it is still in review (`PaymentService::approve(receiptOnly: true)`): a rejection or a cancel made in the same moment
stands, and is never approved over. An online payment is never approved nor rejected: `check()` asks its gateway
(`OnlinePayments::check()`, the gateway's word alone moves it), and one paid after its order closed
(`Payment::wentToWallet()`: its money in the wallet) is neither retried nor
refunded — it paid nothing of the order. One is held for support (`OnlinePayments::isHeld()`) when its gateway answers
what does not fit it, keeps money on it unsettled 48 hours after its page closed, or closes it after saying money had
come in on it — the last at once —, each with its note (`PaymentService::HELD_NOTES`). A held one is asked about no
more — only `cancel()` is allowed —, and while it still waits (pending, or failed with its page) its order neither
expires (`PaymentService::expire()` keeps it) nor is paid another way (`Checkout::pay()` throws
`PaymentHeldException`); cancelled, it holds nothing. The payments screen lists the held ones under a tab of their own
(`PaymentDirectory::HELD`, `Payment::whereHeld()`), and the dashboard counts them among the admin's queues.

`ReceiptDismissals` rejects receipts in bulk by looping `PaymentActions::reject()`, then deletes each upload
(`Receipts::discard()`) and reports one line a request (`ShopReports::receiptsDismissed()`). With `all` (the panel's
«رد همه رسیدهای در انتظار») it takes the shop's oldest 100 a request, each round held to `receipt_at` up to the first
round's moment (`until`, sent back by the panel), so a receipt sent while it runs is never rejected unseen.

How card-to-card payments are taken is `PaymentSettings` (the bot settings' `payments` group). One receipt at a time is
`ReceiptsInReview`: `Checkout::pay()` asks `allowCard()` before anything is ordered (both doors; a website request made
again with its key, whose own receipt is in review, passes), the bot's `ReceiptHandler` and the website's
`CustomerReceipts` ask `allowReceipt()` before looking at the picture, and take it through `submit()` — asked again
under a lock on the customer's row, with a locking read, so of two receipts sent at once to two payments the second
finds the first in review. Each refusal is a `ReceiptInReviewException` (422 on `status`, the bot's «رسید قبلی در حال
بررسی»). `allowCard()` takes no lock: what a race with a receipt could leave — a card payment waiting for its transfer
while a receipt is in review — a customer reaches without one, by starting two card payments before the first receipt;
the rule itself is `submit()`'s.

Card payments in support's private chat (`sendsToSupport()`: the switch on and the bot's support contact set, read by
the payment engine as `PaymentCards::toSupport()`) are payments shown **no card**: `PaymentService::openPayment()` picks
none (`payments.card_id` null), so no card's daily cap is taken, and `PaymentCards::offers()` offers the method whatever
its cards (the methods screen's `to_support`). A top-up still asks its few Toman more (`PaymentCards::amountFor()`),
unique among the method's open payments shown no card — the method's row locked first, as `pick()` locks the cards. An
open payment keeps what it was shown: a card from before the switch, or none — given a card by `openPayment()` once the
shop shows cards again, as a payment from before cards is. Given a card so, it keeps the sum it asked unless another
open payment to that card asks the same, when it gets a fresh one (`amountFor(…, keeping:)`). The display follows the
payment: `ManualGateway::initiate()` answers `CardTransfer::toSupport()` (no card, the method's note) for one shown
none, `CheckoutScreen::transfer()` then draws the order, the way to support and the note and awaits no receipt,
`CustomerCheckout::answer()` gives `contact`
instead of `card`, and `PaymentDirectory` and `ShopReports::receiptSubmitted()` say `Payment::TO_SUPPORT` where the card
would be. `CustomerNotifier::paymentReminder()` follows the payment too: one shown no card gets the way there
(`Buttons::supportChat()`, as `CheckoutScreen` draws it) instead of «ارسال رسید», whatever the switch says now. A protected
card message is sent, never edited in (`Context::resend()` with `protect_content`: the checkout deleted or, past
Telegram's 48 hours, stripped of its buttons).

## Ledgers

The wallet (`WalletService`) and an agent's traffic (`TrafficPool`) are append-only ledgers written through
`Core\Database\Ledger`: the owner's row is locked, the last line read under the lock, and each line carries the balance
after it — the last line *is* the balance (`User::balance()`, `Bot::trafficBalance()`), with no copy to drift. A debit
may go below zero only as far as the caller allows (an agent's credit). Lists read balances with their rows
(`User::balanceColumn()`), never a query per row.

Support's changes by hand may come with an `Idempotency-Key` (the panels send one): a wallet's credit or debit
(`WalletService::credit()`/`debit()` with `$key`) and an agent's traffic adjustment (`TrafficPool::adjust()`) keep it on
the line they write (`request_key`, unique per customer, and per shop for the traffic). The same key again — a retry
whose answer was lost, or two at once — is answered by that line, read under the ledger's lock with nothing written
(`wasRecentlyCreated` false; the wallet's API answers 200 rather than 201); the key with another type, amount or words
is `KeyReusedException`. An account merge keeps a key both ledgers hold on the survivor's line
(`WalletService::takeOver()`).

The locking read of the last line also locks the index gap after it, so two owners' lines at the same moment — two
newcomers' top-ups, two customers' transfers — can deadlock on MySQL, which aborts one transaction whole: no money moves
twice, but the request would fail. So every money write is tried again, `Ledger::ATTEMPTS` times, by the rule in
[Conventions](Conventions.md#only-once): `Ledger::append()` on its own, a wallet transfer, the delivery of a top-up and
of an agent's traffic, the order a manual order makes (`OrderService::openManual()`), a payment's settlement (the
wallet's checkout, `payBySupport()`, `approve()`), a refund, cashback earned, a referrer's commission and the start
gifts, a gift code's Toman and a spin of the wheel. Not tried again: what moves no money (a receipt's verdict, a cancel,
an expiry, which gathers the pictures to delete inside it) and an account merge. Laravel counts a lock wait timeout as a
concurrency error too, so a write that waited out `innodb_lock_wait_timeout` for a lock waits again.

## Wallet transfers

«انتقال موجودی» is `Users\Services\WalletTransfers`, one rule for both doors — the bot
(`Telegram\Handlers\WalletTransferHandler`, `wsend:` and the state `wsend[.{recipient}[.{amount}]]`) and the website
(`Store\Api\WalletController`: `GET /wallet/transfer` the recipient, `POST` the transfer;
`Store\Services\CustomerWalletTransfers` what `GET /wallet` says of it). Its settings are `WalletTransferSettings` (`wallet_transfers`,
off by default).

- **The recipient** — `recipient()` asks `check()` first (both doors: a customer with nothing to send learns no other
  customer's name), then finds them through `CustomerLookup::find()`, the lookup service transfers share: the id as
  typed, a customer of the sender's shop, a customer's lookups counted together (`LOOKUPS` per `LOOKUP_WINDOW`); then
  not the sender, not banned. Refusals under `telegram_id`.
- **The amount** — whole Toman (`Input::amountOf()`) within the shop's bounds, at most the sender's own balance: the debit
  goes through `WalletService::debit()` with no credit, so an agent's credit never moves; and with what the sender sent
  since midnight on the shop's clock (`LocalTime::midnight()`) within the daily cap, when one is set. `most()` is the least of the
  three, what the bot asks for and `GET /wallet`'s `transfer.most`; `check()` refuses at the bot's first step, and before
  any lookup, one who may send less than the least. Refusals under `amount`; the shop offering none under `status`, as
  the other things a shop may switch off (`ServiceTransfers`, `CustomServices`). `TRANSFERS` per `TRANSFER_WINDOW` a customer. The daily cap
  is 0 (none) or at least the least transfer (`WalletTransferSettings`): a cap below it would leave no transfer to make.
- **The move** — `transfer()`: one transaction that locks both customers' rows in id order (as `Ledger::merge()` does,
  so two customers sending each other money at once wait in turn), sums the day's transfers with a locking read, debits
  the sender and credits the recipient (`InsufficientBalanceException` worded as `SHORT`), each line naming the other
  (`LINE_SENT`, `LINE_RECEIVED`, `User::shownAs()`), writes the `wallet_transfers` row — the two lines' ids, what the cap
  sums and the panels show a line's other customer by (`UserDirectory::ledger()`, `WalletTransactionRow.counterpart`) —
  and `ShopReports::walletTransferred()` («شارژ کیف پول»). After the commit `CustomerNotifier::walletReceived()` tells the
  recipient, and `walletSent()` keeps the sender's notice — sent to their chat only from the website, the bot having
  answered in place.
- **The website's key** — `transferById()` answers an `Idempotency-Key` it saw (`wallet_transfers.request_key`, unique
  per sender) with the transfer it made, read again under the locks for two at the same moment; the key with another
  recipient or amount is `KEY_REUSED`. A merge of two accounts carries every row (`AccountMerger::REFERENCES`, `unkey`):
  what both sent counts against one daily cap, and a key both used is the survivor's — the other transfer kept, keyless.

## Rewards

`Rewards::give(user, Reward, reason)` is how every prize or gift reaches a customer — Toman as a wallet line, traffic
or days granted on their newest running service that takes them (from the agent's pool in an agent's shop), or a plan as
a free service: a purchase order at no price, `pricing` gift (so never their first purchase), settled by the wallet row.
`Eligibility` answers "can this reach them now?" before anything is spent (a wheel slice, a gift code). Both read the
shop afresh (`Bot::sellsNow()`): in an agent's shop whose selling is suspended, only Toman is given.

**A plan's free service counts as given once its order is paid**, whatever its delivery does. A delivery that a panel
refused, or that broke on a bug (`OrderService::DELIVERY_BROKEN`), leaves the order for support's retry
(«نیازمند رسیدگی»), as any paid order's failure does: `Rewards::plan()` logs it and never throws, so no caller gives the
prize again — no spin refunded, no milestone freed, no next winner drawn. Only `RewardUnavailableException` means nothing
was given: no running service takes the traffic or days (days only one whose end they keep within the database's
bound: `ServiceTerms::daysLeft()`), no server delivers the plan, the plan's term would end past that bound (in
`TermTooLongException`'s words), or the shop's selling is suspended. `Rewards::failureLevel()` logs a refusal (a panel's or a rule's) as a warning, anything else as an error.

- **Gift codes** — every try is counted before it is judged and given back when the code exists, so only wrong codes
  spend the budget; a code is taken by one conditional update, and a plan's code is given back when nothing was given.
- **The lucky wheel** — cooldown, eligible slices and the cost are checked and paid in one transaction under the
  customer's row lock; the prize is given after the commit. When nothing could be given, the cost goes back and the
  spin keeps why, counting for no cooldown — both in one transaction.
- **Cashback** — earned once per order (`cashbacks.order_id` unique) after the delivery's commit
  (`OrderRewards::delivered()`), so the wallet's lock never holds a delivery up and a failure there never undoes it. A
  refund takes it back by giving that much less.
- **The nightly draw** — the day's row is inserted first (unique per shop and day), so two runs never draw twice.
  Entries are the orders paid since the draw before it (`Lottery::window()`): from a minute before that draw to a
  minute before this one, so a payment settling at that moment enters one draw, and a late run or a moved hour enters
  no order twice and skips none. A window reaches back 48 hours at most; a shop's first is the 24 hours before it.

## Referrals

Only a customer the shop has never seen becomes a referral (`ReferralService::attribute()`, called by
`Customers::register()` for a newcomer) — a known customer opening a link changes nothing. A commission is earned only on
money that came in (`Payment::moneyIn()`): once per payment by a unique row, the `first_only` rule decided under a lock
on the customer and the payment read again under a lock of its own, so a refund at the same moment either comes first
(nothing earned) or finds the commission. A top-up's refund takes it back (its money leaves the shop: [Payments](#payments));
any other refund leaves it standing. `referrer_id` is kept as earned, so an account merge never moves past
earnings. A merged account is brought by the survivor's referrer, else the merged one's — never by one the survivor
brought themselves, at any remove, which would make a loop (`AccountMerger`).

`ReferralGifts` gives the start gift (on joining or on the first purchase with a price) and the plan reward, both
through `Rewards`; "bought" is `keptPurchases()`, so a purchase refunded since leaves the next one the referral's first.
The plan reward counts milestones of referrals who bought: each referral's first purchase claims the next one its count
reached — the lowest multiple of the admin's number above every milestone claimed so far — under a lock on the
referrer, by a row of its own before anything is given. So two first purchases at once that cross two milestones give
both, lowering the number never pays out several at once, and a milestone whose plan could not be given is freed again
for the next first purchase to claim.
While a fake-referral alert stands (`ReferralAlerts`: a burst of joins, or many referrals none of whom bought) both are
withheld and recorded as decided, so clearing the alert never pays them later. `ReferralService` resolves
`ReferralGifts` from the container when needed: `ReferralGifts → Rewards → PaymentService → ReferralService` would
otherwise be a constructor loop.

## Agency

An agent is a main-shop customer with a level (`users.agency_level_id`) and their own bot — a whole shop of its own. A
request is approved or rejected by compare-and-swap; approval opens the shop (bot row and wallet), ending the agency
switches the bot off and keeps its shop for a return. The agent buys traffic at their level's price per GB; every delivery
of their bot draws from that pool first, once per order, and gives it back if the delivery fails (`OrderService`'s
`withTraffic()`). Whether the pool pays for a sale is one judgement, `Bot::trafficRefusal()` — an unlimited quota, or
too little left, in `TrafficShortException`'s words —: the delivery draws by it, a plan it refuses is not offered
(`ServerSelector::covers()`), and `ManualOrders` asks it before anything is made. The program's settings live in the
main bot's rows whichever shop reads them.

**What the owner keeps from an agent's shop** lives on the `Bot` model, which every module may name:
`bot_hidden_servers` (`Bot::hiddenServerIds()`, `hidesServer()`, read once per row) and `plans.hidden_by_owner`. There
is no global scope on `Server` — existing services, the sync, deliveries and grants need every server. The filters are
where something new is sold: `ServerSelector::choicesAmong()` skips an entry on a hidden server (a plan left without one
is `NO_CHOICE`, the entry `HIDDEN_SERVER`) — but not for a paid order's delivery (`resolve(paid: true)`,
`ProvisioningService::provision()`) —; `SubscriptionActions::move()` (anyone's but the owner's), `ManualOrders` and
`PlanService::validateServers()` (a server the plan was not on already) refuse one, all in `HIDDEN_SERVER`'s words —
`moveTarget()` does not, so the owner's `ServerMigrations` and single moves carry an agent's services too —; and the
lists of choices read `Server::shown()`, the current shop's servers but its hidden ones: `TestServers::choices()`,
`TestAccountDirectory::servers()`, `StoreCatalog::status()`, `PlanService::options()` and `DiscountCodes`' servers.
`Plan::active()` (and `Plan::onSale()`) leave hidden plans out, and `Rewards` gives no prize of one; nothing in
`PlanService` writes the column — only `Catalog\Services\AgentRestrictions` (the owner's
`PUT /agency/agents/{id}/hidden`) — and `duplicate()` copies it. Renewals and extras of a service already on a hidden
server, or of a hidden plan, go on: they make nothing new.

**An agent's terms of selling** (`Agency\Services\AgencyStanding`): `bots.agency_expires_at` and `monthly_minimum`, with
`selling_suspended_at` and `suspension_reason` (`Bots\Enums\SellingSuspension`: expired, minimum, manual) and markers
that keep each warning to once (`expiry_warned_at`, `minimum_warned_month`) and each month judged once
(`minimum_checked_month`, a Jalali `YYYY-MM` that compares as its month — `Support\JalaliMonth`, on the shop's clock).
Each reason is worded once, `SellingSuspension::words()`: the agent's notice and account line in the main bot, the
main group's report (then `remedy()`, the owner's way back) and, copied word for word, the panels' `SELLING_SUSPENSION`.
The program's minimum and an agent's own are one field (`AgencySettings::minimumOf()`): whole Toman, 0 for none —
`monthly_minimum` null.
`Bot::sells()` is the one question every door asks — true for the main bot; an end passed counts at once, before
the hourly `Agency\Tasks\AgencyStandingTask` writes it down. It is never `Bot::status()`, which would stop the bot, its
webhook, panel, website and tasks. Where the answer must be the database's as it is now — a bot's polling process keeps
its shop's row for seconds, and a suspension holds from the moment it is written — the door asks `Bot::sellsNow()`,
which reads an agent's row again (without a lock: a free test locks the shop's row before its customer's):
`OrderService::open()` asks it a second time inside its transaction, under the customer's row lock, and `Rewards::give()`,
`Eligibility::givable()` and `GiftCodes::redeem()` ask it too. The doors: `OrderService::open()` (every purchase, built
purchase, renewal, extra and top-up — never `OrderType::Traffic`, bought in the main shop), `openGift()`, `createRenewal()` and `AutoRenewal::due()`,
`CustomerRenewal`'s `RenewalRefusal::SellingSuspended`, `CustomerExtras::offersFor()`/`quote()` (not `refusal()`, which the
delivery of an extra paid before asks), `TestAccounts`' `TestRefusal::Suspended`, `ServerSelector::covered()` and
`weigh()` (no plan offered), `Rewards::give()` (Toman still given — `RewardKind::isSold()`) and
`Rewards\Services\Eligibility::givable()` before it (a wheel slice is drawn among Toman and «پوچ» alone, a gift code
worth a plan is refused in `RewardUnavailableException::SUSPENDED`'s words), `SubscriptionActions::extend()` (the owner
still may) — the shop's staff read one wording, `SellingSuspendedException::STAFF` —, the website's
`CustomerCheckout::ordering()` (503, `taking_orders` false — receipts still taken; what only prices an order, a
renewal's preview and an extra's quote, says it as a 422 on `status`: `SellingSuspendedException::onStatus()`) and the
bot's `CheckoutScreen::buy()` (`SellingSuspendedException`). The bot says `BotText::SellingPaused` — a message's text,
never a popup's, so the agent's HTML shows as written — in place of the screen (`CheckoutScreen::paused()`): at a
renewal, an extra and every step of a top-up (`TopUpHandler`, whose button leaves «کیف پول»), as at the plans and a
free test.
Delivery asks nothing: what was paid is delivered. With automatic renewal waiting, `ServiceReminders` reminds a service
with «تمدید خودکار» on of its end too. Every suspension and lifting is a conditional UPDATE, so two runs, or a run and the owner, tell the
agent (`CustomerNotifier::agencySuspended()`, `agencyResumed()`) and the main group (`ShopReports::agencySuspended()`)
once. A minimum suspension is lifted by the month in progress reaching the minimum — at the traffic order's delivery
(`AgencyStanding::bought()`, after the commit, never failing it) or by the next run —, and a short month is not
suspended for at all when the month in progress reached it before the run judged it. One reason stands at a time
(manual over expired over minimum): lifting the stronger lifts all, a short month's included — except an end lifted
(`AgencyStanding::liftEnd()`): the minimum of last month, left unjudged while the end held the shop, is judged then, and
when both last month and the month in progress fell short of it, the shop stays suspended, now for the minimum, in one
conditional write (the agent and the group told so). An agency's end the program's term works out at approval is held
to the database's bound (`AgencyActions::approve()`, [Database](Database.md#time-and-the-2038-bound)).

## Customers

`Customers` registers every newcomer one way — the bot, or the website's Telegram, email or Google sign-in — in one
transaction with their referrer and the report; a report the database refuses takes the newcomer back. A Telegram
profile is kept as `Customers::profile()` reads it: a first or last name that shows nothing (`Support\Text::blank()`:
spaces of any kind, zero-width or filler characters alone) is no name. A visit alone is
written at most once a minute and quietly (`ChangeFeed::quietly()`), so presence does not make every open panel reload.
`UserActions` holds support's decisions about an account (the website's admins never touch an admin's or an agent's).
`CustomerPictures` is the one rule for uploaded pictures (receipts, ticket pictures): judged by their bytes, re-encoded,
one budget per customer, refused with a 503 while the host's disk is nearly full.

## Tests

The feature tests are named after what they cover (`PaymentSettlementTest`, `DiscountsTest`, `CashbackTest`,
`ReferralGiftsTest`, `Admin*ApiTest` for each screen). Build rows with the `Tests\Support\Fixtures` helpers — `plan()`,
`sellingServer()`, `buy()` (paid from the wallet), `paidByCard()`, `wallet()`, `agent()`, `agentBot()`, `traffic()`,
`discountCode()`, `sale()`, `loyaltyTier()`, `cashbackOn()`, `giftCode()`, `wheelOn()`, `lotteryOn()`,
`referralProgram()`, `customServicesOn()` — and see [Testing](Testing.md) for the fakes (a fake panel, a fake Telegram).
