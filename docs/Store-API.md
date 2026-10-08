# Store API

For the developer of a shop's own website: a landing page and a customer dashboard, in Next.js or anything else that
speaks HTTPS and JSON. AmoBot is the shop — its Telegram bot, its panels and this API share one database and one set of
rules — so whatever a customer does on the website (signing in, buying, renewing, paying by card, reading notices,
writing to support) is what the bot and the support panel see. The site renders; AmoBot decides.

The contract is [`resources/api/openapi.yaml`](https://github.com/amir-aghajani/amo-bot/blob/main/resources/api/openapi.yaml):
every operation under `/api/store/v1/{store}`, its parameters, its request body and its answers. The server's own
tests hold every request and answer to it. Where these pages and the YAML disagree, the YAML is right. Every operation
as it says it is the generated [reference](Store-API-Reference.md) — the shop's admins' in the
[admin API reference](Store-Admin-API-Reference.md) —, and the same description as one JSON file, the admins' paths
included, is [`docs/openapi.json`](https://github.com/amir-aghajani/amo-bot/blob/main/docs/openapi.json). Type names in the
samples (`S['StoreSignInResponse']`) are its schema names.

The pages: this overview (the address, CORS, conventions, errors, a minimal client and the messages worth
recognising), [signing in](Store-API-Sign-In.md), [the account](Store-API-Account.md),
[the shop](Store-API-Shop.md) (public), [buying](Store-API-Buying.md), [after buying](Store-API-After-Buying.md),
[notifications](Store-API-Notifications.md), [support tickets](Store-API-Support-Tickets.md),
[reviews](Store-API-Reviews.md) and [the shop's admins](Store-API-Admins.md).

## How the shop sees it

- **One shop, three doors.** The bot, the panels and the website are surfaces over the same services. A business rule
  — who may renew, what a checkout charges, when an order is payable, what a refund gives back — lives in one domain
  service that every surface calls; no surface keeps a copy of it.
- **A customer is a customer.** One account per person per shop, whichever door they came through: a Telegram account,
  an email address and a Google account are all ways into the same account. Orders, services, the wallet, tickets and
  notices belong to the account, never to a way of signing in.
- **Merging, not refusing.** A person who signs up on the website and later adds the Telegram account that already has
  services gets one account: the newer merges into the older, everything it owned moving with it
  ([the merge offer](Store-API-Account.md#the-merge-offer)).
- **Every shop can have a website** — the main shop and each agent's (an agent's shop is a shop like any other). The
  website is set up per shop and the API answers in that shop.
- **No shell and no build step for the owner.** Everything is set up from the panel; the shop's email goes over the
  host's SMTP account, PHP's own mail or Resend's API; no queue worker is needed.

## What the API covers

| Access | What |
|---|---|
| **Public** (the store key alone) | the shop (`GET /`), what it sells (`GET /plans`, `GET /plans/{id}`), how its servers stand (`GET /status`), the reviews it shows and a review written, while the website takes them (`/reviews` — a signed-in customer's token optional, a guest's review behind the captcha), every way to sign in (`/auth/*`, but `POST /auth/logout`), and the website's captcha (`/captcha/*`) |
| **A customer's own** (their bearer token) | their account (`/me/*`), their services (`/subscriptions/*`), buying (`/payment-methods`, `/orders`, renewals, wallet top-ups, receipts), their orders and payments, the wallet and its ledger, the referral program, every notice the shop sent them, and their support tickets (`/tickets/*`) |
| **The shop's admins** (an admin's bearer token, while the website lets them in) | the shop's daily work as its panels do it — receipts and payments, orders, services, customers, tickets, reviews, plans, referrals, broadcasts — under `/admin/*` ([the shop's admins](Store-API-Admins.md)) |

Not covered: the site's own content (blog, FAQ, app downloads) and online card gateways (the shop takes its wallet and
card-to-card transfers with a receipt).

Every shop can have a website — the main shop and each agent's (a reseller with a bot and a shop of their own). The
store key names one shop: its customers, plans, prices and texts are that shop's alone, and a customer of one shop is
unknown to another.

## The base address

```
{APP_URL}/api/store/v1/{store-key}
https://panel.example.com/api/store/v1/c4859b5b660377ef517299cf
```

- The shop owner (or the agent, for their shop) copies it from the panel: «تنظیمات وب‌سایت › اتصال», the API address
  card. `APP_URL` is wherever AmoBot is installed, sub-folder included.
