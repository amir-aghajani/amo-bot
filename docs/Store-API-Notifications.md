# Store API: notifications

Everything the shop tells a customer on its own — a payment approved or refused, a service ending soon, a gift of days,
a renewal, support's answer to a ticket — is kept for the website for 180 days, whether it reached them in Telegram, by
email or not at all. It goes to their Telegram chat when they have a Telegram account; a customer without one — or who
blocked the bot — gets it **by email**, from the shop's name, when they have an email and the shop's email goes out. A
change of how the account is signed in to goes to both ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)).
Every notice is in one wording: the shop's own bot text, as the bot sent it (or would have). What is not a notice is not
in the feed: a broadcast to many customers, and the answers of a conversation the customer is having — the bot's
screens, a wallet purchase's or renewal's own confirmation (the website's checkout answers it). Paths are under the
[base address](Store-API.md#the-base-address); every one here takes the customer's bearer token.

## The feed

`GET /notifications?unread=&page=` → `200`
```json
{
  "notifications": [
    {
      "id": 306,
      "type": "ticket_answered",
      "text": "💬 پاسخ پشتیبانی به تیکت #12\n📌 قطعی اتصال\n\nمشکل سرور برطرف شد؛ لطفا یک بار اتصال را قطع و دوباره وصل کنید.",
      "html": "💬 <b>پاسخ پشتیبانی به تیکت #12</b><br>📌 قطعی اتصال<br><br>مشکل سرور برطرف شد؛ لطفا یک بار اتصال را قطع و دوباره وصل کنید.",
      "subject": { "type": "ticket", "id": 12 },
      "read": false,
      "created_at": "2026-10-08T10:40:00+00:00"
    },
    {
      "id": 305,
      "type": "payment_rejected",
      "text": "رسید پرداخت سفارش #52 تایید نشد.\nتوضیح پشتیبانی: مبلغ واریزی با مبلغ سفارش یکی نیست.",
      "html": "رسید پرداخت سفارش #52 تایید نشد.<br>توضیح پشتیبانی: مبلغ واریزی با مبلغ سفارش یکی نیست.",
      "subject": { "type": "order", "id": 52 },
      "read": true,
      "created_at": "2026-10-07T13:10:00+00:00"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 2, "last_page": 1, "unread": 1 }
}
```

(The words above are illustrative: a notice's words are the shop's own bot texts, as the bot sent them.)

- `unread=true` lists only the unread ones (`1`, `on` and `yes` say the same); left out, it lists every one.
  `meta.unread` counts the unread whatever the page shows.
- `text` — plain text: no markup, line breaks as `\n` (use `white-space: pre-line`), a premium emoji as its plain emoji.
- `html` — safe HTML: only `b`/`strong`, `i`/`em`, `u`/`ins`, `s`/`strike`/`del`, `code`, `pre`, `blockquote`,
  `<span class="tg-spoiler">`, `<a href>` to an http(s) address and `<br>`; every text escaped. Put it in the page as it
  is (`dangerouslySetInnerHTML`), styling `.tg-spoiler` as a spoiler (hidden until pressed).
- `subject` — what it is about, for a link: one of their orders (`GET /orders/{id}` — a notice about a payment is about
  its order), of their services (`GET /subscriptions/{id}`) or of their tickets (`GET /tickets/{id}`); `null` for none —
  their account, a service deleted, someone else's row. Only a service may be gone since (`404`: support deleted it); an
  order stays — one nobody paid ends `cancelled` —, and so does a ticket.

## Notice types

| `type` | About | `subject` |
|---|---|---|
| `payment_settled` | a payment approved: the service delivered, the wallet charged — or the delivery failed and support finishes it | order |
| `payment_rejected` | a receipt refused, with the reason | order |
| `order_cancelled` | an order cancelled by support | order |
| `payment_refunded` | a payment back in the wallet | order |
| `topup_refunded` | a wallet top-up given back, its amount taken out of the wallet | order |
| `payment_reminder` | a reminder of a card payment never finished | order |
| `service_disabled`, `service_enabled` | a service switched off by support, or back on | subscription |
| `service_deleted` | a running service deleted by support | `null` |
| `service_moved` | a service moved to another server — its new link | subscription |
| `service_granted` | days or traffic added to a service | subscription |
| `expiry_reminder`, `traffic_reminder` | a service ending soon, or its traffic running low | subscription |
| `auto_renewed` | a service renewed from the wallet | order (the renewal) |
| `auto_renew_short` | the wallet short of an automatic renewal | subscription |
| `renewal_failed` | a renewal paid but not delivered — support finishes it | order |
| `referral_joined`, `referral_commission` | a newcomer by their invite code; a commission earned | `null` |
| `agency_approved`, `agency_rejected`, `agency_changed`, `agency_revoked`, `agency_traffic_short` | an agent's agency, and their bot's traffic | `null` |
| `way_in_added`, `way_in_removed` | a Telegram account, a Google account or an email added to their account, or taken off it | `null` |
| `password_changed` | their password set or changed (`PUT /me/password`), reset, or set by a merge ([the merge offer](Store-API-Account.md#the-merge-offer)); an email added with its password is told as `way_in_added` | `null` |
| `two_factor_enabled`, `two_factor_disabled` | their two-factor sign-in turned on, or off — by them, or by support | `null` |
| `account_merged` | another account of theirs made one with this one | `null` |
| `second_step_locked` | their second step failed until it waits: someone has their password | `null` |
| `email_codes_failed` | the codes emailed to their address failed until none goes for a while | `null` |
| `ticket_answered` | support answered their ticket — the words cut to what a Telegram message holds; the whole is in the ticket | ticket |
| `ticket_closed` | support closed their ticket | ticket |

The notices of how the account is signed in to — `way_in_added`, `way_in_removed`, `password_changed`,
`two_factor_enabled`, `two_factor_disabled`, `account_merged`, `second_step_locked`, `email_codes_failed` — go to their
Telegram chat and their email both.

Link a `null`-subject notice by its type: the referral page, the security settings, the wallet. The list grows with the
shop: show a notice of a type you do not know by its words, and link it only by a subject type you know.

## Marking read

`POST /notifications/read` answers how many are left unread:

`POST /notifications/read` request
```json
{ "ids": [306] }
```

`POST /notifications/read` → `200`
```json
{ "unread": 0 }
```

`{ "ids": […] }` marks those (at most 100; a number that is not one of theirs marks nothing and is no error); `{}` marks
every one. An empty list marks nothing; anything but a list of numbers is `422` on `ids`. A notice read before keeps when
it was read. A ticket's notices are marked read with the ticket, too: `POST /tickets/{id}/read`
([the conversation](Store-API-Support-Tickets.md#the-conversation)) — or the bot showing the customer that ticket.

## The bell

`GET /me`'s `unread_notifications`, `meta.unread`, or the answer of `POST /notifications/read`. Read `GET /me` again
every minute or so while a dashboard page is open, and when the tab comes back into view.
