[English](../Store-API-Sign-In.md)

<div dir="rtl" lang="fa">

# رابط Store API: ورود

اینکه کاربر چطور وارد وب‌سایت فروشگاه می‌شود، و Session چیست. مسیرها زیر [آدرس پایه](Store-API.md#آدرس-پایه)
هستند؛ نمونه‌ها از [کلاینت ساده](Store-API.md#یک-کلاینت-ساده) استفاده می‌کنند.

## توکن‌ها و Sessionها

هر ورود موفق همین شکل را برمی‌گرداند:

پاسخ `200` به `POST /auth/telegram`:
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

- فیلد `token` — ۶۴ کاراکتر هگز، که فقط همین یک بار نشان داده می‌شود. آن را به شکل
  <code dir="ltr">Authorization: Bearer &lt;token&gt;</code> به همه اندپوینت‌های کاربر بفرستید. `customer` حساب کاربر است
  ([حساب کاربری](Store-API-Account.md#حساب-کاربری)).
- هر توکن یک دستگاه است: یک Session. هر کاربر حداکثر ۲۰ Session دارد. Session تمام می‌شود — و توکنش از آن به بعد چیزی را
  باز نمی‌کند — وقتی:
  - کاربر از آن دستگاه خارج شود (`POST /auth/logout`)، یا از دستگاه دیگری آن را تمام کند
    ([دستگاه‌ها](Store-API-Account.md#دستگاه‌ها))؛
  - رمز عبورش عوض شود — با `PUT /me/password` ([رمز عبور](Store-API-Account.md#رمز-عبور))، یا با یک ادغام گذاشته شود
    ([پیشنهاد ادغام](Store-API-Account.md#پیشنهاد-ادغام)): همه Sessionها به جز همانی که این کار را کرد — یا بازنشانی
    شود (`POST /auth/password/reset`، یا مرحله دوم آن، [فراموشی رمز عبور](#فراموشی-رمز-عبور)): همه Sessionها؛
  - پشتیبانی او را از همه دستگاه‌ها خارج کند — از یکی از پنل‌ها، یا یکی از مدیران سایت در وب‌سایت؛
  - ورود تازه‌ای بیست‌ویکمین Session او را باز کند: قدیمی‌ترین تمام می‌شود؛
  - ۳۰ روز از آن استفاده نشده باشد، یا ۱۸۰ روز از ورودش گذشته باشد، هر قدر هم استفاده شده باشد.
- با توکن تمام‌شده، همه اندپوینت‌های کاربر `401` با `برای دسترسی باید وارد شوید.` و `WWW-Authenticate: Bearer`
  برمی‌گردانند — همه عملیات‌هایی که ورود لازم دارند این را اعلام می‌کنند، و آنجا معنای دیگری ندارد: توکن را کنار بگذارید
  و صفحه ورود را نشان دهید. refresh token نداریم. توکن کاربر مسدود به جای آن `403` می‌گیرد
  (`حساب کاربری شما مسدود شده است.`). پاسخ `401` به `GET /me`:
  ```json
  { "message": "برای دسترسی باید وارد شوید.", "request_id": "c8e2f4a6b0d1e3f5" }
  ```
- هر ورود تا ۱۵ دقیقه *تازه* است: کارهایی که روش‌های ورود به حساب را عوض می‌کنند ورود تازه لازم دارند
  ([ورود تازه](Store-API-Account.md#ورود-تازه)). و ورود *قوی* است اگر با تلگرام، با گوگل، یا با رمز عبور همراه مرحله دوم
  انجام شده باشد — همان چیزی که API مدیران فروشگاه از مدیرانش می‌خواهد ([مدیران سایت](Store-API-Admins.md#ورود)).
- توکن کاربر را در مرورگر نگه دارید (در `localStorage`، تا وارد بماند) و آن را جز برای API به هیچ جا نفرستید. هر اسکریپتی
  در صفحه‌های شما می‌تواند آن را بخواند، پس سایت را از XSS دور نگه دارید — با Content-Security-Policy و بدون HTML نامطمئن
  (`html` اعلان‌ها از قبل امن است). توکن مدیر در حافظه یا `sessionStorage` نگه داشته می‌شود، هرگز در `localStorage`
  ([مدیران سایت](Store-API-Admins.md#ورود)): با <code dir="ltr">keepToken(token, adminTokens)</code> در
  [کلاینت ساده](Store-API.md#یک-کلاینت-ساده).
- ورود دوباره Session دیگری باز می‌کند: وقتی توکن را عوض می‌کنید، اول با توکن قبلی خارج شوید.

## روش‌های ورود

فیلد `sign_in` در <code dir="ltr">GET /</code> ([آنچه سایت اول می‌پرسد](Store-API-Shop.md#آنچه-سایت-اول-می‌پرسد)) می‌گوید
وب‌سایت چه روش‌هایی دارد:

- فیلد `telegram` — `{ client_id, redirect }` تا وقتی ورود با تلگرام روشن است، وگرنه `null`؛ `redirect` وقتی true است
  که روند ریدایرکت هم راه‌اندازی شده باشد ([تلگرام: ریدایرکت](#تلگرام-ریدایرکت)).
- فیلد `google` — `{ client_id }` تا وقتی ورود با گوگل روشن است، وگرنه `null`.
- فیلد `email` — اینکه ثبت‌نام با ایمیل ارائه می‌شود یا نه (روشن است و ایمیل فروشگاه فرستاده می‌شود). کاربری که ایمیل و
  رمز عبور دارد، هر چه این فیلد بگوید، با آن‌ها وارد می‌شود: ورود با رمز عبور و لینک فراموشی رمز را همیشه نشان دهید.

کنار آن، `captcha` در <code dir="ltr">GET /</code> می‌گوید ثبت‌نام، ورود با رمز عبور و بازنشانی رمز باید کپچای وب‌سایت را
همراه داشته باشند یا نه، و کپچا چطور نشان داده می‌شود ([کپچا](#کپچا)).

## تلگرام: پنجره پاپ‌آپ

کتابخانه خود تلگرام روند OpenID Connect را در یک پاپ‌آپ اجرا می‌کند و یک `id_token` به صفحه می‌دهد؛ فروشگاه آن را بررسی
می‌کند.

1. فایل `https://oauth.telegram.org/js/telegram-login.js` را بارگذاری کنید.
2. پیش از کلیک کاربر — وقتی پنجره ورود باز می‌شود — با `POST /auth/nonce` یک nonce بگیرید. پاپ‌آپ باید مستقیما با خود
   کلیک باز شود؛ اگر اول منتظر جواب یک درخواست بمانید، ممکن است مرورگر آن را مسدود کند. پاسخ `200` به
   `POST /auth/nonce`:
   ```json
   { "nonce": "af8f5b24ef09ddb015185edffaf60717db93bfe4dcb0d37a6b73dcbae51920d7", "expires_in": 1800 }
   ```
3. هنگام کلیک، <code dir="ltr">Telegram.Login.auth({ client_id, scope: \['profile', 'write'\], nonce }, callback)</code>
   — با `client_id` از <code dir="ltr">GET /</code> به شکل عدد. `profile` لازم است: توکن باید شناسه عددی حساب را داشته
   باشد، همان که ربات کاربرانش را با آن می‌شناسد (بدون آن،
   `401` با `تلگرام شناسه حساب را نفرستاد؛ ورود باید دسترسی profile را بخواهد.`). `write` به ربات فروشگاه اجازه می‌دهد
   به کاربر پیام بدهد — کاربری که تلگرام دارد اعلان‌هایش را آنجا می‌گیرد ([اعلان‌ها](Store-API-Notifications.md)).
4. مقدار `id_token` را با همان nonce بفرستید، همراه کد دعوت بازدیدکننده اگر دارد
   ([کدهای دعوت در ثبت‌نام](#کدهای-دعوت-در-ثبت‌نام)). درخواست `POST /auth/telegram`:
   ```json
   {
     "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjEifQ.eyJpc3MiOiJodHRwczovL29hdXRoLnRlbGVncmFtLm9yZyJ9.c2lnbmF0dXJl",
     "nonce": "af8f5b24ef09ddb015185edffaf60717db93bfe4dcb0d37a6b73dcbae51920d7",
     "referral_code": "k7m2xq9p"
   }
   ```
5. پاسخ `200` توکن را برمی‌گرداند ([توکن‌ها و Sessionها](#توکن‌ها-و-sessionها)). کاربر همان کاربر فروشگاه با این حساب
   تلگرام است — همان کاربر ربات، با سرویس‌ها و کیف پولش — یا یک تازه‌وارد، که دقیقا همان‌طور ثبت می‌شود که ربات
   تازه‌واردها را ثبت می‌کند.

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

ردهای `POST /auth/telegram`:

- کد `401` — توکن معتبر نیست، برای سایت دیگری ساخته شده
  (`این ورود برای این وب‌سایت صادر نشده است؛ Client ID وب‌سایت را بررسی کنید.` — از Client ID در
  <code dir="ltr">GET /</code> استفاده کنید)، منقضی شده، یا nonce آن مصرف یا منقضی شده: nonce تازه‌ای بگیرید و دوباره
  امتحان کنید؛
- کد `403` — کاربر مسدود است؛
- کد `422` — ورود با تلگرام خاموش است، یا فیلدی نیامده (`nonce`، یا `id_token` وقتی نه آن فرستاده شده و نه `code`
  ریدایرکت)؛
- کد `429` — تلاش‌های ناموفق زیاد؛
- کد `502` — تلگرام در دسترس نیست: nonce برای تلاش بعدی معتبر می‌ماند.

خود `POST /auth/nonce` به سه شکل رد می‌شود:

- کد `422` (`ورود با تلگرام و Google برای این وب‌سایت فعال نیست.`) وقتی نه ورود با تلگرام روشن است و نه ورود با گوگل:
  هیچ ورودی آن nonce را نمی‌پذیرد؛
- کد `429` وقتی شبکه این آدرس در ۱۰ دقیقه ۱۲۰ nonce، state و کد خواسته باشد؛
- کد `503` (`ورود به وب‌سایت این فروشگاه الان شلوغ است؛ چند دقیقه دیگر دوباره امتحان کنید.`) وقتی فروشگاه به اندازه
  ظرفیتش ورود نیمه‌کاره نگه داشته ([محدودیت‌ها و مدت اعتبار](#محدودیت‌ها-و-مدت-اعتبار)).

## تلگرام: ریدایرکت

وقتی `sign_in.telegram.redirect` برابر true است (مالک فروشگاه Client Secret را ذخیره کرده)، سایت می‌تواند به جای پاپ‌آپ
کل پنجره را به تلگرام بفرستد — که برای مرورگرهای داخل برنامه‌ها مناسب‌تر است. این روند authorization code با PKCE است، و
verifier روند PKCE فقط در خود مرورگر است: سایت آن را می‌سازد و نگه می‌دارد و فقط challenge آن را به فروشگاه می‌فرستد، پس
code و stateی که مرورگر دیگری برگرداند (مثلا لینکی که کسی از ورود خودش ساخته) چیزی را باز نمی‌کند.

1. یک verifier بسازید — ۴۳ تا ۱۲۸ کاراکتر تصادفی از <code dir="ltr">A-Z a-z 0-9 - . \_ \~</code> — و challenge آن را،
   یعنی SHA-256 همان verifier به base64url بدون padding (S256: ۴۳ کاراکتر). verifier را جایی نگه دارید که صفحه‌ای که
   تلگرام مرورگر را به آن برمی‌گرداند بتواند بخواندش (`sessionStorage`). `crypto.subtle` به secure context نیاز دارد:
   صفحه‌ای با `https`، یا `localhost`.
2. آدرس تلگرام را با challenge و صفحه‌ای که تلگرام باید مرورگر را به آن برگرداند بگیرید: یک آدرس <code dir="ltr">http(s)</code>
   روی Origin وب‌سایت یا یکی از Originهای مجازش، بدون `#`، که در @BotFather هم زیر Allowed URLs آمده باشد. برای ورود،
   `POST /auth/telegram/authorize` را بفرستید؛ کاربری که وارد شده و حساب تلگرامش را
   اضافه می‌کند ([روش‌های ورود](Store-API-Account.md#روش‌های-ورود-افزودن-و-حذف)) یا دوباره ثابتش می‌کند
   ([ورود تازه](Store-API-Account.md#ورود-تازه)) `POST /me/telegram/authorize` را با توکنش می‌فرستد — همان بدنه و همان
   پاسخ، ولی با stateی مخصوص همان Session: فقط درخواستی با همان توکن آن را پس می‌گیرد. درخواست
   `POST /auth/telegram/authorize`:
   ```json
   {
     "redirect_uri": "https://shop.example.com/auth/telegram/callback",
     "code_challenge": "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
   }
   ```
   پاسخ `200` به `POST /auth/telegram/authorize`:
   ```json
   {
     "url": "https://oauth.telegram.org/auth?client_id=7012345678&redirect_uri=https%3A%2F%2Fshop.example.com%2Fauth%2Ftelegram%2Fcallback&response_type=code&scope=openid%20profile%20telegram%3Abot_access&state=206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&nonce=a47fde7a40fa8ca1afacdaee073feb3f"
   }
   ```
3. مقدار `state` را که در `url` هست کنار verifier نگه دارید، بعد مرورگر را به `url` بفرستید. فروشگاه state را همراه
   challenge، آدرس بازگشت و یک nonce به مدت ۱۰ دقیقه نگه می‌دارد، و خودش profile و اجازه پیام دادن ربات به کاربر را
   درخواست می‌کند.
4. تلگرام مرورگر را به <code dir="ltr">redirect_uri?code=…&amp;state=…</code> برمی‌گرداند. **پیش از فرستادن هر چیزی،
   آن `state` را با همانی که نگه داشته‌اید مقایسه کنید**، و اگر فرق داشت یا چیزی نگه نداشته بودید، هیچ چیز نفرستید. بعد
   `code`، `state` و `code_verifier` نگه‌داشته را به همان جایی بفرستید که روند از آن شروع شد — برای ورود به
   `POST /auth/telegram`، و برای کاربری که وارد شده به `POST /me/identities/telegram` یا `POST /me/reauthenticate`، با همان
   توکنی که آن را درخواست کرده بود. هر state فقط در روند خودش پس گرفته می‌شود، و فقط با verifierی که challenge آن از آن
   ساخته شده: state یک ورود روی <code dir="ltr">/me/\*</code> چیزی را ثابت نمی‌کند؛ state یک کاربر کسی را وارد نمی‌کند، و
   از دستگاه دیگری از همان کاربر، یا با توکنی که بعد از درخواست state وارد شده، هم چیزی را ثابت نمی‌کند.

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

خطاها، علاوه بر خطاهای پاپ‌آپ:

- کد `422` وقتی Client Secret ذخیره نشده
  (`ورود با redirect برای این وب‌سایت آماده نیست؛ Client Secret تلگرام در پنل وارد نشده است.`)؛
- کد `422` روی `redirect_uri`
  (`آدرس بازگشت باید یک آدرس http یا https روی آدرس وب‌سایت یا یکی از Originهای مجاز آن باشد، بدون #.`) یا روی
  `code_challenge` (نیامده، یا challenge از نوع S256 نیست)، هنگام گرفتن آدرس؛
- کد `422` روی `state` وقتی نیامده، و روی `code_verifier` وقتی نیامده یا ۴۳ تا ۱۲۸ کاراکتر از
  <code dir="ltr">A-Z a-z 0-9 - . \_ \~</code> نیست — در این حالت state هنوز معتبر است؛
- کد `401` وقتی state مصرف شده، بیش از ۱۰ دقیقه از آن گذشته یا مال روند دیگری است، یا verifier همانی نیست که challenge از
  آن ساخته شده (`این درخواست ورود منقضی شده یا قبلا استفاده شده است؛ دوباره وارد شوید.`)، یا تلگرام کد را نپذیرد
  (`تلگرام کد ورود را نپذیرفت؛ دوباره وارد شوید.`).

درخواستی که از این بررسی‌های فیلد بگذرد، state خودش را مصرف می‌کند، نتیجه‌اش هر چه باشد: بعد از `401` — یا `502`، وقتی
تلگرام در دسترس نیست — از قدم ۱ و با verifier تازه دوباره شروع کنید. روی <code dir="ltr">/me/\*</code> همین خطاها — و
stateی که Session دیگری گرفته — `422` روی `code` هستند و Session دست نمی‌خورد. گرفتن آدرس هم مثل nonce ممکن است `429` و
`503` بگیرد.

## گوگل

کتابخانه Google Identity Services برای nonceی که سایت از فروشگاه گرفته یک `id_token` (همان `credential`) به صفحه می‌دهد.

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

حالت پیش‌فرض پاپ‌آپ گوگل را نگه دارید: حالت ریدایرکت آن credential را به جای صفحه به سرور شما می‌فرستد. کاربری که وارد
می‌شود حسابی است که این حساب گوگل از قبل به آن وصل است؛ وگرنه حسابی که آدرسش همان آدرسی است که گوگل تایید می‌کند، و از آن
به بعد با این حساب گوگل وارد می‌شود (و به کاربرش خبر داده می‌شود)؛ وگرنه یک تازه‌وارد با همان آدرس. گوگل فقط آدرسی را
تایید می‌کند که خودش مرجع آن باشد: `email_verified`، و یک آدرس <code dir="ltr">@gmail.com</code> یا آدرس یک حساب Google
Workspace. هر آدرس دیگری — که گوگل یک بار تاییدش کرده، و شاید امروز صاحب دامنه‌اش کس دیگری باشد — هیچ حسابی پیدا
نمی‌کند، حسابی را تصاحب نمی‌کند و نگه داشته نمی‌شود: چنین تازه‌واردی تا خودش ایمیلی اضافه نکند ایمیل ندارد
([روش‌های ورود](Store-API-Account.md#روش‌های-ورود-افزودن-و-حذف)). ورود با گوگل نام‌های حساب را عوض نمی‌کند.

دو نوع حساب هیچ‌وقت فقط با آدرسشان باز نمی‌شوند — حسابی که ورود دومرحله‌ای‌اش روشن است، و حسابی که حساب گوگل دیگری به آن
وصل است: ورود با گوگل در این حالت `409` با
`حسابی با این ایمیل هست؛ با همان روش وارد شوید و گوگل را از تنظیمات حساب وصل کنید.` برمی‌گرداند. کاربر با روش ورود همان
حساب وارد می‌شود و گوگل را از حسابش اضافه می‌کند ([روش‌های ورود](Store-API-Account.md#روش‌های-ورود-افزودن-و-حذف)). خطاهای
دیگر مثل خطاهای تلگرام‌اند: `401`، `403` برای کاربر مسدود، `422` برای خاموش بودن ورود با گوگل یا نیامدن فیلدی
(`id_token`، `nonce`)، `429`، و `502` وقتی گوگل در دسترس نیست.

## ثبت‌نام با ایمیل

تا وقتی `sign_in.email` برابر true است ارائه می‌شود. دو مرحله دارد، تا هر آدرسی که فروشگاه نگه می‌دارد تاییدشده باشد:

درخواست `POST /auth/register`:
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

پاسخ `202` به `POST /auth/register`:
```json
{ "expires_in": 900 }
```

یک کد شش‌رقمی به آدرس فرستاده می‌شود — یا، اگر آدرس از قبل حساب دارد، ایمیلی که همین را می‌گوید. پاسخ در هر دو حالت یکی
است: اینکه کدام آدرس‌ها حساب دارند به کسی گفته نمی‌شود. متن صفحه بعد را هم همین‌طور بنویسید: «ایمیلی به sara@example.com
فرستادیم. کدش را وارد کنید — یا اگر از قبل حساب دارید، وارد شوید.» درخواست دوباره، کد تازه‌ای به جای کد قبلی می‌فرستد.
ایمیل‌ها سهمیه دارند ([محدودیت‌ها و مدت اعتبار](#محدودیت‌ها-و-مدت-اعتبار)): برای هر آدرس یک بار در دقیقه و ده بار در
روز، برای هر شبکه بیست بار در ساعت (`429`)، و سهمیه ساعتی و روزانه خود فروشگاه و کل نصب (`503` تا وقتی یکی تمام شده)؛
درخواستی که چیزی نفرستاده — رد شده، یا ایمیلش نرفته — آن نوبت‌ها را برمی‌گرداند، هرچند همچنان در هزینه‌ای که هر شبکه
می‌تواند برای فروشگاه داشته باشد شمرده می‌شود. ایمیل ثبت‌نام هیچ چیزی از متنی که درخواست‌دهنده تایپ کرده ندارد.

درخواست `POST /auth/register/verify`:
```json
{ "email": "sara@example.com", "code": "۴۸۲۹۱۳" }
```

پاسخ `200` توکن را برمی‌گرداند ([توکن‌ها و Sessionها](#توکن‌ها-و-sessionها)): حساب ساخته شده و کاربر وارد شده — به
فروشگاه گزارش می‌شود و صاحب کد دعوت خبردار می‌شود.

| فیلد | قاعده |
|---|---|
| `first_name` | ۱ تا ۶۴ کاراکتر |
| `last_name` | حداکثر ۶۴؛ برای هیچ، خالی بگذارید یا نفرستید |
| `email` | یک آدرس، حداکثر ۱۹۱ کاراکتر؛ با حروف کوچک نگه داشته می‌شود |
| `password` | دست‌کم ۸ کاراکتر، حداکثر ۷۲ بایت (هر حرف فارسی دو بایت است)، بدون کاراکتر NUL؛ دقیقا همان‌طور که تایپ شده پذیرفته می‌شود، با همه فاصله‌هایش |

خطای همه فیلدها با هم می‌آید (`422` زیر هر کدام)، و بعد خطای کپچا. کد شش رقم است — رقم فارسی یا عربی هم، ولی چیز دیگری
نه: پیش از فرستادن، هر فاصله یا خط تیره‌ای را که کاربر تایپ کرده بردارید (کدی با شکل دیگر مثل کد اشتباه رد می‌شود و یک
ورود ناموفق حساب می‌شود). کد ۱۵ دقیقه اعتبار دارد و پنج بار می‌شود امتحانش کرد؛ کد اشتباه، منقضی، مصرف‌شده یا کدی که
امتحان‌هایش تمام شده `422` روی `code` می‌گیرد (`کد درست نیست یا منقضی شده است.`). کدهای اشتباهی که با ایمیل‌های یک آدرس
وارد می‌شوند سهمیه جداگانه‌ای دارند — ده در ساعت و بیست در روز، که هر کدام پیش از بررسی شمرده می‌شود —؛ بعد از آن برای هر
کد شش‌رقمی `429` برمی‌گردد (`کد اشتباه زیادی برای این ایمیل وارد شد؛ …`)، تا دوباره جا باز نشود کد تازه‌ای به آن آدرس
نمی‌رود، و حساب آن آدرس، اگر باشد، روزی یک بار خبردار می‌شود (`email_codes_failed`). `409` یعنی آدرس در این فاصله حساب
گرفته: کاربر را به ورود با رمز عبور بفرستید. `503` یعنی ایمیل فروشگاه فرستاده نمی‌شود؛ `502` یعنی همین ایمیل نرفت.

## رمز عبور و مرحله دوم

درخواست `POST /auth/login`:
```json
{ "email": "sara@example.com", "password": "kooh-e damavand 1404", "captcha": "0.mT9z3bVr2Q8yKp1sWnA4hD7eLc" }
```

- پاسخ `200` — توکن ([توکن‌ها و Sessionها](#توکن‌ها-و-sessionها)).
- پاسخ `202` — رمز عبور درست بود و حساب کد برنامه احراز هویت را می‌خواهد. هنوز Sessionی ساخته نشده. پاسخ `202` به
  `POST /auth/login`:
  ```json
  { "two_factor": { "challenge": "206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6", "expires_in": 300 } }
  ```
- پاسخ `401` با `ایمیل یا رمز عبور درست نیست.` — یکی از این دو اشتباه است؛ API هیچ‌وقت نمی‌گوید کدام، و بررسی آدرس
  اشتباه همان‌قدر طول می‌کشد که رمز اشتباه.
- پاسخ `403` برای کاربر مسدود، `422` برای یک فیلد یا کپچا، `429` برای تلاش‌های ناموفق زیاد از این آدرس یا برای این حساب
  ([محدودیت‌ها و مدت اعتبار](#محدودیت‌ها-و-مدت-اعتبار)) — یا، تا وقتی وب‌سایت کپچا می‌خواهد، وقتی شبکه این آدرس از سقف
  هزینه‌اش برای فروشگاه گذشته (`درخواست‌ها زیاد بود؛ …`: هر توکن پیش از سنجیده شدن در این سقف شمرده می‌شود) —، و `503`
  وقتی نشد کپچا را سنجید.

مرحله دوم، challenge را با کد شش‌رقمی‌ای که برنامه همین حالا نشان می‌دهد می‌فرستد — یا با یکی از کدهای بازیابی حساب
([ورود دومرحله‌ای](Store-API-Account.md#ورود-دومرحله‌ای)). رقم فارسی، فاصله و خط تیره اشکالی ندارند؛ در کدهای بازیابی
حروف بزرگ و کوچک فرقی ندارند.

درخواست `POST /auth/login/2fa`:
```json
{ "challenge": "206b15971eac2d02cb232593836cd114d37e86e2ba3f36ca53dff03645c8add6", "code": "۴۸۲ ۹۱۳" }
```

پاسخ `200` توکن را برمی‌گرداند. کد اشتباه `422` روی `code` می‌گیرد
(`کد درست نیست؛ کد شش‌رقمی برنامه احراز هویت یا یکی از کدهای بازیابی را وارد کنید.`)؛ هر challenge پنج کد می‌پذیرد و پنج
دقیقه اعتبار دارد. هر کد فقط یک بار کار می‌کند: از دو ورود با یک کد، فقط یکی وارد می‌شود. `401` یعنی challenge دیگر کار
نمی‌کند (منقضی شده، امتحان‌هایش تمام شده، یا در این فاصله ورود دومرحله‌ای خاموش شده): به مرحله رمز عبور برگردید.

هر کسی به این مرحله برسد رمز عبور را دارد، پس کدهای هر حساب سهمیه جداگانه‌ای دارند، از همه آدرس‌ها با هم: ۱۰ کد اشتباه
در ساعت و ۲۰ در روز، که هر کدام پیش از بررسی شمرده می‌شود (کد درست شمارش خودش را برمی‌گرداند). وقتی سهمیه تمام شود، پاسخ
هر کدی با شکل درست `429` است — شش رقم، یا ده کاراکتر کد بازیابی (هر چیز دیگری همان `422` می‌ماند و از سهمیه کم نمی‌کند)
—، و به کاربر خبر داده می‌شود — روزی یک بار، با اعلان `second_step_locked`
([انواع اعلان](Store-API-Notifications.md#انواع-اعلان)).

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

هش قدیمی رمز عبور هنگام ورود کاربر با پیش‌فرض فعلی PHP دوباره ساخته می‌شود: سایت لازم نیست کاری بکند.

## فراموشی رمز عبور

1. درخواست `POST /auth/password/forgot` با <code dir="ltr">{ email, captcha? }</code> پاسخ `202` با
   <code dir="ltr">{ "expires_in": 900 }</code> می‌گیرد. به آدرسی که حساب دارد کد فرستاده می‌شود؛ به آدرسی که حساب ندارد،
   ایمیلی که می‌گوید حسابی ندارد — روزی یک بار (اگر همان روز دوباره خواسته شود، چیزی نمی‌رود). پاسخ در هر دو حالت یکی است،
   و سهمیه‌هایی هم که درخواست از آن‌ها کم می‌کند یکی است، پس هیچ‌کدام لو نمی‌دهد کدام آدرس‌ها حساب دارند
   ([محدودیت‌ها و مدت اعتبار](#محدودیت‌ها-و-مدت-اعتبار))؛ `503` تا وقتی ایمیل فروشگاه فرستاده نمی‌شود.
2. درخواست `POST /auth/password/reset` با `{ email, code, password }` پاسخ `200` با توکن می‌گیرد: رمز عبور گذاشته می‌شود،
   همه Sessionهای حساب تمام می‌شوند و به کاربر خبر داده می‌شود (`password_changed`). اگر ورود دومرحله‌ای روشن باشد
   (بازنشانی آن را روشن نگه می‌دارد)، به جای آن `202` با `{ two_factor }` می‌آید و هنوز چیزی عوض نمی‌شود:
   `POST /auth/login/2fa` مثل بالا رمز عبور را می‌گذارد، Sessionها را تمام می‌کند و کاربر را وارد می‌کند.

این برای هر حسابی که ایمیل دارد کار می‌کند، هر چه `sign_in.email` بگوید — و کاربری که با گوگل ثبت‌نام کرده و هیچ‌وقت رمز
عبوری نگذاشته، از همین راه رمز می‌گذارد. کد اشتباه `422` روی `code` می‌گیرد و مثل کد ثبت‌نام در سهمیه کدهای اشتباه آدرس
شمرده می‌شود؛ رمز عبور تابع قاعده ثبت‌نام است.

## کپچا

تا وقتی `captcha` در <code dir="ltr">GET /</code> برابر `null` نیست، چهار فرم باید `captcha` را داشته باشند، یعنی توکن
ویجت که برای action همان فرم حل شده (`x-captcha` در قرارداد):

| فرم | action |
|---|---|
| `POST /auth/register` | `sign_up` |
| `POST /auth/login` | `sign_in` |
| `POST /auth/password/forgot` | `password_reset` |
| `POST /reviews` | `review` ([نظرات](Store-API-Reviews.md#ثبت-نظر)) — بدون کپچا، وب‌سایت فقط نظر کاربران واردشده‌اش را می‌گیرد |

نبودن آن، یا توکنی که قبول نمی‌شود — برای action دیگری حل شده، روی هاستی که مال وب‌سایت نیست (آدرس و Originهای آن)،
منقضی شده یا قبلا استفاده شده — `422` روی `captcha` می‌گیرد (`تایید امنیتی انجام نشد؛ دوباره تلاش کنید.`)؛ کپچایی که
فروشگاه همین حالا نتوانست بسنجد `503` می‌گیرد (`تایید امنیتی در دسترس نیست؛ کمی بعد دوباره تلاش کنید.`). هر توکن فقط یک
بار معتبر است: بعد از هر پاسخ ویجت را ریست کنید و پیش از فرستادن بعدی منتظر توکن تازه بمانید. توکن ردشده در فرم‌های ورود
در سقف هزینه شبکه آن آدرس برای فروشگاه شمرده می‌شود (۱۲۰ nonce، state، کد و توکن ردشده در ۱۰ دقیقه)؛ در فرم نظر، در
سرعت مجاز خود نظرات ([نظرات](Store-API-Reviews.md#ثبت-نظر)).

فیلد `captcha` به شکل `{ driver, site_key, challenge_url }` است و یکی از دو کپچاست:

- **کپچای `turnstile`** — Cloudflare Turnstile. ویجت آن را با `site_key` نشان دهید و action فرم را بدهید (`action` در
  `turnstile.render`، یا `data-action`)؛ `challenge_url` برابر `null` است. هر توکن ۳۰۰ ثانیه معتبر است. فروشگاه آن را
  با Cloudflare بررسی می‌کند.
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
- **کپچای `altcha`** — [ALTCHA](https://altcha.org)، متن‌باز: بدون سرویس شخص ثالث و بدون کلید (`site_key` برابر `null`
  است)، پس روی هر هاستی کار می‌کند، حتی جایی که Cloudflare در دسترس نیست. ویجت آن هر بار یک چالش تازه از فروشگاه در
  `challenge_url` با action فرم می‌گیرد — `GET /captcha/challenge?action=sign_up` — که هیچ‌وقت کش نمی‌شود؛ مرورگر
  بازدیدکننده آن را حل می‌کند (کار یک لحظه) و ویجت یک payload (JSON به base64) به فرم می‌دهد که با نام `captcha` فرستاده
  می‌شود. هر چالش یک بار و ظرف ۱۵ دقیقه حل و فرستاده می‌شود؛ جوابش برای فرمی با action دیگر قبول نمی‌شود.
  `GET /captcha/challenge` وقتی کپچای وب‌سایت چالش خودش را دارد (Turnstile) یا کپچایی نمی‌خواهد `404` می‌دهد
  (`تایید امنیتی این وب‌سایت چالشی از فروشگاه نمی‌گیرد.`)، و برای actionی که ۱ تا ۳۲ کاراکتر از
  <code dir="ltr">A-Z a-z 0-9 - \_</code> نباشد `422` روی `action`.
  ```tsx
  // npm i altcha — the <altcha-widget> web component
  import 'altcha'

  <altcha-widget challengeurl={`${shop.captcha!.challenge_url}?action=sign_up`} ref={widget} />
  // its `statechange` event carries the payload once solved: ev.detail.state === 'verified' → ev.detail.payload
  ```

**فرم‌های خود سایت** — فرم تماس، عضویت در خبرنامه — هم می‌توانند همین کپچا را داشته باشند، با actionی از خود سایت (۱ تا
۳۲ کاراکتر از <code dir="ltr">A-Z a-z 0-9 - \_</code>)، و بک‌اند سایت سنجیدن آن را، سرور به سرور، همان‌طور که مرورگر
بازدیدکننده فرستاده، از فروشگاه می‌خواهد: اینکه مرورگر بگوید کپچا را گذرانده چیزی را ثابت نمی‌کند.

درخواست `POST /captcha/verify`:
```json
{ "token": "0.mT9z3bVr2Q8yKp1sWnA4hD7eLc", "action": "contact", "remoteip": "203.0.113.7" }
```

پاسخ `200` به `POST /captcha/verify`:
```json
{ "passed": false, "action": "sign_in", "hostname": "shop.example.com", "verified_at": "2026-10-08T10:15:00+00:00", "reasons": ["action-mismatch"] }
```

پاسخ چه کپچا قبول شده باشد چه نه `200` است: `action` و `hostname` همان actionی هستند که توکن برایش حل شده و هاستی که
روی آن حل شده، تا جایی که کپچا می‌گوید (ALTCHA هاستی را نمی‌گوید)، و `reasons` دلیل رد — `hostname-mismatch` (روی هیچ‌کدام
از هاست‌های وب‌سایت حل نشده)، `action-mismatch`، `timeout-or-duplicate` (منقضی شده، یا قبلا استفاده شده)،
`invalid-input-response`، `missing-input-response`، و کدهای خود Cloudflare همان‌طور که خودش می‌گوید. هر توکن فقط یک بار
پذیرفته می‌شود.

فیلد اختیاری `remoteip` آدرس بازدیدکننده است، همان‌طور که بک‌اند شما دریافتش کرده (IPv4 یا IPv6): فروشگاه توکن‌های ردشده
آن بازدیدکننده را با شبکه‌اش می‌شمارد (آدرس IPv6 با <code dir="ltr">/64</code> آن) و، با Turnstile، آن را همراه توکن به
Cloudflare می‌دهد. اگر نفرستید، آدرس خود بک‌اند شما جای همه بازدیدکننده‌ها حساب می‌شود.

خطاها: `409` وقتی وب‌سایت کپچا نمی‌خواهد (`این وب‌سایت تایید امنیتی ندارد؛ آن را در پنل، تنظیمات وب‌سایت، روشن کنید.`)؛
`422` روی `token` (نیامده، یا بیش از ۴۰۹۶ کاراکتر)، روی `action`، یا روی `remoteip` برای چیزی که آدرس IP نیست
(<code>remoteip باید آدرس IP بازدیدکننده باشد (IPv4 یا IPv6).</code>)؛ `503` وقتی نشد آن را سنجید. و `429`
(`درخواست تایید زیادی فرستاده شد؛ …`)، که پیش از سنجیدن توکن شمرده می‌شود — پس درخواست ردشده چیزی از Cloudflare
نمی‌پرسد —، به دو شکل:

- **سهمیه وب‌سایت**: ۳٬۰۰۰ توکن در ۱۰ دقیقه، قبول‌شده یا نه — بک‌اند شما برای همه بازدیدکننده‌ها یک درخواست‌دهنده است.
- **سهمیه شبکه بازدیدکننده**: ۱۲۰ توکن ردشده در ۱۰ دقیقه از شبکه‌ای که `remoteip` می‌گوید — وگرنه شبکه بک‌اند شما.
  توکنی که قبول شود، یا سنجیده نشود، شمارشش را برمی‌گرداند: فقط توکن‌های ردشده از این سهمیه کم می‌کنند.

این سهمیه مخصوص خود وب‌سایت است: هیچ درخواستی در اینجا از سقف هزینه ورودها کم نمی‌کند، پس رباتی که توکن‌های بی‌ارزش به
فرم‌های شما می‌فرستد جلوی ورود کسی را نمی‌گیرد. `remoteip` را بفرستید تا ربات سهمیه ۱۲۰تایی شبکه خودش را مصرف کند، نه
سهمیه همه بازدیدکننده‌ها را، و پیش از پرسیدن از فروشگاه، سرعت ارسال فرم‌های خودتان را هم با قاعده خودتان محدود کنید.

## کدهای دعوت در ثبت‌نام

درخواست‌های `POST /auth/telegram`، <code dir="ltr">/auth/google</code> و <code dir="ltr">/auth/register</code> فیلد
`referral_code` را می‌گیرند، یعنی کد دعوت یک کاربر ([برنامه زیرمجموعه‌گیری](Store-API-After-Buying.md#برنامه-زیرمجموعه‌گیری)).
فقط تازه‌وارد — حسابی که همین ورود می‌سازد — زیرمجموعه صاحب کد می‌شود؛ کاربری که فروشگاه از قبل می‌شناخت هیچ‌وقت دوباره به
کسی نسبت داده نمی‌شود. در کد، حروف بزرگ و کوچک فرقی ندارند و رقم فارسی هم خوانده می‌شود. کد ناشناخته، کد کاربر مسدود، یا
خاموش بودن برنامه در آن لحظه بدون خطا نادیده گرفته می‌شود. کدی را که بازدیدکننده با آن آمده نگه دارید و هنگام ورود
بفرستید:

```ts
// Any page: https://shop.example.com/?ref=k7m2xq9p
const ref = new URLSearchParams(location.search).get('ref')
if (ref) localStorage.setItem('referral-code', ref)
// …later: referral_code: localStorage.getItem('referral-code') ?? undefined
```

## محدودیت‌ها و مدت اعتبار

| چه چیزی | محدودیت |
|---|---|
| ورودهای ناموفق با هر روشی — تلگرام، گوگل، رمز عبور، کد ایمیل، کد مرحله دوم، رمز عبوری که پیش از یک تغییر دوباره وارد می‌شود، روش ورودی که دوباره ثابت می‌شود | ۳۰ بار برای هر آدرس کلاینت در ۱۵ دقیقه؛ این بازه با اولین شکست شروع می‌شود و یک ورود موفق آن را نمی‌بندد. IPv6 با <code dir="ltr">/64</code> آن شمرده می‌شود |
| رمزها و کدهای ناموفق برای یک آدرس ایمیل | ۵۰ بار در ۱۵ دقیقه، از همه آدرس‌ها با هم |
| کدهای مرحله دوم برای یک حساب | ۱۰ کد اشتباه در ساعت و ۲۰ در روز، از همه آدرس‌ها با هم ([رمز عبور و مرحله دوم](#رمز-عبور-و-مرحله-دوم)) |
| کدهای اشتباه ایمیل‌شده به یک آدرس — و کدهایی که حساب هنگام افزودن ایمیل امتحان می‌کند | ۱۰ در ساعت و ۲۰ در روز، هر کدام پیش از بررسی شمرده می‌شود؛ بعد تا جا باز نشود کد تازه‌ای به آن آدرس نمی‌رود، و حساب آن روزی یک بار خبردار می‌شود |
| هزینه هر ورود برای فروشگاه: nonceها، stateهای ریدایرکت، ایمیل‌های درخواست‌شده، توکن‌های کپچای ردشده در فرم‌های ورود | ۱۲۰ بار برای هر آدرس کلاینت در ۱۰ دقیقه |
| ایمیل‌هایی که شبکه یک آدرس کلاینت درخواست می‌کند | ۲۰ در ساعت |
| ایمیل به یک آدرس | یکی در دقیقه، و ۱۰ در روز از همه فروشگاه‌ها با هم |
| کدهایی که کاربر واردشده هنگام افزودن ایمیل درخواست می‌کند | ۵ در روز |
| ایمیلی که به یک آدرس می‌گوید حسابی ندارد | روزی یک بار |
| ایمیل‌های ورودی که فروشگاه می‌فرستد | ۶۰ در ساعت و ۳۰۰ در روز؛ همه فروشگاه‌های یک نصب با هم، ۱۵۰ در ساعت و ۱٬۰۰۰ در روز. وقتی تمام شود، تا جا باز نشود کدی فرستاده نمی‌شود (`503`) |
| ورودهایی که یک فروشگاه شروع می‌کند (nonceها؛ stateهای ریدایرکت، چه برای ورود و چه برای کاربر واردشده) | ۵٬۰۰۰ nonce در ۳۰ دقیقه و جداگانه ۵٬۰۰۰ state در ۱۰ دقیقه، که هنگام صادر شدن شمرده می‌شوند؛ بیشتر از هر کدام، `503` تا پایان آن بازه |
| توکن‌های کپچایی که بک‌اند سایت سنجیدنشان را از فروشگاه می‌خواهد | ۳٬۰۰۰ برای هر وب‌سایت در ۱۰ دقیقه، و ۱۲۰ توکن ردشده از شبکه یک بازدیدکننده (شبکه `remoteip`، وگرنه شبکه بک‌اند) در همان بازه — هیچ‌وقت از سهمیه ورودهای بالا |
| یک nonce | ۳۰ دقیقه، یک بار مصرف |
| state یک ریدایرکت | ۱۰ دقیقه |
| کد ایمیل | ۱۵ دقیقه، ۵ بار امتحان؛ کد تازه جایش را می‌گیرد |
| challenge یک مرحله دوم | ۵ دقیقه، ۵ کد |
| کلید برنامه احراز هویت که منتظر اولین کدش است | ۱۵ دقیقه، ۵ بار امتحان |
| یک چالش ALTCHA | ۱۵ دقیقه، یک بار مصرف |
| یک ورود تازه | ۱۵ دقیقه از ورود، یا از آخرین `POST /me/reauthenticate` ([ورود تازه](Store-API-Account.md#ورود-تازه)) |
| یک پیشنهاد ادغام | ۱۵ دقیقه، یک بار مصرف |
| یک Session | ۳۰ روز بدون استفاده، حداکثر ۱۸۰ روز؛ ۲۰ Session برای هر کاربر |

هر `429` هدر `Retry-After` را به ثانیه دارد و مدت انتظار را در پیامش می‌گوید. محدودیت‌های هر آدرس عمدا دست‌ودل‌باز
هستند — اپراتورهای موبایل کاربران زیادی را پشت یک آدرس می‌گذارند — و این محدودیت‌های هر حساب است که جلوی حدس زدن را
می‌گیرد؛ با این حال، nonce را فقط وقتی بگیرید که کاربر واقعا می‌خواهد وارد شود، نه با هر بار باز شدن صفحه.

## آنچه مالک فروشگاه تنظیم می‌کند

- **وب‌سایت** — «تنظیمات وب‌سایت › اتصال»: آن را روشن کنید، آدرس سایت و هر Origin اضافه را وارد کنید
  (`http://localhost:3000` در زمان ساخت)، و آدرس پایه را کپی کنید.
- **تلگرام** — در @BotFather، ربات فروشگاه › Bot Settings › Login Widget: آدرس سایت را، و برای روند ریدایرکت آدرس صفحه
  بازگشت را، زیر Allowed URLs اضافه کنید. Client ID (و برای ریدایرکت، Client Secret) را در «تنظیمات وب‌سایت › ورود با
  تلگرام» وارد و روشنش کنید. Client ID را BotFather می‌دهد و لزوما همان شناسه ربات نیست.
- **گوگل** — در Google Cloud Console › APIs & Services › Credentials › Create Credentials › OAuth client ID، از نوع Web
  application (گوگل اول تنظیم صفحه رضایت را می‌خواهد). Origin سایت را زیر Authorized JavaScript origins اضافه کنید — و
  برای کار محلی، `http://localhost` و `http://localhost:3000` — و Client ID را در «ورود با گوگل» وارد کنید (خالی گذاشتنش
  ورود با گوگل را خاموش می‌کند).
- **ایمیل** — ایمیل نصب باید فرستاده شود: «تنظیمات پنل › ایمیل» در پنل مدیریت، با یک ارسال آزمایشی. بعد «ورود با ایمیل ›
  ثبت‌نام با ایمیل» را روشن کنید. در فروشگاه نماینده، ایمیل را مالک فروشگاه تنظیم می‌کند.
- **کپچا** — «تنظیمات وب‌سایت › ورود با ایمیل»، کارت «تایید امنیتی»: ALTCHA چیز دیگری لازم ندارد؛ برای Cloudflare
  Turnstile، در داشبورد Cloudflare (بخش Turnstile) یک ویجت برای hostname سایت بسازید و Site Key و Secret Key آن را همین‌جا
  وارد کنید.
- **نظرات** — «تنظیمات وب‌سایت › نظرات»: «نظرات در وب‌سایت» را روشن کنید، وگرنه <code dir="ltr">/reviews</code> پاسخ
  `404` می‌دهد؛ مهمان‌ها فقط تا وقتی کپچا روشن است می‌توانند نظر بنویسند ([نظرات](Store-API-Reviews.md)).

پاپ‌آپ تلگرام فقط روی سایتی باز می‌شود که در @BotFather ثبت شده؛ دکمه گوگل هم فقط روی Origin ثبت‌شده. اگر BotFather آدرس
محیط توسعه شما را نمی‌پذیرد، تلگرام را روی یک دامنه staging امتحان کنید و روی سیستم خودتان از ورود با ایمیل استفاده کنید.

</div>
