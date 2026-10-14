# Store API: the shop

What anyone may read with the store key alone: the shop itself, what it sells now, and how its servers stand. Paths are
under the [base address](Store-API.md#the-base-address). Render these on your server and cache them briefly
([calling from your server](Store-API.md#calling-from-your-server)) — but the catalogue read with a customer's token,
which is theirs ([read signed in](#read-signed-in)).

## What the site asks first

`GET /` — the shop's name and bot, whether it takes orders and services by their link, whether customers may hand a
service on or build one, how they may name a new one, how to reach support, the ways in, the captcha and the referral
program's terms.

`GET /` → `200`
```json
{
  "shop": {
    "name": "Amo Shop",
    "bot": { "username": "amo_shop_bot", "url": "https://t.me/amo_shop_bot" },
    "taking_orders": true,
    "service_claims": false,
    "service_transfers": false,
    "custom_services": true,
    "name_modes": ["sequential", "random", "custom"]
  },
  "support": { "url": "https://t.me/amo_support" },
  "sign_in": {
    "telegram": { "client_id": "7012345678", "redirect": true },
    "google": { "client_id": "1234567890-abc123def456.apps.googleusercontent.com" },
    "email": true
  },
  "captcha": { "driver": "turnstile", "site_key": "0x4AAAAAAAAbcdEfGhIjKlMn", "challenge_url": null },
  "referral": { "enabled": true, "rate": 10, "first_only": false }
}
```

- `shop.name` — the main shop's name, or an agent's bot's title (else its @username, else the main shop's name).
  `shop.bot` is `null` while the bot's @username is unknown.
- `shop.taking_orders` — whether the shop takes orders now: its bot switched on. While it is `false`, a purchase, a
  renewal, a top-up and a receipt are refused (`503` `فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.`); what it
  sells is still read, and customers still sign in. Say so where the site sells.
- `shop.service_claims` — whether a customer may add a service to their account by its link now (it stays so while
  `taking_orders` is false: adding a service is no order)
  ([adding a service by its link](Store-API-After-Buying.md#adding-a-service-by-its-link)): the main shop's alone, while
  its admin switches it on. Show «افزودن سرویس» among the customer's services while it is `true`.
- `shop.service_transfers` — whether a customer may hand one of their services to another customer of the shop now
  ([handing a service to another customer](Store-API-After-Buying.md#handing-a-service-to-another-customer)): in any
  shop, while its admin switches it on. Show «انتقال سرویس» on a service while it is `true`.
- `shop.custom_services` — whether a customer may build a service of the traffic and days they choose now
  ([a service the customer builds](Store-API-Buying.md#a-service-the-customer-builds)): the main shop's alone, while its
  admin switches it on, both price lists sell and one of its servers can take a new client; always `false` in an agent's
  shop. Show «ساخت سرویس دلخواه» beside the plans while it is `true`.
- `shop.name_modes` — the ways a buyer may name their new service on its panel: `sequential` (the shop's pattern) always
  first, then `random` and `custom` while the shop's admin offers them. With `sequential` alone, ask nothing; else let
  the customer choose at the checkout ([a purchase](Store-API-Buying.md#a-purchase)).
- `support.url` — the bot's «پشتیبانی» contact as a link: a `https://t.me/…` address, a web address or a `tel:` number;
  `null` when none is set or it is plain words.
- `sign_in` — [which ways in](Store-API-Sign-In.md#which-ways-in). `captcha` — `null`, or the captcha the website's
  forms ask and how to draw it ([the captcha](Store-API-Sign-In.md#the-captcha)).
- `referral` — `rate` percent of what a referred customer pays, or of their first payment alone with `first_only`
  ([the referral program](Store-API-After-Buying.md#the-referral-program)).

## What it sells

`GET /plans` is exactly what the bot's «خرید اشتراک» offers now: every active category in the bot's order — an empty one
too — then the plans of no category (or of one switched off) as a last group with `"category": null`. A plan is listed
only while it can be delivered: switched on, on a server able to sell it, and — in an agent's shop — covered by the
agent's traffic. A shop without categories has this one group, or none when it sells nothing now. Read with a customer's
token, it is what the bot offers that customer ([read signed in](#read-signed-in)).

`GET /plans` → `200`
```json
{
  "groups": [
    {
      "category": { "id": 1, "name": "یک ماهه" },
      "plans": [
        {
          "id": 3,
          "name": "یک ماهه ۵۰ گیگ",
          "description": "مناسب استفاده روزانه",
          "checkout_note": "روی Happ و v2rayNG تست شده است.",
          "price": "120000.00",
          "first_purchase_price": "90000.00",
          "agent_price": null,
          "offer_price": "72000.00",
          "discount": { "source": "sale", "label": "فروش ویژه نوروز ۲۰٪", "percent": 20, "ends_at": "2026-03-21T20:30:00+00:00" },
          "traffic_gb": 50,
          "duration_days": 30,
          "duration_hours": 720,
          "devices": 2,
          "category_id": 1,
          "locations": [{ "id": 1, "name": "آلمان" }, { "id": 2, "name": "هلند" }]
        }
      ]
    },
    {
      "category": null,
      "plans": [
        {
          "id": 7,
          "name": "نامحدود سه ماهه",
          "description": null,
          "checkout_note": null,
          "price": "450000.00",
          "first_purchase_price": null,
          "agent_price": null,
          "offer_price": "450000.00",
          "discount": null,
          "traffic_gb": 0,
          "duration_days": 90,
          "duration_hours": 2160,
          "devices": 0,
          "category_id": null,
          "locations": [{ "id": 2, "name": "هلند" }]
        },
        {
          "id": 9,
          "name": "تست یک ساعته",
          "description": "برای اولین آشنایی",
          "checkout_note": null,
          "price": "5000.00",
          "first_purchase_price": "0.00",
          "agent_price": null,
          "offer_price": "0.00",
          "discount": null,
          "traffic_gb": 1,
          "duration_days": 1,
          "duration_hours": 1,
          "devices": 1,
          "category_id": null,
          "locations": [{ "id": 1, "name": "آلمان" }]
        }
      ]
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `checkout_note` | a note to show with it at checkout («یادداشت پیش‌فاکتور» — the apps it was tested on, a caveat), plain text: escape it, keep its line breaks; `null` for none |
| `price` | Toman |
| `first_purchase_price` | what a customer pays for it as their first purchase in the shop («قیمت خرید اول»), in Toman — below `price`, `"0.00"` a free first purchase; `null` for none, or — read with a customer's token — once they have bought a service ([first-purchase prices](#first-purchase-prices)) |
| `agent_price` | what the shop's agents and its colleagues pay for it («قیمت نماینده»), in Toman — below `price`; `null` for none, and for anyone else: a guest, a customer who is neither ([agent prices](#agent-prices)) |
| `offer_price` | what the reader pays for it today, in Toman: the lowest of its prices that is theirs, less their largest automatic discount; `price`'s own while there is none. Show this as the price ([discounts](#discounts)) |
| `discount` | the automatic discount `offer_price` took — `source` (`sale`, `personal` or `tier`), `label` (the customer's words: «فروش ویژه نوروز ۲۰٪», «تخفیف اختصاصی شما ۱۰٪», «سطح طلایی ۵٪»), `percent`, and a sale's `ends_at` (else `null`) —; `null` for none |
| `traffic_gb` | gigabytes, decimals allowed; `0` unlimited |
| `duration_hours` | the term, exactly, in hours — counted from the customer's **first connection**; `0` never ends. Plans may be sold by the hour from this release (a one-hour test); a plan of whole days says its days × 24 |
| `duration_days` | the same term in whole days, rounded up — a one-hour plan says `1`; `0` never ends. Kept for the sites made before `duration_hours`: show the term from `duration_hours` |
| `devices` | devices at once; `0` no limit |
| `category_id` | the category it is listed under; `null` for the last group |
| `locations` | the servers it can be bought on today («لوکیشن» in the bot), in the plan's order — at least one; the customer picks one, which becomes `server_id` at checkout |

`GET /plans/{id}` answers `{ "plan": … }`, one plan in the same shape, or `404` for one the shop does not sell now
(switched off, on no server able to deliver it, or more than an agent's traffic covers). Prices and locations change with
the shop: cache briefly (a minute) and read again before a checkout.

### Read signed in

`GET /plans` and `GET /plans/{id}` are anyone's, with or without a token. Sent with a customer's bearer token, they
answer the catalogue as that customer is offered it — as the bot offers it to them: its first-purchase prices, `null`
once they have bought, and its agent prices, `null` but for an agent or a colleague. A token that opens no session — expired, signed out, of
another shop — is the `401` of any customer endpoint (`WWW-Authenticate: Bearer`), never the guests' catalogue in its
place: forget the token, as anywhere ([tokens and sessions](Store-API-Sign-In.md#tokens-and-sessions)), and read the
catalogue again without it. A banned customer's is `403`. Cache only what you read without a token; a customer's own
read is theirs alone.

### First-purchase prices

A plan may have a price of its own for a customer's first purchase, `first_purchase_price` («قیمت خرید اول»): what a
customer who has never bought a service in the shop pays for it — a purchase of theirs paid at some point, whatever came
of it since (delivered, failed, refunded), is one; an order never paid, or cancelled unpaid, is none, and neither is a
wallet top-up. Every plan is sold to everyone: only what it costs differs. Each shop counts its own customers'.

- **A guest** reads it on every plan that has one: show it as the price of a first purchase, with `price` beside it —
  `"0.00"` is a free first purchase.
- **A customer who has not bought** reads it signed in, as a guest does, and `POST /orders` charges it
  ([a purchase](Store-API-Buying.md#a-purchase)); the order's `amount` is what it was made at.
- **One who has** reads `null` on every plan: they pay `price`. A request made again with its `Idempotency-Key` still
  answers the order it made, at its own amount.
- **Two at once** — both ordered at the first-purchase price before either was paid —: the one paid first is their
  first purchase, and the other is not delivered (the order `failed`, for support to give the money back).
- **Its service renews at `price`**, by its customer or by itself ([a renewal](Store-API-Buying.md#a-renewal)) — at
  `agent_price` for an agent or a colleague.

### Agent prices

A plan may have a price for the shop's resellers, `agent_price` («قیمت نماینده»): what an agent of the shop pays for it,
and a customer of a group the shop marks «همکار» — a colleague. It is `null` but for them: a guest never reads it, and
neither does a customer who is neither.

- **Read signed in**, an agent's or a colleague's `agent_price` is what they pay: show it as the plan's price for them,
  with `price` beside it. `POST /orders` charges it, and their renewals are at it, by them or by themselves.
- **With a first-purchase price** — a colleague who has bought nothing yet reads both, and is charged the lower.
- **The order says** at which price it was made: `pricing` — `regular`, `first_purchase` or `agent`
  ([what a checkout answers](Store-API-Buying.md#what-a-checkout-answers)).

### Discounts

What a customer pays is priced in three steps, by the shop's one rule (the bot's too):

1. **The base** — the lowest of the plan's prices that is theirs (`price`, `first_purchase_price`, `agent_price`).
2. **The largest automatic discount** on it — a timed sale on the plan («فروش ویژه»), the customer's own percent
   («تخفیف اختصاصی», set by support) or their loyalty tier's —, never two added together. `offer_price` is the base less
   it, and `discount` says which it was. A guest reads the sales alone; read signed in, the customer's own and their
   tier's count too. A sale ends at `discount.ends_at`: read the catalogue again after it.
3. **A discount code** the customer types, on what step 2 left — at checkout, `discount_code`
   ([discount codes](Store-API-Buying.md#discount-codes)); `POST /discount-codes/check` quotes it before they pay.

A wallet top-up is never discounted. A renewal and extra traffic, time or devices get the same automatic discount (a sale
by the service's plan) and take a code too.

## How its servers stand

`GET /status` — the servers of the shop's plans that are switched on, in the servers' order — never their addresses,
their connectors or what their panels said. A plan switched on that `GET /plans` leaves out (it can be delivered
nowhere, or an agent's traffic does not cover it) still brings its servers here.

`GET /status` → `200`
```json
{
  "servers": [
    { "id": 1, "name": "آلمان", "available": true, "checked_at": "2026-10-08T09:30:12+00:00" },
    { "id": 2, "name": "هلند", "available": true, "checked_at": "2026-10-08T09:30:09+00:00" },
    { "id": 3, "name": "فرانسه", "available": false, "checked_at": "2026-10-08T09:29:58+00:00" }
  ]
}
```

`available` — the server itself takes a new customer now (switched on, checked and serving subscription links, with
room); a server whose last contact failed is still available, as the bot still sells on it. It says nothing of a plan:
whether a plan can be bought on a server is its `locations` in `GET /plans`, the one word on that. `checked_at` — when
the shop last talked to it, `null` for never.
