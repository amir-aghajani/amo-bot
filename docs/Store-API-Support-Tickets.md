# Store API: support tickets

A ticket is a conversation with support: a subject, the service it is about (or none), and messages — the customer's
and support's, each words and at most one picture, 200 at most a ticket. The customer opens and writes in tickets on the
website or in the bot, which list the same tickets; support answers from either panel, the shop's Telegram group or the
website's admin side ([the shop's admins](Store-API-Admins.md)) — which, the customer's side need not care. Paths are under the [base address](Store-API.md#the-base-address); every one here
takes the customer's bearer token, and another customer's ticket is `404`, as one that never was.

## The list

`GET /tickets?status=&page=` → `200`
```json
{
  "tickets": [
    {
      "id": 12,
      "subject": "قطعی اتصال",
      "status": "answered",
      "subscription": { "id": 9, "name": "ali_1" },
      "last_message_at": "2026-10-08T10:40:00+00:00",
      "unread": true,
      "rating": null,
      "created_at": "2026-10-08T10:15:00+00:00",
      "closed_at": null
    },
    {
      "id": 7,
      "subject": "سوال درباره تمدید خودکار",
      "status": "closed",
      "subscription": null,
      "last_message_at": "2026-10-02T18:20:00+00:00",
      "unread": false,
      "rating": 5,
      "created_at": "2026-10-02T17:55:00+00:00",
      "closed_at": "2026-10-02T18:20:00+00:00"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 2, "last_page": 1, "unread": 1 }
}
```

The latest activity first; `status` narrows it to one state, anything else is every one. `meta.unread` counts every
ticket of theirs with support's words unread, whatever the page or the `status` shows — the support link's badge.

