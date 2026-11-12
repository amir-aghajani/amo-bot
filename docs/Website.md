[فارسی](fa/Website.md)

# Website

Every shop — the main one and each agent's — may have a website of its own, built by whoever runs the shop, which talks
to the shop through the [Store API](Store-API.md): its customers sign in, buy, renew, pay, read the shop's notices and
write to support there — the same shop, customers and services the bot and the panels show. «تنظیمات وب‌سایت», in your
panel and an agent's alike, sets it up: a section each, each card saved on its own — a secret left blank keeps the one
kept. The shop's other door onto the same account, inside Telegram, is its [mini app](Mini-App.md), which AmoBot ships
ready to switch on.

## Connection

«اتصال», card «وب‌سایت فروشگاه»:

- **«API وب‌سایت روشن باشد»** (off) — off, every call to the API's address is answered «این فروشگاه فعلا در دسترس نیست.».
  An agent's website closes with their agency too.
- **«آدرس وب‌سایت»** — the site, `https://shop.example.com`: required to switch the API on. Its origin — the scheme, the
  host and the port — may call the API from a visitor's browser.
- **«Originهای مجاز دیگر»** — up to ten more origins, one a line: `http://localhost:3000` while the site is built, another
  domain of it. An origin is a scheme, a host and a port — no path.

