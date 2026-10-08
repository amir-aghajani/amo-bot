# Store API: buying

A purchase, a renewal and a wallet top-up go through the shop's one checkout — the one the bot's purchases go through —
so the same rules answer on both doors. Paths are under the [base address](Store-API.md#the-base-address); every one
here takes the customer's bearer token.

## The flow

1. The customer is signed in.
2. Read the ways to pay that kind of order: `GET /payment-methods?for=purchase|renewal|wallet_topup`.
3. When they confirm, make the attempt's `Idempotency-Key`.
4. Send the order: `POST /orders`, `POST /subscriptions/{id}/renewal` or `POST /wallet/top-up`.
5. Act on the outcome: delivered, a card to pay, or a delivery under way ([what a checkout answers](#what-a-checkout-answers)).
6. For a card transfer, upload the receipt ([card transfer and the receipt](#card-transfer-and-the-receipt)) and wait
   for the review ([the review](#the-review)).

Nothing is ordered until the way to pay is picked: browsing plans and opening a checkout cost nothing. While the shop
takes no orders — `GET /`'s `shop.taking_orders` is `false`, its bot switched off — every ordering request and every
receipt is `503` (`فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.`), a request made again too.

## Ways to pay

`GET /payment-methods?for=purchase` → `200`
```json
{
  "methods": [
    { "id": 1, "label": "کیف پول", "kind": "instant" },
    { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual" }
  ]
}
```

In checkout order, as the bot offers them. `kind: "instant"` is the wallet — paid at once from the balance (an agent's
credit counting); `"manual"` is a card-to-card transfer and its receipt. A way of paying whose driver is no longer
installed is never offered (an earlier payment made with one shows it in its order with `kind: null`). The wallet is
never offered for a top-up. An empty list means the shop takes no way to pay that kind of order now. `for` is required: anything but the three kinds is `422` on
`for`.

## The Idempotency-Key

`POST /orders`, `POST /subscriptions/{id}/renewal` and `POST /wallet/top-up` must carry an `Idempotency-Key` header: 1
to 64 Latin letters, digits, `-` and `_` — a `crypto.randomUUID()`. The shop records each request's key with the order
it came to and the way to pay it was first sent with (`method_id`), for a week.

- **Make one per checkout attempt** — when the customer presses pay —, and a new one whenever they pick another way to
  pay: a key is tied to its `method_id`.
- **Send the same key again** — the same body, the same `method_id` — to retry the same request: a network error, a
  timeout, a `5xx`, a double click. It never makes a second order or a second charge; two sent in the same moment make
  one order and get one answer. What it answers depends on where that order stands:
  - **no longer to be paid** — paid, being delivered, delivered, failed, refunded, or its receipt with support: the
    order as it stands, whatever changed since (a price, the plan, the wallet);
  - **still to be paid** — a card transfer with no receipt yet, a wallet payment the wallet refused: the request is
    tried again on that same order, at the price it was made at — and today's rules are asked again first, so a plan
    switched off, a location or a way to pay no longer offered, or a top-up outside today's bounds is a `422` although
    the order exists (look at `GET /orders`);
  - **cancelled since** — see the next point.
- **Once that order was cancelled** — by support, or left unpaid for 48 hours, perhaps days ago —, a retry with its key
  is a `409` in words of its own (`این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو
  شروع کنید.`), not the «همین حالا» `409` of an order closed elsewhere a moment ago
  ([refusals and what to do](#refusals-and-what-to-do)): buying it again is a new attempt, with a new key.
- **Make a new one** for a new attempt: another plan, location or amount, another way to pay, or after a refusal.
- The same key for something else — another type, plan, server, service or top-up amount — is `422` on
  `idempotency_key` (`این Idempotency-Key پیش‌تر برای سفارش دیگری به کار رفته است.`), and so is the same key with another
  `method_id` (`این Idempotency-Key پیش‌تر با روش پرداخت دیگری به کار رفته است؛ برای پرداخت به روش دیگر کلید تازه‌ای
  بسازید.`): it would pay its order another way. A key missing or malformed is `422` on `idempotency_key` too, before
  the body is read.

The shop also finds the customer's open unpaid order of the same thing (same plan, location and price — one started in
the bot, say), so a new attempt does not make a duplicate either; after a price change it is a new order. Two caps hold
a customer's checkout: 30 ordering requests in 10 minutes — purchases, renewals and top-ups together, retries among
them —, then `429`; and 5 unpaid orders open — the bot's among them —, past which a new one is `422` with no field
(`سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.`). Paying one, or letting it
expire, makes room; one whose receipt is with support never expires, so it waits for support's decision.

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

A `503` because the shop takes no orders is a 5xx too: the retry above meets it again until the bot is switched back on —
show its message rather than retrying for long. After any other 5xx, retry with the same key, never a new one: a wallet
payment may have gone through before the failure (a `500` there can leave the order paid and `failed`, for support to
deliver), and only the same key finds it.

## A purchase

`POST /orders` request
```json
{ "plan_id": 3, "server_id": 1, "method_id": 1 }
```

`server_id` is one of the plan's `locations`, `method_id` one of `GET /payment-methods?for=purchase`. The checks run one
at a time, in this order, each a `422` on its field:

1. `plan_id` — `این پلن الان فروخته نمی‌شود.`: switched off, gone, more than an agent's traffic covers, or on no
   location that can deliver it now. Read the catalogue again.
2. `server_id` — `سرور را انتخاب کنید.` when missing; or that location does not sell it now while another of its
   locations does (`سرور #2 برای پلن «یک ماهه ۵۰ گیگ» در دسترس نیست.`): read the plan again (`GET /plans/{id}`) and let
   the customer pick from its `locations`.
3. `method_id` — `روش پرداخت را انتخاب کنید.`, `این روش پرداخت برای این سفارش در دسترس نیست.`, or the wallet short of
   the price ([refusals and what to do](#refusals-and-what-to-do)).

Then the unpaid-order cap of [the Idempotency-Key](#the-idempotency-key). A request made again with its key whose order
is no longer to be paid is answered by that order before any of these is asked (or refused on `idempotency_key`); one
whose order is still to be paid meets them again.

## A renewal

A service is renewed on its own plan at that plan's price today. Show the renewal first:

`GET /subscriptions/{id}/renewal` → `200`
```json
{
  "renewal": {
    "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ", "price": "120000.00", "traffic_gb": 50, "duration_days": 30 },
    "price": "120000.00",
    "after": {
      "term": { "duration_days": 60, "starts_at": "2026-09-20T10:00:00+00:00", "expires_at": "2026-11-19T10:00:00+00:00", "awaits_first_use": false },
      "traffic": { "limit_bytes": 107374182400, "used_bytes": 42949672960, "remaining_bytes": 64424509440 },
      "next_period": { "ends_at": "2026-10-20T10:00:00+00:00", "bytes": 53687091200 }
    }
  }
}
```

`after` is the service as the renewal would leave it, in a service's own shapes
([services](Store-API-After-Buying.md#services)), worked out from the shop's last copy of its server's numbers (the
renewal itself reads the server as it is paid). This one runs until 20 October with 10 GB left of 50: renewed, it ends on
19 November, its quota grows to 100 GB at once (60 GB left), and when the current period ends on 20 October what is
left is cut to at most 50 GB — the 10 GB of the old period go then (`next_period`), unless the shop carries unused
traffic, in which case `next_period` is `null`. The days left always carry over: a running service gets the plan's days
on top of its end, one waiting for its first connection a longer term, an expired one a new term from its next
connection — its `after.term` then has `awaits_first_use: true`, and no `starts_at` nor `expires_at` until that
connection.

Then `POST /subscriptions/{id}/renewal` `{ method_id }` with an `Idempotency-Key` (`GET /payment-methods?for=renewal`)
answers a checkout ([what a checkout answers](#what-a-checkout-answers)). Both refuse with `422` on `status`: `تمدید
این سرویس در حال حاضر ممکن نیست؛ با پشتیبانی در تماس باشید.` (switched off by support, gone from its server, its plan
deleted or renewing nothing, or — an agent's shop — more than the agent's traffic), or `برای سرویس ali_1 یک تمدید در حال
بررسی یا انجام است.` while another renewal of it is with support or being delivered. The `POST` is refused as
`POST /orders` is, too ([refusals and what to do](#refusals-and-what-to-do)). Offer renewal while the service's
`renewable` is true.

## A wallet top-up

`POST /wallet/top-up` request
```json
{ "amount": "50000.00", "method_id": 2 }
```

`amount` is whole Toman: a JSON number, the text a customer typed (`"۵۰٬۰۰۰ تومان"` is fine), or an amount as this API
writes one — `GET /wallet`'s presets as they are (`"50000.00"`; a fraction other than `.00` is refused). It must be
within `GET /wallet`'s `top_up.min` and `top_up.max` (`422` on `amount`, e.g. `مبلغ شارژ باید بین ۱۰٬۰۰۰ تومان و
۵۰۰٬۰۰۰٬۰۰۰ تومان باشد.`). Any way to pay but the wallet (`GET /payment-methods?for=wallet_topup`; the wallet is `422` on
`method_id`, `کیف پول با خودش شارژ نمی‌شود؛ روش دیگری انتخاب کنید.`); once paid, the amount lands on the balance.

## What a checkout answers

All three ordering requests answer `{ "checkout": { outcome, order, transfer, subscription } }`:

| `outcome` | `order.status` | Meaning | The site |
|---|---|---|---|
| `settled` | `fulfilled` | paid from the wallet and delivered | show `subscription` — for a purchase the new service, for a renewal the renewed one: its link and a QR code of it |
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
      "created_at": "2026-10-07T12:00:00+00:00",
      "fulfilled_at": "2026-10-07T12:00:03+00:00",
      "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
      "server": { "id": 1, "name": "آلمان" },
      "subscription": { "id": 17, "name": "ali_2" },
      "payments": [
        {
          "id": 88,
          "method": { "id": 1, "label": "کیف پول", "kind": "instant" },
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
      "term": { "duration_days": 30, "starts_at": null, "expires_at": null, "awaits_first_use": true },
      "auto_renew": { "on": false, "offered": true, "days_before": 2 },
      "renewable": true,
      "link_rotation": true,
      "next_period": null,
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
      "created_at": "2026-10-07T12:10:00+00:00",
      "fulfilled_at": null,
      "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
      "server": { "id": 1, "name": "آلمان" },
      "subscription": null,
      "payments": [
        {
          "id": 89,
          "method": { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual" },
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
      "instructions": "شماره پیگیری را نگه دارید."
    },
    "subscription": null
  }
}
```

A wallet purchase or renewal made on the site sends no notice: this answer is its confirmation.

## Refusals and what to do

| Answer | Why | The site |
|---|---|---|
| `422` on `method_id`: `موجودی کیف پول کافی نیست؛ ۷۰٬۰۰۰ تومان کم است.` | the wallet (with an agent's credit) does not cover the price; nothing was ordered | offer a top-up or the card |
| `422` on `method_id`, other words | that way cannot pay this kind of order now, or it refused the payment | read the ways to pay again |
| `422` on `plan_id` | not on sale now | read the catalogue again (`GET /plans`) |
| `422` on `server_id` | not on that location now, while another of the plan's sells it | read the plan again (`GET /plans/{id}`) and offer its `locations` |
| `422` on `amount` | a top-up outside the bounds | show the message |
| `422` on `status` | a renewal not possible now, or one under way | show the message; link to the orders |
| `422` on `idempotency_key` | a key reused for something else or with another `method_id`, or none | a new key for a new attempt — and look at `GET /orders` first, the earlier attempt may have gone through |
| `422`, no field: `سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.` | 5 unpaid orders open; nothing was ordered | link to the orders: pay one, or let it expire |
| `409` `این سفارش همین حالا جای دیگری پرداخت یا بسته شد؛ وضعیت آن را در سفارش‌ها ببینید.` | the order was paid or closed elsewhere in the same moment; nothing was charged | show the orders |
| `409` `این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو شروع کنید.` | made again with its key, its order was cancelled since — by support, or unpaid for 48 hours; nothing was charged | a new attempt, with a new key |
| `429` `درخواست پرداخت زیادی فرستاده‌اید؛ ۸ دقیقه دیگر دوباره امتحان کنید.` | 30 ordering requests in 10 minutes | hold the pay button for `Retry-After` |
| `503` `فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.` | the shop takes no orders now (its bot switched off); nothing was ordered | say so; offer it again later |
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
`holder`, `amount` and `instructions` (the shop's note, `null` without one). Once the customer has paid, upload the
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
    "created_at": "2026-10-07T12:10:00+00:00",
    "fulfilled_at": null,
    "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
    "server": { "id": 1, "name": "آلمان" },
    "subscription": null,
    "payments": [
      {
        "id": 89,
        "method": { "id": 2, "label": "کارت به کارت (ملت)", "kind": "manual" },
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
شده است.`), paid, or not a card transfer —, then on `file` (`تصویر رسید را بفرستید.`, `تصویر رسید باید JPG، PNG یا WebP
باشد.`, `حجم تصویر رسید حداکثر ۱۰ مگابایت می‌تواند باشد.`) and on `note` (`توضیح حداکثر 1024 کاراکتر است.`) together;
`503` while the shop takes no orders; `503` too while the server's disk is too full to take a picture (`فضای ذخیره سرور
پر شده و فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.`), or once the shop took 1 GB of pictures in a day —
receipts and tickets' pictures, its customers' and support's (`امروز بیش از این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر
دوباره بفرستید.`) — the picture's two said before the other fields are checked, so the same request may still be
refused for them later;
`429` past 10 pictures in an hour — the customer's receipts and their tickets' pictures together, one budget
([the tickets' limits](Store-API-Support-Tickets.md#limits)), a receipt counted once it passed its checks (`تصویر زیادی
فرستاده‌اید؛ …`).

## The review

Support reviews the receipt — in the panel or in the shop's Telegram group — always: the review window a card may have,
which accepts on its own a receipt sent in the bot that nobody looked at, never takes one uploaded from a website. Then:

- **Approved** — the payment is `paid` and the order delivered: `fulfilled` (a purchase's or a renewal's service in
  `subscription`, a top-up's amount on the balance) — or `failed` when the delivery failed, which support finishes. A
  `payment_settled` notice either way.
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
