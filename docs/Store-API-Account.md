# Store API: the account

The signed-in customer's own account: who they are, how they sign in, their devices — and two accounts of theirs made
one. Paths are under the [base address](Store-API.md#the-base-address); every one here takes the customer's bearer
token ([tokens and sessions](Store-API-Sign-In.md#tokens-and-sessions)).

## The account

`GET /me` → `200`
```json
{
  "customer": {
    "id": 57,
    "first_name": "سارا",
    "last_name": "احمدی",
    "telegram": { "id": 987654321, "username": null },
    "email": "sara@example.com",
    "google": false,
    "has_password": true,
    "two_factor": true,
    "phone": null,
    "balance": "150000.00",
    "created_at": "2026-10-01T14:05:09+00:00"
  },
  "unread_notifications": 3,
  "staff": null
}
```

| Field | Meaning |
|---|---|
| `id` | the customer's number — it changes when the account merges into an older one ([the merge offer](#the-merge-offer)) |
| `first_name`, `last_name` | their names; `null` without one |
| `telegram` | their Telegram account (`id`, and `username` without the @ or `null`); `null` for one who never linked Telegram |
| `email` | their email, always proven — a code sent to it typed back, or Google vouching for it; `null` without one |
| `google` | a Google account signs them in |
| `has_password` | their email and a password sign them in |
| `two_factor` | the password sign-in asks a second step ([two-factor sign-in](#two-factor-sign-in)) |
| `phone` | E.164, as they shared it with the bot; `null` without one |
| `balance` | the wallet in Toman; below zero an agent's debt |
| `created_at` | since when they are the shop's customer |
| `unread_notifications` | the count for the site's bell ([notifications](Store-API-Notifications.md)) |
| `staff` | what the shop's admin API lets them do — their grants, whether this session is signed in strongly enough, until when it works the admin API and until when it counts as recent —; `null` for a customer who is no admin of the shop, and while the website lets no admin in ([the shop's admins](Store-API-Admins.md#signing-in)) |

`PATCH /me` answers as `GET /me` does. `PUT /me/password`, `POST /me/2fa/disable`, a way in added or removed (the `200`s
of `/me/identities/*`) and `POST /me/merge` answer `{ "customer": … }` — the same object, without the count. The rest
answer what they make: a secret to set up, recovery codes, how long a code or a proof lasts, a merge offer.

## A recent sign-in

What changes how the account is signed in to asks more of a session than its token, so a token that leaked takes no
account over:

| Asks a recent sign-in | See |
|---|---|
| `PUT /me/password` | [password](#password) |
| `POST /me/2fa/setup`, `POST /me/2fa/enable`, `POST /me/2fa/disable` | [two-factor sign-in](#two-factor-sign-in) |
| `POST /me/identities/telegram`, `…/google`, `…/email`, `…/email/verify`, and `DELETE /me/identities/{kind}` | [ways in](#ways-in-adding-and-removing) |
| `POST /me/merge` | [the merge offer](#the-merge-offer) |

The session's sign-in must be at most 15 minutes old, or a way into the account proven again on it since. Otherwise the
answer is `403` with `WWW-Authenticate: Bearer error="insufficient_user_authentication", max_age=900` (RFC 9470), given
before anything is done; it ends nothing — the token stands:

`PUT /me/password` → `403`
```json
{ "message": "برای این کار دوباره وارد شوید.", "request_id": "5d1e0c3b9a4f7e21" }
```

`POST /me/reauthenticate` proves one way into this very account again and opens these changes for 15 minutes more.
Offer the ways the account has:

| `method` | Body | Offer it when |
|---|---|---|
| `password` | `{ method, password, code? }` — `code` the app's code or a recovery code, as the second step takes it ([a password and the second step](Store-API-Sign-In.md#a-password-and-the-second-step)), required while `two_factor` is on; a recovery code used here is spent, as at a sign-in | `has_password` |
| `telegram` | the popup's `{ method, id_token, nonce }` ([the popup](Store-API-Sign-In.md#telegram-the-popup)), or — while `GET /`'s `sign_in.telegram.redirect` is true — the redirect's `{ method, code, state, code_verifier }` with a state this very session asked `POST /me/telegram/authorize` for ([the redirect](Store-API-Sign-In.md#telegram-the-redirect)) | `customer.telegram` is not `null`, and `GET /` offers Telegram |
| `google` | `{ method, id_token, nonce }` ([Google](Store-API-Sign-In.md#google)) | `google`, and `GET /` offers Google |

`POST /me/reauthenticate` request
```json
{ "method": "password", "password": "kooh-e damavand 1404", "code": "482913" }
```

`POST /me/reauthenticate` → `200`
```json
{ "expires_in": 900 }
```

Refusals are `422` under a field: a wrong password (`رمز عبور درست نیست.`) or code, or the code missing while two-factor
sign-in is on (`کد برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.`); an account without a password, on
`password` (`این حساب با رمز عبور وارد نمی‌شود؛ با روش ورود دیگری تایید کنید.`); a Telegram or Google proof that does
not hold (on `id_token`, or the redirect's `code`), or that proves another account (`این ورود مال حساب دیگری است؛ با یکی
از روش‌های ورود همین حساب تایید کنید.`); a `method` none of the three, on `method`. A way the website switched off since
`GET /` was read is a `422` with no field (`ورود با تلگرام برای این وب‌سایت فعال نیست.`, `ورود با Google برای این وب‌سایت
فعال نیست.`). Each failed proof counts as a failed sign-in — a code against the second step's budget too —, so `429`
past the limits of [limits and lifetimes](Store-API-Sign-In.md#limits-and-lifetimes); `502` the provider out of reach.
Adding a way in ([ways in](#ways-in-adding-and-removing)) proves only the way being added: it does not renew the 15
minutes.

A session remembers how it was signed in, too. One signed in with a password alone becomes a strong one — what the
shop's admin API asks of its admins ([the shop's admins](Store-API-Admins.md#signing-in)) — once a strong way is proven
on it here: Telegram, Google, or the password with its code. A password proven again alone renews the sign-in — the 15
minutes, and the 12 hours an admin's session works the admin API — but does not make it a strong one.

The site's part: send the change; on that `403`, ask the customer to prove a way in, then send the change again —
refused before it ran, it now runs for the first time.

```ts
/** A change of how the account is signed in to: on the 403 that asks a recent sign-in, proven again and sent again. */
export async function withRecentSignIn<T>(change: () => Promise<T>, reprove: () => Promise<void>): Promise<T> {
  try {
    return await change()
  } catch (e) {
    if (!(e instanceof StoreError) || !e.needsRecentSignIn) throw e
    await reprove() // your dialog: the password (and code), or Telegram's or Google's button → POST /me/reauthenticate
    return change()
  }
}

// await withRecentSignIn(() => store('/me/identities/google', { method: 'DELETE' }), askToProve)
```

A change that goes through Telegram or Google — adding one, or the redirect — is smoother proven before it starts: keep
when the session last proved itself (its sign-in, or a re-proof's `expires_in`) and ask first once 15 minutes have gone,
so the customer meets one prompt rather than a provider's window and then another.

**Every such change is told the customer** — a way in added or taken away, a password set or changed, two-factor
sign-in turned on or off, two accounts merged, the second step failed until it waits, the codes emailed to their address
failed until none goes for a while: as a notice ([notice types](Store-API-Notifications.md#notice-types)), and on every
door the account has — its Telegram chat *and* its email, since whoever made the change may hold one of them.

## Names

`PATCH /me` `{ first_name?, last_name? }`: only the fields sent change (an empty body changes nothing), and the answer
is `GET /me`'s. A first name is 1 to 64 characters, a last name 64 at most (`""` removes it). An account with Telegram
is refused (`422` under each field sent, `نام حساب‌های تلگرامی از تلگرام خوانده می‌شود.`): its names are Telegram's,
which the bot reads afresh. Hide the form while `customer.telegram` is not `null`.

## Password

`PUT /me/password` `{ current_password?, password }`, with a recent sign-in ([a recent sign-in](#a-recent-sign-in)). The
account must have an email (else `422` `اول یک ایمیل به حساب اضافه کنید.`); `current_password` is required while it has
a password (`has_password`), and a wrong one is `422` on `current_password` (`رمز عبور درست نیست.`) counted as a failed
sign-in. Without a password yet — a Google sign-up, say — this sets one. The new password follows the sign-up's rule
([signing up with an email](Store-API-Sign-In.md#signing-up-with-an-email)). Every other session of the customer ends;
this one stays, and the customer is told (`password_changed`).

## Two-factor sign-in

An authenticator app's code (TOTP: six digits, 30-second steps, RFC 6238) asked after the password. Only for an account
with an email and a password — else `422` (`ورود دو مرحله‌ای برای ورود با ایمیل و رمز است؛ اول ایمیل و رمز عبور را به
حساب اضافه کنید.`). Telegram and Google sign-ins are never asked: the provider signed the customer in. Each step below
asks a recent sign-in ([a recent sign-in](#a-recent-sign-in)); turning it on or off is told the customer
(`two_factor_enabled`, `two_factor_disabled`).

1. `POST /me/2fa/setup` (no body) answers a new secret, waiting 15 minutes for its first code; a later setup replaces it
   — while it is off (on already: `422` `ورود دو مرحله‌ای این حساب از قبل روشن است؛ برای کلید تازه، اول آن را خاموش
   کنید.`). Draw `uri` as a QR code — the site renders it, e.g. `QRCode.toDataURL(uri)` with the `qrcode` package — and
   show `secret` for typing in by hand. The URI's issuer is the shop's name, its account the customer's email.

   `POST /me/2fa/setup` → `200`
   ```json
   {
     "secret": "NA7NDMGJE74CMTA6EYKQXVVMQH5TV45Y",
     "uri": "otpauth://totp/Amo%20Shop:sara%40example.com?secret=NA7NDMGJE74CMTA6EYKQXVVMQH5TV45Y&issuer=Amo%20Shop&algorithm=SHA1&digits=6&period=30"
   }
   ```
2. `POST /me/2fa/enable` `{ code }` with the app's code turns it on and answers the ten recovery codes — shown this once:
   ask the customer to save them. Each is ten capital letters and digits without look-alikes, and signs in once in
   place of the app's code.

   `POST /me/2fa/enable` → `200`
   ```json
   { "recovery_codes": ["EVWJRCJMJX", "WWFJGJDFD4", "PUJNQZJSVR", "NTYX8TXUQJ", "PK8XHXEND9", "2YXYP83VP6", "C4GU7BH8CH", "F85HJMXH35", "MKMYTZEDDY", "ED3GBCHUJD"] }
   ```

   A wrong code is `422` on `code` (`کد درست نیست؛ کدی را که برنامه احراز هویت همین حالا نشان می‌دهد وارد کنید.`);
   after five, or past 15 minutes, `422` on `code` says to set it up again (`زمان راه‌اندازی ورود دو مرحله‌ای گذشته است؛
   آن را از اول راه‌اندازی کنید.`).
3. `POST /me/2fa/disable` `{ password }` turns it off (`422` on `password` when wrong, counted as a failed sign-in; off
   already, `422` `ورود دو مرحله‌ای این حساب روشن نیست.`).

A customer who lost their phone uses a recovery code, or asks support, who can turn it off from the panel — or one of
the shop's admins from its website, when it grants them that — the customer is told then too (`two_factor_disabled`),
in Telegram and by email. An admin's own two-factor sign-in is turned off from the panel alone.

## Devices

`GET /me/sessions` → `200`
```json
{
  "sessions": [
    { "id": 77, "device": "Chrome در Windows", "ip": "203.0.113.7", "created_at": "2026-10-07T12:00:00+00:00", "last_used_at": "2026-10-08T09:41:00+00:00", "current": true },
    { "id": 61, "device": "Safari در iOS", "ip": "198.51.100.23", "created_at": "2026-09-30T18:12:44+00:00", "last_used_at": "2026-10-06T21:05:10+00:00", "current": false }
  ]
}
```

Newest first, 20 at most ([tokens and sessions](Store-API-Sign-In.md#tokens-and-sessions)); `device` is what the
browser said it is, in words; `ip` the address it signed in from; `current` is the device asking; `last_used_at` is
written every few minutes, not on each request. `DELETE /me/sessions/{id}` ends one (`204`) — ending `current` signs this
device out, like `POST /auth/logout`. Another customer's session is `404`.

## Ways in: adding and removing

An account has at most one of each: a Telegram account, a Google account, an email (with its password). Each is proven
the way it signs in; adding and removing one ask a recent sign-in ([a recent sign-in](#a-recent-sign-in)):

| Add | Request | Answers |
|---|---|---|
| Telegram | `POST /me/identities/telegram` — the popup's `{ id_token, nonce }`, or the redirect's `{ code, state, code_verifier }` with a state this very session asked `POST /me/telegram/authorize` for ([the popup](Store-API-Sign-In.md#telegram-the-popup), [the redirect](Store-API-Sign-In.md#telegram-the-redirect)) | `200` `{ customer }`, or `202` a merge offer |
| Google | `POST /me/identities/google` — `{ id_token, nonce }` ([Google](Store-API-Sign-In.md#google)) | `200` `{ customer }`, or `202` a merge offer |
| Email | `POST /me/identities/email` — `{ email, password }`: a code to the address; then `POST /me/identities/email/verify` — `{ email, code }` | `202` `{ expires_in }`; then `200` `{ customer }`, or `202` a merge offer |

- `200` — added (the customer told: `way_in_added`), or it was this account's already. Telegram brings its handle and
  its names, in place of the names the account had (a Telegram account without a last name leaves none), and
  `PATCH /me` refuses them from then on; Google brings the address it speaks for ([Google](Store-API-Sign-In.md#google))
  when the account has none and nobody else has it; an email comes with the password chosen with it (told as the way in
  added, not as a password changed).
- `202` — it belongs to another account of this shop: a merge offer ([the merge offer](#the-merge-offer)). For Google,
  that is also when the Google account is new to the shop but the address it speaks for is another account's.
- `422` — the account has another of that kind (`این حساب به حساب تلگرام دیگری وصل است؛ اول آن را جدا کنید.`,
  `این حساب به حساب گوگل دیگری وصل است؛ اول آن را جدا کنید.`, `این حساب ایمیل دارد.`), a merge the rules refuse
  ([the merge offer](#the-merge-offer)), the website's Telegram or Google sign-in switched off, or Telegram's redirect not
  set up (`ورود با redirect برای این وب‌سایت آماده نیست؛ Client Secret تلگرام در پنل وارد نشده است.`) — those three with
  no field —, or a proof that did not hold: a Telegram or Google proof on `id_token` (or the redirect's `code`, a state
  another session asked for among them) — never a `401`, the session is fine —, an emailed code on `code`
  (`کد درست نیست یا منقضی شده است.`).
- The email's first step is also `503` while the shop's email does not go out, or its budget of sign-in emails is spent
  (`فعلا ایمیلی از این فروشگاه فرستاده نمی‌شود؛ کمی بعد دوباره امتحان کنید یا از راه دیگری وارد شوید.`), and `502` when
  the mail server did not take the email (`ایمیل فرستاده نشد؛ کمی بعد دوباره امتحان کنید.`). `GET /`'s `sign_in.email`
  says nothing of it while email sign-up is off: offer the email and handle these.

The email's code is sent whether or not another account has the address — it is proven first —, it is this account's
alone (another customer's code for the same address stays as it is), and it goes under the sign-up's email budgets
([limits and lifetimes](Store-API-Sign-In.md#limits-and-lifetimes)) plus one of its own: a customer asks for five such
codes a day. Wrong codes count against the address's budget and this account's.

`DELETE /me/identities/{telegram|google|email}` removes one and answers `{ customer }` (the customer told:
`way_in_removed`) — never the last (`422` `حداقل یک روش ورود باید بماند.`; one the account does not have, `422` `این روش
ورود به حساب وصل نیست.`). An email counts as a way in whether or not it has a password, so an account that signed up
with Google keeps its address when Google goes: without a password (`has_password` false) it then signs in only by
setting one through a forgotten password's code, or with Google again — say so before the customer removes Google. An
email goes with its password and two-factor sign-in, and the address is emailed that it is no way in any more. Removing
Telegram leaves the services with this account, and the bot will know that Telegram account as a new customer from its
next message — say so before the customer confirms. There is no "change email": remove it (another way in must remain)
and add the new one.

## The merge offer

A way in being added that is another account's of the same shop answers `202`:

`POST /me/identities/telegram` → `202`
```json
{
  "merge": {
    "token": "6a4ae800353b0c5c9a84b8a84d6caabce080931b4a069c6140425447d532f462",
    "expires_in": 900,
    "account": {
      "name": "علی رضایی",
      "created_at": "2025-11-02T17:20:00+00:00",
      "services": 1,
      "orders": 4,
      "balance": "15000.00",
      "telegram": true,
      "email": false,
      "google": false
    },
    "keeps": "other"
  }
}
```

Show the customer the other account so they can tell it is theirs — its name (`null` without one), since when, its
running `services`, its `orders`, its `balance`, the ways it signs in — and what a merge means:

- **The older account always stays.** `keeps: "this"` — the account signed in stays and the other merges into it.
  `keeps: "other"` — the signed-in account merges into the other one; the customer stays signed in, as that account,
  whose `id` is the one from then on.
- **Everything moves to the account that stays:** orders and their payments, services, the wallet (one ledger, the
  balances added up), every device signed in, the notices, the support tickets, the reviews, the customers it brought,
  an agency request. Its ways in, names, phone and invite code fill the empty places of the account that stays, and so does what
  was being added: the Telegram or Google account, or the email. The password chosen with an email becomes the password
  of the account that stays whenever that email is its address — replacing one it had — and ends every other session of
  that account. The other account is gone.

Yes is `POST /me/merge` with the ticket, with a recent sign-in ([a recent sign-in](#a-recent-sign-in)); it answers the
account that stays, the customer told (`account_merged` — and `password_changed` when the merge set the password chosen
with an email) — replace the customer you keep, and read everything again. No is doing nothing: the ticket expires in 15
minutes and nothing changes.

`POST /me/merge` request
```json
{ "token": "6a4ae800353b0c5c9a84b8a84d6caabce080931b4a069c6140425447d532f462" }
```

Refused at the offer, with no ticket (`422`): either account is banned; both have a *different* account of one kind
(`هر دو حساب به یک نوع روش ورود متفاوت وصل هستند (تلگرام)؛ اول یکی را جدا کنید.` — one must be removed first); both are
agents; or the other account was reached through its email alone — an emailed code, or the address Google speaks for —
and has two-factor sign-in on (`این ایمیل به حسابی با ورود دو مرحله‌ای وصل است؛ برای یکی کردن دو حساب، با همان ایمیل
وارد شوید و روش ورود این حساب را از آنجا وصل کنید.`): the customer signs in to that account and adds this one's way in
from there. At `POST /me/merge`, `422` `این پیشنهاد ادغام دیگر معتبر نیست؛ دوباره تلاش کنید.` means the ticket is spent,
expired or not this account's, or the other account no longer has that way in: start over. The merge is judged again
there too, so the refusals of the offer may come back — the other account banned, or given a way in of a kind this one
has, or reached by its address and its two-factor sign-in turned on since —, each a `422` in the same words.

The shop keeps a record of every merge — the account that stays, the merged one's former number and ways in, what
moved —, which support sees on the customer's page in the panel.
