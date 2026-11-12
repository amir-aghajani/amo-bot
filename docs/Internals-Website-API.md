[فارسی](fa/Internals-Website-API.md)

# Internals: website API

The Store API that a shop's own website and its Telegram mini app talk to, the customers' accounts on them, the shop's
admins working from the website, and the panels' controllers — `app/Modules/{Store,Accounts,Admin}`. This page is how
they fit together and why; what a website's developer sees is in [Store API](Store-API.md) and its pages, what an owner
sees in [Website](Website.md). The mini app's own code is [Internals: mini app](Internals-Mini-App.md).

## The modules

| Module | What it owns | Open first |
| --- | --- | --- |
| Store | the Store API's two doors and CORS, the website and mini app rows and their settings, everything a signed-in customer does through them, the admins' door | `Http\StoreMiddleware`, `Contracts\Door`, `Http\StaffMiddleware`, `Services\Websites`, `Services\MiniApps`, `Services\CustomerCheckout` |
| Accounts | customers' sign-in on a website or the mini app: sessions, one-use challenges, OpenID providers, email and password, a code by SMS, two-factor, the mini app's launch data, ways in, merges | `Services\ProviderSignIn`, `Services\EmailSignIn`, `Services\PhoneSignIn`, `Services\MiniAppSignIn`, `Services\CustomerSessions`, `Services\AuthChallenges` |
| Admin | the panels' API controllers and the panel-only services (dashboard, queues, customer page, client errors, system status) | `Api\*Controller`, `Services\DashboardStats` |

