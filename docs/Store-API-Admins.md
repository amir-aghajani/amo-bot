# Store API: the shop's admins

For the developer of a shop's website who builds an admin area into it: the shop's admins — its bot's admins — work the
shop's daily work there, with their own accounts on the website, as they would in a panel. Every endpoint, with its
body and answers: the [admin API reference](Store-Admin-API-Reference.md).

## What it is

The admin API is the panels' own operations of the shop's daily work, under the website's base address and `/admin`:

```
{APP_URL}/api/store/v1/{store-key}/admin
GET {base}/admin/payments?status=awaiting_review      the panels' GET /api/{panel}/payments, the same answer
```

| Area | What the shop's admins do there |
|---|---|
| The dashboard, the queues, the change feed | `GET /admin/dashboard`, `/admin/queues`, `/admin/changes` (poll it to follow the shop live) |
| Payments | the list and a payment, its receipt; approve, reject, cancel, remind, retry — refund with the `refunds` grant |
| Orders | the list and an order; retry a delivery, cancel an unpaid order |
| Services | the list and a service; read it from its panel, switch it off or on, move it — days and traffic with `extend`, delete with `delete` |
| Customers | the list (by role too) and a customer's page, their wallet's ledger, their groups, a ban — the wallet changed with `wallet`; two-factor sign-in turned off and every device signed out with `account_security` |
| Customer groups | the list; add, rename, reorder, delete |
| Support tickets | the list and a ticket; answer (a picture too), close, open again |
| Reviews | the list; approve, reject, delete ([reviews](Reviews.md)) |
| Plans and categories | the lists — add, change, reorder, switch, duplicate and delete with `catalog` |
| Referrals | the figures and the three lists |
| Broadcasts | the runs; pause, resume, cancel, unpin (composing one stays the bot's `/broadcast`) |

Everything else is the panels' alone, and a `404` here: the shop's configuration — its payment methods, the bot's
settings, texts and keyboards, the report group, the website itself, **who its admins are** — and the owner's sections
(the servers, the agency, the installation's settings).

The answers are the panels' very shapes: the same JSON, read the same way, the customers as the panels name them.

## Letting admins in

The shop's owner — or the agent, for their shop — sets it up in the panel, «تنظیمات وب‌سایت › مدیران سایت»
([Website](Website.md#the-shops-admins)):

- **«کار مدیران از وب‌سایت»** — off by default: until it is on, every `/admin` request is refused.
- **«ورود امن مدیران»** — on by default: the admins must have signed in strongly ([below](#signing-in)).
- **«کارهای بیشتر»** — a switch per grant, all off by default ([grants](#grants)).

**Who is an admin** is the shop's customers whose role is admin — given on the panel's «کاربران», the row's menu
«مدیر ربات کردن» ([Customers](Customers.md#the-bots-admins)). The role is the customer's account's: an admin signs in to
the website with the very account that has it — their Telegram, or a way in added to that account
([ways in](Store-API-Account.md#ways-in-adding-and-removing)). A website account they made with an email alone is another customer, no admin.

## Signing in

An admin signs in as any customer does ([signing in](Store-API-Sign-In.md)), and their bearer token opens the admin API
too. `GET /me` (and `PATCH /me`) say what it lets them do, in `staff` — `null` for a customer who is no admin, and while
the website lets no admin in:

```json
"staff": {
  "grants": ["refunds", "extend"],
  "strong_sign_in": true,
  "signed_in_strongly": true,
  "signed_in_until": "2026-10-08T23:45:00+00:00",
  "recent_until": "2026-10-08T12:00:00+00:00"
}
```

| Field | What it says |
|---|---|
| `grants` | What the website grants its admins beyond the daily work |
| `strong_sign_in` | Whether the website asks its admins a strong sign-in |
| `signed_in_strongly` | Whether this session is one: signed in with Telegram, Google, or a password with its second step |
| `signed_in_until` | Until when this session works the admin API at all — 12 hours after a way in was last proven on it; `null`: prove one again first |
| `recent_until` | Until when this session's sign-in counts as recent — what a granted operation and approving a payment ask; `null`: prove a way in again first |

- **A strong sign-in.** While the website asks one, a session signed in with a password alone is refused every admin
  operation, with `WWW-Authenticate: Bearer error="insufficient_user_authentication", acr_values="telegram google password_2fa"`.
  Proving a strong way on the session — `POST /me/reauthenticate` with Telegram, with Google, or with the password and
  the code of an account whose two-factor sign-in is on ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)) —
  makes it a strong one for the rest of its life. A password proven again alone changes nothing; an admin who signs in
  with a password should turn two-factor sign-in on, or add Telegram or Google.
- **A working day.** Every admin operation asks a way in proven on the session within the last **12 hours** — at the
  sign-in, or by `POST /me/reauthenticate` since —, else `403` `برای این کار دوباره وارد شوید.` with the challenge of a
  recent sign-in: an admin's token that leaked works for hours, not for the months a customer's session lasts.
- **A recent sign-in.** An operation that asks a grant, and **approving a payment** (it delivers a service on the admin's
  word alone), ask the session's sign-in to be at most **15 minutes** old too, as a change of the customer's own sign-in
  does: else `403` with `WWW-Authenticate: Bearer error="insufficient_user_authentication", max_age=900`.
  `POST /me/reauthenticate` opens the next 15 minutes (and the next 12 hours); `recent_until` and `signed_in_until` say
  when they end. The [reference](Store-Admin-API-Reference.md) names each operation that asks it.
- **Keep an admin's token in memory or `sessionStorage` — never `localStorage`**, where it would outlive the tab and any
  script of the site could read it for as long as it lasts. The [minimal client](Store-API.md#a-minimal-client) keeps a
  customer's in `localStorage` and an admin's in `sessionStorage`: the admin area keeps its sign-in with
  `keepToken(token, adminTokens)` and calls `admin()`.

## Grants

| Grant | What it opens |
|---|---|
| `catalog` | The plans and their categories: add, change, reorder, switch on or off, duplicate, delete |
| `wallet` | A customer's wallet credited or debited by hand |
| `refunds` | A payment given back |
| `extend` | A service given days and traffic |
| `delete` | A service deleted for good, from its panel and the shop |
| `account_security` | A customer's website account taken in hand: two-factor sign-in turned off, every device signed out |

The rest of the daily work asks none. An operation that asks one names it in the [reference](Store-Admin-API-Reference.md).

## Refusals

Every refusal is the Store API's error shape ([errors](Store-API.md#errors)). At the door, a `403`:

| Message | Why | The site |
|---|---|---|
| `این بخش فقط برای پشتیبانی فروشگاه است.` | The customer is no admin of the shop — whatever the website says | show no admin area |
| `این وب‌سایت فعلا مدیران فروشگاه را راه نمی‌دهد.` | The website lets no admin in now | say so |
| `برای کار مدیریت، با تلگرام، گوگل یا رمز عبور همراه کد ورود دو مرحله‌ای وارد شوید.` | A password alone, while a strong sign-in is asked (`acr_values` in `WWW-Authenticate`) | offer the strong ways, through `POST /me/reauthenticate` |
| `فروشگاه این کار را به مدیران وب‌سایت نسپرده است.` | An operation the website has not granted | hide the control (read `staff.grants`) |
| `برای این کار دوباره وارد شوید.` | The session's sign-in older than 12 hours — or, for a granted operation or a payment's approval, than 15 minutes (`WWW-Authenticate` with `max_age=900`) | prove a way in again, then retry |
| `حساب کاربری شما مسدود شده است.` | The admin is banned | sign out |

And past the door, what no admin decides, whatever the row's state allows — a `403` too:

| Message | Why |
|---|---|
| `پرداخت خودتان را نمی‌توانید تایید یا بازپرداخت کنید؛ این کار با مدیر دیگری است.` | Their own payment, approved or given back |
| `موجودی کیف پول خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.` | Their own wallet |
| `سرویس خودتان را نمی‌توانید تغییر دهید؛ این کار با مدیر دیگری است.` | Their own service: days and traffic, switched off or on, moved, deleted (reading it from its panel is fine) |
| `نظر خودتان را نمی‌توانید تایید، رد یا حذف کنید؛ این کار با مدیر دیگری است.` | Their own review, approved, rejected or deleted |
| `حساب مدیران فروشگاه فقط از پنل تغییر می‌کند.` | An admin's account — a ban, two-factor sign-in, the devices — theirs or another admin's |
| `حساب نماینده‌ها فقط از پنل تغییر می‌کند.` | An agent's account, the same way: the owner's partners are the panel's |
| `از وب‌سایت فقط پرداختی تایید می‌شود که رسیدش رسیده و در انتظار بررسی است.` | Approving a payment without a receipt in review |

**These guards stop an admin acting on their own account only: two admins can do these things for each other** — approve
each other's payments, credit each other's wallets. Give the role only to people you trust.

A client is left on its panel (a service deleted from the shop alone, a move without deleting the old client) only
while that panel does not answer: a `422` on `status` otherwise (`پنل سرور «…» در دسترس است؛ …`). What the admins ask of
the VPN panels — a service read, switched, moved, given days, deleted — is held to 60 operations a minute in the shop,
theirs together (in an agent's shop, with the agent's own), then a `429` with its wait
(`درخواست زیادی به پنل سرورها فرستاده‌اید؛ …`); and a panel's failure — an operation it refused, the reason an order's
delivery failed — reads to them as a short word, never the owner's diagnosis.

## What the shop keeps of it

- **Every change an admin asks is logged** with them on the line — done or refused —, and the decisions keep who made it
  (a payment's verdict, a wallet's line, a ticket's answer): their `@handle`, else `tg:<id>`, else `user#<id>`. The
  owner's own decisions read «پشتیبانی» to the admins.
- **The customer reads «پشتیبانی»**: an admin's answer to a ticket shows in the panels as written «مدیریت وب‌سایت», and to
  the customer as support's, like any other.

## Building the admin area

1. On sign-in, and after any `403` at the door, read `GET /me`: `staff` null hides the admin area.
2. Offer what `staff.grants` holds: a control whose grant is missing would only be refused.
3. Past `staff.signed_in_until`, ask the admin to prove a way in again before anything else; before a granted operation
   or a payment's approval, check `staff.recent_until` the same way (`POST /me/reauthenticate`).
4. Read and write with the admin's token, kept in `sessionStorage`, through the
   [minimal client](Store-API.md#a-minimal-client)'s `admin()` — `store()` with the admin's token:

   ```ts
   const queue = await admin<S['PaymentsResponse']>('/admin/payments?status=awaiting_review')
   await admin('/admin/payments/' + id + '/reject', { method: 'POST', json: { note: 'مبلغ رسید با سفارش نمی‌خواند' } })
   ```
5. A picture — a receipt, a ticket's — is asked with the token too: fetch it, and show it as a blob —
   `picture('/admin/payments/' + id + '/receipt', adminTokens)` ([the conversation](Store-API-Support-Tickets.md#the-conversation)
   has the function).
6. Poll `GET /admin/changes` every few seconds to know which lists moved — each area's number grows when its rows
   change — and read again only those.