| Field | Meaning |
|---|---|
| `status` | `open` waiting on support; `answered` support wrote last, waiting on the customer; `closed` |
| `subscription` | the service it is about, by its name on the server ([services](Store-API-After-Buying.md#services)); `null` for none, or one deleted since |
| `last_message_at` | its latest message — the list's order |
| `unread` | support wrote since the customer last read it — the site said so (`POST /tickets/{id}/read`, [the conversation](#the-conversation)), or the bot showed it to them: mark the row |
| `rating` | the customer's 1 to 5, once it was closed; `null` for none |
| `closed_at` | when it was closed; `null` unless it is closed |

What moves it: the customer's message makes it `open` — a closed one too; support's answer makes it `answered`, a closed
one too; either side closes it. Support may also open a closed ticket again (`open`), telling the customer nothing. A
ticket opened again, whichever way, leaves its `closed_at`, `rating` and `rating_note` behind.

## Opening one

`POST /tickets` request
```json
{ "subject": "قطعی اتصال", "body": "از دیشب سرویس ali_1 وصل نمی‌شود.\nروی دو گوشی امتحان کردم.", "subscription_id": 9 }
```

`POST /tickets` → `201`
```json
{
  "ticket": {
    "id": 12,
    "subject": "قطعی اتصال",
    "status": "open",
    "subscription": { "id": 9, "name": "ali_1" },
    "last_message_at": "2026-10-08T10:15:00+00:00",
    "unread": false,
    "rating": null,
    "created_at": "2026-10-08T10:15:00+00:00",
    "closed_at": null,
    "rating_note": null,
    "messages": [
      { "id": 31, "author": "customer", "body": "از دیشب سرویس ali_1 وصل نمی‌شود.\nروی دو گوشی امتحان کردم.", "attachment": null, "created_at": "2026-10-08T10:15:00+00:00" }
    ]
  }
}
```

The answer is the ticket with its conversation, as `GET /tickets/{id}` gives it ([the conversation](#the-conversation));
the shop's Telegram group hears of it within a minute or so. With a picture, send the same fields as a form, the picture
as `file` — a form without `file` opens a ticket without a picture, as JSON does:

```ts
const form = new FormData()
form.append('subject', subject)
form.append('body', body)
if (serviceId) form.append('subscription_id', String(serviceId))
if (file) form.append('file', file) // a JPEG, PNG or WebP picture, 10 MB at most
const { ticket } = await store<S['StoreTicketResponse']>('/tickets', { method: 'POST', form })
```

| Field | Rule |
|---|---|
| `subject` | 3 to 120 characters |
| `body` | 1 to 4000 characters, line breaks kept — words are needed with a picture too |
| `subscription_id` | one of the customer's services (`GET /subscriptions`, any state); left out, `null` or `""` for none |
| `file` | a form's, optional: one picture, a JPEG, PNG or WebP by its bytes, 10 MB at most (an iPhone's HEIC converted first, and kept as the shop writes it again: [the receipt](Store-API-Buying.md#card-transfer-and-the-receipt)) |

Every refusal comes at once:

`POST /tickets` → `422`
```json
{
  "message": "اطلاعات واردشده معتبر نیست.",
  "errors": {
    "subject": ["موضوع حداقل 3 کاراکتر است."],
    "file": ["تصویر باید JPG، PNG یا WebP باشد."]
  },
  "request_id": "9b2d4e6f8a0c1e3f"
}
```

Others: `موضوع تیکت را بنویسید.`, `موضوع حداکثر 120 کاراکتر است.`, `متن پیام را بنویسید.`, `پیام حداکثر 4000 کاراکتر
است.`, `این سرویس پیدا نشد.` (not one of theirs), `حجم تصویر حداکثر ۱۰ مگابایت می‌تواند باشد.` — and the host's own
limits, as for a receipt ([the receipt](Store-API-Buying.md#card-transfer-and-the-receipt)). Then the caps of
[limits](#limits) (`429`). A form with a picture is `503` while the server's disk is too full (`فضای ذخیره سرور پر شده و
فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.`) or once the shop took 1 GB of pictures in a day (`امروز بیش از
این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر دوباره بفرستید.`) — said before the other fields are checked, so try again
later, or send the words without the picture.

## The conversation

`GET /tickets/{id}` answers the ticket with its whole conversation, as it stands — reading it changes nothing. When the
site shows it to the customer, it says so: `POST /tickets/{id}/read` (no body) answers the same ticket, read —
support's latest words read, `unread` `false` from then on — and marks its notices in their feed read with it
([notifications](Store-API-Notifications.md)). So open the ticket's page with that `POST`; read the ticket again with
`GET` (a poll, a prefetch, a tab in the background), and `POST …/read` once more when a `GET` finds it `unread` while
the customer has the page in view. Writing in it, closing or rating it reads nothing; the bot's ticket screen reads it as
`POST …/read` does.

`POST /tickets/{id}/read` → `200`
```json
{
  "ticket": {
    "id": 12,
    "subject": "قطعی اتصال",
    "status": "answered",
    "subscription": { "id": 9, "name": "ali_1" },
    "last_message_at": "2026-10-08T10:40:00+00:00",
    "unread": false,
    "rating": null,
    "created_at": "2026-10-08T10:15:00+00:00",
    "closed_at": null,
    "rating_note": null,
    "messages": [
      { "id": 31, "author": "customer", "body": "از دیشب سرویس ali_1 وصل نمی‌شود.\nروی دو گوشی امتحان کردم.", "attachment": null, "created_at": "2026-10-08T10:15:00+00:00" },
      { "id": 33, "author": "customer", "body": "این خطا را می‌دهد:", "attachment": { "name": "screenshot.jpg", "kept": true }, "created_at": "2026-10-08T10:18:00+00:00" },
      { "id": 35, "author": "support", "body": "مشکل سرور برطرف شد؛ لطفا یک بار اتصال را قطع و دوباره وصل کنید.", "attachment": null, "created_at": "2026-10-08T10:40:00+00:00" }
    ]
  }
}
```

The messages run first to last; `author` is `customer` or `support` — support's never say who of support wrote them.
`body` is plain text: show it as text, never as HTML, with `white-space: pre-line`.

A message's picture is `GET /tickets/{id}/messages/{message}/attachment`: its bytes — a JPEG, PNG, WebP or GIF as itself,
anything else as a file to save —, cached privately five minutes. It needs the bearer token, which an `<img src>`
cannot send: fetch it and show an object URL, and name a download by `attachment.name` (`Content-Disposition` is not
readable cross-origin). A picture uploaded from the website or a panel comes back as the shop wrote it again
([the receipt](Store-API-Buying.md#card-transfer-and-the-receipt)), not as it was sent.

`attachment.kept` says whether there is a picture to fetch. One uploaded to a ticket goes 30 days after the ticket
closed — a ticket opened again before then keeps its own —: the message still says it had one (`kept: false`) and its
address is a `404`, so show the name without asking for the picture. A picture sent in the bot stays Telegram's.

```ts
// lib/store.ts, beside store(): a picture the API serves, as an address an <img> can show —
// a message's (ask only while attachment.kept): picture(`/tickets/${ticket}/messages/${message}/attachment`)
export async function picture(path: string, tokens: Tokens = customerTokens): Promise<string> {
  const res = await fetch(BASE + path, { headers: { Authorization: `Bearer ${tokens.get()}` } })
  if (!res.ok) {
    const wait = res.headers.get('Retry-After')
    const error = new StoreError(res.status, await res.json(), wait === null ? null : Number(wait), res.headers.get('WWW-Authenticate'))
    if (error.signedOut) tokens.clear()
    throw error
  }
  return URL.createObjectURL(await res.blob()) // revoke it once the picture leaves the page
}
```

`404` when the message has no picture or it is gone (`این پیام تصویری ندارد.`, `این تصویر دیگر در تلگرام نیست.`,
`فایل این تصویر دیگر روی سرور نیست.` — the last one for `kept: false` too); `502` while Telegram is out of reach — a
picture sent in the bot is Telegram's (`تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.`); `429` past 120 pictures
read in an hour — every request counts, a refused one too (`تصویر زیادی خواسته‌اید؛ …`): fetch a picture as it comes
into view, and keep its object URL while the page is open.

## Writing, closing, rating

Each answers `{ ticket }` — the ticket as it stands now, with its conversation ([the conversation](#the-conversation));
none of them marks it read.

- `POST /tickets/{id}/messages` — `{ body }`, or a form with `body` and, if it has one, `file`
  ([opening one](#opening-one)'s rules): it waits on support again (`open`), a closed one too. A ticket holds 200
  messages, the customer's and support's together; then `422` on `status` (`این تیکت به سقف پیام‌ها رسیده؛ تیکت تازه‌ای
  باز کنید.`) — a new ticket is the way on. It keeps 20 pictures the customer uploaded; past them a message with a
  picture is `422` on `file` (`این تیکت بیشتر از ۲۰ تصویر از شما نمی‌گیرد؛ پیام را بدون تصویر بفرستید، یا تیکت تازه‌ای
  باز کنید.`) — send the words alone.

  `POST /tickets/{id}/messages` request
  ```json
  { "body": "درست شد، ممنون." }
  ```
- `POST /tickets/{id}/close` (no body) — closed; `422` on `status` once it is (`این تیکت بسته شده است.`). The customer's
  own closing tells only support.
- `POST /tickets/{id}/rating` — 1 to 5, and a note of 500 characters at most; only while it is closed (`422` on
  `status`: `امتیاز را پس از بسته شدن تیکت می‌توانید ثبت کنید.`), a rating given again replacing it. Out of range:
  `امتیاز باید عددی بین 1 تا 5 باشد.` on `rating`. Each rating counts as one of the customer's messages in the cap of
  [limits](#limits); the shop's group hears a first rating, or one that changed.

  `POST /tickets/{id}/rating` request
  ```json
  { "rating": 5, "note": "سریع جواب دادید." }
  ```

A message and the change it makes are one: support closing a ticket as the customer writes in it comes before the
message or after it, never between — no message is left unseen on a closed ticket.

## Limits

| What a customer does | At most | Past it, `429` with `Retry-After` |
|---|---|---|
| Open tickets | 10 an hour | `تیکت زیادی باز کرده‌اید؛ …` |
| Write messages — a ticket's first, and every rating, among them | 30 in 10 minutes | `پیام زیادی فرستاده‌اید؛ …` |
| Upload pictures — receipts ([the receipt](Store-API-Buying.md#card-transfer-and-the-receipt)) and tickets' pictures together, one budget | 10 an hour | `تصویر زیادی فرستاده‌اید؛ …` |
| Read pictures ([the conversation](#the-conversation)) | 120 an hour | `تصویر زیادی خواسته‌اید؛ …` |

Each is the customer's own, from every device together. A ticket, a message or an upload is counted once its fields
passed their checks — one refused for them costs nothing —; a picture read is counted whatever its answer. Besides: 200
messages a ticket and 20 pictures of the customer's ([writing, closing, rating](#writing-closing-rating)), and no
picture taken while the server's disk is too full or once the shop took its gigabyte of pictures for the day (`503`,
[opening one](#opening-one)).

## How answers reach the customer

Support's answer makes the ticket `answered` and `unread`, and is told as every notice is
([notifications](Store-API-Notifications.md)): a `ticket_answered` notice whose `subject` is
`{ "type": "ticket", "id": … }`, its words the answer cut to what a Telegram message holds — the whole is in the ticket
—, sent to their Telegram chat (support's picture with it, and buttons that reply or open the ticket in the bot) or —
without a Telegram account, or having blocked the bot — by email, while the shop's email goes out. Support closing the
ticket is a `ticket_closed` notice. Support opening it again, and what the customer does themselves, tell the customer
nothing.

On the site: mark the `unread` tickets in the list and show `meta.unread` as the support link's badge; read the list
again when `GET /me`'s `unread_notifications` grows; link a ticket notice to its ticket — and once the ticket's page has
shown it, `POST /tickets/{id}/read`: the ticket read, and its notices with it (no `POST /notifications/read` needed).
