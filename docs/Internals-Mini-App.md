[فارسی](fa/Internals-Mini-App.md)

# Internals: mini app

The shops' Telegram mini app: the customer side of a shop inside Telegram — buying, paying, services, the wallet, the
shop's programs, support, the account. It is a third page of the panels' React project (`resources/panel`), built
apart, and it talks to PHP only through the Store API, under the mini app's own store key
([two doors](Store-API.md#two-doors)): the same services and rules as the bot and the website, nothing of its own on
the server. This page is the map of its code; the server's side — the door, the launch data's sign-in, the settings
the panels save — is [Internals: website API](Internals-Website-API.md), and the bot's buttons that open it are
[Internals: Telegram bot](Internals-Telegram-Bot.md#the-mini-app).

## The source tree

```
resources/panel/
  app/index.html     the mini app's page (entry: src/apps/app/main.tsx)
  app/open.html      the bridge that hands a VPN app its "add a subscription" address (plain HTML, no React)
  src/apps/app/
    config.ts        the store key, the Store API's base and the router's basename, read off the page's address
    main.tsx         Telegram's script (bundled), the page handed to Telegram, the app drawn
    app.tsx          the routes (APP_ROUTES), the sign-in gate, the shop's look
    app.css          the design tokens, from the user's Telegram theme
    lib/             the Store API client, the query client, the session, the recent sign-in, the checkout's hand-over
    telegram/        the typed bridge to Telegram.WebApp: buttons, haptics, theme, fingerprint
    ui/              the app's own kit: Screen, Section and Row, Button, MainAction, Sheet, fields, states, the tab bar,
                     the preview's mark
    parts/           what several screens share (the checkout's ways to pay, a discount code, the price)
    screens/<area>/  a file per route, its default export the screen; its parts/ and its tests beside it
    test/            the tests' address and render helpers
```

It shares the panels' libraries — `lib/api` (`apiClient`, `ApiError`), `lib/api-types`, `lib/format`, `lib/statuses`,
`lib/jalali` — and the self-hosted Vazirmatn; it imports no panel component or page, and the panels never import its
code.

## The build

`vite build --mode app` — the second step of `pnpm build` — builds it apart from the panels (`vite.config.ts`, its
`MINI_APP` mode):

- its inputs are `app/index.html` and `app/open.html`; it writes `public/app/`, its hashed files in `public/app/assets/`
  (a brotli copy beside each script and stylesheet, and their own `assets/.htaccess`), in two library chunks (`react`,
  `vendor`) beside its own; it removes only `public/app` before it writes;
- each page gets the build's Content-Security-Policy, as a panel's does: scripts and connections to its own origin
  alone, its inline script allowed by its hash. Telegram's own script, `telegram-web-app.js`, comes bundled from
  `@twa-dev/sdk` (imported first by `main.tsx`): no script is loaded from anywhere else, and a phone that cannot reach
  telegram.org still opens the app;
- it writes `public/app/.htaccess`: Telegram's web client (web.telegram.org) shows a mini app in a frame, which
  `public/.htaccess` refuses every page (`X-Frame-Options: DENY`), so the app's folder unsets that and lets that site
  and the shop's own frame it (`frame-ancestors 'self' https://web.telegram.org`, `MINI_APP_FRAME_ANCESTORS` in
  `vite.config.ts`) — `'self'` for the panels' «پیش‌نمایش», which shows the app in a frame on the same site.

`public/.htaccess` serves the app's files as they are, answers a missing one under `app/assets/` with a 404, and sends
any other address under `app/` to `app/index.html` — `public/index.php` does the same under `php -S`. The build's
`public/app/.htaccess` says it again for its own folder, so it holds on a server that does not carry a folder's
rewrite rules into a folder with an `.htaccess` of its own. Where the document root is the project folder (cPanel's
`public_html`), `/app/…` first reaches the PHP code's folder, `app/`: its `.htaccess` sends every request on to
`public/app/` under the project's own folder (wherever it is installed), refuses what it cannot place, and refuses
everything where rewriting is unavailable — so nothing of the code is served (`tests/Unit/Http/WebRootTest.php`). The release zip
ships `public/app` (`scripts/release.php` refuses to build without it), Git ignores it, and knip is given
`src/apps/app/main.tsx` as an entry of its own (`knip.jsonc`), since it reads `vite.config.ts` as the panels' build.

**Where it is served**: `{APP_URL}/app/{store key}/…`, the key the mini app's own. The page's inline script sets
`<base>` to the app's folder, wherever the shop is installed; `config.ts` reads the key after `app/` (24 lower-case hex
characters), the Store API's base beside the folder (`…/api/store/v1/{key}`), and the router's basename (the folder and
the key). An address with no key opens no shop.

**The VPN app bridge** (`app/open.html`): Telegram's `openLink` opens web addresses alone, so «اتصال سریع» on a service
(`screens/services/parts/connect`) opens this page in Telegram's browser with the app's address after the `#` —
`v2rayng://`, `happ://`, `hiddify://` or `streisand://`, any other scheme refused —, and the page hands it to the
device (a tap on its button does too). The address after the `#` never reaches the server or its log.

## Starting up

`main.tsx` imports Telegram's script, which reads the launch data, then `startTelegram()` (`telegram/theme`): the app
tells Telegram it is ready, takes the whole height, locks the swipe that would close it (Bot API 7.7), paints
Telegram's header, background and bottom bar in the theme's colours, and follows the theme as it changes. Then `App`
(`app.tsx`) draws, inside a last-resort error boundary (`Crash`, a way to start again, never a blank page):

1. **The session** (`lib/session`) signs the customer in — below.
2. **The gate** (`Gate`): outside Telegram the app says to open it from the bot, with a button to the bot when `GET /`
   names one; a sign-in that failed says why, with «تلاش دوباره» where trying again may help; skeletons meanwhile.
3. **The shop's look**, once `GET /` answered (`useShopLook`): `app.accent_color` as the accent, `app.time_zone` for
   every date (`setShopTimeZone`), `app.title` as the document's title, and full screen on a phone (Bot API 8.0) when
   `app.fullscreen` asks.
4. **The router**: `APP_ROUTES`, a `BrowserRouter` under the basename, each screen a lazy route.

## The Telegram bridge

`telegram/` is the one place that touches `window.Telegram.WebApp`, typed for the parts the app uses:

- `webapp.ts` — `telegram()` (the object while the page was opened from Telegram with launch data, else `null`),
  `inTelegram()`, `supports(version)` and `when(version, call)`: every feature is asked of the client's Bot API version
  first, since an older client lacks it and the script throws on a method it does not have; `when()` also swallows a
  client's throw. `openLink()` (Telegram's browser, the app staying open), `openTelegramLink()` (a `t.me` address inside
  Telegram), `shareToChat()` and `confirmBox()` — each a browser's own outside Telegram.
