# Store API: the shop

What anyone may read with the store key alone: the shop itself, what it sells now, and how its servers stand. Paths are
under the [base address](Store-API.md#the-base-address). Render these on your server and cache them briefly
([calling from your server](Store-API.md#calling-from-your-server)).

## What the site asks first

`GET /` — the shop's name and bot, whether it takes orders, how to reach support, the ways in, the captcha and the
referral program's terms.

`GET /` → `200`
```json
{
  "shop": {
    "name": "Amo Shop",
    "taking_orders": true,
    "bot": { "username": "amo_shop_bot", "url": "https://t.me/amo_shop_bot" }
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
agent's traffic. A shop without categories has this one group, or none when it sells nothing now.

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
          "price": "120000.00",
          "traffic_gb": 50,
          "duration_days": 30,
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
          "price": "450000.00",
          "traffic_gb": 0,
          "duration_days": 90,
          "devices": 0,
          "category_id": null,
          "locations": [{ "id": 2, "name": "هلند" }]
        }
      ]
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `price` | Toman |
| `traffic_gb` | gigabytes, decimals allowed; `0` unlimited |
| `duration_days` | the term, counted from the customer's **first connection**; `0` never ends |
| `devices` | devices at once; `0` no limit |
| `category_id` | the category it is listed under; `null` for the last group |
| `locations` | the servers it can be bought on today («لوکیشن» in the bot), in the plan's order — at least one; the customer picks one, which becomes `server_id` at checkout |

`GET /plans/{id}` answers `{ "plan": … }`, one plan in the same shape, or `404` for one the shop does not sell now
(switched off, on no server able to deliver it, or more than an agent's traffic covers). Prices and locations change
with the shop: cache briefly (a minute) and read again before a checkout.

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