**Store leans on Accounts, never the other way.** What signing in needs of a website is Accounts' own interface,
`Contracts\SignInSite`, which Store's `Contracts\Door` extends and both `Store\Models\Website` and `Store\Models\MiniApp`
implement (the mini app's ways in all off: no Client ID, no email sign-up, no captcha, no origin); no Accounts file
names a Store class.
`tests/Unit/Core/LayersTest` holds that, and the module map as a whole.

**Controllers are thin**: load the row, call one service, present the answer (`Core\Http\ApiController`: `load()`,
`routeId()`, `pageRequest()`, `uploadedFile()`, `switch()`, `bytes()`). The panels' sign-ins are no exception: the
form, the throttle and the password check are `Auth\Services\OwnerAuth`'s and `AgentAuth`'s. Refusals are exceptions the
error handler answers in the one error shape, so an action catches nothing it does not word itself. Business rules live
in the service the bot uses too — a controller never has a rule of its own.

## The request path

Besides `/health`, `/api/app` and the installer's `/api/install`, routes/api.php mounts two families of routes:

```
/api/admin, /api/agent ── Session · CSRF header · PanelAuthMiddleware (owner or agent)
    $operations       the shop's daily work (lists, payments, orders, tickets, …)
    $configuration    the shop's settings (methods, bot settings, texts, the website, …)
/api/store/v1/{store} ── StoreMiddleware (the key opens a website or a mini app; the request runs in its shop) · JsonBodies
    public            GET /, the catalogue, the sales, the tutorial, the reviews
    website only      WebsiteOnlyMiddleware → the website's ways in (/auth/*, /auth/phone and /auth/phone/verify among
                      them), its captcha
    mini app only     MiniAppOnlyMiddleware → POST /auth/mini-app, GET /app/logo
    customer          CustomerAuthMiddleware (bearer token) — and RecentSignInMiddleware on what changes the ways in
                      or gives a service or money away
    website only      WebsiteOnlyMiddleware · CustomerAuthMiddleware → /me/telegram/authorize, /me/reauthenticate/phone,
                      /me/identities/{telegram,google,phone}, /me/identities/phone/verify
    mini app only     MiniAppOnlyMiddleware · CustomerAuthMiddleware → PUT and DELETE /me/biometric
    /admin            WebsiteOnlyMiddleware · CustomerAuthMiddleware · StaffMiddleware → $operations again, never $configuration
```

- **The doors.** `StoreMiddleware` reads the 24-hex store key, finds the open website (`Websites::open()`) or else the
  open mini app (`MiniApps::open()`) across shops — switched on, its bot running —, runs the request in that shop
  (`CurrentBot::run`) and puts the door on the request: `StoreMiddleware::door()` reads it as a `Contracts\Door` (its
  `storeKey()`, `showsReviews()` and the sign-in site's answers), `website()` and `miniApp()` as the one door a route
  group allows. Any other key is the same 404, whatever the path under it. What only one door has is a route group whose
  outermost guard is `WebsiteOnlyMiddleware` or `MiniAppOnlyMiddleware`: through the other door it answers the router's
  own 404 before a token is read, so the other door shows nothing of it. Only the Store group's own middleware runs
  before that guard — `JsonBodiesMiddleware` among them, so a POST, PUT or PATCH whose body is not JSON gets its 415
  first. The description marks those operations `x-door` ([API contract](API-Contract.md#what-the-description-holds)),
  and `ApiDescriptionTest` and `MiniAppDoorTest` hold the routes and the marks together. A mini app switched off still
  opens for a panel's preview of it: a key no open door has is tried as a switched-off app (`MiniApps::previewable()`),
  and a request carrying the token of a live preview session of that shop finds it as if it were on — every request the
  preview makes, `GET /` among them —, so the owner tries the app before customers see it; one a preview may not ask
  (`CustomerAuthMiddleware::previewMayAsk()`) is the 403 `PREVIEW` there, whatever the route, and anyone else still gets
  `CLOSED`.
- **JSON only.** `JsonBodiesMiddleware` answers 415 to a body that is not JSON, except multipart on the few routes marked
  `UPLOAD` (a receipt, a ticket's picture). A form or text body is a CORS "simple request" any site can make a browser
  send without a preflight; refusing it means a sign-in posted from another site always needs the preflight.
- **CORS** is `Store\Http\WebsiteCorsPolicy` under Core's `CorsMiddleware`: only a Store API path, only an origin the
  open website lists (`allowsOrigin()`); the mini app, on the shop's own address, allows none — not even Telegram's
  web client, which frames the page, not the API; the panels' API never answers an origin.
- **Optional sign-in.** `OptionalCustomerMiddleware` (the catalogue's prices, a review) lets a request without a token
  through as a guest, but sends one with a token through the customer's door whole — a bad token is a 401, never
  quietly a guest.

## Customers' sign-in

A website customer is the same `users` row the bot serves; a customer may have a Telegram account, an email (with a
password), a Google account, or any mix (`Enums\WayIn`). The ways in:

- **Telegram and Google** share `ProviderSignIn`: `nonced()` checks an id_token handed to the page against a nonce the
  shop issued (the token verified before the nonce is spent, so a provider outage leaves the nonce for a retry);
  `prove()` runs any proof under the address's sign-in throttle and turns a failed proof into a 422 on its field for a
  signed-in customer (a 401 would tell the site their session ended); `open()` refuses a banned customer and opens the
  session. `Oidc\IdTokenVerifier` checks signature, issuer, audience and times; `Oidc\Jwks` keeps each provider's keys
  in a file and refreshes for an unknown `kid` at most once a minute. A website with neither Telegram's nor Google's
  sign-in on is handed no nonce (`POST /auth/nonce`: a 422, `SignInRefusedException::idTokenOff()`).
- **Telegram's redirect flow** is code + PKCE with the verifier kept by the browser: the shop stores only the challenge
  under a one-time `state`. A code and state carried to another browser come without the verifier and open nothing. A
  state belongs to whoever asked: a sign-in's is spent only by a sign-in, a signed-in customer's only by that session.
- **Google's email** is trusted only when Google is that address's authority now (`email_verified`, and @gmail.com or a
  Workspace `hd`), otherwise an address someone else owns today could take over an account.
- **Email and password** (`EmailSignIn`) never tell whether an address has an account: sign-up and reset answer the
  same and send a different email; a wrong address is checked against a dummy hash of the same cost as a real one.
- **A code by SMS** (`PhoneSignIn`, while `SignInSite::allowsPhoneSignIn()`: the site's switch, `Core\Sms\Sms`
  available, and the main shop's site or `Sms::forAgents()` — `Store\Services\Websites` tells each website it loads
  whether SMS goes out for it, `Website::smsOpen()`) texts a six-digit code (`AuthChallenges::issueCode()`, a keyed
  hash, five minutes, five tries) to an Iranian mobile in E.164 (`Support\Mobile`) whether or not it has an account. The
  code typed back signs in the customer whose `users.phone` it is — of two, the one with Telegram, then the one seen
  last (`holderOf()`), the others offered as a merge beside the token (`mergeOffer()`, `SignedIn::$merge`; the number
  proved for all of them, never merged unasked) — or registers a newcomer with the number (`Customers::byPhone()`),
  `SignInMethod::Phone`. A code by SMS is weaker than a provider (a SIM swapped, a number recycled): an account with
  two-factor sign-in answers the second step's challenge (`TwoFactor::signIn()` then opens `PhoneAndCode`),
  `SignInMethod::strong()` leaves `Phone` out, and the number proves no such account again. Every SMS is counted
  against its budgets before it goes and given back when the provider refused (`SignInThrottle::texting()`; the new
  code replaces the last only once the provider took it, `AuthChallenges::issueCode()`'s `$deliver`), and a six-digit
  code against the number's wrong codes (`textedCode()`) only while a code waits (`AuthChallenges::liveCode()`) — a
  blind guess locks nobody out, and wrong codes never stop a code going. A mobile number is a `WayIn` too: added by a
  code (a merge offered when another account holds it, refused past a second step), removed while another way stays —
  the number counting as one only where a sign-in by SMS opens that very account —, and proven again by a code bound
  to the session that asked (`sendReauthCode()`).
- **Two-factor** (`TwoFactor`) is the password sign-in's second step, and a sign-in by SMS's (TOTP, ten recovery codes kept as keyed hashes). A
  password reset leaves it on and keeps the new password in the second step's challenge, because the email alone must
  not get past it.
- **The mini app's launch data** (`MiniAppSignIn`, `POST /auth/mini-app`): `Support\LaunchData::read()` checks
  `Telegram.WebApp.initData` as Telegram's documentation says — every field but `hash`, sorted, as `name=value` lines,
  under HMAC-SHA256 keyed with the HMAC-SHA256 of the bot's token and `WebAppData`; a field twice, a missing account, or
  an `auth_date` older than allowed or over 60 seconds ahead of the shop's clock is refused — and answers a
  `DTO\MiniAppLaunch`: the Telegram id, its profile, when Telegram made it. A sign-in takes data up to a day old
  (`MAX_AGE_SECONDS`), registers a newcomer exactly as the bot does (`Customers::byTelegram()`, with the invite code),
  and opens the session through `ProviderSignIn::open()` with `SignInMethod::MiniApp` (strong) and `authenticated_at` the
  launch's moment, not now: an old launch signs in, but proves nothing a recent sign-in guards. Every proof runs under
  the address's website throttle, a failure counted; a refusal is a 401 at sign-in and a 422 on `init_data` for a
  signed-in customer (`launch()`'s `$signedIn`).

**Sessions** (`CustomerSessions`): a bearer token per device, only its sha256 kept, per shop, idle after 30 days, ended
after 180. `authenticated_at` is when a way in was last proven: changes to the ways in, the password, two-factor
sign-in or a merge, and a service handed to another customer, need it within 15 minutes (`RecentSignInMiddleware`, a
403 with RFC 9470's challenge), and `POST /me/reauthenticate` proves one again (`Reauthentication`). So a leaked token
alone takes no account over and gives no service away. `GET /me` says where the session stands (`session`: its method,
whether it keeps a fingerprint secret, `recent_until` from `CustomerSessions::provenUntil()`).

A session is the shop's, not its door's: a token either door signed in opens the other's customer routes too. The mini
app proves a way in again two ways of its own:

- **Fresh launch data** (`mini_app`): `MiniAppSignIn::launch()` with `RECENT_SECONDS` as the age limit; the session is
  recent from the launch's `auth_date` (`CustomerSessions::reauthenticated()`'s `$provenAt`), never less recent than it
  was, and upgraded to `mini_app` when it was a password alone.
- **The fingerprint secret** (`biometric`): `PUT /me/biometric` (itself behind `RecentSignInMiddleware`) makes 32 random
  bytes for Telegram's `BiometricManager` to keep behind the device's fingerprint or face, and keeps only their sha256 on
  the session (`customer_sessions.biometric_hash`, `CustomerSessions::setBiometric()`); `holdsBiometric()` compares in
  constant time. Proven, the session is recent from now and keeps its method — it proves the session's holder, not a
  way in; `DELETE /me/biometric` forgets it. A failure counts as a failed sign-in.

**Challenges** (`AuthChallenges`) are every short-lived secret: a random secret whose sha256 is kept with an encrypted
payload, spent once by one conditional DELETE. Emailed codes are six digits, so they are kept as an HMAC under a key
derived from APP_KEY, and each challenge counts its tries on its row. What anyone may ask for without an account
(nonces, redirect states) is capped per shop (`LIVE_MAX`) so strangers cannot fill the table.

**Budgets.** All of the website's sign-in failures count in one throttle way (`SignInThrottle::WEBSITE`, loose per
address because mobile carriers put many customers behind one address, tight per account). Before anything is tried,
what a sign-in costs the shop (a nonce, a state, an email, a captcha check) is held per network (`issuing()`). Emails
have their own budgets per address, network, customer, shop and installation, counted before sending and given back
when none went. Wrong emailed codes and wrong second-step codes each have a per-account budget counted before judging,
so codes sent at once cannot slip past it. The website's captcha (`Accounts\Services\Captcha`) guards sign-up, password
sign-in and reset.

## Ways in and merging accounts

`Identities` adds a way in only after proving it the way it signs in, then: already this account's (nothing to do), the
account has another of the kind (refused), nobody's (filled by one conditional UPDATE while the slot is empty — a race
lost reads the account again and decides afresh), or another account's — then `MergeOffers` describes that account and
hands out a one-use ticket. An account reached by its email address alone is offered no merge while it has two-factor
sign-in on: a reset needs only the address, so a merge must not open what a reset cannot. Removing a way in never
removes the last one, again by a conditional UPDATE.

`AccountMerger` keeps the older account and moves everything the other owned by `REFERENCES` — every column that points
at `users`. `tests/Unit/Accounts/AccountMergerReferencesTest` reads database/schema.php and fails when a new such
column is missing there, so a merge never leaves rows behind. Both rows are locked in id order and the rules judged
again under the locks. The survivor keeps its referrer, else takes the other's — never one that would make a loop of
referrals ([Internals: commerce](Internals-Commerce.md#referrals)). A merge never brings a «همکار» group, which sets
agent prices, into a shop admin's or an agent's account (`AccountMerger::leaveAgentGroups()`): while one side is an
admin or an agent and the other neither, the other side's «همکار» memberships go before the groups are joined — the
plain groups join as ever — so an admin's second account put in one and then merged is no way around the website's
rule (below). After the commit, each service whose client on its panel names its owner otherwise than the survivor now
is relabelled (`ProvisioningService::relabel()`: its Telegram id and comment), a panel that fails it logged
([a change of owner](Internals-Services.md#service-transfers)). A Telegram account linked or taken off relabels every
service of the account the same way.

## The shop on the website

Everything a customer does on the site — or in the mini app — goes through the services the bot uses; the Store
services only add the API's shape and limits.

- **The storefront** (`Storefront::present()`, `GET /`) takes the door: `sign_in.mini_app` and `app` (its look, its
  logo's address versioned by the row's `updated_at`, the shop's zone) through the mini app, `null` through a website;
  `programs`, the shop's programs' switches as the bot reads them; `phone_required`, the bot's phone gate. The tutorial
  (`StoreController::tutorial()`) is the bot's own text (`BotText::Tutorial`).
- **Catalogue** (`StoreCatalog`): exactly the bot's «خرید اشتراک» (`PlanCategoryService::catalogue()`), priced for the
  reader by `Pricing`, in a few queries however many plans.
- **Checkout** (`CustomerCheckout`) is the bot's checkout (`Payments\Services\Checkout`) under the bot's rules. Every
  ordering request carries an `Idempotency-Key`; a repeat is answered by that key's order as it stands, before any rule
  is asked again — the rules may say otherwise a moment later, and a retry must never order or charge twice. A
  purchase, a built service, a renewal or an extra may name the amount the site showed (`expected_amount`): `asShown()`,
  just before `Checkout::pay()`, holds it to what the request would be charged — the key's unpaid order's own amount,
  else the quote — and refuses another with a 409 naming the price now (`CheckoutRefusedException::repriced()`),
  nothing ordered, as the bot pays only the checkout on screen; a top-up takes none. The prices the website lists are
  those same quotes — a plan's `offer_price`, an extra's `offer_price` and each extra option's
  (`SubscriptionPresenter::extras()`, by `CustomerCheckout::extras()` for the customer), a renewal's `price` — so a
  price shown is the price charged. A purchase or a renewal that would end the service past the last day the shop keeps
  is a 422 (`TermTooLongException`). While the
  bot's master switch is off the website takes no orders (`takingOrders()`), nor while an agent's selling is suspended
  (`ordering()`: both a 503 — a renewal's preview and an extra's quote, which order nothing, say the suspension as a
  422 on `status`). A renewal names what it is with as the
  bot's buttons do — a plan picked (`plan_id`) or a package built (`gb`, `days`: `renewsWith()`) — and goes through
  `CustomerRenewal::preview()`; `refused()` turns its `refusal()` into the field and words the site shows.
- **Services** (`CustomerSubscriptions`): what each may do is judged for a whole page at once; reading a panel and new
  links are rate-limited per customer, and a panel failure is told as its summary, never the owner's diagnosis.
  `update()` takes `auto_renew` and `label` (the customer's name for it, `subscriptions.label`) judged together, the
  label written only while the service is still theirs — `ProvisioningService::newOwner()` clears it when the service
  changes hands —; `qrCard()` draws the bot's QR card (`Telegram\Qr\QrCard`), 30 a minute, a 503
  (`QrCardUnavailableException`) when it cannot.
- **Sales** (`StoreSales`): the timed sales running (`Sale::running()`), for a banner; prices already carry them.
- **Programs**: `CustomerTestAccounts` gives a free test through `Subscriptions\Services\TestAccounts` under the bot's
  quota; `CustomerWheel` spins `Rewards\Services\LuckyWheel` with `tell: false` and answers where it landed for the
  page's animation (the server draws; the page only shows); `Rewards\Services\LotteryBoard` reads the nightly draw for one
  customer, counting their entries as `Lottery::entries()` will. A free test and a spin first ask what the bot's gates
  ask before anything free (`CustomerGates`: a Telegram account, the customer's own number while the shop asks it, the
  required channels joined — `ChannelMembership::missingFor()` —; the shop's admins pass). Through the mini app, the
  bot's own door, an order, a gift code and a service claimed by its link ask the number and the channels too
  (`CustomerGates::holdBack()`, from their controllers: the door is the request's). A program switched off is a 404
  (`ProgramOffException`) — the wheel's refusals, the bot's words, a 422 on `status`. Nothing of these is sent in
  Telegram: the page shows it.
- **Receipts** (`CustomerReceipts`) are taken as the bot takes them, but an uploaded receipt never auto-approves: the
  account behind it may be an email made a minute ago.
- **Online payments** (`CustomerOnlinePayments`): a checkout by an online gateway answers its page (`link`, read off
  the payment by the gateway's `initiate()`, which calls nothing — so does an order's pending payment while its page
  takes money, `OrderPresenter::link()`); `POST /payments/{id}/check` asks the gateway through
  `Payments\Services\OnlinePayments::check()`, the one way any door asks, rate-limited per customer, and a gateway out
  of reach is a 503 (`CheckoutRefusedException::unreachable()`) with its detail logged; a payment held for support and
  still waiting — before, or by that very ask — is a 422 (`PaymentHeldException`), as a checkout of its order is, and
  its order shows no `link` for it (`OrderPresenter::link()`); whatever its status, its `note` is the customer's words
  for it, never the shop's words to support.
- **Wallet transfers** («انتقال موجودی», `WalletController`, `CustomerWalletTransfers`) are the bot's
  `Users\Services\WalletTransfers`; the `POST` asks a recent sign-in (as a service handed away does) and an
  `Idempotency-Key`, answered by the transfer it made (`transferById()`), never a second
  ([Internals: commerce](Internals-Commerce.md#wallet-transfers)). The recipient of money or of a service is shown by
  `Presenters\RecipientPresenter` alone: their Telegram name and handle, nothing else of their account.
- **Notices, tickets, reviews, gift codes, referrals**: thin layers over Notifications, Support, Reviews, Rewards and
  Referrals (see [Internals: services](Internals-Services.md) and [Internals: commerce](Internals-Commerce.md)). A
  ticket's messages say `seen` from `tickets.support_read_at`, which support's reading writes
  (`Tickets::readBySupport()`, quietly, when the panels or the website's admin API open it) and an answer writes too;
  reviews follow the door's own switch (`Door::showsReviews()`, `shop.programs.reviews`). Through the mini app a
  customer does not take their Telegram account off (`IdentitiesController::TELEGRAM_STAYS`, a 422 on `kind`): the app and
  the bot know them by it.

A customer's rows are always read through their own query (`where('user_id', …)`), so another customer's id is a 404
like a row that never existed.

## The shop's admins on the website

Customers with the bot's admin role can work the shop from its website: `/admin/*` mounts the panels' `$operations`
again behind `StaffMiddleware`, whose checks run in a fixed order (an admin; the website lets admins in; a session that
is not the mini app's — `CustomerSessions::isMiniApp()`, its launch data's for its life: the mini app is the customer
side alone; a strong sign-in while asked; a sign-in within a working day; the operation's grant; a recent sign-in for a
granted operation or a payment approval). A leaked admin token therefore works for hours, not months. Each operation that needs a grant names
it on its route (`Auth\StaffGrant::ARGUMENT`) and in the API description (`x-staff`); `ApiDescriptionTest` holds the
two together. The request then carries the staff `Principal` — with the website's grants only while the sign-in is
recent — and every change it asks is logged with them on the line, a refused one too, a 404 included. Decisions carry
an `Auth\Actor`, and the services refuse an admin deciding about themselves, another admin's or an agent's account
(`UserActions::mayTouch()`). A grant that only some requests of an operation need is asked in the service by
`Actor::holds()` (always true for anyone but a website's admin); refused for a grant the website does give, the
answer is the recent-sign-in 403, since only the sign-in's age stood in the way.

That covers the customer groups, since a «همکار» group sets agent prices. Creating, changing, deleting and reordering
groups need the `catalog` grant; putting a customer into a «همکار» group they are not in needs it too
(`CustomerGroups::assign()`), while plain groups need none. From the website, `UserActions::assignGroups()` puts no
admin's or agent's account in any group, and `CustomerGroups::update()` refuses switching «همکار» on for a group an
admin is in; a merge does not carry one into such an account either ([above](#ways-in-and-merging-accounts)). The
admins' live view is their own shop's (`GET …/admin/changes`: [the change feed](Architecture.md#the-change-feed)). See
[Store API: admins](Store-API-Admins.md).

Support's changes by hand through this door and the panels — a wallet's credit or debit, an agent's traffic
adjustment, a ticket's answer — may carry an `Idempotency-Key` (`Store\Http\IdempotencyKey::optional()`; the panels
always send one): the same request again is answered by the line or the message the key wrote, never made twice
([Internals: commerce](Internals-Commerce.md#ledgers), [Internals: services](Internals-Services.md#support-tickets)).
An order support makes by hand needs one (`IdempotencyKey::of()`).

## Website settings

`Websites` makes a shop's row (switched off, with a random key) the first time a panel asks. Each settings card sends
only its fields: `update()` is a partial save on the field engine (`Form::checkSent()`), with the rules between fields
judged on the row as the save would leave it (`Core\Forms\Fields\Ruled`) and the captcha checked by its driver's form
(see [Drivers](Drivers.md)). Secrets are never sent back, only whether one is kept.

## Mini app settings

`Store\Services\MiniApps` keeps a shop's mini app (`mini_apps`, one row per bot), made switched off with a random key
the first time a panel asks (`current()`). Both panels' «مینی‌اپ تلگرام» (`Admin\Api\MiniAppController`,
`GET|PATCH /api/{panel}/mini-app`, part of `$configuration`: the website's admins never have it) save through
`update()`, a partial save on the field engine; switching it on needs `APP_URL` on https (`secure()`), since Telegram
opens a mini app from no other address. `address()` is `{APP_URL}/app/{key}/`; `url()` is that address while the app is
on and secure — `Telegram\Contracts\MiniAppAddress`, bound to `MiniApps` in `bootstrap/container.php`, which is how the
bot's buttons find it ([Internals: Telegram bot](Internals-Telegram-Bot.md#the-mini-app)).

Every save sets the bot's menu button as the app now stands (`applyMenuButton()`, `BotApi::setChatMenuButton`): the
app's, with its words, while it is on, secure and keeps its button. Otherwise it asks Telegram what the button is now
(`BotApi::getChatMenuButton()`) and gives Telegram's own back only when that button opens this app — under any `APP_URL`
the shop had, by its key —, so a button the shop set itself at @BotFather is left alone. Telegram refusing or out of
reach never undoes the save: the answer's `menu_button_error` says why the button is not set.

The logo (`GET|POST|DELETE /api/{panel}/mini-app/logo`) is a picture judged by its bytes (PNG, JPEG or WebP, 2 MB at
most), kept no larger than 512 pixels a side and written again without its metadata where the host has GD, in the
shop's own file under `storage/uploads/logos` (`miniapp.logos`); the Store API answers it at `GET /app/logo`, through
the mini app's key alone (`MiniAppLogoController`, any cache five minutes).

The panels' «پیش‌نمایش» (`POST /api/{panel}/mini-app/preview`, `MiniAppController::preview()`) opens the app as one of
the shop's customers sees it: `Store\Services\MiniAppPreviews::open()` refuses anyone who is no customer of the shop, or
is banned (a 422 on `user_id`), opens a preview session for them (`CustomerSessions::openPreview()`, `method`
`preview`, `PREVIEW_MINUTES` — 30 — and never longer), logs who looked and as whom, and answers its token once with the
app's address on this host (`MiniApps::path()`, under the folder the panel's request came in by); the panel hands the
token to the app in its address's fragment ([Internals: mini app](Internals-Mini-App.md#the-preview)). The customer is
told nothing. `CustomerAuthMiddleware` admits such a session through the mini app's key alone — under a website's it
opens nothing — and to read only: any request but GET, HEAD or OPTIONS is a 403 with `PREVIEW` («این پیش‌نمایش است؛ در
آن چیزی ذخیره یا فرستاده نمی‌شود.») before anything runs, but for a route marked `PREVIEW_READS`, which only works
something out — `POST /custom-services/quote`, its value naming the field (`discount_code`) that, sent, makes it a
write again. Nothing of a preview is recorded: neither the session's use nor the customer's visit; it is none of their
devices (`CustomerSessions::isPreview()`), and never a recent sign-in.

## The panels' controllers

`app/Modules/Admin/Api` holds both panels' controllers; the owner and an agent share most of them because their routes
are the same `$operations` and `$configuration`. The request's `Principal` (`Principal::of($request)->actor()`) is who
decides. The panel-only services:

- `DashboardStats` — figures against the period before (one query each), days in the shop's zone with UTC queries,
  revenue as money that came in, never wallet spending; and the attention counts (below).
- `Queues` — what waits on a human: receipts to review, paid orders not delivered, tickets waiting on an answer,
  reviews waiting (`GET /queues`, the sidebar's badges). The dashboard's attention card reads them from
  `GET /dashboard`'s `attention`, with online payments held for support (`Payment::whereHeld()`), failing servers (the
  main shop's) and what waits on customers — unpaid orders and services ending soon —, which the card lists apart and
  does not count.
- `CustomerProfile` — a customer's page, including their website account and merges.
- `ClientErrors` — a panel's own crash, logged as one redacted line with every stack line indented (no forged log
  lines) and rate-limited per principal and address.
- `SystemStatus` — the owner's system card.

## Testing

Feature tests live in `tests/Feature/Store` (the Store API) and `tests/Feature/Admin*`; every HTTP test is checked
against resources/api/openapi.yaml both ways (see [API contract](API-Contract.md)). Useful helpers: `website()`,
`webCustomer()`, `customerSession()` and `bearer()` for a signed-in customer, `loginAsStaff()` for the admin API,
`telegramLogin()`, `googleLogin()`, `turnstile()` and `mail()` for the outside services, and the `WebsiteSignIns`
trait for nonces and redirect states. The mini app's: `miniApp()` (the shop's, switched on, with a key of its own),
`launchData()` (signed launch data, `Tests\Support\TelegramLaunch`) and `storeApi($door, $path)` for either door.
`RouteGuardsTest` walks every route with another shop's rows and every admin-API route through each step of the door;
`MiniAppDoorTest` asks every Store API route under each door's key. See [Testing](Testing.md).

## Where to start

- A new customer route: its controller in `Store\Api`, its service in `Store\Services`, the route in routes/api.php
  (inside the customer group, and the recent-sign-in group if it changes how the account is signed in to), and its
  operation in openapi.yaml. A route only one door has goes in that door's group, its operation marked `x-door`.
- A new way in: a proof method returning an account DTO through `ProviderSignIn::prove()`, a `WayIn` case (its column on
  `users`), and how a merge carries it (`AccountMerger::takeOn()`).
- A new admin-API operation: add it to `$operations` and mark it `x-staff` (with its grant when it needs one).
