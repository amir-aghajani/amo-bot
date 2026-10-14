# Store API: buying

A purchase, a renewal, extra traffic, time or devices for a service and a wallet top-up go through the shop's one
checkout — the one the bot's purchases go through — so the same rules answer on both doors. Paths are under the
[base address](Store-API.md#the-base-address); every one here takes the customer's bearer token.

## The flow

1. The customer is signed in.
2. Read the ways to pay that kind of order at its price:
   `GET /payment-methods?for=purchase|renewal|wallet_topup|extra_traffic|extra_time|extra_devices&amount=…`.
3. When they confirm, make the attempt's `Idempotency-Key`.
4. Send the order: `POST /orders`, `POST /subscriptions/{id}/renewal`, `POST /subscriptions/{id}/extra-traffic`,
   `POST /subscriptions/{id}/extra-time`, `POST /subscriptions/{id}/extra-devices` or `POST /wallet/top-up`.
5. Act on the outcome: delivered, a card to pay, or a delivery under way ([what a checkout answers](#what-a-checkout-answers)).
6. For a card transfer, upload the receipt ([card transfer and the receipt](#card-transfer-and-the-receipt)) and wait
   for the review ([the review](#the-review)).

Nothing is ordered until the way to pay is picked: browsing plans and opening a checkout cost nothing. While the shop
takes no orders — `GET /`'s `shop.taking_orders` is `false` — every ordering request is `503`, a request made again too:

- **Its bot switched off** — `فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.`, and every receipt is `503` too.
- **An agent's shop whose selling the main shop suspended** ([the agent's terms](Agency.md#the-terms-of-selling-an-end-and-a-monthly-minimum))
  — `فروش این فروشگاه فعلا متوقف است؛ سرویس‌های فعلی شما مثل قبل کار می‌کنند. برای اطلاعات بیشتر با پشتیبانی در تماس
  باشید.` A receipt is still taken: an order made before is still paid. What only prices an order answers in the same
  words with a `422` on `status` instead — a renewal's preview (`GET /subscriptions/{id}/renewal`) and a code's check
  for a renewal or an extra (`POST /discount-codes/check`) —; the catalogue offers no plan, and a code's check for a
  purchase is a `422` on `plan_id`.

## Ways to pay

`GET /payment-methods?for=purchase&amount=120000` → `200`
```json
{
  "methods": [
    { "id": 1, "label": "کیف پول", "kind": "instant", "min_amount": null, "max_amount": "5000000.00" },
    { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual", "min_amount": "10000.00", "max_amount": null }
  ]
}
```

In checkout order, as the bot offers them. `kind: "instant"` is the wallet — paid at once from the balance (an agent's
credit counting); `"manual"` is a card-to-card transfer and its receipt. A way of paying whose driver is no longer
installed is never offered (an earlier payment made with one shows it in its order with `kind: null`). The wallet is
never offered for a top-up — and is for extra traffic, time or devices. `for` is required: anything but the six kinds is
`422` on `for`.

Each way is offered for the amounts between its `min_amount` and `max_amount` (Toman; `null`: no bound). With `amount`
— the price to pay, after its discounts (a quote's `amount`, `POST /discount-codes/check`'s), as the bot reads one:
Persian digits and separators are fine; anything else is `422` on `amount` — only the ways that take it are listed: within
their bounds, and a card-to-card way with a card that has room for it today (the shop may cap what each card takes in a
day) — or a card-to-card way the customer holds an unpaid payment of, for that kind and amount, whatever its card's room
or switch now: asking again shows them that payment, on its card. Without `amount`, every way of the
kind (a card-to-card way while a card of it is switched on), whatever its bounds — check them yourself. An empty list
means the shop takes no way to pay that kind of order, or that amount, now; say so («برای این مبلغ روش پرداختی نیست»).

## The Idempotency-Key

`POST /orders`, `POST /subscriptions/{id}/renewal`, `POST /subscriptions/{id}/extra-traffic`,
`POST /subscriptions/{id}/extra-time`, `POST /subscriptions/{id}/extra-devices` and `POST /wallet/top-up` must carry an
`Idempotency-Key` header: 1 to 64 Latin
letters, digits, `-` and `_` — a `crypto.randomUUID()`. The shop records each request's key with the order it came to and
the way to pay it was first sent with (`method_id`), for a week.

- **Make one per checkout attempt** — when the customer presses pay —, and a new one whenever they pick another way to
  pay: a key is tied to its `method_id`.
- **Send the same key again** — the same body, the same `method_id` — to retry the same request: a network error, a
  timeout, a `5xx`, a double click. It never makes a second order or a second charge; two sent in the same moment make
  one order and get one answer. What it answers depends on where that order stands:
  - **no longer to be paid** — paid, being delivered, delivered, failed, refunded, or its receipt with support: the
    order as it stands, whatever changed since (a price, the plan, the wallet, the extras on sale);
  - **still to be paid** — a card transfer with no receipt yet, a wallet payment the wallet refused: the request is
    tried again on that same order, at the price it was made at — and today's rules are asked again first, so a plan
    switched off, a location or a way to pay no longer offered, an amount of extra traffic, time or devices no longer
    sold, or a top-up outside today's bounds is a `422` although the order exists (look at `GET /orders`);
  - **cancelled since** — see the next point.
- **Once that order was cancelled** — by support, or left unpaid for 48 hours, perhaps days ago —, a retry with its key
  is a `409` in words of its own (`این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو
  شروع کنید.`), not the «همین حالا» `409` of an order closed elsewhere a moment ago
  ([refusals and what to do](#refusals-and-what-to-do)): buying it again is a new attempt, with a new key.
- **Make a new one** for a new attempt: another plan, location or amount, another way to pay, or after a refusal.
- The same key for something else — another type, plan, server, service, top-up amount, or amount of extra traffic or
  time — is `422` on
  `idempotency_key` (`این Idempotency-Key پیش‌تر برای سفارش دیگری به کار رفته است.`), and so is the same key with another
  `method_id` (`این Idempotency-Key پیش‌تر با روش پرداخت دیگری به کار رفته است؛ برای پرداخت به روش دیگر کلید تازه‌ای
  بسازید.`): it would pay its order another way. A key missing or malformed is `422` on `idempotency_key` too, before
  the body is read.

The shop also finds the customer's open unpaid order of the same thing (same plan, location and price — one started in
the bot, say), so a new attempt does not make a duplicate either; after a price change it is a new order. Two caps hold
a customer's checkout: 30 ordering requests in 10 minutes — purchases, renewals, extras and top-ups together, retries
among them —, then `429`; and 5 unpaid orders open — the bot's among them —, past which a new one is `422` with no field
(`سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.`). Paying one, or letting it
expire, makes room; one whose receipt is with support — or whose card payment went to support's private chat — never
expires, so it waits for support's decision.

```ts
/** The same ordering request again — same body, same key — after a failure that may not have reached the shop. */
export async function retrying<T>(send: () => Promise<T>, tries = 3): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await send()
    } catch (e) {
      const transient = !(e instanceof StoreError) || e.status >= 500 // network failure, timeout, 5xx
      if (!transient || attempt === tries) throw e
      await new Promise((r) => setTimeout(r, 1500 * attempt))
    }
  }
}

const key = crypto.randomUUID() // this attempt's — another way to pay is another attempt, another key
const { checkout } = await retrying(() =>
  store<S['StoreCheckoutResponse']>('/orders', {
    method: 'POST',
    key,
    json: { plan_id: plan.id, server_id: location.id, method_id: method.id },
    signal: AbortSignal.timeout(90_000), // a wallet purchase waits for the VPN server to make the service
  }),
)
```

A `503` because the shop takes no orders is a 5xx too: the retry above meets it again until the bot is switched back on
(or an agent's suspended selling resumes) — show its message rather than retrying for long. After any other 5xx, retry with the same key, never a new one: a wallet
payment may have gone through before the failure (a `500` there can leave the order paid and `failed`, for support to
deliver), and only the same key finds it.

## A purchase

`POST /orders` request
```json
{ "plan_id": 3, "server_id": 1, "method_id": 1, "discount_code": "NOWRUZ" }
```

`discount_code` is optional — a code the customer typed ([discount codes](#discount-codes)).

`name_mode` and `client_name` are optional — how the new service is named on its panel. Left out, or `sequential`, by
the shop's pattern (`amir_3`, [Bot settings](Bot-Settings.md#service-usernames)). While the shop offers more —
`shop.name_modes` of `GET /` lists `random`, `custom` or both after `sequential` —, let the customer choose:

```json
{ "plan_id": 3, "server_id": 1, "method_id": 1, "name_mode": "custom", "client_name": "my-vpn" }
```

- `random` — a name of six letters and digits, worked out from the request's key as the order is made; the delivered
  service (`subscription.name`) says it.
- `custom` — `client_name`, the name the customer typed: 3 to 32 English letters, digits, `_` and `-`, one that
  server's panel takes (on a PasarGuard server, never two of `_` and `-` side by side) and no service on it has.

Made again with its key, the request asks the name its order was made with, and is not refused for what changed since.

A name the customer chose that a service — or a client made by hand on the panel — takes before the order is delivered
gives way to the pattern: show the delivered `subscription.name`, not the name sent.

`server_id` is one of the plan's `locations`, `method_id` one of `GET /payment-methods?for=purchase`. The checks run one
at a time, in this order, each a `422` on its field:

1. `plan_id` — `این پلن الان فروخته نمی‌شود.`: switched off, gone, more than an agent's traffic covers, or on no
   location that can deliver it now. Read the catalogue again.
2. `server_id` — `سرور را انتخاب کنید.` when missing; or that location does not sell it now while another of its
   locations does (`سرور #2 برای پلن «یک ماهه ۵۰ گیگ» در دسترس نیست.`): read the plan again (`GET /plans/{id}`) and let
   the customer pick from its `locations`.
3. `name_mode` — a way the shop does not offer (`نام رندوم برای سرویس در این فروشگاه فعال نیست.`): read `GET /` again.
   `client_name`, for `custom` — `نام کاربری سرویس باید بین ۳ تا ۳۲ کاراکتر و فقط از حروف و رقم انگلیسی، _ و - باشد.`,
   what the server's panel does not take (`روی این سرور دو علامت _ یا - پشت سر هم در نام کاربری سرویس نمی‌آید.`), or
   `این نام روی این سرور گرفته شده است.`: let the customer type another.
4. `method_id` — `روش پرداخت را انتخاب کنید.`, `این روش پرداخت برای این سفارش در دسترس نیست.`, or the wallet short of
   the price ([refusals and what to do](#refusals-and-what-to-do)).
5. `discount_code` — the code does not hold for it, worded for the customer ([discount codes](#discount-codes)).
6. `method_id` again, once the price is known — a way that does not take it: outside its bounds (`«کارت به کارت (ملت)»
   برای مبلغ‌های بین ۱۰٬۰۰۰ تومان و ۵۰۰٬۰۰۰ تومان است؛ روش دیگری انتخاب کنید.`), or a card-to-card way none of whose cards
   has room for it today (`«کارت به کارت (ملت)» امروز برای این مبلغ در دسترس نیست؛ روش دیگری انتخاب کنید.`). Nothing is
   left ordered — should no card take it after all as its payment is made, the order just made is cancelled at once, and
   the key is free for this way or another —; read the ways to pay for that amount again.

Then the unpaid-order cap of [the Idempotency-Key](#the-idempotency-key). A request made again with its key whose order
is no longer to be paid is answered by that order before any of these is asked (or refused on `idempotency_key`); one
whose order is still to be paid meets them again.

It is ordered at the plan's price for the customer — the lowest of `price`, its first-purchase price while they have
bought no service yet ([first-purchase prices](Store-API-Shop.md#first-purchase-prices)) and its agent price for an
agent or a colleague ([agent prices](Store-API-Shop.md#agent-prices)) —, less the customer's largest automatic
discount and then their code ([discounts](Store-API-Shop.md#discounts)), the wallet judged by what is left. Show the
checkout at the plan's `offer_price` from the customer's own read of it, with the plan's `checkout_note` under it, and
the answer's `order.amount` as what was charged — its `pricing` says which price it was, `discounts` what was taken off.

**A free plan** (`price` `"0.00"`, or a free first purchase, `first_purchase_price` `"0.00"`; a free test account is no
plan, and is taken in the bot alone) is taken, not paid: whichever way to pay `method_id` names, the shop's own wallet
settles it on the spot, taking nothing — no transfer, no line in the customer's wallet — and the answer is `settled`
with its service, its payment the wallet's at `"0.00"`. Show such a plan's checkout as one button («دریافت رایگان»),
sending the first of the ways to pay.

## A service the customer builds

While `shop.custom_services` of `GET /` is true, a customer may build a service of the traffic and days they choose
(«سرویس دلخواه», the main shop's alone — [Custom services](Custom-Services.md)) instead of picking a plan. Read what may
be built — anyone may:

`GET /custom-services` response
```json
{
  "custom_services": {
    "offered": true,
    "traffic": { "tiers": [{ "from": 1, "price": "3000.00" }, { "from": 50, "price": "2500.00" }], "min": 1, "max": 500, "options": [10, 50, 100] },
    "days": { "tiers": [{ "from": 1, "price": "2000.00" }, { "from": 30, "price": "1500.00" }], "min": 1, "max": 90, "options": [30, 60] },
    "devices": 2,
    "servers": [{ "id": 1, "name": "آلمان" }, { "id": 2, "name": "فرانسه" }]
  }
}
```

While `offered` is false — switched off, or none of its servers can take a client now — `servers` and every `tiers`
and `options` are empty. Offer the `options` as buttons and any whole amount from `min` to `max` typed. A quantity is
priced **whole** at the `price` of the last tier whose `from` it reaches — 49 GB at 3,000 a GB, 50 GB at 2,500 —, and
the GB's price and the days' are added; you may show that as the customer types. The customer's own price, with their
discount and — once they type one — a code, is a quote, nothing ordered:

`POST /custom-services/quote` request
```json
{ "gb": 50, "days": 30, "server_id": 1, "discount_code": "NOWRUZ" }
```

response
```json
{
  "quote": {
    "gb": 50, "days": 30, "devices": 2, "server_id": 1,
    "traffic_price": "125000.00", "days_price": "45000.00", "base": "170000.00",
    "discounts": [{ "source": "personal", "label": "تخفیف اختصاصی شما ۱۰٪", "amount": "17000.00", "percent": 10 }],
    "amount": "153000.00"
  }
}
```

`server_id` and `discount_code` are optional there. A built service is a purchase of no plan: only a sale of every plan
reaches it, and a code limited to certain plans never does (one limited to servers holds once `server_id` names one of
them). Then buy it — `server_id` may be left out while `servers` holds one:

`POST /custom-services/orders` request
```json
{ "gb": 50, "days": 30, "server_id": 1, "method_id": 1, "name_mode": "custom", "client_name": "my-vpn", "discount_code": "NOWRUZ" }
```

with its `Idempotency-Key`, answered as any checkout ([what a checkout answers](#what-a-checkout-answers)). Its order
has `plan` null and carries `traffic_bytes`, `days` and `devices` — its `package_name` says them in words, «سرویس دلخواه
· ۵۰ گیگ · ۳۰ روز» —; its delivered service has `plan` null too. `name_mode` and `client_name` name it as they name [a
purchase](#a-purchase)'s, on the server it is built on. The checks, each a `422` on its field:

1. `status` — `ساخت سرویس دلخواه الان در دسترس نیست.`: switched off, no tier, or none of its servers can take a client
   now. Read `GET /custom-services` again.
2. `gb`, `days` — no whole number (`تعداد گیگابایت را با عدد و بدون اعشار بنویسید.`), or outside the bounds
   (`حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.`, `مدت را عددی بین ۱ تا ۹۰ روز بنویسید.`).
3. `server_id` — `سرور را انتخاب کنید.` among several, or `این سرور برای سرویس دلخواه در دسترس نیست.`.
4. `name_mode` and `client_name`, `method_id`, then `discount_code`, as for [a purchase](#a-purchase).

The limits, the `409`s and the `503` are a purchase's; a request made again with its key is answered by its order.

## A renewal

A service is renewed on its own plan at that plan's price today — its agent price for an agent or a colleague
([agent prices](Store-API-Shop.md#agent-prices)). Show the renewal first, with the plan's `checkout_note` under it:

`GET /subscriptions/{id}/renewal` → `200`
```json
{
  "renewal": {
    "method": "extend",
    "methods": ["extend", "reset_traffic"],
    "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ", "description": null, "checkout_note": null, "price": "120000.00", "traffic_gb": 50, "duration_days": 30, "duration_hours": 720, "devices": 2 },
    "price": "108000.00",
    "discounts": [{ "source": "personal", "label": "تخفیف اختصاصی شما ۱۰٪", "amount": "12000.00", "percent": 10 }],
    "after": {
      "term": { "duration_days": 60, "duration_hours": 1440, "starts_at": "2026-09-20T10:00:00+00:00", "expires_at": "2026-11-19T10:00:00+00:00", "awaits_first_use": false },
      "traffic": { "limit_bytes": 107374182400, "used_bytes": 42949672960, "remaining_bytes": 64424509440 },
      "next_period": { "ends_at": "2026-10-20T10:00:00+00:00", "bytes": 53687091200 },
      "next_devices": null
    }
  }
}
```

`price` is what it costs the customer today — the plan's price for them less their largest automatic discount, which
`discounts` says (none, or one); a code is applied as it is paid. `after` is the service as the renewal would leave it, in a service's own shapes
([services](Store-API-After-Buying.md#services)), worked out from the shop's last copy of its server's numbers (the
renewal itself reads the server as it is paid). This one runs until 20 October with 10 GB left of 50: renewed, it ends on
19 November, its quota grows to 100 GB at once (60 GB left), and when the current period ends on 20 October what is
left is cut to at most 50 GB — the 10 GB of the old period go then (`next_period`), unless the shop carries unused
traffic, in which case `next_period` is `null`. The days left carry over: a running service gets the plan's days on top
of its end, one waiting for its first connection a longer term, an expired one a new term from its next connection —
its `after.term` then has `awaits_first_use: true`, and no `starts_at` nor `expires_at` until that connection.

**The renewal method.** `methods` are the ways the shop renews (its admin switches them on); `method` is the one this
preview is by — the first of them unless you ask for another with `?method=`:

| `method` | the bot calls it | the term | the traffic |
|---|---|---|---|
| `extend` | «تمدید پلن فعلی» | the plan's days on top of what is left | on top of the quota; the period's unused traffic as the shop carries it (above) |
| `reset_traffic` | «ریست حجم + افزایش روز» | the plan's days on top of what is left | counted afresh from the plan's: what was left is lost |
| `reset_time` | «ریست زمان + انتقال حجم» | the plan's, starting again now (`starts_at` now): the days left are lost | the plan's on top of what is left, whatever the shop carries |

**The devices.** `plan.devices` (or `custom.devices`) is the device limit the renewal gives, whatever the service has now
— the extra devices bought for its period count no more, nor an unlimited limit of its own. By `extend` and
`reset_traffic` a running service keeps its devices until the period in use ends (its end date, the same moment as
`next_period`'s) and has the plan's from then — `after.next_devices` says from when and how many, `null` when the limit
comes at once or stays as it is (the service's `next_devices` says it again once renewed); by `reset_time`, or for a
service ended by time or traffic, it has them at once; one still waiting for its first connection has them at once with
the extra devices bought for it on top.

With more than one, let the customer pick — the price is the same by each — and preview again with `?method=` to show
the `after` it leaves; send the one picked as `method` with the renewal (and with `POST /discount-codes/check`, for a
renewal). One the shop does not offer is `422` on `method` (`این روش تمدید در این فروشگاه نیست.`). With one, there is
nothing to pick and `method` may be left out. A request made again with its `Idempotency-Key` sends the same `method`.

Then `POST /subscriptions/{id}/renewal` `{ method_id, plan_id? | gb? + days?, method?, discount_code? }` with an `Idempotency-Key` (`GET /payment-methods?for=renewal`)
answers a checkout ([what a checkout answers](#what-a-checkout-answers)). Both refuse with `422` on `status`: `تمدید
این سرویس در حال حاضر ممکن نیست؛ با پشتیبانی در تماس باشید.` (switched off by support, gone from its server, its plan
renewing nothing, without a plan while neither a plan on sale is sold on its server nor the builder is offered, or — an
agent's shop — more than the agent's traffic), or `برای سرویس ali_1 یک تمدید در حال بررسی یا انجام است.` while another
renewal of it is with support or being delivered. The `POST` is refused as
`POST /orders` is, too ([refusals and what to do](#refusals-and-what-to-do)) — in an agent's shop whose selling is
suspended, a `503`, which the preview says as a `422` on `status` in the same words. Offer renewal while the service's
`renewable` is true — or, for a service without a plan, while its `picks_plan` is true (below).

**Not yet.** A shop may hold a running service back until it nears its end («آستانه مجاز تمدید»): its
`renewal_window` ([services](Store-API-After-Buying.md#services)) says whether it is `open`, and while it is not, what
opens it — `opens_at` (the moment its end date comes near enough), `days_before` (for one whose clock waits for its first
connection: so many days before the end date it will have), `traffic_percent` (once that share of its traffic or less
is left); whichever comes first, and with none of them its end. Until then the preview, the renewal and a code's check
are `422` on `status` saying from when: `تمدید این سرویس از ۲۳ مهر ۱۴۰۵، ۱۵:۳۰ یا وقتی ٪۲۰ از حجم یا کمتر مانده باشد
ممکن است.` Keep the renewal button and show the same words beside it. An ended service may always be renewed.

**A service without a plan** — one added by its link (`claimed_at`,
[adding a service by its link](Store-API-After-Buying.md#adding-a-service-by-its-link)), one built
([a service the customer builds](#a-service-the-customer-builds)) — is renewed on a plan
the customer picks among those on sale on its server, and that plan becomes its own: its next renewal, and «تمدید
خودکار», are on it — or [with a package they build](#renewing-with-a-package-built). List the choices first, each plan at
what renewing on it costs the customer today:

`GET /subscriptions/{id}/renewal-plans` → `200`
```json
{
  "plans": [
    { "id": 3, "name": "یک ماهه ۵۰ گیگ", "description": null, "checkout_note": null, "price": "120000.00", "renewal_price": "108000.00", "discounts": [{ "source": "personal", "label": "تخفیف اختصاصی شما ۱۰٪", "amount": "12000.00", "percent": 10 }], "traffic_gb": 50, "duration_days": 30, "duration_hours": 720, "devices": 2 }
  ],
  "custom_services": true
}
```

Then pass the one picked as `plan_id`: `GET /subscriptions/{id}/renewal?plan_id=3` previews it, and the `POST` body
carries it too (and `POST /discount-codes/check`'s, for a renewal). Without it both are `422` on `plan_id` (`یک پلن برای
تمدید این سرویس انتخاب کنید.`), and one not in the list is `422` on it too (`این پلن برای تمدید این سرویس در دسترس
نیست.`); while no plan on sale is sold on its server the list is empty — and, the builder not offered either, the
renewal is refused on `status`, as above. While the service cannot be renewed now — not at all, not yet (its window), or
a renewal of it under way — the list is empty and `custom_services` false: the renewal would be refused. A service with
its own plan lists that plan alone, and `custom_services` is false for it; `plan_id` may be left out for it, and sent it
must be that plan — another is `422` on `plan_id`, so a page drawn before the service's first renewal never pays for a
plan it did not show. A request made again with its `Idempotency-Key` sends the same `plan_id`: another one is `422` on
`idempotency_key`.

### Renewing with a package built

While `custom_services` of `GET …/renewal-plans` is true — the service has no plan, and the shop's builder is offered
(`shop.custom_services`) —, the customer may renew it with the traffic and days they choose instead, as the bot's «🛠
تمدید با سرویس دلخواه» does: read the bounds, buttons and tiers from `GET /custom-services` (its `servers` play no part —
a renewal makes no new client), then send `gb` and `days` in place of `plan_id`:
`GET /subscriptions/{id}/renewal?gb=50&days=30` previews it, and the `POST` body and `POST /discount-codes/check`'s (for
a renewal) carry them too:

```json
{
  "renewal": {
    "method": "extend",
    "methods": ["extend"],
    "plan": null,
    "custom": { "gb": 50, "days": 30, "devices": 2, "traffic_price": "125000.00", "days_price": "45000.00" },
    "price": "170000.00",
    "discounts": [],
    "after": { "…": "as above" }
  }
}
```

It is priced by the builder's tiers, discounted as a renewal of no plan — a sale for renewals of every plan, the
customer's own percent or tier, and a code not limited to certain plans (one limited to servers holds on the service's
own server) —, by any of the shop's `methods`, inside its window. It adds the package and gives the builder's `devices`
(from when, as a plan's: [a renewal](#a-renewal)). Its order has `plan` null and carries `traffic_bytes`, `days` and `devices`; the
service it renews **keeps having no plan**, so `picks_plan` stays true, its next renewal is picked again, and
`auto_renew.offered` stays false ([automatic renewal](Store-API-After-Buying.md#automatic-renewal)). The refusals, each a
`422`:

1. `status` — `ساخت سرویس دلخواه الان در دسترس نیست.` while the builder is not offered (read `GET …/renewal-plans`
   again), or the renewal's own refusals above.
2. `gb`, `days` — the builder's: no whole number, or outside its bounds (`حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.`);
   `gb` also for a service renewed on its own plan (`این سرویس با پلن خودش تمدید می‌شود.`).
3. `plan_id` — sent beside them (`برای تمدید یا یک پلن انتخاب کنید یا حجم و مدت دلخواه را، نه هر دو.`); and, neither sent
   while either may be, `یک پلن برای تمدید این سرویس انتخاب کنید، یا حجم و مدت دلخواه را.`.

A request made again with its `Idempotency-Key` sends the same `gb` and `days`: another package is `422` on
`idempotency_key`.

## Extra traffic, time and devices

«حجم، زمان و کاربر اضافه»: more traffic (whole GB), more time (whole days) or more devices at once (whole devices, «کاربر
اضافه» — the service's IP limit) for one of the customer's services, each bought on its own, on top of what the service
has — as the bot's «🔋 حجم اضافه», «⏳ زمان اضافه» and «👥 کاربر اضافه» sell them —, at its plan's own prices when it
has some, else the shop's. Offer each while the service's `extras.traffic`, `extras.time` or `extras.devices` is true
([services](Store-API-After-Buying.md#services)), and read what may be bought when the customer opens it:

`GET /subscriptions/{id}/extras` → `200`
```json
{
  "extras": {
    "traffic": {
      "price": "2000.00",
      "options": [
        { "quantity": 10, "price": "18000.00" },
        { "quantity": 50, "price": "100000.00" }
      ],
      "custom": { "min": 1, "max": 100 },
      "days": null
    },
    "time": {
      "price": "1000.00",
      "options": [
        { "quantity": 7, "price": "7000.00" },
        { "quantity": 30, "price": "25000.00" }
      ],
      "custom": { "min": 1, "max": 60 },
      "days": null
    },
    "devices": {
      "price": "18000.00",
      "options": [
        { "quantity": 1, "price": "18000.00" },
        { "quantity": 2, "price": "30000.00" }
      ],
      "custom": { "min": 1, "max": 5 },
      "days": 18
    }
  }
}
```

- `traffic` is counted in GB, `time` in days, `devices` in devices; a kind is `null` while none of it may be bought for
  this service — the shop sells none (it starts with none on sale), or the service cannot take it (below).
- `options` are the amounts offered as buttons, the smallest first, each with what it costs: a price of its own, or
  `price` — one GB's, one day's or one device's — times it.
- `days` — devices alone: the shop prices them for 30 days, and a service pays its share for the days it has left — to
  its end date (whole days, a part of one counting as one, at least 1), its whole term while it waits for its first
  connection, 30 for one that never ends. Every price of `devices` is already that share, rounded up to whole Toman
  (above: 30,000 a device and two at 50,000, for 18 of 30 days); say "for the 18 days left". A custom count costs its
  whole price prorated and rounded up once, so it may come a Toman or so under `price` times it — the checkout and the
  code check say it exactly. `null` for traffic and time.
- `custom`, while not `null`: any whole amount from `min` to `max` may be bought, at `price` each — show a field for it,
  and its cost as it is typed. `null`: the `options` alone.
- In an agent's shop, no more traffic than the agent still has to sell is offered: the amounts above it go, and
  `custom.max` comes down to it.

Which services take them: an active or ended one — not one support switched off, nor one gone from its server. Traffic
while it has a quota and its time has not run out — one whose traffic ran out runs again with it; time while it has a
term and its traffic has not run out — one whose time ran out starts again, its days counted from its next connection.
Devices while it has a device limit (its `devices` above 0) and neither its time nor its traffic has run out, on a
server whose panel enforces the limit — none on PasarGuard —; they last until the period in use ends (`days`), and a
renewal brings its plan's limit. One that ran out on both counts is a [renewal](#a-renewal)'s.

Then `POST /subscriptions/{id}/extra-traffic` `{ gb, method_id, discount_code? }`, `POST /subscriptions/{id}/extra-time`
`{ days, method_id, discount_code? }` or `POST /subscriptions/{id}/extra-devices`
`{ devices, method_id, discount_code? }`, with an `Idempotency-Key` (`GET /payment-methods?for=extra_traffic`,
`for=extra_time` or `for=extra_devices`; the wallet pays them as it pays a purchase):

`POST /subscriptions/{id}/extra-traffic` request
```json
{ "gb": 10, "method_id": 1 }
```

The amount is a whole number: a JSON number, or the text a customer typed (`"۱۰"` is fine). The checks run one at a
time, in this order, each a `422` on its field:

1. `status` — the service cannot take it: `این سرویس فعال نیست؛ با پشتیبانی در تماس باشید.` (switched off by support,
   gone from its server), `حجم این سرویس نامحدود است.`, `زمان این سرویس نامحدود است.`, `زمان این سرویس تمام شده است؛
   اول زمانش را بخرید یا سرویس را تمدید کنید.` (traffic or devices for one whose time ran out), `حجم این سرویس تمام شده
   است؛ اول حجمش را بخرید یا سرویس را تمدید کنید.` (time for one whose traffic ran out), `تعداد کاربر این سرویس نامحدود
   است.`, `سرور این سرویس تعداد کاربر را محدود نمی‌کند؛ کاربر اضافه برایش فروخته نمی‌شود.` (devices on PasarGuard).
2. `gb`, `days` or `devices` — none sold for this service now (`این فروشگاه برای این سرویس حجم اضافه نمی‌فروشد.`, or
   `زمان اضافه`, `کاربر اضافه`); no whole number (`تعداد گیگابایت را با عدد و بدون اعشار بنویسید.`, or `روز`, `کاربر`);
   or an amount not on offer, with
   what is (`این مقدار فروخته نمی‌شود؛ حجم اضافه بین ۱ تا ۱۰۰ گیگابایت بنویسید، یا یکی از این‌ها را بخرید: ۱۰ گیگابایت
   (۱۸٬۰۰۰ تومان)، ۵۰ گیگابایت (۱۰۰٬۰۰۰ تومان).`) — read the offer again.
3. `method_id` — as for a purchase.
4. `discount_code` — as for a purchase ([discount codes](#discount-codes)). What it costs is that amount's price less the
   customer's automatic discount (a sale by the service's plan), then the code.

Then the unpaid-order cap of [the Idempotency-Key](#the-idempotency-key); a request made again with its key whose order
is no longer to be paid is answered by that order before any of these is asked, whatever the prices say since.

The answer is a checkout ([what a checkout answers](#what-a-checkout-answers)) of an order of type `extra_traffic`,
`extra_time` or `extra_devices`, its `traffic_bytes`, `days` or `devices` what it brings. Paid from the wallet, it is
`settled` with the service as it now stands in `subscription`: its quota grown by the GB — counted on from what it used,
where that ran past the quota —, its end moved by the days — or, its time run out, a new term from its next connection
(`awaits_first_use` true) —, or its `devices` grown by those bought. By
card, the extra is added once support approves the receipt. As it is added the server is read again: a service switched
off since, or run out on the other count, fails the order, for support to finish or give the money back.

## Discount codes

A code the customer types («کد تخفیف») goes with the ordering request as `discount_code` — any case, Persian digits too —
on a purchase, a renewal, extra traffic, time or devices (never a top-up). It is applied to what the customer's automatic
discount left ([discounts](Store-API-Shop.md#discounts)): a percent (held to its cap), a fixed number of Toman, or the
whole amount — a code that takes it all is a free order, `settled` at `"0.00"`. It holds while it is the shop's,
switched on, inside its window (an "hourly" code ends at an hour), for that kind of order, plan and server (a renewal's
and an extra's: their service's), for a customer who has bought nothing when it says so, for an order of at least its
least, and while it has uses left — in all, and for this customer. Each refusal is a `422` on `discount_code`, worded for
the customer: show it under the code's field.

Quote it before the customer pays: `POST /discount-codes/check` — the same quote their order would be made at, nothing
ordered:

`POST /discount-codes/check` request
```json
{ "code": "nowruz", "for": "purchase", "plan_id": 3, "server_id": 1 }
```

`for` is `purchase` (`plan_id`, and `server_id` once a location is picked — a code for some servers alone is refused
until it is), `renewal` (`subscription_id`; for one without a plan the `plan_id` picked, or the `gb` and `days` of a
package built) or `extra_traffic` / `extra_time` / `extra_devices` (`subscription_id` and
`gb` / `days` / `devices` — devices at their share for the days the service has left, as `GET …/extras` shows them).

`POST /discount-codes/check` → `200`
```json
{
  "quote": {
    "base": "120000.00",
    "discounts": [
      { "source": "sale", "label": "فروش ویژه نوروز ۲۰٪", "amount": "24000.00", "percent": 20 },
      { "source": "code", "label": "کد NOWRUZ", "amount": "9600.00", "percent": 10 }
    ],
    "amount": "86400.00"
  }
}
```

A code that does not hold is a `422` on `code`, worded for the customer; the rest of the request is refused as the
checkout's own would be (`plan_id`, `server_id`, `status`, `gb`, `days`, `devices`, a `404` for a service not theirs) —
in an agent's shop whose selling is suspended, a renewal or an extra on `status` where the checkout answers `503`.

- **Its uses.** A use is an order made with the code that is not cancelled. An order nobody pays gives its use back as it
  is cancelled or expires — an order made with a code expires once the shop's hold is over (60 minutes by default, set
  by the shop), sooner than any other unpaid order. The customer's own order still to be paid is not counted against
  them while they come back to the same checkout; the last use is taken as the order is made, so of two customers
  paying at once one gets it — the other's request is refused on `discount_code`, nothing ordered.
- **Guessing.** Each code that does not hold — here and on the ordering requests — costs one of the customer's 20 an
  hour; then a `429` with `Retry-After`, whatever is typed.
- **Made again.** A request made again with its `Idempotency-Key` is answered by its order, whatever the code says now.
- **What the order says.** `discount_amount` (Toman taken off), `discounts` (each line, the automatic one first) and
  `discount_code` (`{ id, code }`, `null` for none) — on the checkout's answer and every order read.

## A wallet top-up

`POST /wallet/top-up` request
```json
{ "amount": "50000.00", "method_id": 2 }
```

`amount` is whole Toman: a JSON number, the text a customer typed (`"۵۰٬۰۰۰ تومان"` is fine), or an amount as this API
writes one — `GET /wallet`'s presets as they are (`"50000.00"`; a fraction other than `.00` is refused). It must be
within `GET /wallet`'s `top_up.min` and `top_up.max` (`422` on `amount`, e.g. `مبلغ شارژ باید بین ۱۰٬۰۰۰ تومان و
۵۰۰٬۰۰۰٬۰۰۰ تومان باشد.`). Any way to pay but the wallet that takes the amount (`GET
/payment-methods?for=wallet_topup&amount=…`; the wallet is `422` on `method_id`, `کیف پول با خودش شارژ نمی‌شود؛ روش دیگری
انتخاب کنید.`); once paid, the amount lands on the balance.

Paid by card, a top-up may ask a few Toman more than `amount`, at random — while the shop adds such a sum to card-to-card
top-ups, so each transfer is a different amount and its receipt is told apart: `transfer.amount` (and the payment's
`amount`) is then, say, `"50037.00"` while `order.amount` stays `"50000.00"`. Show `transfer.amount` as what to transfer;
the whole of it lands on the balance once approved.

## What a checkout answers

Every ordering request answers `{ "checkout": { outcome, order, transfer, subscription } }`:

| `outcome` | `order.status` | Meaning | The site |
|---|---|---|---|
| `settled` | `fulfilled` | paid from the wallet and delivered | show `subscription` — for a purchase the new service, for a renewal the renewed one, for extra traffic, time or devices the service with it: its link and a QR code of it |
| `settled` | `failed` | paid, but the delivery failed; support delivers it (the money is not lost) | "Paid — support is finishing your order", with a link to the order |
| `transfer` | `pending` | pay by card: `transfer` says where | show the card and the receipt upload for `transfer.payment_id` ([the receipt](#card-transfer-and-the-receipt)) |
| `processing` | `paid` or `processing` | paid, its delivery under way | read `GET /orders/{id}` every few seconds until `fulfilled` or `failed` |

A request made again with its key answers its order as it stands now, so it may also be `settled` with a card order
since approved, or with `refunded` — or the `409` of [the Idempotency-Key](#the-idempotency-key), once the order was
cancelled; go by `order.status`. A `transfer` whose payment already has its receipt (`awaiting_review`) means it is with
support. Support's own notes on an order are never in the answer.

`POST /orders` → `200`
```json
{
  "checkout": {
    "outcome": "settled",
    "order": {
      "id": 51,
      "type": "purchase",
      "status": "fulfilled",
      "amount": "120000.00",
      "pricing": "regular",
      "discount_amount": "0.00",
      "discounts": [],
      "discount_code": null,
      "created_at": "2026-10-07T12:00:00+00:00",
      "fulfilled_at": "2026-10-07T12:00:03+00:00",
      "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
      "package_name": "یک ماهه ۵۰ گیگ",
      "server": { "id": 1, "name": "آلمان" },
      "subscription": { "id": 17, "name": "ali_2" },
      "traffic_bytes": null,
      "days": null,
      "payments": [
        {
          "id": 88,
          "method": { "id": 1, "label": "کیف پول", "kind": "instant", "min_amount": null, "max_amount": "5000000.00" },
          "status": "paid",
          "amount": "120000.00",
          "created_at": "2026-10-07T12:00:00+00:00",
          "paid_at": "2026-10-07T12:00:00+00:00",
          "receipt": null,
          "note": null
        }
      ]
    },
    "transfer": null,
    "subscription": {
      "id": 17,
      "name": "ali_2",
      "status": "active",
      "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
      "server": { "id": 1, "name": "آلمان" },
      "link": "https://sub.example.net/sub/8f1c2e7a9b0d",
      "traffic": { "limit_bytes": 53687091200, "used_bytes": 0, "remaining_bytes": 53687091200 },
      "term": { "duration_days": 30, "duration_hours": 720, "starts_at": null, "expires_at": null, "awaits_first_use": true },
      "auto_renew": { "on": false, "offered": true, "days_before": 2 },
      "renewable": true,
      "picks_plan": false,
      "extras": { "traffic": true, "time": true },
      "link_rotation": true,
      "next_period": null,
      "next_devices": null,
      "presence": null,
      "synced_at": "2026-10-07T12:00:03+00:00",
      "created_at": "2026-10-07T12:00:03+00:00"
    }
  }
}
```

`POST /orders` → `200`
```json
{
  "checkout": {
    "outcome": "transfer",
    "order": {
      "id": 52,
      "type": "purchase",
      "status": "pending",
      "amount": "120000.00",
      "pricing": "regular",
      "created_at": "2026-10-07T12:10:00+00:00",
      "fulfilled_at": null,
      "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
      "package_name": "یک ماهه ۵۰ گیگ",
      "server": { "id": 1, "name": "آلمان" },
      "subscription": null,
      "traffic_bytes": null,
      "days": null,
      "payments": [
        {
          "id": 89,
          "method": { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual", "min_amount": "10000.00", "max_amount": null },
          "status": "pending",
          "amount": "120000.00",
          "created_at": "2026-10-07T12:10:00+00:00",
          "paid_at": null,
          "receipt": null,
          "note": null
        }
      ]
    },
    "transfer": {
      "payment_id": 89,
      "amount": "120000.00",
      "card": "6037997700001119",
      "holder": "محمد کریمی",
      "bank": "ملت",
      "instructions": "شماره پیگیری را نگه دارید.",
      "contact": null
    },
    "subscription": null
  }
}
```

A wallet purchase, renewal or extra made on the site sends no notice: this answer is its confirmation.

## Refusals and what to do

| Answer | Why | The site |
|---|---|---|
| `422` on `method_id`: `موجودی کیف پول کافی نیست؛ ۷۰٬۰۰۰ تومان کم است.` | the wallet (with an agent's credit) does not cover the price; nothing was ordered | offer a top-up or the card |
| `422` on `method_id`, other words | that way cannot pay this kind of order now, or it refused the payment | read the ways to pay again |
| `422` on `plan_id` | not on sale now | read the catalogue again (`GET /plans`, with the customer's token) |
| `422` on `server_id` | not on that location now, while another of the plan's sells it | read the plan again (`GET /plans/{id}`) and offer its `locations` |
| `422` on `amount` | a top-up outside the bounds | show the message |
| `422` on `gb`, `days` or `devices` | extra traffic, time or devices none of which is sold for the service now, an amount that is no whole number, or one not on offer | read the offer again (`GET /subscriptions/{id}/extras`) |
| `422` on `status` | a renewal not possible now, not yet (the shop's window: the message says from when), or one under way — or extra traffic, time or devices the service cannot take | show the message; link to the orders |
| `422` on `method` | a renewal method the shop does not offer (switched off since the page was drawn) | preview the renewal again and offer its `methods` |
| `422` on `idempotency_key` | a key reused for something else or with another `method_id`, or none | a new key for a new attempt — and look at `GET /orders` first, the earlier attempt may have gone through |
| `422`, no field: `سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.` | 5 unpaid orders open; nothing was ordered | link to the orders: pay one, or let it expire |
| `422` on `status`: `رسید سفارش #142 هنوز در حال بررسی پشتیبانی است؛ …` | a card while the shop takes one receipt at a time and the customer's last receipt (of that order) is still with support; nothing was ordered | show the message, link to that order; the wallet still pays |
| `409` `این سفارش همین حالا جای دیگری پرداخت یا بسته شد؛ وضعیت آن را در سفارش‌ها ببینید.` | the order was paid or closed elsewhere in the same moment; nothing was charged | show the orders |
| `409` `این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو شروع کنید.` | made again with its key, its order was cancelled since — by support, or unpaid for 48 hours; nothing was charged | a new attempt, with a new key |
| `429` `درخواست پرداخت زیادی فرستاده‌اید؛ ۸ دقیقه دیگر دوباره امتحان کنید.` | 30 ordering requests in 10 minutes | hold the pay button for `Retry-After` |
| `503` `فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.` | the shop takes no orders now (its bot switched off); nothing was ordered | say so; offer it again later |
| `503` `فروش این فروشگاه فعلا متوقف است؛ …` | an agent's shop whose selling the main shop suspended; nothing was ordered (a renewal's preview and a code's check say it as a `422` on `status`) | say so; the customer's services keep working, and a receipt for an earlier order is still taken |
| `404` | a service that is not this customer's | — |

`POST /orders` → `422`
```json
{
  "message": "موجودی کیف پول کافی نیست؛ ۷۰٬۰۰۰ تومان کم است.",
  "errors": { "method_id": ["موجودی کیف پول کافی نیست؛ ۷۰٬۰۰۰ تومان کم است."] },
  "request_id": "afacdaee073feb3f"
}
```

## Card transfer and the receipt

After a `transfer` outcome, show `transfer.card` grouped and left-to-right (`6037 9977 0000 1119`, with a copy button),
`holder`, `bank` when there is one, `amount` (with a copy button too, copying the digits alone — `120000` —, as a banking
app takes them) and `instructions` (the shop's note, `null` without one). The card is the payment's own: a shop with
several cards shows each payment one of them, picked at random, and the same one every time after — a request made
again with its key answers the same card, even if the shop switched it off since. Once the customer has paid, upload the
receipt for `transfer.payment_id`:

```ts
const form = new FormData()
form.append('file', file) // a JPEG, PNG or WebP picture of the receipt, 10 MB at most
if (note) form.append('note', note) // the customer's words for support, 1024 characters at most
const { order } = await store<S['StoreOrderResponse']>(`/payments/${transfer.payment_id}/receipt`, { method: 'POST', form })
// Do not set Content-Type: the browser adds multipart/form-data with its boundary.
```

The answer is the order, its payment now `awaiting_review` with `receipt.sent_at`:

`POST /payments/{id}/receipt` → `200`
```json
{
  "order": {
    "id": 52,
    "type": "purchase",
    "status": "pending",
    "amount": "120000.00",
    "pricing": "regular",
    "created_at": "2026-10-07T12:10:00+00:00",
    "fulfilled_at": null,
    "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
    "server": { "id": 1, "name": "آلمان" },
    "subscription": null,
    "traffic_bytes": null,
    "days": null,
    "payments": [
      {
        "id": 89,
        "method": { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual", "min_amount": "10000.00", "max_amount": null },
        "status": "awaiting_review",
        "amount": "120000.00",
        "created_at": "2026-10-07T12:10:00+00:00",
        "paid_at": null,
        "receipt": { "sent_at": "2026-10-07T12:16:41+00:00" },
        "note": null
      }
    ]
  }
}
```

The picture is judged by its bytes: JPEG, PNG or WebP — an iPhone's HEIC must be converted first (an `accept` of
`image/jpeg,image/png,image/webp` makes iOS do it) — and 10 MB at most. The host's PHP may cap uploads lower: a file past
its limit is `422` on `file` (`فایل بزرگ‌تر از حد مجاز سرور است.`), a form past its limit for a whole request `413`
(`درخواست بزرگ‌تر از حد مجاز است.`) — so shrink large photos before sending. Where the host's PHP has GD and can read
the picture, the shop keeps it as it writes it again: 2560 pixels on its longest side at most, turned the way the camera
held it, nothing of the file but its pixels — no EXIF, no location. Where it cannot (no GD, a picture GD does not read,
such as an animated WebP, or one too large for the memory PHP has left), the file is kept as it came, its metadata too:
take a photo's location out on the site when it matters. The kept copy is what support sees, and what a ticket's picture
comes back as ([the conversation](Store-API-Support-Tickets.md#the-conversation)): never count on getting back the exact
bytes you sent. Two pictures sent for one payment in the same moment: one is taken, the other refused in the state's
words.

Refusals: `404` for a payment that is not theirs; `422` on `status` when the payment awaits no receipt — one sent already
(`رسید این پرداخت رسیده و در انتظار بررسی است.`), refused (`رسید این پرداخت رد شده است.`), cancelled (`این پرداخت لغو
شده است.`), paid, or not a card transfer —, or while the shop takes one receipt at a time and another receipt of the
customer's is still with support (`رسید سفارش #142 هنوز در حال بررسی پشتیبانی است؛ …`, naming its order; the same
refuses a new card checkout), then on `file` (`تصویر رسید را بفرستید.`, `تصویر رسید باید JPG، PNG یا WebP
باشد.`, `حجم تصویر رسید حداکثر ۱۰ مگابایت می‌تواند باشد.`) and on `note` (`توضیح حداکثر 1024 کاراکتر است.`) together;
`503` while the shop takes no orders; `503` too while the server's disk is too full to take a picture (`فضای ذخیره سرور
پر شده و فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.`), or once the shop took 1 GB of pictures in a day —
receipts and tickets' pictures, its customers' and support's (`امروز بیش از این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر
دوباره بفرستید.`) — the picture's two said before the other fields are checked, so the same request may still be
refused for them later;
`429` past 10 pictures in an hour — the customer's receipts and their tickets' pictures together, one budget
([the tickets' limits](Store-API-Support-Tickets.md#limits)), a receipt counted once it passed its checks (`تصویر زیادی
فرستاده‌اید؛ …`).

### Card payments in support's private chat

A payment made while the shop takes card payments in support's private chat (the bot setting «هدایت پرداخت کارت به
کارت به پیوی پشتیبانی») is shown no card: its `transfer` has `card`, `holder` and `bank` `null`, and `contact` says where
to go — `text`, support's contact as the shop wrote it, and `url`, it as a link to open (`https://t.me/…` for a handle,
`tel:` for a phone number; `null` when it is none of these). Show the order's number, `amount` and `instructions` (the
bot shows the same note) and send the customer there with the order's number: support gives them the card, takes the
receipt in that chat and approves the payment in its panel — no upload here. `contact` is `null` whenever a card is
shown. A request made again answers the same payment — given a card, should the shop show cards again since.

```json
"transfer": {
  "payment_id": 89,
  "amount": "120000.00",
  "card": null,
  "holder": null,
  "bank": null,
  "instructions": "شماره پیگیری را نگه دارید.",
  "contact": { "text": "@amo_support", "url": "https://t.me/amo_support" }
}
```

## The review

Support reviews the receipt — in the panel or in the shop's Telegram group — always: the review window a card may have,
which accepts on its own a receipt sent in the bot that nobody looked at, never takes one uploaded from a website. Then:

- **Approved** — the payment is `paid` and the order delivered: `fulfilled` (a purchase's, a renewal's or extras'
  service in `subscription`, a top-up's amount on the balance) — or `failed` when the delivery failed, which support
  finishes. A `payment_settled` notice either way.
- **Refused** — the payment becomes `failed` with support's reason in its `note` (`null` when they gave none); the order
  stays `pending`. "Pay again" is a new attempt with a new key — the same order is found while its price stands, and a
  new `transfer` comes with a new `payment_id`. The customer gets a `payment_rejected` notice.
- **Cancelled** by support — the order is `cancelled` (`order_cancelled` notice).
- **Left alone** — an unpaid order with nothing happening for 48 hours is cancelled within the hour after, quietly — no
  notice: only `GET /orders/{id}` shows it. Its payments' `note` reads `پرداخت در مهلت کامل نشد` unless one already had
  a note (a refused receipt keeps support's reason), and a receipt uploaded for it is deleted. A receipt with support
  never expires.

To learn the outcome, read `GET /orders/{id}` while its page is open (every 15–30 seconds) until the order is
`fulfilled`, `failed` or `cancelled`, or watch `unread_notifications` in `GET /me`.