Card **«آدرس API»**: the base address to hand the site's developer — `<APP_URL>/api/store/v1/<key>`, with «کپی» — and its
**«کلید فروشگاه»**, which names the shop in the address and is no secret. **«ساخت کلید جدید»** (asks first) ends the old
address at once: the site must take the new one. The address is built on the shop's «آدرس سایت» (`APP_URL`, [Panel
settings](Panel-Settings.md#the-app)): change that, and the site's address changes with it.

## Signing in with Telegram

«ورود با تلگرام» — customers sign in with their Telegram account and find the same account the bot knows them by: their
services, their wallet.

1. In @BotFather, pick the shop's bot › *Bot Settings › Login Widget*, and add the website's address under *Allowed URLs*
   (and the page the redirect sign-in comes back to, when the site uses it).
2. Type the **«Client ID»** BotFather shows there — digits; the bot's own id is shown beside the field, which it usually
   is — and, for the redirect sign-in, the **«Client Secret»** (the popup needs none).
3. Switch **«ورود با تلگرام»** on.

## Signing in with Google

«ورود با گوگل» — customers sign in with their Google account:

1. In Google Cloud, *APIs & Services › Credentials › Create Credentials › OAuth client ID*, of type *Web application*.
2. Add the website's origin (and the other origins, if they sign in too) to its *Authorized JavaScript origins*.
3. Type its **«Client ID گوگل»** (`1234567890-abc123.apps.googleusercontent.com`); empty, Google sign-in is off.

A Google account that is new to the shop makes a new account — or signs in to the account of the same address, when
Google speaks for that address (a Gmail or Workspace one) and the account has no two-factor sign-in nor another Google
account; otherwise the customer is asked to sign in their own way and add Google from their account's settings.

## Signing in with an email

«ورود با ایمیل», two cards:

- **«ثبت‌نام با ایمیل»** — **«ثبت‌نام با ایمیل و رمز عبور»** (off): a code goes to the address a customer types, and their
  account is made once they type it on the site. It needs the shop's email to go out — set up under «تنظیمات پنل ›
  ایمیل» ([Panel settings](Panel-Settings.md#email)); in an agent's shop, the owner sets it up: until then it cannot be
  switched on. Customers who signed up before keep signing in and resetting their password whatever it says.
- **«تایید امنیتی»** — a captcha on the site's sign-up, password sign-in, password-reset, SMS-code and review forms
  («نوع تایید امنیتی»):
  - **«خاموش»** (the default) — the forms are held only by the limits on requests.
  - **«Cloudflare Turnstile»** — make a widget for the site's domain in Cloudflare's dashboard (*Turnstile*), and type its
    **«Site Key»** and **«Secret Key»**. A new Site Key needs its widget's Secret Key typed again. A token solved on
    another host than the site's address and its other origins, or for another form, is refused.
  - **«ALTCHA»** — open source, no keys, no service outside: the site's widget takes its puzzle from the shop's API.

  Leaving Turnstile throws its keys away: going back needs both again. The site's own forms — a contact form, say — may
  have the shop check their captcha too.

## Signing in by SMS

«ورود با موبایل», card «ورود مشتری با شماره موبایل» — customers sign in with a six-digit code texted to their mobile:

- **«ورود با شماره موبایل (پیامک)»** (off) — the customer types their mobile number on the site (an Iranian mobile:
  `09121234567`, with or without the 0, with `+98`, in Persian digits), gets a code by SMS and types it back. A customer
  who shared that number with the bot («تایید شماره») signs in to that very account, with their services and wallet; a
  number new to the shop makes a new account with the number alone — they add their name on the site. When two
  accounts hold the number — a customer who signed up on the site by phone, then started the bot with the same number
  —, the code signs in to the bot's and offers to merge the other into it, so neither is left without a way in.
- **A code by SMS is weaker than Telegram or Google** — a SIM swapped, or a number the carrier gave someone else, reads
  it. So an account with two-factor sign-in is asked its authenticator app's code after the SMS code, as after its
  password, and a sign-in by SMS alone is no strong sign-in for your admins on the website
  ([the shop's admins](#the-shops-admins)).
- **It needs SMS set up** in «تنظیمات پنل › پیامک» ([SMS](Sms.md)): until then the switch cannot be turned on, and the
  card says why. **An agent's website** texts codes only while you let agents' websites use your SMS account (the switch
  on that page) — every code they send is paid from your credit; until then the agent's card says the owner has not
  opened SMS to agents' websites. Switched on, it rests while no SMS goes out for the site, and works again once it does.
- **Limits** keep a stranger from spending your credit: a code lives five minutes and takes five tries; a number gets a
  code a minute at most, five an hour and ten a day; one address network asks for 20 an hour; a shop's website sends at
  most 60 an hour and 300 a day (all websites together 150 and 1000). The captcha of «ورود با ایمیل» guards the form
  that asks for a code too.
- A signed-in customer adds a number to their account on the site — another account holding it is offered as a merge,
  as for an email —, takes it off (never their last way in), and proves it again by a code before changing their ways
  in.

What the site's developer does with each: [Store API: signing in](Store-API-Sign-In.md#what-the-owner-sets-up).

## Reviews

«نظرات», card «نظرات مشتریان در وب‌سایت» — customers write about the shop on the website, and it shows the reviews you
approved on the «نظرات» page ([Reviews](Reviews.md)):

- **«نظرات در وب‌سایت»** (off) — on, the website shows the approved reviews and takes new ones; off, neither — the site's
  reviews and its form answer that the website has none.
- **Who may write** follows the captcha: while «تایید امنیتی» is on, guests too; while it is off, only customers signed
  in to the website — the card says which, and leads to «ورود با ایمیل» to turn the captcha on.

No review shows on the website until you approve it.

## The shop's admins

«مدیران سایت», card «مدیریت فروشگاه از وب‌سایت» — the bot's admins work the shop's daily work from the website, with
their own accounts there: receipts and payments, orders, services, tickets, reviews, customers and the groups they are
in, referrals and broadcasts. The payment methods, the bot, the report group, the website and who is an admin change
from the panel alone.

- **«کار مدیران از وب‌سایت»** (off) — off, the website takes no admin's work at all.
- **«ورود امن مدیران»** (on) — an admin's work is taken only from a sign-in with Telegram, Google, or a password or a
  code to their mobile with its two-factor code; off, a password or a code by SMS alone is enough. Keep it on: a leaked
  password, or a SIM swapped, would otherwise open the shop.
- **«کارهای بیشتر»** — what they may do beyond the daily work, a switch each, all off: «پلن‌ها و دسته‌بندی‌ها» (the plans
  and their categories, and the customer groups: adding, changing, reordering and deleting them, and putting a customer
  into a «همکار» group, whose members pay the agents' prices), «کیف پول مشتری», «بازپرداخت», «رد یکجای رسیدها» (receipts waiting for review rejected in bulk —
  [Orders and payments](Orders-And-Payments.md#receipts)), «افزایش زمان و حجم سرویس», «افزودن سفارش برای مشتری» (a
  service made for a customer by hand and delivered at once —
  [Orders and payments](Orders-And-Payments.md#orders-support-makes)), «حذف یا انتقال سرویس» (to another customer, as
  from a service's dialog), «امنیت حساب مشتری» (two-factor sign-in turned off, every device signed out). Each asks the
  admin to have signed in — or proven it again — in the last 15 minutes.

An admin's sign-in works the shop for **12 hours**; after that they prove it again — a token that leaked does not work
for long. **Approving a payment** delivers a service on their word alone, so it asks a sign-in of the last 15 minutes,
as the extra work does.

**Who the admins are** is the users list's: the customers with the bot's admin role («لیست کاربران» on the card opens
them; [Customers](Customers.md#the-bots-admins)). An admin signs in to the website with that same account — their
Telegram, or a way in added to it. Whatever is granted:

- an admin never decides about themselves — their own payment, wallet, service or review;
- an admin never touches an admin's or an agent's account — their groups included —, nor marks a group that holds an
  admin «همکار»; and without «پلن‌ها و دسته‌بندی‌ها», adds nobody to a «همکار» group;
- an admin approves only a payment whose receipt waits for review.

**Those guards are about each admin's own account: two admins can do these things for each other** — approve each
other's payments, credit each other's wallets —, as the card warns. Give the role only to people you trust. What the
website's developer builds for them: [Store API: the shop's admins](Store-API-Admins.md).

## Watch out for

- **With the bot switched off** («تنظیمات ربات › عمومی»), the website takes no orders; browsing and signing in still work.
- **A new key, or another «آدرس سایت», breaks the site** until its developer gives it the new address.
- **Telegram sign-in needs the site's address in BotFather** — a sign-in from an address it does not list fails there.
- **The site shows no reviews until «نظرات در وب‌سایت» is on**, and guests write none while «تایید امنیتی» is off.
- **Sign-in by SMS spends your SMS credit**, and an agent's website spends it too once you let agents' websites send
  codes. With no credit left, nobody signs in by their number: keep the other ways in on as well.
- **An admin's role opens the shop's daily work on the website** while «کار مدیران از وب‌سایت» is on: give the role with
  care, and take it away when someone leaves.
