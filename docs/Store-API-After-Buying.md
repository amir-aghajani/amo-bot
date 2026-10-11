# Store API: after buying

A signed-in customer's services, orders and payments, wallet and referral program — the customer's own alone: another
customer's service or order is a `404`, as one that never was. Paths are under the
[base address](Store-API.md#the-base-address); every one here takes the customer's bearer token.

## Services

`GET /subscriptions?status=&page=` lists the customer's services, newest first (`status`: `active`, `expired`,
`disabled`, `deleted`; anything else is every one); `GET /subscriptions/{id}` answers `{ "subscription": … }`. Both
show the service as the shop last read it from its server (`synced_at`; the shop reads every running service about
every 15 minutes).

`GET /subscriptions/{id}` → `200`
```json
{
  "subscription": {
    "id": 9,
    "name": "ali_1",
    "status": "active",
    "plan": { "id": 3, "name": "یک ماهه ۵۰ گیگ" },
    "server": { "id": 2, "name": "هلند" },
    "link": "https://sub.example.net/sub/3d9e6b1f0a72",
    "traffic": { "limit_bytes": 107374182400, "used_bytes": 42949672960, "remaining_bytes": 64424509440 },
    "term": { "duration_days": 60, "duration_hours": 1440, "starts_at": "2026-09-20T10:00:00+00:00", "expires_at": "2026-11-19T10:00:00+00:00", "awaits_first_use": false },
    "auto_renew": { "on": true, "offered": true, "days_before": 2 },
    "renewable": true,
    "extras": { "traffic": true, "time": true },
    "link_rotation": true,
    "next_period": { "ends_at": "2026-10-20T10:00:00+00:00", "bytes": 53687091200 },
    "presence": null,
    "synced_at": "2026-10-08T09:30:12+00:00",
    "created_at": "2026-09-20T09:58:40+00:00"
  }
}
```

