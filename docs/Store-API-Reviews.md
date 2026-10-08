# Store API: reviews

The shop's customers' reviews: the ones support approved are anyone's to read, and a review may be written by a customer
signed in — linked to their account then — or, behind the website's captcha, by a guest. Support decides each in the
panels' «نظرات» or the website's admin side ([the shop's admins](Store-API-Admins.md)); until then the website shows
nothing of it. Paths are under the [base address](Store-API.md#the-base-address).

**The website shows reviews only while the shop says so**: the panel's «تنظیمات وب‌سایت › نظرات» switch
([Website](Website.md#reviews)), off as a website starts. Off, `GET` and `POST /reviews` both answer `404`
(`این وب‌سایت نظرات مشتریان را نشان نمی‌دهد و نظری نمی‌گیرد.`): hide the reviews and their form.

## The reviews the shop shows

`GET /reviews?page=` → `200`
```json
{
  "reviews": [
    {
      "id": 31,
      "name": "مریم",
      "rating": 5,
      "body": "از اول ماه وصلم و مشکلی نداشتم.\nپشتیبانی هم سریع جواب داد.",
      "context": "ایرانسل · اندروید · Happ",
      "avatar": "fox",
      "created_at": "2026-10-06T19:22:41+00:00"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 18, "last_page": 1, "summary": { "count": 18, "average": 4.61 } }
}
```

- The approved reviews alone, newest first, 25 a page — anyone's to read, no token.
- `body` keeps its line breaks (show it with `white-space: pre-line`); `name`, `body` and `context` are plain text the
  writer typed: escape them, never render them as HTML. `context` — where they use the service from — and `avatar` — a
  key of your site's own avatars, as the writer picked it — may be `null`: draw your own default.
- `meta.summary` is every approved review, whatever the page: how many, and their average rating to two decimals —
  `null` while there is none; a whole average comes as a whole number (`5`), so read it as a number.
- It holds nothing of who wrote a review, nor of support's: no account, no status, no decision.

Render the list on your server and keep it briefly, as the catalogue ([calling from your server](Store-API.md#calling-from-your-server)).

## Writing one

`POST /reviews` request
```json
{ "name": "مریم", "rating": 5, "body": "از اول ماه وصلم و مشکلی نداشتم.", "context": "ایرانسل · اندروید · Happ", "avatar": "fox", "captcha": "…" }
```

`POST /reviews` → `201`
```json
{ "review": { "id": 32, "status": "pending" } }
```

| Field | Rule |
|---|---|
| `name` | 2 to 64 characters, one line (spaces and line breaks become one space): the name it is signed with |
| `rating` | 1 to 5 — a JSON number, or its text, Persian digits too |
| `body` | 10 to 600 characters, its line breaks kept |
| `context` | optional, 100 characters at most, one line: where they use the service from — «ایرانسل · اندروید · Happ» |
| `avatar` | optional: the key of one of your site's own avatars — 1 to 32 of `a-z 0-9 - _`, never an address |
| `captcha` | while `GET /`'s `captcha` is not `null`: the widget's token, solved for the action `review` ([the captcha](Store-API-Sign-In.md#the-captcha)) |

**Who writes it**: with a signed-in customer's bearer token the review is theirs — support sees their account beside
it —; a token that opens no session is `401` (never kept as a guest's review instead), and a banned customer's `403`.
Without a token it is a guest's, **taken only while the website asks a captcha**: with none, nothing would hold a bot
back, so a guest's review is `403` (`برای نوشتن نظر، اول وارد حساب خود شوید.`) — offer the sign-in, or ask the shop's
owner to turn the captcha on. `GET /`'s `captcha` says which: `null`, show the form to signed-in customers alone.

**The answer is `201` and `pending`**: tell the writer it shows once support approves it. Nobody is told of the decision —
read `GET /reviews` again to see it there.

**In this order**, every refusal said at once where it can be:

1. Who asks — a token's `401` or a banned customer's `403`; then `404` while the website's reviews are off; then a
   guest's review without a captcha, `403`.
2. The fields — `422` under each refused field at once: `نام خود را بنویسید.`, `نام حداقل 2 کاراکتر است.`,
   `نام حداکثر 64 کاراکتر است.`, `امتیاز باید عددی بین 1 تا 5 باشد.`, `متن نظر را بنویسید.`,
   `متن نظر حداقل 10 کاراکتر است.`, `متن نظر حداکثر 600 کاراکتر است.`, `مشخصات حداکثر 100 کاراکتر است.`, and an avatar
   that is no key (`آواتار باید کلید یکی از آواتارهای خود وب‌سایت باشد: …`).
3. The shop's room — `503` (`این فروشگاه الان نظر تازه‌ای نمی‌گیرد؛ کمی بعد دوباره امتحان کنید.`) while 200 reviews wait
   on support: try again later.
4. The writer's pace — `429` with `Retry-After` (`نظر زیادی ثبت شده است؛ …`): 20 reviews an hour from one address
   network (an IPv6 address counted by its /48), 3 a day from one signed-in customer, and 20 an hour from every guest
   together — the shop's whole guest book.
5. The captcha — `422` on `captcha` (`تایید امنیتی انجام نشد؛ دوباره تلاش کنید.`): a refused token still counts against
   the network's pace and a signed-in customer's own, never against the guests' together; `503` when it could not be
   judged now (`تایید امنیتی در دسترس نیست؛ کمی بعد دوباره تلاش کنید.`), which counts nothing. Reset the widget after
   every answer.

Post a review from the visitor's browser: the pace is counted by the address the request comes from — from your server,
every visitor would share its one.

```ts
// A review written on the site: the visitor's token when one is kept — theirs then —, the widget's token always.
export async function writeReview(input: { name: string; rating: number; body: string; context?: string; avatar?: string }, captcha?: string) {
  const { review } = await store<S['StoreReviewWrittenResponse']>('/reviews', { method: 'POST', json: { ...input, captcha } })
  return review.status // 'pending': say it shows once approved
}
```

Every operation, field by field: the [reference](Store-API-Reference.md).