- The store key — 24 lower-case hex characters — only names the shop. It is no secret: every browser request carries
  it. «ساخت کلید جدید» on the same card replaces it, and the old address stops answering at once.
- Paths on these pages are relative to the base: `GET /plans` is `GET {base}/plans`, and `GET /` is the base itself,
  without a trailing slash.
- A website starts switched off. Until the owner switches it on with the site's address — and after the key is
  replaced, or when an agent's shop closes — every endpoint answers `404` with `این فروشگاه وب‌سایت فعالی ندارد.` It
  allows no origin then, so a page's `fetch` sees no answer at all (a `TypeError`, [below](#calling-from-the-browser));
  only a call from your server reads the `404`. An address that is no endpoint — a key that is not 24 lower-case hex
  characters, a path with a trailing slash — is the router's own `404` (`صفحه مورد نظر پیدا نشد.`), and an endpoint
  called with another method `405` (`این روش درخواست پشتیبانی نمی‌شود.`).

## Calling from the browser

A page may call the API from the origin of the website's address (the address field of «اتصال») and from up to ten
more origins listed there — `http://localhost:3000` while you build. An origin is scheme, host and port:
`https://shop.example.com`, `https://www.shop.example.com` and `http://shop.example.com` are three origins, and
`http://127.0.0.1:3000` is not `http://localhost:3000`.

For an allowed origin a preflight (`OPTIONS`) is answered `204` — methods `GET, POST, PUT, PATCH, DELETE`, request
headers `Authorization, Content-Type, Idempotency-Key`, kept 600 seconds — and every answer, an error's too, carries
`Access-Control-Allow-Origin` and `Access-Control-Expose-Headers: X-Request-Id, Retry-After, WWW-Authenticate`. So:

- send no other custom header (`X-Requested-With`, a tracing header): the preflight refuses it and the browser never
  sends the request;
- never use `credentials: 'include'` — the API takes no cookies and allows no credentialed request;
- a page on any other origin gets no CORS headers: the browser hides the answer and `fetch` rejects with a
  `TypeError`. When every call fails that way, check «اتصال» — the switch, the address, the origins.