| Field | Meaning |
|---|---|
| `name` | its name on the server (`ali_1`, `USER_7`) — what the customer quotes to support |
| `status` | `active` running (or waiting for its first connection); `expired` its time or traffic ran out; `disabled` switched off by support; `deleted` gone from its server |
| `plan` | the plan it was sold or last renewed on; `null` once that plan was deleted |
| `server` | where it is («لوکیشن») |
| `link` | the one subscription link the customer adds to their app — show it with a copy button and a QR code the site draws; `null` once `deleted` |
| `traffic` | `limit_bytes` (`0` unlimited), `used_bytes`, `remaining_bytes` (`null` when unlimited) |
| `term` | `duration_hours` the term exactly, in hours (`0` never ends — a one-hour test says `1`, a month `720`), `duration_days` the same in whole days, rounded up (kept for the sites made before `duration_hours`), `starts_at` (the first connection), `expires_at`, `awaits_first_use` |
| `auto_renew` | `on` the customer's switch, `offered` whether it may be set, `days_before` how many days before its end the wallet renews it — the shop's rule, one for every service ([automatic renewal](#automatic-renewal)) |
| `renewable` | it may be renewed now ([a renewal](Store-API-Buying.md#a-renewal)): active or ended, its plan still there and renewing something — and in an agent's shop covered by their traffic |
| `extras` | `traffic` and `time`: whether extra traffic, and extra time, may be bought for it now ([extra traffic and time](Store-API-Buying.md#extra-traffic-and-time)) — the shop sells it, and the service can take it: traffic while it has a quota and its time has not run out, time while it has a term and its traffic has not run out |
| `link_rotation` | its link may be changed now ([a new link](#a-new-link)): it runs, and its server can give it a new one |
| `next_period` | a renewal queued behind the period in use: when that period ends, and the most that is left then ([a renewal](Store-API-Buying.md#a-renewal)); `null` for none |
| `presence` | whether it is connected — only in `POST …/refresh`'s answer, else `null` |

**The term starts at the first connection.** A new service has `awaits_first_use: true`, `starts_at` and `expires_at`
`null`: show "starts on first connection — 30 days" (or "— 1 hour": word the term from `duration_hours`, which an hourly
plan's services have since this release). Once it connects, `expires_at` is its end.

## Reading a service now

`POST /subscriptions/{id}/refresh` reads the service from its server now (no body) and answers it with `presence`, e.g.
`"presence": { "online": true, "last_online_at": "2026-10-08T09:41:02+00:00" }` (`online` is `null` when the server
cannot tell). A service the server no longer has comes back `deleted`, its `link` `null`. `502` `اطلاعات این سرویس الان
از سرور خوانده نشد؛ کمی بعد دوباره تلاش کنید.` means the server cannot be read right now — out of reach, or left alone
for a few minutes after it failed: keep showing the copy you have with its `synced_at`. Call it when the service's page
opens or on a button — not in a loop: a customer makes 10 such requests a minute at most, a refused or failed one
counted too, then `429` (`اطلاعات سرویس را زیاد به‌روز کرده‌اید؛ …`).

## Automatic renewal

`PATCH /subscriptions/{id}` `{ "auto_renew": true }` or `false` (a JSON boolean) answers `{ "subscription": … }`. Offered
(`auto_renew.offered`) while the service runs, ends some day, still has its plan — one whose term is at least the
`days_before` it is renewed in: a plan of a few hours is not —, and the shop takes the wallet;
otherwise — turning it off too — `422` on `auto_renew` (`تمدید خودکار برای این سرویس در دسترس نیست.`), and anything but
a boolean is `422` on it too. With it on, the shop renews the service from the wallet `auto_renew.days_before` days
before its end, on its plan at that plan's price. The customer hears of it: `auto_renewed`, `auto_renew_short` (the
wallet does not cover it — said once, and tried again until it does), or `renewal_failed`.

## A new link

`POST /subscriptions/{id}/rotate-link` gives the service a new link (no body) and answers it; every device on the old
link is cut off — ask the customer to confirm first. Offer it while `link_rotation` is true. `422` on `status` (`لینک
فقط برای سرویس فعال عوض می‌شود.`, `سرور این سرویس تغییر لینک را پشتیبانی نمی‌کند.`); `409` `این سرویس همین حالا در حال
تغییر است؛ چند لحظه بعد دوباره تلاش کنید.` — try again in a moment; `502` (`لینک این سرویس عوض نشد: …`, under
`errors.panel` too) when its server failed it — read the service again before showing a link; `429` past 5 requests an
hour, the refused and the failed ones counted too (`لینک سرویس را زیاد عوض کرده‌اید؛ …`). The bot does not announce a
new link.

## Orders and payments

`GET /orders?status=&type=&page=` lists the customer's orders, newest first, each with its payments (newest first) — the
order shape of [what a checkout answers](Store-API-Buying.md#what-a-checkout-answers); `GET /orders/{id}` answers
`{ "order": … }`. An order's `subscription` is the service it delivered, renews or adds extras to, as `{ id, name }` — its
name on the server, as a ticket names its service ([the list](Store-API-Support-Tickets.md#the-list)): a purchase's once
delivered, a renewal's and extras' from the start; `null` for none, or once that service was deleted. Link it to
`GET /subscriptions/{id}`. `traffic_bytes` and `days` are what an order of extras brings its service — extra traffic in
bytes, extra time in days —, `null` for the rest (an agent's `traffic` order says its traffic in `traffic_bytes` too).

| `type` | |
|---|---|
| `purchase` | a new service |
| `renewal` | a service's renewal (`subscription` is that service, `server` where it is) |
| `extra_traffic` | extra traffic for a service (`traffic_bytes`; `subscription` is that service, `plan` its plan, `server` where it is) |
| `extra_time` | extra time for a service (`days`; `subscription`, `plan` and `server` as for extra traffic) |
| `wallet_topup` | money into the wallet (`plan`, `server` and `subscription` are `null`) |
| `traffic` | an agent's traffic for their bot, bought in the bot |

| Order `status` | |
|---|---|
| `pending` | waiting to be paid — a card's receipt to come, refused, or with support (its payment says which) |
| `paid` | paid; the delivery has not started |
| `processing` | paid; the delivery is under way |
| `fulfilled` | delivered (`fulfilled_at`; `subscription` the service delivered, renewed or given its extras) |
| `failed` | paid, but the delivery failed — support delivers it (or gives the money back) |
| `cancelled` | cancelled by support, or left unpaid for 48 hours |
| `refunded` | the money went back — a purchase's, a renewal's or extras' into the wallet, a delivered top-up's out of it again |

| Payment `status` | |
|---|---|
| `pending` | a card transfer waiting for its receipt |
| `awaiting_review` | its receipt is with support (`receipt.sent_at`) |
| `paid` | accepted (`paid_at`) |
| `failed` | its receipt was refused, or the payment was turned down |
| `cancelled` | cancelled by support, the order expired, or it was paid another way |
| `refunded` | given back |

A payment's `note` is the word on its latest verdict, for the customer: support's reason for a refusal, their note on a
cancellation or a refund, or the shop's own (`سفارش با روش دیگری پرداخت شد`, `پرداخت در مهلت کامل نشد`). Show it under
the payment. Support's internal notes on an order are never in the API.

## The wallet

`GET /wallet` → `200`
```json
{
  "balance": "380000.00",
  "credit": "0.00",
  "spendable": "380000.00",
  "top_up": { "min": "10000.00", "max": "500000000.00", "presets": ["50000.00", "100000.00", "200000.00", "500000.00"] }
}
```

`balance` is the ledger's last line; `credit` is how far below zero an agent's wallet may go (`"0.00"` for anyone else);
`spendable` is what the wallet can pay now. `top_up` is what the bot's «افزایش موجودی» offers: the smallest and the
largest top-up, and the amounts shown as buttons, ascending — send any of them back as it is
([a wallet top-up](Store-API-Buying.md#a-wallet-top-up)).

`GET /wallet/transactions?page=` → `200`
```json
{
  "transactions": [
    { "id": 31, "type": "debit", "amount": "120000.00", "balance_after": "380000.00", "description": "پرداخت سفارش #51 (خرید)", "created_at": "2026-10-07T12:00:00+00:00" },
    { "id": 30, "type": "credit", "amount": "500000.00", "balance_after": "500000.00", "description": "شارژ کیف پول (سفارش #50)", "created_at": "2026-10-07T11:40:21+00:00" }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 2, "last_page": 1 }
}
```

Newest line first, the lines the bot's «کیف پول» shows; `description` is Persian, ready to show.

## The referral program

`GET /referral` → `200`
```json
{
  "enabled": true,
  "rate": 10,
  "renewal_rate": 5,
  "first_only": false,
  "start_gift": { "referrer": "20000.00", "invitee": "10000.00", "trigger": "purchase" },
  "reward": { "every": 5, "plan": { "id": 4, "name": "یک‌ماهه" } },
  "code": "k7m2xq9p",
  "bot_link": "https://t.me/amo_shop_bot?start=ref_k7m2xq9p",
  "invited": 3,
  "earned": "36000.00",
  "rewards": 0
}
```

- `code` — the customer's invite code, made the first time it is asked for, program on or not.
- `bot_link` — the bot's invite link with it; `null` while the bot's @username is unknown.
- The site's own invite link is yours to build — `https://shop.example.com/?ref=k7m2xq9p` — and to honour: keep the
  code the visitor arrives with and send it as `referral_code` with their sign-in
  ([invite codes at sign-up](Store-API-Sign-In.md#invite-codes-at-sign-up)).
- `invited` — the customers their code brought; `earned` — what those brought them, in Toman.
- While `enabled`, a referred customer's payment that brings money in — a card transfer, for anything; not a wallet
  payment — earns the referrer `rate` percent (whole Toman, rounded down) into their wallet — a renewal's
  `renewal_rate` instead (0: renewals earn nothing) —; with `first_only`, only that customer's first. One level: a
  referral's referrals earn the referrer nothing.
- `start_gift` («هدیه شروع», `null` while there is none) — Toman to the referrer's wallet (`referrer`) and to the
  newcomer's own (`invitee`), once a newcomer: as they join (`trigger` `join`) or as their first purchase is paid
  (`purchase` — any way to pay; a service given free is no purchase). Both are told (`referral_start_gift` notices).
- `reward` («جایزه دعوت», `null` while there is none) — each time `every` more of the newcomers their code brought
  have bought, a service of `plan` is given to them free (a `referral_reward` notice, then the service as any
  order's); `rewards` — how many they were given.
- While an alert on their referrals stands — support's, when they look made up —, no start gift and no reward is given,
  and none of what was held back later; commissions are as ever.

## Gift codes

A gift code («کد هدیه») is worth Toman in the wallet or a plan's service given free, and is taken once — by one
customer of the shop, the bot's «🎁 کد هدیه» and the website alike ([gift codes](Gift-Codes-And-Lucky-Wheel.md#gift-codes)).

`POST /gift-codes/redeem` `{ "code": "nowruz-7k3m 9qxapt" }` → `200`
```json
{
  "gift": {
    "kind": "wallet",
    "words": "۵۰٬۰۰۰ تومان شارژ کیف پول",
    "balance": "430000.00",
    "order": null,
    "subscription": null
  }
}
```

- Send the code as the customer typed it: any case, dashes and spaces anywhere, Persian digits — the shop reads it as
  it keeps it (capital Latin letters and digits).
- `words` is what it gave, ready to show. A wallet code answers the `balance` it brought the wallet to; a plan's code the
  `order` its service was given by — `fulfilled`, or `failed` and waiting for support's retry, as any order — and, once
  delivered, its `subscription` ([services](#services)); `balance` is then `null`. Nothing is sent in Telegram: your
  site shows it.
- Refused, a `422` with the reason under `code`, worded for the customer: the shop takes no codes now (its switch is
  off), no such code, taken — by this customer or another —, past its last day, or its service cannot be delivered right
  now (no server delivers the plan: the code stays the customer's to try again later).
- Every try counts against the customer and the address network it came from until it names one of the shop's codes:
  10 wrong ones an hour for a customer, 30 for a network, then a `429` whose `Retry-After` says the wait — so codes are
  not guessed.