- `buttons.ts` — `useBackButton(onBack)`: Telegram's back button held by a stack, the latest holder's handler called, so
  a `Sheet` over a `Screen` closes before the screen goes back; `useMainButton()`: Telegram's main button, or one the
  screen draws in a browser.
- `haptics.ts` — `tap()`, `result()`, `selection()`.
- `theme.ts` — `startTelegram()`, `goFullscreen()`, `setAccent()`.
- `biometric.ts` — Telegram's `BiometricManager` (Bot API 7.2): `biometrics()` (null where the device has none),
  `requestAccess()`, `keepToken()`, `unlock()`.
- `active.ts` — `useAppActive()`: whether the customer has the app in front — its page shown and, Bot API 8.0, the app
  not minimised (Telegram's `activated` and `deactivated`) —, so a screen that reads something again every few seconds
  (home, the nightly draw, a gateway's payment) does so only then.

## Sessions and the recent sign-in

`lib/session.tsx` signs the customer in by the launch data: `POST /auth/mini-app` with `Telegram.WebApp.initData`, and
the invite code the app was opened with (`initDataUnsafe.start_param`, what follows `ref_`)
([the mini app](Store-API-Sign-In.md#the-mini-app)). The token is kept in this device's storage under the shop's key and
the Telegram account's id (`amobot-app:{key}:{telegram id}`): the next launch checks it with `GET /me` — that it still
opens a session, and of this Telegram account — and goes on with it, so a launch does not open a session each time and
the session's fingerprint secret stays useful. A kept token ended or another account's is let go and the launch data
signs in afresh, as it does whenever a request answers that the session ended (`onSessionEnded` from the query client).
A sign-in refused (`401`, `403`) asks for a new launch; a `404` says the shop's mini app is off. There is no sign-out:
Telegram signs the customer in each time.

`lib/recent.ts` — `withRecentSignIn(reason, run)` wraps any request that may answer the recent-sign-in `403` (a service
or money given away, an email added, a password, the fingerprint set up): refused for it, the customer proves it is
them without leaving the app — the fingerprint first while `GET /me`'s `session.biometric` says one is kept
(`POST /me/reauthenticate`, `biometric`, `reason` being Telegram's prompt), else the launch data while it is under 15
minutes old (`mini_app`) — and the request runs once more. Past both, `RecentSignInNeeded` tells them to open the app
again or switch the fingerprint on ([a recent sign-in](Store-API-Account.md#a-recent-sign-in)).

The rest of `lib/`:

- `store.ts` — `store`, the Store API client typed by the description (`StoreWrite`: a wrong body is a compile error),
  the bearer token on every request; `pictureUrl()` for a picture only the customer may read (a service's QR card, a
  ticket's picture) as an object URL; `requestKey()`, a new `Idempotency-Key` — one per checkout attempt, kept across
  its retries; `problemOf()`, the customer's words for a failure (with «کد پیگیری» for a server failure);
  `signedOut()`, `needsRecentSignIn()`, `fieldProblem()`.
- `query.ts` — the query client: only a read that got no answer is retried (twice), a read is fresh for 20 seconds and
  read again as the app comes back into view (Telegram minimises it often), a write fails at once when offline and is a
  toast unless `meta.quiet`; `keys`, the query keys.
- `checkout.ts` — what an ordering request answered (the card, the gateway's page) is kept for its order in the tab's
  storage and handed to `/pay/:orderId` (`goPay()`), so going back or a reload shows it again.

## Theming and the kit

`app.css` makes the tokens of the user's Telegram theme — the `--tg-theme-*` properties Telegram's script keeps on the
page — with a light and a dark fallback for a client that sends fewer, or a browser; the scheme is a class on `<html>`;
the shop's colour, when it set one, is the accent (`--app-brand`); `--app-top` and `--app-bottom` keep a full-screen app
clear of Telegram's controls and the phone's edges. Pages are grouped sections on the secondary background, as
Telegram's own settings are; right to left, with Latin runs (links, numbers to copy) set `ltr`; money in Toman and dates
in the shop's zone through `lib/format`.

`ui/` is the kit every screen is built from: `Screen` (title, back, actions, the tab bar or a bottom action), `Section`,
`Row` and `Fact`, `Button`, `MainAction` (one a screen, never in a sheet), `Sheet`, the fields, the visuals (rings,
bars, badges, a price with its before-price, a countdown), the states (`Empty`, `ErrorView` with a retry,
`LoadingBlocks`, `Skeleton`, `Notice`), the tab bar and the toaster.

## The screens

`APP_ROUTES` (`app.tsx`) lists them: the five tabs — home (`/`), buying (`/buy`), services (`/services`), the wallet
(`/wallet`) and the account (`/account`) — show the tab bar; every other screen is pushed over them with Telegram's back
button. The areas under `screens/`: home and its notifications; buying (the catalogue, a plan, a service built, a free
test); paying (`/pay/:orderId`); services (the list, one added by its link, a service, its renewal, its extras, its
transfer); the wallet (a top-up, money sent, the history, an invoice at `/orders/:id`); the club (the lucky wheel, the
nightly draw, referrals); support (tickets, a new one, a conversation, the tutorial, reviews); the account (security,
an email added).

What every screen keeps to:

- **The server decides.** A price is the API's `offer_price` or quote; an ordering request carries one
  `Idempotency-Key` per attempt; the wheel turns to the slice `POST /wheel/spin` drew (`landed`), the motion
  (`screens/club/parts/spin`) only drawing it.
- **The shop's programs** show while `GET /`'s `shop.programs` says so.
- **No raw HTML.** The shop's tutorial, in Telegram's HTML, is parsed into an inert document and only Telegram's own
  tags are built again as React elements (`screens/support/parts/telegram-html`); nothing is put into the page as HTML.
- **A phone number** the shop's bot asks for (`shop.phone_required`) is shared with Telegram's `requestContact` (Bot
  API 6.9), which sends the customer's contact card to the bot ([the bot's side](Internals-Telegram-Bot.md#the-mini-app));
  the account is read again until it shows. Until then the server refuses their orders, gift codes and services claimed
  by a link (`422` on `status`, `CustomerGates::holdBack()`), as the bot answers nothing before its gates.
- **An invoice kept as a picture** is drawn on a canvas (`screens/wallet/parts/invoice-image`) — the browser shapes the
  Persian text — and shown as a PNG to hold and save: Telegram's own download takes an https address only.

## The preview

The panels' «پیش‌نمایش» opens the real app in a frame that answers as Telegram does
([Internals: panels](Internals-Panels.md#website-and-panel-settings)): no launch data, and in the address's fragment
`tgWebAppPreview`, the token of a read-only session for one of the shop's customers
([the server's side](Internals-Website-API.md#mini-app-settings)). The app tells it from Telegram's own launch by
`previewToken()` (`telegram/webapp`): the token telegram-web-app.js read off the fragment
(`Telegram.WebView.initParams`) while there is no launch data, and only a bearer token's characters — else `null`;
`inPreview()` is whether there is one. A preview counts as inside Telegram (`telegram()` gives the object,
`inTelegram()` is true), since its frame plays Telegram's part.

- **Signing in**: `lib/session` takes the preview's token as the session — `GET /me` with it, never kept in the
  device's storage, never traded for another. Its `401` is the preview's end: the app says so («پیش‌نمایش تمام شده
  است؛ …»), and only the panel opens another.
- **Nothing by itself**: the server refuses a preview every write (a `403`, «این پیش‌نمایش است؛ …»), and the app asks
  none by itself — no notice or ticket marked read, no service read again from its panel on opening, no gateway asked
  what came of a payment, and no recent sign-in asked (`needsRecentSignIn()` is false): the customer's account stays
  as they left it.
- **The mark**: `ui/preview-mark` draws a small «پیش‌نمایش» pill just under Telegram's header and controls, in the end
  corner; it takes no tap.

## Tests

The mini app's tests run in the panels' Vitest suite, beside what they test, against the same fake server, every
request held to the API description ([Testing](Testing.md#the-panels)). The suite's page is the owner's panel, so
**every mini app test file starts with**
`vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))`: the app's address in its tests — store key
`0123456789abcdef01234567`, its requests under `/api/store/v1/0123456789abcdef01234567`. Then `test/app.tsx`:

- `screen(element, { at, route, shop, account })` — a screen as the signed-in customer sees it: the providers, a router
  at `at` under the pattern `route`, a bearer token on every request, and `GET /` and `GET /me` already answered
  (`null` leaves either to the test);
- `shop()`, `account()` — those answers: a shop with every program on, no colour or logo of its own; customer 7
  (Telegram 42), signed in by the mini app a moment ago;
- `telegram(overrides)` — a fake Telegram on the window (a client of Bot API 8.0, opened by customer 42), with
  `mainButton.press()`, `backButton.press()` and haptics spies.

`npx vitest run src/apps/app` runs them alone (paths from `resources/panel`, Vitest's root).

## Where to start

- A new screen: a file under `screens/<area>/` whose default export is the screen, its lazy route in `APP_ROUTES`, built
  from `ui/`, with its test beside it.
- A new Store API call: its operation in `resources/api/openapi.yaml`, `pnpm api:types`, then `store.get<T>()` or a
  typed write; a write that may ask a recent sign-in goes through `withRecentSignIn()`.
