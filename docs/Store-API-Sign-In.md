# Store API: signing in

How a customer signs in to a shop's website, and what a session is. Paths are under the
[base address](Store-API.md#the-base-address); the samples use the [minimal client](Store-API.md#a-minimal-client).

## Tokens and sessions

Every successful sign-in answers the same shape:

`POST /auth/telegram` → `200`
```json
{
  "token": "93a3e53f9ef695f274f228abff720dce204880d36090fd749cc79dd9f489dfea",
  "customer": {
    "id": 42,
    "first_name": "علی",
    "last_name": null,
    "telegram": { "id": 123456789, "username": "ali" },
    "email": null,
    "google": false,
    "has_password": false,
    "two_factor": false,
    "phone": "+989123456789",
    "balance": "380000.00",
    "created_at": "2026-03-01T08:30:00+00:00"
  }
}
```

- `token` — 64 hex characters, shown this once. Send it as `Authorization: Bearer <token>` to every customer endpoint.
  `customer` is the account ([the account](Store-API-Account.md#the-account)).
- One token is one device: a session. A customer keeps 20 at most. A session ends — its token opens nothing from then
  on — when:
  - the customer signs that device out (`POST /auth/logout`), or ends it from another
    ([devices](Store-API-Account.md#devices));
  - their password is changed — by `PUT /me/password` ([password](Store-API-Account.md#password)), or set by a merge
    ([the merge offer](Store-API-Account.md#the-merge-offer)): every session but the one that did it — or reset
    (`POST /auth/password/reset`, or its second step, [a forgotten password](#a-forgotten-password)): every session;
  - support signs them out of every device — from a panel, or one of the shop's admins on its website;
  - a sign-in opens their 21st: the oldest ends;
  - it went 30 days unused, or 180 days have passed since its sign-in, whatever its use.
- An ended token makes every customer endpoint answer `401` `برای دسترسی باید وارد شوید.` with
  `WWW-Authenticate: Bearer` — every signed-in operation declares it, and there it means nothing else: forget the token
  and show the sign-in. There is no refresh token. A banned customer's token answers `403` instead
  (`حساب کاربری شما مسدود شده است.`).

  `GET /me` → `401`
  ```json
  { "message": "برای دسترسی باید وارد شوید.", "request_id": "c8e2f4a6b0d1e3f5" }
  ```
- A sign-in is *recent* for 15 minutes: what changes how the account is signed in to asks that of the session
  ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)). And it is *strong* when it came by Telegram, by Google,
  or by a password with its second step — what the shop's admin API asks of its admins
  ([the shop's admins](Store-API-Admins.md#signing-in)).
- Keep a customer's token in the browser (`localStorage`, so they stay signed in) and send it nowhere but the API. Any
  script on your pages can read it, so keep the site free of XSS — a Content-Security-Policy, no untrusted HTML (the
  notices' `html` is safe already). An admin's token is kept in memory or `sessionStorage`, never `localStorage`
  ([the shop's admins](Store-API-Admins.md#signing-in)): the [minimal client](Store-API.md#a-minimal-client)'s
  `keepToken(token, adminTokens)`.
- Signing in again opens another session: sign the old token out first when you replace one.

## Which ways in

`sign_in` in `GET /` ([what the site asks first](Store-API-Shop.md#what-the-site-asks-first)) says what the website
offers:

- `telegram` — `{ client_id, redirect }` while Telegram sign-in is on, else `null`; `redirect` is true when the
  redirect flow is set up too ([Telegram: the redirect](#telegram-the-redirect)).
- `google` — `{ client_id }` while Google sign-in is on, else `null`.
- `email` — whether signing up with an email is offered (it is on, and the shop's email goes out). A customer who has
  an email and a password signs in with them whatever this says: always show the password sign-in and the
  forgotten-password link.

Beside it, `GET /`'s `captcha` says whether a sign-up, a password sign-in and a password reset must carry the website's
captcha, and how to draw it ([the captcha](#the-captcha)).

## Telegram: the popup

Telegram's own library runs the OpenID Connect flow in a popup and hands the page an `id_token`; the shop checks it.

1. Load `https://oauth.telegram.org/js/telegram-login.js`.
2. Before the customer clicks — when the sign-in dialog opens — ask `POST /auth/nonce` for a nonce. A popup must open
   from the click itself; awaiting a request first can get it blocked.

   `POST /auth/nonce` → `200`
   ```json
   { "nonce": "af8f5b24ef09ddb015185edffaf60717db93bfe4dcb0d37a6b73dcbae51920d7", "expires_in": 1800 }
   ```
3. On the click, `Telegram.Login.auth({ client_id, scope: ['profile', 'write'], nonce }, callback)` — the `client_id`
   from `GET /` as a number. `profile` is required: the token must carry the account's numeric id, the one the bot
   knows its customers by (without it, `401` `تلگرام شناسه حساب را نفرستاد؛ ورود باید دسترسی profile را بخواهد.`).
   `write` lets the shop's bot message the customer — a customer with Telegram gets their notices there
   ([notifications](Store-API-Notifications.md)).
4. Post the `id_token` with the same nonce, and the visitor's invite code if any
   ([invite codes at sign-up](#invite-codes-at-sign-up)):

   `POST /auth/telegram` request
   ```json
   {
     "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjEifQ.eyJpc3MiOiJodHRwczovL29hdXRoLnRlbGVncmFtLm9yZyJ9.c2lnbmF0dXJl",
     "nonce": "af8f5b24ef09ddb015185edffaf60717db93bfe4dcb0d37a6b73dcbae51920d7",
     "referral_code": "k7m2xq9p"
   }
   ```
5. `200` answers the token ([tokens and sessions](#tokens-and-sessions)). The customer is the shop's customer with that
   Telegram account — the bot's own, services and wallet included — or a newcomer, registered exactly as the bot
   registers one.

```tsx
'use client'
import Script from 'next/script'
import { useCallback, useEffect, useState } from 'react'
import { keepToken, store, type S } from '@/lib/store'

declare global {
  interface Window {
    Telegram?: {
      Login: {
        auth(
          options: { client_id: number; scope?: ('profile' | 'phone' | 'write')[]; nonce?: string; lang?: string },
          callback: (result: { id_token?: string; user?: unknown; error?: string }) => void,
        ): void
      }
    }
  }
}

export function TelegramButton({ clientId, referralCode }: { clientId: string; referralCode?: string }) {
  const [nonce, setNonce] = useState<string>()
  const renew = useCallback(() => {
    store<S['StoreNonceResponse']>('/auth/nonce', { method: 'POST' }).then((r) => setNonce(r.nonce))
  }, [])
  useEffect(renew, [renew])

  function open() {
    if (!nonce || !window.Telegram) return
    window.Telegram.Login.auth({ client_id: Number(clientId), scope: ['profile', 'write'], nonce }, async (result) => {
      if (result.error || !result.id_token) return // closed, or refused in Telegram's window: the nonce is unused
      try {
        const { token } = await store<S['StoreSignInResponse']>('/auth/telegram', {
          method: 'POST',
          json: { id_token: result.id_token, nonce, referral_code: referralCode },
        })
        keepToken(token)
      } catch (e) {
        // show (e as StoreError).message
      } finally {
        renew() // a nonce goes with the sign-in it was posted with
      }
    })
  }

  return (
    <>
      <Script src="https://oauth.telegram.org/js/telegram-login.js" strategy="afterInteractive" />
      <button type="button" onClick={open} disabled={!nonce}>Sign in with Telegram</button>
    </>
  )
}
```

Refusals: `401` — the token is not one, was made for another site
(`این ورود برای این وب‌سایت صادر نشده است؛ Client ID وب‌سایت را بررسی کنید.` — use `GET /`'s Client ID), expired, or
its nonce is spent or expired: get a new nonce and try again; `403` banned; `422` Telegram sign-in off, or a field
missing (`nonce`, or `id_token` when neither it nor a redirect's `code` is sent); `429` too many failures; `502` Telegram
out of reach — the nonce stays good for another try. `POST /auth/nonce` itself is `429` once this address network asked
for 120 nonces, states and codes in 10 minutes, and `503`
(`ورود به وب‌سایت این فروشگاه الان شلوغ است؛ چند دقیقه دیگر دوباره امتحان کنید.`) while the shop keeps as many sign-ins
begun as it takes ([limits and lifetimes](#limits-and-lifetimes)).

## Telegram: the redirect

When `sign_in.telegram.redirect` is true (the owner saved the Client Secret), the site may send the whole window to
Telegram instead — no popup, which suits in-app browsers. It is the authorization-code flow with PKCE, and the PKCE
verifier is the browser's own: the site makes it, keeps it, and sends the shop only its challenge, so a code and state
another browser brings back (a link someone made from their own sign-in) open nothing.

1. Make a verifier — 43 to 128 random characters of `A-Z a-z 0-9 - . _ ~` — and its challenge, the SHA-256 of the
   verifier in base64url without padding (S256: 43 characters). Keep the verifier where the page Telegram sends the
   browser back to can read it (`sessionStorage`). `crypto.subtle` needs a secure context: an `https` page, or
   `localhost`.
2. Ask for Telegram's address with the challenge and the page Telegram should send the browser back to: an `http(s)`
   address on the website's origin or one it lists, without `#`, and listed under Allowed URLs in @BotFather. A
   sign-in asks `POST /auth/telegram/authorize`; a signed-in customer adding their Telegram account
   ([ways in](Store-API-Account.md#ways-in-adding-and-removing)) or proving it again
   ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)) asks `POST /me/telegram/authorize`, with their token —
   the same body and answer, but a state of that session's own: only a request with the same token takes it back.

   `POST /auth/telegram/authorize` request
   ```json
   {
     "redirect_uri": "https://shop.example.com/auth/telegram/callback",
     "code_challenge": "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
   }
   ```

   `POST /auth/telegram/authorize` → `200`
   ```json
   {
     "url": "https://oauth.telegram.org/auth?client_id=7012345678&redirect_uri=https%3A%2F%2Fshop.example.com%2Fauth%2Ftelegram%2Fcallback&response_type=code&scope=openid%20profile%20telegram%3Abot_access&state=206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&nonce=a47fde7a40fa8ca1afacdaee073feb3f"
   }
   ```
3. Keep the `state` that `url` carries beside the verifier, then send the browser to `url`. The shop keeps the state
   with the challenge, the return address and a nonce — for 10 minutes — and asks for the profile and the bot's right to
   write to the customer itself.
4. Telegram sends the browser back to `redirect_uri?code=…&state=…`. **Compare that `state` with the one you kept
   before you post anything**, and post nothing when it differs or you kept none. Then post `code`, `state` and the
   kept `code_verifier` where the flow began — a sign-in's to `POST /auth/telegram`, a signed-in customer's to
   `POST /me/identities/telegram` or `POST /me/reauthenticate`, with the token that asked for it. A state is taken back
   by its own flow alone, and only with the verifier its challenge was made of: a sign-in's proves nothing on `/me/*`; a
   customer's signs nobody in, and proves nothing from another device of theirs or with a token signed in since it was
   asked.

```ts
type TelegramPurpose = 'sign-in' | 'link' | 'reauth' // link: adding Telegram to the account; reauth: proving it again

const base64Url = (bytes: Uint8Array) =>
  btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

// The sign-in page, or the account page
export async function startTelegramRedirect(purpose: TelegramPurpose, referralCode?: string) {
  const verifier = base64Url(crypto.getRandomValues(new Uint8Array(32))) // 43 characters, this browser's alone
  const challenge = base64Url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))))
  const { url } = await store<S['StoreAuthorizeResponse']>(purpose === 'sign-in' ? '/auth/telegram/authorize' : '/me/telegram/authorize', {
    method: 'POST',
    json: { redirect_uri: `${location.origin}/auth/telegram/callback`, code_challenge: challenge },
  })
  const state = new URL(url).searchParams.get('state')
  sessionStorage.setItem('telegram-redirect', JSON.stringify({ purpose, state, verifier, referralCode }))
  location.assign(url)
}

// app/auth/telegram/callback/page.tsx — a client page
export async function finishTelegramRedirect() {
  const query = new URLSearchParams(location.search)
  const code = query.get('code')
  const state = query.get('state')
  history.replaceState(null, '', location.pathname) // a code opens once: keep it out of the history
  const kept = JSON.parse(sessionStorage.getItem('telegram-redirect') ?? 'null') as
    | { purpose: TelegramPurpose; state: string | null; verifier: string; referralCode?: string }
    | null
  sessionStorage.removeItem('telegram-redirect')
  if (!code || !state || !kept || state !== kept.state) return // cancelled, or not the redirect this tab began: post nothing

  const proof = { code, state, code_verifier: kept.verifier }
  // link and reauth go with the token that asked: the state is that session's alone
  if (kept.purpose === 'link') {
    return store<S['StoreMeResponse'] | S['StoreMergeOfferResponse']>('/me/identities/telegram', { method: 'POST', json: proof })
  }
  if (kept.purpose === 'reauth') {
    return store<S['StoreReauthenticatedResponse']>('/me/reauthenticate', { method: 'POST', json: { method: 'telegram', ...proof } })
  }
  const { token } = await store<S['StoreSignInResponse']>('/auth/telegram', {
    method: 'POST',
    json: { ...proof, referral_code: kept.referralCode },
  })
  keepToken(token)
}
```

Refusals besides the popup's: `422` on `redirect_uri` (`آدرس بازگشت باید یک آدرس http یا https روی آدرس وب‌سایت یا
یکی از Originهای مجاز آن باشد، بدون #.`) or on `code_challenge` (none, or not an S256 challenge), `422` on `state` when
it is missing and on `code_verifier` when it is missing or not 43 to 128 of `A-Z a-z 0-9 - . _ ~` — the state still
good —, `422` while no Client Secret is kept (`ورود با redirect برای این وب‌سایت آماده نیست؛ Client Secret تلگرام در پنل
وارد نشده است.`), `401` when the state is spent, older than 10 minutes or another flow's, or the verifier is not the one
its challenge was made of (`این درخواست ورود منقضی شده یا قبلا استفاده شده است؛ دوباره وارد شوید.`), or Telegram
refuses the code (`تلگرام کد ورود را نپذیرفت؛ دوباره وارد شوید.`). A post that passed those field checks spends its
state, whatever comes of it: after a `401` — or a `502`, Telegram out of reach — start again from step 1, with a new
verifier. On `/me/*` the same failures — and a state another session asked for — are a `422` on `code`, the session
untouched. Asking for the address is `429` and `503` as a nonce is.

## Google

Google Identity Services hands the page an `id_token` (its `credential`) for a nonce the site asked the shop for.

```tsx
'use client'
import Script from 'next/script'
import { useRef } from 'react'
import { keepToken, store, type S } from '@/lib/store'

// Types: npm i -D @types/google.accounts
export function GoogleButton({ clientId, referralCode }: { clientId: string; referralCode?: string }) {
  const button = useRef<HTMLDivElement>(null)

  async function mount() {
    const { nonce } = await store<S['StoreNonceResponse']>('/auth/nonce', { method: 'POST' })
    google.accounts.id.initialize({
      client_id: clientId, // GET / → sign_in.google.client_id
      nonce,
      callback: async ({ credential }) => {
        try {
          const { token } = await store<S['StoreSignInResponse']>('/auth/google', {
            method: 'POST',
            json: { id_token: credential, nonce, referral_code: referralCode },
          })
          keepToken(token)
        } catch (e) {
          // show (e as StoreError).message; then a fresh nonce for the next try:
          void mount()
        }
      },
    })
    google.accounts.id.renderButton(button.current!, { type: 'standard', theme: 'outline', size: 'large', locale: 'fa' })
  }

  return (
    <>
      <Script src="https://accounts.google.com/gsi/client" strategy="afterInteractive" onReady={() => void mount()} />
      <div ref={button} />
    </>
  )
}
```

Keep Google's default popup mode: its redirect mode posts the credential to your server instead of the page. The
customer signed in is the account this Google account already signs in to; else the account with the address Google
speaks for, which signs in with this Google account from then on (its customer is told); else a newcomer, with that
address. Google speaks for an address only where it is the address's own authority: `email_verified`, and an
`@gmail.com` address or a Google Workspace account's. Any other address — verified by Google once, its domain's owner
today perhaps someone else — finds no account, takes none on and is not kept: such a newcomer has no email until they
add one ([ways in](Store-API-Account.md#ways-in-adding-and-removing)). A Google sign-in does not change an account's
names.

Two accounts are never opened by their address alone — one with two-factor sign-in on, and one another Google account
signs in to: Google's sign-in answers `409` `حسابی با این ایمیل هست؛ با همان روش وارد شوید و گوگل را از تنظیمات حساب
وصل کنید.` The customer signs in the way that account does and adds Google from their account
([ways in](Store-API-Account.md#ways-in-adding-and-removing)). The other refusals mirror Telegram's: `401`, `403`
banned, `422` Google sign-in off or a field missing (`id_token`, `nonce`), `429`, `502` Google out of reach.

## Signing up with an email

Offered while `sign_in.email` is true. Two steps, so every address the shop keeps is proven:

`POST /auth/register` request
```json
{
  "first_name": "سارا",
  "last_name": "احمدی",
  "email": "sara@example.com",
  "password": "kooh-e damavand 1404",
  "referral_code": "k7m2xq9p",
  "captcha": "0.mT9z3bVr2Q8yKp1sWnA4hD7eLc"
}
```

`POST /auth/register` → `202`
```json
{ "expires_in": 900 }
```

A six-digit code goes to the address — or, when the address has an account already, an email saying so. The answer is
the same either way: which addresses have accounts is told to nobody. Word the next screen accordingly: "We sent an
email to sara@example.com. Enter its code — or, if you already have an account, sign in." Asking again sends a new code
in place of the last. Emails are held to budgets ([limits and lifetimes](#limits-and-lifetimes)): once a minute and ten
times a day an address, twenty an hour a network (`429`), and the shop's own hour and day and the whole installation's
(`503` while one is spent); a request that sent nothing — refused, or its email not gone out — gives those turns back,
though it still counts against what the network may cost the shop. The sign-up's email carries nothing the requester
typed.

`POST /auth/register/verify` request
```json
{ "email": "sara@example.com", "code": "۴۸۲۹۱۳" }
```

`200` answers the token ([tokens and sessions](#tokens-and-sessions)): the account is made and signed in — reported to
the shop, and the invite code's owner told.

| Field | Rule |
|---|---|
| `first_name` | 1 to 64 characters |
| `last_name` | 64 at most; blank or left out for none |
| `email` | an address, 191 characters at most; kept in lower case |
| `password` | 8 characters at least, 72 bytes at most (a Persian letter is two bytes), no NUL character; taken exactly as typed, spaces and all |

Every field's refusal comes at once (`422` under each), then the captcha's. A code is six digits — Persian or Arabic
ones too, but nothing else: take out any space or dash the customer typed before sending it (a code of another shape is
refused as a wrong one, and counted as a failed sign-in). A code lasts 15 minutes and takes five tries; wrong, expired, spent or tried
out is `422` on `code` (`کد درست نیست یا منقضی شده است.`). The wrong codes an address's emails take have a budget of
their own — ten an hour and twenty a day, each counted before it is checked —, then `429`
(`کد اشتباه زیادی برای این ایمیل وارد شد؛ …`) for every six-digit code, no new code goes to the address until it has
room again, and its account, if it has one, is told once a day (`email_codes_failed`). `409` means the address got an
account in the meantime: send the customer to the password sign-in. `503` means the shop's email does not go out;
`502` that this email did not.

## A password and the second step

`POST /auth/login` request
```json
{ "email": "sara@example.com", "password": "kooh-e damavand 1404", "captcha": "0.mT9z3bVr2Q8yKp1sWnA4hD7eLc" }
```

- `200` — the token ([tokens and sessions](#tokens-and-sessions)).
- `202` — the password was right, and the account asks the code of its authenticator app. No session yet:

  `POST /auth/login` → `202`
  ```json
  { "two_factor": { "challenge": "206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6", "expires_in": 300 } }
  ```
- `401` `ایمیل یا رمز عبور درست نیست.` — either is wrong; the API never says which, and a wrong address takes as long
  as a wrong password.
- `403` banned, `422` a field or the captcha, `429` too many failures from this address or for this account
  ([limits and lifetimes](#limits-and-lifetimes)) — or, while the website asks a captcha, the address network past what
  it may cost the shop (`درخواست‌ها زیاد بود؛ …`: each token is counted against it before it is judged) —, `503` the
  captcha could not be judged.

The second step posts the challenge with the six-digit code the app shows now — or one of the account's recovery codes
([two-factor sign-in](Store-API-Account.md#two-factor-sign-in)). Persian digits, spaces and dashes are fine; recovery
codes take any case.

`POST /auth/login/2fa` request
```json
{ "challenge": "206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6", "code": "۴۸۲ ۹۱۳" }
```

`200` answers the token. A wrong code is `422` on `code` (`کد درست نیست؛ کد شش‌رقمی برنامه احراز هویت یا یکی از
کدهای بازیابی را وارد کنید.`); the challenge takes five codes and lasts five minutes. A code opens once: two sign-ins
with one code, one gets in. `401` means it opens nothing any more (expired, tried out, or two-factor sign-in turned off
meanwhile): go back to the password.

Whoever reaches this step has the password, so the account's codes have a budget of their own, from every address
together: 10 wrong codes an hour and 20 a day, each counted before it is checked (a right one gives its count back).
Once it is spent the answer is `429` for any code of the right shape — six digits, or a recovery code's ten characters
(anything else stays a `422` and costs nothing) —, and the customer is told — once a day, a `second_step_locked` notice
([notice types](Store-API-Notifications.md#notice-types)).

```ts
const answer = await store<S['StoreSignInResponse'] | S['StoreTwoFactorResponse']>('/auth/login', {
  method: 'POST',
  json: { email, password, captcha },
})
if ('two_factor' in answer) {
  const code = await askForCode() // your form: six digits, or a recovery code
  const { token } = await store<S['StoreSignInResponse']>('/auth/login/2fa', {
    method: 'POST',
    json: { challenge: answer.two_factor.challenge, code },
  })
  keepToken(token)
} else {
  keepToken(answer.token)
}
```

An old password hash is made again with PHP's current default as the customer signs in: nothing for the site to do.

## A forgotten password

1. `POST /auth/password/forgot` `{ email, captcha? }` → `202` `{ "expires_in": 900 }`. A code goes to an address with
   an account; to one without, an email saying it has none — once a day (asked again that day, nothing goes). The
   answer is the same either way, and so are the budgets the request is counted against, so neither tells which
   addresses have accounts ([limits and lifetimes](#limits-and-lifetimes)); `503` while the shop's email does not go
   out.
2. `POST /auth/password/reset` `{ email, code, password }` → `200` the token: the password set, every session the
   account had ended, the customer told (`password_changed`). With two-factor sign-in on (a reset leaves it on), `202`
   `{ two_factor }` instead and nothing changes yet: `POST /auth/login/2fa` as above sets the password, ends the
   sessions and signs in.

This works for every account with an email, whatever `sign_in.email` says — and it is how a customer who signed up
with Google, and never set a password, gets one. A wrong code is `422` on `code`, counted against the address's budget
of wrong codes as a sign-up's is; the password follows the sign-up's rule.

## The captcha

While `GET /`'s `captcha` is not `null`, four forms must carry `captcha`, the widget's token, solved for the form's
own action (the contract's `x-captcha`):

| Form | Action |
|---|---|
| `POST /auth/register` | `sign_up` |
| `POST /auth/login` | `sign_in` |
| `POST /auth/password/forgot` | `password_reset` |
| `POST /reviews` | `review` ([reviews](Store-API-Reviews.md#writing-one)) — without a captcha, the website takes its signed-in customers' reviews alone |

None, or a token that does not pass — solved for another action, on a host that is not the website's (its address and
its origins), expired or used before — is `422` on `captcha` (`تایید امنیتی انجام نشد؛ دوباره تلاش کنید.`); a captcha
the shop could not judge right now is `503` (`تایید امنیتی در دسترس نیست؛ کمی بعد دوباره تلاش کنید.`). A token is good
once: reset the widget after every answer and wait for a fresh token before the next submission. A refused token on a
sign-in form counts against what the address network may cost the shop (120 nonces, states, codes and refused tokens in
10 minutes); on a review, against the reviews' own pace ([reviews](Store-API-Reviews.md#writing-one)).

`captcha` is `{ driver, site_key, challenge_url }`, one of two captchas:

- **`turnstile`** — Cloudflare Turnstile. Draw its widget with `site_key`, naming the form's action (`action` of
  `turnstile.render`, or `data-action`); `challenge_url` is `null`. A token is good for 300 seconds. The shop checks it
  with Cloudflare.

  ```ts
  // <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" onReady={…} />
  declare const turnstile: {
    render(el: HTMLElement | string, o: { sitekey: string; action?: string; callback?: (t: string) => void; 'expired-callback'?: () => void }): string
    reset(widgetId?: string): void
  }

  const widgetId = turnstile.render('#captcha', {
    sitekey: shop.captcha!.site_key!,
    action: 'sign_in', // the form's action
    callback: (token) => setCaptcha(token),
    'expired-callback': () => setCaptcha(undefined),
  })
  // …after every answer to a request that carried it:
  turnstile.reset(widgetId)
  setCaptcha(undefined)
  ```
- **`altcha`** — [ALTCHA](https://altcha.org), open source: no third party and no keys (`site_key` is `null`), so it
  works on any host, where Cloudflare cannot be reached too. Its widget fetches a challenge from the shop at
  `challenge_url` with the form's action — `GET /captcha/challenge?action=sign_up` —, a new one every time, never
  cached; the visitor's browser solves it (a moment's work) and the widget hands the form a payload (base64 JSON), which
  goes as `captcha`. A challenge is solved and sent once, within 15 minutes; its solution passes no form of another
  action. `GET /captcha/challenge` is `404` (`تایید امنیتی این وب‌سایت چالشی از فروشگاه نمی‌گیرد.`) while the website's
  captcha draws its own (Turnstile) or it asks none, and `422` on `action` for one that is not 1 to 32 of `A-Z a-z 0-9 - _`.

  ```tsx
  // npm i altcha — the <altcha-widget> web component
  import 'altcha'

  <altcha-widget challengeurl={`${shop.captcha!.challenge_url}?action=sign_up`} ref={widget} />
  // its `statechange` event carries the payload once solved: ev.detail.state === 'verified' → ev.detail.payload
  ```

**A form of the site's own** — a contact form, a newsletter's sign-up — may carry the same captcha, under an action of the site's own
(1 to 32 of `A-Z a-z 0-9 - _`), and the site's backend asks the shop to judge it, server to server, as the visitor's
browser posted it: a browser's word that it passed proves nothing.

`POST /captcha/verify` request
```json
{ "token": "0.mT9z3bVr2Q8yKp1sWnA4hD7eLc", "action": "contact", "remoteip": "203.0.113.7" }
```

`POST /captcha/verify` → `200`
```json
{ "passed": false, "action": "sign_in", "hostname": "shop.example.com", "verified_at": "2026-10-08T10:15:00+00:00", "reasons": ["action-mismatch"] }
```

The answer is `200` whether it passed or not: `action` and `hostname` are what the token was solved for and on, as far
as the captcha says (ALTCHA names no host), and `reasons` why it did not pass — `hostname-mismatch` (solved on none of
the website's hosts), `action-mismatch`, `timeout-or-duplicate` (expired, or taken before), `invalid-input-response`,
`missing-input-response`, and Cloudflare's own codes as it says them. A token is taken once.

`remoteip`, optional, is the visitor's address as your backend received it (IPv4 or IPv6): the shop counts that
visitor's refused tokens by its network (an IPv6 address by its /64) and, with Turnstile, hands it to Cloudflare with the
token. Leave it out and your backend's own address stands for every visitor.

`409` while the website asks no captcha (`این وب‌سایت تایید امنیتی ندارد؛ آن را در پنل، تنظیمات وب‌سایت، روشن کنید.`);
`422` on `token` (none, or past 4096 characters), on `action`, or on `remoteip` for what is no IP address
(`remoteip باید آدرس IP بازدیدکننده باشد (IPv4 یا IPv6).`); `503` while it could not be judged. And `429`
(`درخواست تایید زیادی فرستاده شد؛ …`), counted before the token is judged — so a refused request asks Cloudflare nothing —,
two ways:

- **The website's**: 3,000 tokens asked in 10 minutes, passed or not — your backend is one caller for every visitor.
- **A visitor's network's**: 120 refused tokens in 10 minutes from the network `remoteip` names — else your backend's.
  A token that passes, or that could not be judged, gives its count back: only refused ones spend it.

This is the website's own budget: nothing asked here touches what the sign-ins may cost, so a bot posting junk tokens to
your forms keeps nobody from signing in. Send `remoteip`, so a bot spends its own network's 120 rather than every
visitor's, and hold your form's own submissions to a pace of your own before asking the shop.

## Invite codes at sign-up

`POST /auth/telegram`, `/auth/google` and `/auth/register` take `referral_code`, a customer's invite code
([the referral program](Store-API-After-Buying.md#the-referral-program)). Only a newcomer — an account the sign-in makes
— becomes the code's owner's referral; a customer the shop knew already is never attributed again. An unknown code, a
banned customer's, or the program switched off at that moment, is ignored without an error. Keep the code a visitor arrived with and send it with their
sign-ins:

```ts
// Any page: https://shop.example.com/?ref=k7m2xq9p
const ref = new URLSearchParams(location.search).get('ref')
if (ref) localStorage.setItem('referral-code', ref)
// …later: referral_code: localStorage.getItem('referral-code') ?? undefined
```

## Limits and lifetimes

| What | Limit |
|---|---|
| Failed sign-ins of every way — Telegram, Google, a password, an emailed code, a second step's code, the password typed again before a change, a way in proven again | 30 per client address in 15 minutes; the window opens with the first failure, and a success does not close it. IPv6 counts by its /64 |
| Failed passwords and codes for one email address | 50 in 15 minutes, every address together |
| Second-step codes for one account | 10 wrong an hour and 20 a day, every address together ([a password and the second step](#a-password-and-the-second-step)) |
| Wrong codes emailed to one address — and tried by the account adding an email | 10 an hour and 20 a day, each counted before it is checked; then no new code goes to the address until it has room, and its account is told once a day |
| What a sign-in costs the shop: nonces, redirect states, emails asked for, a sign-in form's captcha tokens refused | 120 per client address in 10 minutes |
| Emails one client address network asks for | 20 an hour |
| Emails to one address | one a minute, and 10 a day from every shop together |
| Codes a signed-in customer asks for, adding an email | 5 a day |
| An email telling an address it has no account | once a day |
| The sign-in emails the shop sends | 60 an hour and 300 a day; every shop of the installation together, 150 an hour and 1,000 a day. Spent, no code goes out until there is room (`503`) |
| The sign-ins a shop begins (nonces; redirect states, a sign-in's and a signed-in customer's together) | 5,000 nonces in 30 minutes and, apart, 5,000 states in 10 minutes, counted as they are issued; past either, `503` until its window ends |
| Captcha tokens the site's backend asks the shop to judge | 3,000 a website in 10 minutes, and 120 refused ones of a visitor's network (`remoteip`'s, else the backend's) in the same window — never the sign-ins' budget above |
| A nonce | 30 minutes, one use |
| A redirect's state | 10 minutes |
| An emailed code | 15 minutes, 5 tries; a new one replaces it |
| A second step's challenge | 5 minutes, 5 codes |
| An authenticator secret waiting for its first code | 15 minutes, 5 tries |
| An ALTCHA challenge | 15 minutes, one use |
| A recent sign-in | 15 minutes from the sign-in, or from the last `POST /me/reauthenticate` ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)) |
| A merge offer | 15 minutes, one use |
| A session | 30 days unused, 180 days at most; 20 a customer |

A `429` carries `Retry-After` in seconds and says the wait in its message. The address limits are loose on purpose — a
mobile carrier puts many customers behind one address — and the account limits are what stops a guesser; still, ask
for a nonce only when a customer is about to sign in, not on every page view.

## What the owner sets up

- **The website** — «تنظیمات وب‌سایت › اتصال»: switch it on, enter the site's address and any extra origins
  (`http://localhost:3000` while building), copy the base address.
- **Telegram** — in @BotFather, the shop's bot › Bot Settings › Login Widget: add the site's address, and the redirect
  page's for the redirect flow, under Allowed URLs. Copy the Client ID (and the Client Secret, for redirect) into
  «تنظیمات وب‌سایت › ورود با تلگرام» and switch it on. The Client ID is BotFather's, not necessarily the bot's id.
- **Google** — Google Cloud Console › APIs & Services › Credentials › Create Credentials › OAuth client ID, a Web
  application (Google asks for its consent screen first). Add the site's origin under Authorized JavaScript origins —
  and, for local work, `http://localhost` and `http://localhost:3000` — and copy the Client ID into «ورود با گوگل»
  (blank turns Google sign-in off).
- **Email** — the installation's email must go out: «تنظیمات پنل › ایمیل» in the owner's panel, with a test send. Then
  switch on «ورود با ایمیل › ثبت‌نام با ایمیل». In an agent's shop, the owner sets the email up.
- **Captcha** — «تنظیمات وب‌سایت › ورود با ایمیل», the «تایید امنیتی» card: ALTCHA needs nothing more; for Cloudflare
  Turnstile, add a widget for the site's hostname in the Cloudflare dashboard (Turnstile) and enter its Site Key and
  Secret Key there.
- **Reviews** — «تنظیمات وب‌سایت › نظرات»: switch «نظرات در وب‌سایت» on, or `/reviews` answers `404`; guests write
  reviews only while the captcha is on ([reviews](Store-API-Reviews.md)).

Telegram's popup opens only on a site @BotFather lists; Google's button only on a listed origin. If BotFather does not
take your development address, try Telegram on a staging domain and use email sign-in locally.