A body is JSON (see [Conventions](#conventions)), which a browser sends only after the preflight: a page of another
site cannot make its visitors' browsers post a sign-up, a sign-in or a reset to the shop.

## Calling from your server

A request without an `Origin` header — a Next.js server component, a route handler, a build — is served as is. That
suits the public catalogue: render `GET /`, `/plans`, `/status` and `/reviews` on the server and cache them briefly
(`fetch(url, { next: { revalidate: 60 } })`). Every answer says `Cache-Control: no-store`, so no browser or CDN keeps one
by itself: any cache is the site's own. Reading a signed-in customer's data from your server with their token is
fine too, and a form of the site's own that carries the captcha is judged from your server
([the captcha](Store-API-Sign-In.md#the-captcha)).

Sign-ins are different. The API counts failed sign-ins, and what a sign-in costs it (nonces, states, emailed codes),
per client address, and records each session's device (from `User-Agent`) and address from the request that signed
in. Proxied through your server, every visitor would share your server's address — one customer's typos would lock
everyone out, and every device would read as your server. Send `/auth/*`, `/me/reauthenticate`,
`/me/telegram/authorize`, `/me/identities/*`, `PUT /me/password` and `POST /me/2fa/disable` from the visitor's browser —
and `POST /reviews`, whose pace is counted by address too. If you must proxy them, the shop owner has to list your server's address in `TRUSTED_PROXIES` (the shop's
[config.php](Config-Reference.md#keys-and-secrets)), and your proxy must append the visitor's address to
`X-Forwarded-For` and pass their `User-Agent` on.

## Conventions

- **JSON** both ways. Send `Content-Type: application/json` with every body: a `POST`, `PUT` or `PATCH` whose body is
  anything else — a form, text, a body that says nothing of itself — is refused with `415`
  (`بدنه این درخواست باید JSON باشد؛ آن را با Content-Type: application/json بفرستید.`). A JSON body is an object (or a
  list); one that does not parse is read as no body, so the answer is the operation's own `422` for what is missing. An
  operation that takes no body is sent with none. A JSON body is capped at 1 MiB (`413`
  `درخواست بزرگ‌تر از حد مجاز است.`).
- **A picture goes in a form** (`multipart/form-data`): a receipt is a form
  ([the receipt](Store-API-Buying.md#card-transfer-and-the-receipt)) — sent as JSON it has no picture, a `422` —, and
  opening a ticket (`POST /tickets`) and writing in one (`POST /tickets/{id}/messages`) take JSON or a form
  ([opening one](Store-API-Support-Tickets.md#opening-one)). Any other body there is `415`
  (`بدنه این درخواست باید JSON یا فرم چندبخشی (multipart/form-data) باشد.`). A form's text fields
  are read as a JSON body's are, and must be UTF-8 — one that is not is `422` on that field
  (`متن این فیلد درست نیست؛ آن را با کدگذاری UTF-8 بفرستید.`; a browser's `FormData` always is). A form is not held to
  1 MiB: its picture may be up to 10 MB (`422` on `file` past it), a file past what the host's PHP takes for one file is
  `422` on `file` (`فایل بزرگ‌تر از حد مجاز سرور است.`), and a form past what it takes for a whole request is `413` —
  never read as a form that sent nothing.
- **Money** is Toman as a decimal string with two places: `"120000.00"`. Never do arithmetic on floats; format for
  display, e.g. `Math.trunc(Number(amount)).toLocaleString('fa-IR')`. A negative balance is an agent's debt. An amount
  the API wrote is taken back as written (a top-up preset, [a wallet top-up](Store-API-Buying.md#a-wallet-top-up)).
- **Traffic** is bytes, as integers; a gigabyte is 1024³ bytes (`traffic_gb: 50` is 53 687 091 200 bytes). A traffic
  of `0` is unlimited, a term of `0` days never ends, `0` devices is no limit.
- **Times** are ISO 8601 in UTC: `"2026-10-07T12:00:00+00:00"`. Show them in the shop's zone — the API does not say
  it: ask the owner (it is `APP_TIMEZONE`, «تنظیمات پنل › برنامه») —, e.g.
  `new Intl.DateTimeFormat('fa-IR-u-ca-persian', { timeZone: 'Asia/Tehran', dateStyle: 'medium' })` for Jalali dates in
  Iran's time.
- **What a customer types** may use Persian or Arabic digits: emailed codes (six digits and nothing else — take out a
  space or a dash before sending), authenticator codes (spaces and dashes are fine there), the top-up amount (which takes
  thousands separators and «تومان» too). Send ids as JSON numbers and switches as JSON booleans.
- **Lengths** on these pages are characters as the shop counts them — Unicode code points: `😀` is one (a password's
  most, 72, is bytes). JavaScript's `.length` counts UTF-16 units, `😀` two: count a field with `[...text].length`.
  Only what the bot sends is measured Telegram's way, in UTF-16 units — why a `ticket_answered` notice may be cut
  ([notice types](Store-API-Notifications.md#notice-types)).
- **Optional fields**: leave out what does not apply (`JSON.stringify` drops `undefined`); send only the fields the
  contract describes.
- **Lists** take `?page=` (from 1; a page past the last answers the last) and answer 25 rows, newest first (tickets:
  the latest activity first), with `meta: { page, per_page, total, last_page }` — the notices' and the tickets' with
  `unread` beside them, a badge's count.
- **Enums** are lower-case strings (`active`, `awaiting_review`). New values may appear: show something sensible for
  one you do not know.
- **Words** the API sends — error messages, notices, method labels, plan and server names — are Persian, written for
  the customer. Show them as they are, in a right-to-left layout or with `dir="auto"`.

## Errors

Every refusal and failure has one shape:

`POST /auth/register` → `422`
```json
{
  "message": "اطلاعات واردشده معتبر نیست.",
  "errors": {
    "email": ["ایمیل درست نیست؛ آن را مثل name@example.com بنویسید."],
    "password": ["رمز عبور دست‌کم 8 کاراکتر است."]
  },
  "request_id": "a47fde7a40fa8ca1"
}
```

- `message` — always there, in Persian: show it.
- `errors` — only when the refusal concerns request fields: each field's messages. Show them under their inputs. Some
  fields are not inputs the customer types — `status`, `plan_id`, `server_id`, `method_id`, `for`, `idempotency_key`,
  `panel`, `captcha`, `ids`, a provider's proof and its parts (`id_token`, `nonce`, a redirect's `code`, `state`,
  `code_verifier`, `code_challenge`, `redirect_uri`), and the captcha check's `token` and `action` — so show those as the
  form's message. A refusal of the request as a whole has no `errors`.
- `request_id` — 16 hex characters, also in the `X-Request-Id` header of every answer (readable cross-origin). With a
  5xx, show it as «کد پیگیری» so support can find the request in the shop's log.

| Status | In this API | The site |
|---|---|---|
| `200` | Done | — |
| `201` | Made: a ticket opened ([opening one](Store-API-Support-Tickets.md#opening-one)) | — |
| `202` | Taken, one more step: a code was emailed, the account asks its second step, or a merge is offered | follow the answer's shape |
| `204` | Done, no body: signed out, a session ended | — |
| `401` | On a customer endpoint: no token, or its session ended ([tokens and sessions](Store-API-Sign-In.md#tokens-and-sessions)) — the only meaning it has there, and the only `401` with `WWW-Authenticate: Bearer`. On `/auth/*`: the sign-in's proof did not hold (no such header) | forget the token and show the sign-in; on `/auth/*`, let them try again |
| `403` | The customer is banned (`حساب کاربری شما مسدود شده است.`) — or, on a change of how the account is signed in to, a sign-in not recent enough: `WWW-Authenticate: Bearer error="insufficient_user_authentication", max_age=900` ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)). On `POST /reviews`: a guest's review while the website asks no captcha. On `/admin/*`: the admin API's door, or a decision no admin makes ([the shop's admins](Store-API-Admins.md#refusals)) | show the message and the support link; or prove a way in again, then retry |
| `404` | The store key opens no website (seen from a server only: a browser gets no answer); a row that is not this customer's, or no longer exists; a plan not on sale now; the reviews while the website shows none; a captcha challenge asked of a website whose captcha takes none from the shop; an address no route has | — |
| `405` | An endpoint called with a method it does not take | — |
| `409` | Something changed in the same moment: the address took an account, the order was paid or closed elsewhere, the service is being changed — or an ordering request made again finds its order cancelled since ([the Idempotency-Key](Store-API-Buying.md#the-idempotency-key)). On `POST /auth/google`: the address is an account's that Google's word alone may not open ([Google](Store-API-Sign-In.md#google)). On `POST /captcha/verify`: the website asks no captcha | read again, show the new state |
| `413` | A JSON body over 1 MiB, or a form over what the host's PHP takes for a whole request ([conventions](#conventions)) | a form: shrink its picture |
| `415` | A body that is not JSON — nor, where a picture goes, a form ([conventions](#conventions)) | send `Content-Type: application/json` |
| `422` | Refused: fields under `errors` — on `/me/*` a Telegram or Google proof that did not hold too —, a state under `errors.status`, or the request as a whole | show the messages |
| `429` | Too many tries ([limits and lifetimes](Store-API-Sign-In.md#limits-and-lifetimes)), or past one of the customer's own caps ([the Idempotency-Key](Store-API-Buying.md#the-idempotency-key), [the receipt](Store-API-Buying.md#card-transfer-and-the-receipt), [reading a service now](Store-API-After-Buying.md#reading-a-service-now), [a new link](Store-API-After-Buying.md#a-new-link), [the tickets' limits](Store-API-Support-Tickets.md#limits)) — or the admins' work on the VPN panels ([the shop's admins](Store-API-Admins.md#refusals)); wait the seconds `Retry-After` says | hold the action that long |
| `500` | The server failed (logged under `request_id`) | message and «کد پیگیری» |
| `502` | Something the shop relies on did not answer: Telegram, Google, a VPN server's panel, the mail server | try again later |
| `503` | The shop's email does not go out — or its budget of sign-in emails is spent for now —, the captcha could not be judged, the server's disk is too full to take a picture or the shop took its pictures for the day, the shop takes no orders now (its bot switched off), the shop keeps as many sign-ins begun — or reviews waiting on support — as it takes, or the shop is not installed | another way in, or later |

## Versioning

The `v1` in the path is the API's version and `resources/api/openapi.yaml` its contract. Within v1 an answer only gains
fields — none is removed, renamed or given another type — and a request goes on taking what it took; a change that
would break a website comes as v2, beside v1. Write the client to tolerate additions — a new field, a new enum value, a
new notice type — and to ignore what it does not know. The contract's objects are closed (`additionalProperties: false`)
for the shop's own tests: a site that validates answers against a copy of it must refresh the copy as the shop
upgrades, or an older copy refuses a newer answer.

## A minimal client

Types come from the contract — `docs/openapi.json` of the version the shop runs
(`npx openapi-typescript openapi.json -o lib/store-api.ts`) —; the rest is `fetch`:

```ts
// lib/store.ts — browser side
import type { components } from './store-api'
export type S = components['schemas']

const BASE = process.env.NEXT_PUBLIC_STORE_API! // https://panel.example.com/api/store/v1/c4859b5b660377ef517299cf

/** Where a bearer token is kept: read, written and forgotten in one place. */
export type Tokens = { get(): string | null; keep(token: string): void; clear(): void }
const keptIn = (storage: () => Storage, key: string): Tokens => ({
  get: () => storage().getItem(key),
  keep: (token) => storage().setItem(key, token),
  clear: () => storage().removeItem(key),
})
/** A customer's: in localStorage, so they stay signed in across visits. */
export const customerTokens = keptIn(() => localStorage, 'store-token')
/** An admin's: in sessionStorage, gone with the tab — never in localStorage (see the shop's admins). */
export const adminTokens = keptIn(() => sessionStorage, 'store-admin-token')

export class StoreError extends Error {
  constructor(
    readonly status: number,
    readonly body: S['ErrorResponse'],
    readonly retryAfter: number | null,
    readonly challenge: string | null, // WWW-Authenticate
  ) {
    super(body.message)
  }
  /** The first message under a request field, when the refusal names one. */
  on(field: string): string | undefined {
    return this.body.errors?.[field]?.[0]
  }
  /** The session ended: a 401 with `WWW-Authenticate: Bearer` — a sign-in's refused proof (/auth/*) has none. */
  get signedOut(): boolean {
    return this.status === 401 && /^Bearer\b/i.test(this.challenge ?? '')
  }
  /** A change of how the account is signed in to, refused for a sign-in that is not recent. */
  get needsRecentSignIn(): boolean {
    return this.status === 403 && /insufficient_user_authentication/.test(this.challenge ?? '')
  }
}

type Call = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  json?: unknown // a JSON body
  form?: FormData // a request with a picture: a receipt, a ticket's message
  key?: string // Idempotency-Key, for a request that orders
  signal?: AbortSignal
  tokens?: Tokens // whose token goes: the customer's, unless the call says otherwise
}

export async function store<T>(path: string, { method = 'GET', json, form, key, signal, tokens = customerTokens }: Call = {}): Promise<T> {
  const token = tokens.get()
  const headers: Record<string, string> = {}
  if (token) headers.Authorization = `Bearer ${token}`
  if (key) headers['Idempotency-Key'] = key
  if (json !== undefined) headers['Content-Type'] = 'application/json'

  const res = await fetch(BASE + path, { method, headers, body: json !== undefined ? JSON.stringify(json) : form, signal })
  if (res.status === 204) return undefined as T
  const body = await res.json().catch(() => null)
  if (res.ok) return body as T

  const wait = res.headers.get('Retry-After')
  const error = new StoreError(
    res.status,
    body ?? { message: `HTTP ${res.status}`, request_id: res.headers.get('X-Request-Id') ?? '' },
    wait === null ? null : Number(wait),
    res.headers.get('WWW-Authenticate'),
  )
  if (error.signedOut) tokens.clear() // the session ended: show the sign-in
  throw error
}

/** The admin area's calls — `/admin/*`, and `/me` as its admin — with the admin's token. */
export function admin<T>(path: string, call: Call = {}): Promise<T> {
  return store<T>(path, { ...call, tokens: adminTokens })
}

export const keepToken = (token: string, tokens: Tokens = customerTokens) => tokens.keep(token)

export async function signOut(tokens: Tokens = customerTokens) {
  await store<void>('/auth/logout', { method: 'POST', tokens }).catch(() => undefined)
  tokens.clear()
}
```

A customer signs in with `keepToken(token)` and the site calls `store()`; the admin area signs its admin in with
`keepToken(token, adminTokens)`, calls `admin()` and signs out with `signOut(adminTokens)` — an admin's token never
reaches `localStorage` ([the shop's admins](Store-API-Admins.md#signing-in)). `store()` is the browser's: it reads the
storage as it calls, so importing the module on a server is harmless, but render the public catalogue there with a plain
`fetch` ([calling from your server](#calling-from-your-server)).

## Every endpoint

Every operation — who may call it, its parameters, its body field by field, its answers — is the generated
[Store API reference](Store-API-Reference.md): the public ones first, then the signed-in customer's; the shop's admins' are
the [admin API reference](Store-Admin-API-Reference.md). Across all of them: a customer's operation answers `401` with
`WWW-Authenticate: Bearer` once the session ended, and `403` to a banned customer; one that changes how the account is
signed in to answers `403` with the challenge of [a recent sign-in](Store-API-Account.md#a-recent-sign-in) unless the
sign-in is at most 15 minutes old or proven again since; an ordering request carries its
[Idempotency-Key](Store-API-Buying.md#the-idempotency-key); and any of them may answer the error shape ([errors](#errors)).

## Messages worth recognising

Branch on the status and on the `errors` field; the words are for the customer and may be reworded. These are the ones
a site may want to tell apart, or explain better.

| Status | Message | Where, and what it means |
|---|---|---|
| 404 | `این فروشگاه وب‌سایت فعالی ندارد.` | every endpoint, read from a server: the store key opens no website — switched off, the key replaced, or an agent's shop closed |
| 404 | `صفحه مورد نظر پیدا نشد.` | an address that is no endpoint (a mistyped key or path, a trailing slash) |
| 405 | `این روش درخواست پشتیبانی نمی‌شود.` | an endpoint called with another method |
| 401 | `برای دسترسی باید وارد شوید.` | a customer endpoint: no token, or the session ended — the only 401 there, the only one with `WWW-Authenticate: Bearer` |
| 403 | `حساب کاربری شما مسدود شده است.` | the customer is banned |
| 403 | `برای این کار دوباره وارد شوید.` | a change of how the account is signed in to, the sign-in not recent (`WWW-Authenticate` says so): prove a way in again and retry |
| 403 | `این بخش فقط برای پشتیبانی فروشگاه است.` | `/admin/*`: the customer is no admin of the shop — the admin API's other refusals are [the shop's admins](Store-API-Admins.md#refusals)' |
| 409 | `حسابی با این ایمیل هست؛ با همان روش وارد شوید و گوگل را از تنظیمات حساب وصل کنید.` | `POST /auth/google`: the address is an account's Google may not open by itself — sign in its way, then add Google |
| 404 | `مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.` | a row that is not theirs or no longer exists, a plan not on sale |
| 415 | `بدنه این درخواست باید JSON باشد؛ آن را با Content-Type: application/json بفرستید.` | a body that is not JSON |
| 415 | `بدنه این درخواست باید JSON یا فرم چندبخشی (multipart/form-data) باشد.` | a receipt or a ticket's message in neither JSON nor a form |
| 401 | `ایمیل یا رمز عبور درست نیست.` | `POST /auth/login` |
| 401 | `این درخواست ورود منقضی شده یا قبلا استفاده شده است؛ دوباره وارد شوید.` | a nonce, a redirect's state or a second step's challenge spent, expired or another flow's — or a redirect's `code_verifier` that is not the one its challenge was made of: start again (on `/me/*` a 422 on its field) |
| 401 | `این ورود برای این وب‌سایت صادر نشده است؛ Client ID وب‌سایت را بررسی کنید.` | a token made for another Client ID — the site's configuration |
| 401 | `تلگرام شناسه حساب را نفرستاد؛ ورود باید دسترسی profile را بخواهد.` | the popup did not ask the `profile` scope — the site's code |
| 401 | `تلگرام کد ورود را نپذیرفت؛ دوباره وارد شوید.` | the redirect's code refused by Telegram: start again |
| 422 | `این ورود مال حساب دیگری است؛ با یکی از روش‌های ورود همین حساب تایید کنید.` | `POST /me/reauthenticate`: a proof of another account |
| 422 | `این حساب با رمز عبور وارد نمی‌شود؛ با روش ورود دیگری تایید کنید.` | `POST /me/reauthenticate`: the account has no password |
| 422 | `ورود با تلگرام برای این وب‌سایت فعال نیست.` / `ورود با Google برای این وب‌سایت فعال نیست.` / `ثبت‌نام با ایمیل برای این وب‌سایت فعال نیست.` | a way in switched off since `GET /` was read |
| 422 | `کد درست نیست یا منقضی شده است.` | an emailed code (`errors.code`) |
| 422 | `کد درست نیست؛ کد شش‌رقمی برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.` | `POST /auth/login/2fa` (`errors.code`) |
| 422 | `تایید امنیتی انجام نشد؛ دوباره تلاش کنید.` | the captcha (`errors.captcha`): reset the widget |
| 503 | `تایید امنیتی در دسترس نیست؛ کمی بعد دوباره تلاش کنید.` | the captcha could not be judged now (Cloudflare out of reach) |
| 503 | `فعلا ایمیلی از این فروشگاه فرستاده نمی‌شود؛ کمی بعد دوباره امتحان کنید یا از راه دیگری وارد شوید.` | the shop's email does not go out, or its budget of sign-in emails is spent for now: offer another way in |
| 503 | `ورود به وب‌سایت این فروشگاه الان شلوغ است؛ چند دقیقه دیگر دوباره امتحان کنید.` | the shop keeps as many sign-ins begun (nonces, redirect states) as it takes: later |
| 502 | `ایمیل فرستاده نشد؛ کمی بعد دوباره امتحان کنید.` | this email did not go |
| 502 | `تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.` / `Google در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.` | the provider out of reach |
| 409 | `با این ایمیل همین حالا حسابی ساخته شد؛ با ایمیل و رمز عبور وارد شوید.` | `POST /auth/register/verify`: sign in instead |
| 429 | `تلاش‌های ناموفق زیاد بود؛ ۵ دقیقه دیگر دوباره امتحان کنید.` | too many failed sign-ins (the minutes vary; `Retry-After` has the seconds) |
| 429 | `درخواست‌ها زیاد بود؛ ۵ دقیقه دیگر دوباره امتحان کنید.` | too many nonces, states, codes or refused captcha tokens asked for |
| 429 | `درخواست تایید زیادی فرستاده شد؛ …` | `POST /captcha/verify`: the website's 3,000 tokens in 10 minutes, or 120 refused ones from the visitor's network |
| 429 | `برای این ایمیل همین حالا درخواست شد؛ ۴۵ ثانیه دیگر دوباره امتحان کنید.` | a code went to this address a moment ago |
| 429 | `امروز ایمیل زیادی برای این آدرس فرستاده شد؛ …` / `ایمیل زیادی درخواست کرده‌اید؛ …` | the address had its emails for the day, or this network (or this customer, adding an email) asked for too many |
| 429 | `کد اشتباه زیادی برای این ایمیل وارد شد؛ …` | the codes emailed to this address were failed until it waits: no new code goes to it meanwhile |
| 422 | `حداقل یک روش ورود باید بماند.` | `DELETE /me/identities/{kind}`: the last way in |
| 422 | `این پیشنهاد ادغام دیگر معتبر نیست؛ دوباره تلاش کنید.` | `POST /me/merge`: start over |
| 422 | `نام حساب‌های تلگرامی از تلگرام خوانده می‌شود.` | `PATCH /me` on an account with Telegram |
| 404 | `این وب‌سایت نظرات مشتریان را نشان نمی‌دهد و نظری نمی‌گیرد.` | `GET` and `POST /reviews` while the website's reviews are switched off (as a website starts) |
| 403 | `برای نوشتن نظر، اول وارد حساب خود شوید.` | `POST /reviews` from a guest while the website asks no captcha: sign in first |
| 503 | `این فروشگاه الان نظر تازه‌ای نمی‌گیرد؛ کمی بعد دوباره امتحان کنید.` | `POST /reviews` while 200 reviews wait on support: later |
| 429 | `نظر زیادی ثبت شده است؛ …` | `POST /reviews`: 20 reviews an hour from the network (IPv6 by its /48), 3 a day from the customer, or 20 an hour from every guest together |
| 503 | `فروشگاه فعلا سفارش نمی‌گیرد؛ کمی بعد دوباره سر بزنید.` | an order, a renewal, a top-up or a receipt while the shop takes no orders (`GET /`'s `shop.taking_orders` false) |
| 422 | `موجودی کیف پول کافی نیست؛ ۷۰٬۰۰۰ تومان کم است.` | the wallet short (`errors.method_id`; the amount varies) |
| 409 | `این سفارش همین حالا جای دیگری پرداخت یا بسته شد؛ وضعیت آن را در سفارش‌ها ببینید.` | a checkout overtaken by another payment or a cancellation in the same moment |
| 409 | `این سفارش لغو شده است؛ پرداخت نشد یا پشتیبانی آن را لغو کرد. برای خرید دوباره از نو شروع کنید.` | an ordering request made again with its key, its order cancelled since — by support, or unpaid in its time: a new attempt, a new key |
| 422 | `این پلن الان فروخته نمی‌شود.` | `errors.plan_id` |
| 422 | `سرور #2 برای پلن «یک ماهه ۵۰ گیگ» در دسترس نیست.` | `errors.server_id`: another of the plan's locations sells it (the number and name vary) |
| 422 | `سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.` | 5 unpaid orders open, no field |
| 422 | `این Idempotency-Key پیش‌تر برای سفارش دیگری به کار رفته است.` | `errors.idempotency_key`: the key came to an order of something else |
| 422 | `این Idempotency-Key پیش‌تر با روش پرداخت دیگری به کار رفته است؛ برای پرداخت به روش دیگر کلید تازه‌ای بسازید.` | `errors.idempotency_key`: the key was first sent with another `method_id` — another way to pay is a new attempt |
| 422 | `تمدید این سرویس در حال حاضر ممکن نیست؛ با پشتیبانی در تماس باشید.` | a renewal not possible now (`errors.status`) |
| 422 | `رسید این پرداخت رسیده و در انتظار بررسی است.` | a second receipt for one payment (`errors.status`) |
| 429 | `درخواست پرداخت زیادی فرستاده‌اید؛ …` / `اطلاعات سرویس را زیاد به‌روز کرده‌اید؛ …` / `لینک سرویس را زیاد عوض کرده‌اید؛ …` | a customer's own caps: ordering requests, a service read now, new links |
| 429 | `تصویر زیادی فرستاده‌اید؛ …` | 10 pictures uploaded in an hour — receipts and tickets' pictures together, one budget |
| 503 | `فضای ذخیره سرور پر شده و فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.` | a receipt or a ticket's picture while the server's disk is too full: try again later |
| 503 | `امروز بیش از این تصویری پذیرفته نمی‌شود؛ چند ساعت دیگر دوباره بفرستید.` | a receipt or a ticket's picture once the shop took 1 GB of pictures in a day: try again in a few hours |
| 502 | `اطلاعات این سرویس الان از سرور خوانده نشد؛ کمی بعد دوباره تلاش کنید.` | `POST /subscriptions/{id}/refresh` |
| 409 | `این سرویس همین حالا در حال تغییر است؛ چند لحظه بعد دوباره تلاش کنید.` | the service is being changed: try again shortly |
| 422 | `این تیکت بسته شده است.` | `POST /tickets/{id}/close` on a closed ticket (`errors.status`) |
| 422 | `امتیاز را پس از بسته شدن تیکت می‌توانید ثبت کنید.` | `POST /tickets/{id}/rating` on a ticket not closed (`errors.status`) |
| 422 | `این تیکت به سقف پیام‌ها رسیده؛ تیکت تازه‌ای باز کنید.` | `POST /tickets/{id}/messages`: the ticket holds 200 messages — offer a new ticket (`errors.status`) |
| 422 | `این تیکت بیشتر از ۲۰ تصویر از شما نمی‌گیرد؛ پیام را بدون تصویر بفرستید، یا تیکت تازه‌ای باز کنید.` | `POST /tickets/{id}/messages` with a picture: the ticket keeps 20 the customer uploaded (`errors.file`) |
| 429 | `تیکت زیادی باز کرده‌اید؛ …` / `پیام زیادی فرستاده‌اید؛ …` / `تصویر زیادی خواسته‌اید؛ …` | the tickets' caps: tickets opened, messages and ratings written, pictures read |
| 404 | `این پیام تصویری ندارد.` | a ticket message's attachment address, for a message without a picture |
| 404 | `فایل این تصویر دیگر روی سرور نیست.` | a ticket message's picture no longer kept (`attachment.kept` false: do not ask for it) |
| 404 | `این تصویر دیگر در تلگرام نیست.` | a ticket message's picture sent in the bot, which Telegram no longer hands over (`kept` stays true for those) |
| 409 | `این وب‌سایت تایید امنیتی ندارد؛ آن را در پنل، تنظیمات وب‌سایت، روشن کنید.` | `POST /captcha/verify` while the website asks no captcha |
| 404 | `تایید امنیتی این وب‌سایت چالشی از فروشگاه نمی‌گیرد.` | `GET /captcha/challenge` while the website's captcha draws its own (Turnstile), or it asks none |
| 422 | `اطلاعات واردشده معتبر نیست.` | a form's fields refused: see `errors` |
| 422 | `متن این فیلد درست نیست؛ آن را با کدگذاری UTF-8 بفرستید.` | a form field that is not UTF-8 (under its field) |
| 413 | `درخواست بزرگ‌تر از حد مجاز است.` | a body over 1 MiB, or a form over what the host's PHP takes: shrink the picture |
| 500 | `خطایی در سرور رخ داد. لطفا بعدا دوباره تلاش کنید.` | the server failed: show `request_id` |
