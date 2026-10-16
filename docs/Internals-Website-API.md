# Internals: website API

The Store API that a shop's own website talks to, the customers' accounts on it, the shop's admins working from it, and
the panels' controllers — `app/Modules/{Store,Accounts,Admin}`. This page is how they fit together and why; what a
website's developer sees is in [Store API](Store-API.md) and its pages, what an owner sees in [Website](Website.md).

## The modules

| Module | What it owns | Open first |
| --- | --- | --- |
| Store | the Store API's door and CORS, the website row and its settings, everything a signed-in customer does on the site, the admins' door | `Http\StoreMiddleware`, `Http\StaffMiddleware`, `Services\Websites`, `Services\CustomerCheckout` |
| Accounts | customers' sign-in on a website: sessions, one-use challenges, OpenID providers, email and password, two-factor, ways in, merges | `Services\ProviderSignIn`, `Services\EmailSignIn`, `Services\CustomerSessions`, `Services\AuthChallenges` |
| Admin | the panels' API controllers and the panel-only services (dashboard, queues, customer page, client errors, system status) | `Api\*Controller`, `Services\DashboardStats` |

**Store leans on Accounts, never the other way.** What signing in needs of a website is Accounts' own interface,
`Contracts\SignInSite`, which `Store\Models\Website` implements; no Accounts file names a Store class.
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
/api/store/v1/{store} ── StoreMiddleware (the key opens a website; the request runs in its shop) · JsonBodies
    public            GET /, the catalogue, the reviews, /auth/*
    customer          CustomerAuthMiddleware (bearer token) — and RecentSignInMiddleware on what changes the ways in
                      or gives a service away
    /admin            CustomerAuthMiddleware · StaffMiddleware → $operations again, never $configuration
```

- **The door.** `StoreMiddleware` reads the 24-hex store key, finds the open website across shops (`Websites::open()`:
  switched on, its bot running) and runs the request in that shop (`CurrentBot::run`). Any other key is the same 404,
  whatever the path under it.
- **JSON only.** `JsonBodiesMiddleware` answers 415 to a body that is not JSON, except multipart on the few routes marked
  `UPLOAD` (a receipt, a ticket's picture). A form or text body is a CORS "simple request" any site can make a browser
  send without a preflight; refusing it means a sign-in posted from another site always needs the preflight.
- **CORS** is `Store\Http\WebsiteCorsPolicy` under Core's `CorsMiddleware`: only a Store API path, only an origin the
  open website lists; the panels' API never answers an origin.
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
  in a file and refreshes for an unknown `kid` at most once a minute.
- **Telegram's redirect flow** is code + PKCE with the verifier kept by the browser: the shop stores only the challenge
  under a one-time `state`. A code and state carried to another browser come without the verifier and open nothing. A
  state belongs to whoever asked: a sign-in's is spent only by a sign-in, a signed-in customer's only by that session.
- **Google's email** is trusted only when Google is that address's authority now (`email_verified`, and @gmail.com or a
  Workspace `hd`), otherwise an address someone else owns today could take over an account.
- **Email and password** (`EmailSignIn`) never tell whether an address has an account: sign-up and reset answer the
  same and send a different email; a wrong address is checked against a dummy hash of the same cost as a real one.
- **Two-factor** (`TwoFactor`) is the password sign-in's second step (TOTP, ten recovery codes kept as keyed hashes). A
  password reset leaves it on and keeps the new password in the second step's challenge, because the email alone must
  not get past it.

**Sessions** (`CustomerSessions`): a bearer token per device, only its sha256 kept, per shop, idle after 30 days, ended
after 180. `authenticated_at` is when a way in was last proven: changes to the ways in, the password, two-factor
sign-in or a merge, and a service handed to another customer, need it within 15 minutes (`RecentSignInMiddleware`, a
403 with RFC 9470's challenge), and `POST /me/reauthenticate` proves one again. So a leaked token alone takes no
account over and gives no service away.

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
again under the locks. After the commit, each service whose client on its panel names its owner otherwise than the
survivor now is relabelled (`ProvisioningService::relabel()`: its Telegram id and comment), a panel that fails it
logged ([Services](Internals-Services.md#service-transfers)). A Telegram account linked or taken off relabels every
service of the account the same way.

## The shop on the website

Everything a customer does on the site goes through the services the bot uses; the Store services only add the
website's shape and limits.

- **Catalogue** (`StoreCatalog`): exactly the bot's «خرید اشتراک» (`PlanCategoryService::catalogue()`), priced for the
  reader by `Pricing`, in a few queries however many plans.
- **Checkout** (`CustomerCheckout`) is the bot's checkout (`Payments\Services\Checkout`) under the bot's rules. Every
  ordering request carries an `Idempotency-Key`; a repeat is answered by that key's order as it stands, before any rule
  is asked again — the rules may say otherwise a moment later, and a retry must never order or charge twice. While the
  bot's master switch is off the website takes no orders (`takingOrders()`), nor while an agent's selling is suspended
  (`ordering()`: both a 503 — a renewal's preview and an extra's quote, which order nothing, say the suspension as a
  422 on `status`). A renewal names what it is with as the
  bot's buttons do — a plan picked (`plan_id`) or a package built (`gb`, `days`: `renewsWith()`) — and goes through
  `CustomerRenewal::preview()`; `refused()` turns its `refusal()` into the field and words the site shows.
- **Services** (`CustomerSubscriptions`): what each may do is judged for a whole page at once; reading a panel and new
  links are rate-limited per customer, and a panel failure is told as its summary, never the owner's diagnosis.
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
  Referrals (see [Internals: services](Internals-Services.md) and [Internals: commerce](Internals-Commerce.md)).

A customer's rows are always read through their own query (`where('user_id', …)`), so another customer's id is a 404
like a row that never existed.

## The shop's admins on the website

Customers with the bot's admin role can work the shop from its website: `/admin/*` mounts the panels' `$operations`
again behind `StaffMiddleware`, whose checks run in a fixed order (an admin; the website lets admins in; a strong
sign-in while asked; a sign-in within a working day; the operation's grant; a recent sign-in for a granted operation or
a payment approval). A leaked admin token therefore works for hours, not months. Each operation that needs a grant names
it on its route (`StaffGrant::ARGUMENT`) and in the API description (`x-staff`); `ApiDescriptionTest` holds the two
together. Decisions carry an `Auth\Actor`, and the services refuse an admin deciding about themselves, another admin's
or an agent's account. See [Store API: admins](Store-API-Admins.md).

## Website settings

`Websites` makes a shop's row (switched off, with a random key) the first time a panel asks. Each settings card sends
only its fields: `update()` is a partial save on the field engine (`Form::checkSent()`), with the rules between fields
judged on the row as the save would leave it (`Core\Forms\Fields\Ruled`) and the captcha checked by its driver's form
(see [Drivers](Drivers.md)). Secrets are never sent back, only whether one is kept.

## The panels' controllers

`app/Modules/Admin/Api` holds both panels' controllers; the owner and an agent share most of them because their routes
are the same `$operations` and `$configuration`. The request's `Principal` (`Principal::of($request)->actor()`) is who
decides. The panel-only services:

- `DashboardStats` — figures against the period before (one query each), days in the shop's zone with UTC queries,
  revenue as money that came in, never wallet spending.
- `Queues` — what waits on a human; the sidebar's badges and the dashboard's attention card read the same counts.
- `CustomerProfile` — a customer's page, including their website account and merges.
- `ClientErrors` — a panel's own crash, logged as one redacted line with every stack line indented (no forged log
  lines) and rate-limited per principal and address.
- `SystemStatus` — the owner's system card.

## Testing

Feature tests live in `tests/Feature/Store` (the Store API) and `tests/Feature/Admin*`; every HTTP test is checked
against resources/api/openapi.yaml both ways (see [API contract](API-Contract.md)). Useful helpers: `website()`,
`webCustomer()`, `customerSession()` and `bearer()` for a signed-in customer, `loginAsStaff()` for the admin API,
`telegramLogin()`, `googleLogin()`, `turnstile()` and `mail()` for the outside services, and the `WebsiteSignIns`
trait for nonces and redirect states. `RouteGuardsTest` walks every route with another shop's rows and every admin-API
route through each step of the door. See [Testing](Testing.md).

## Where to start

- A new customer route: its controller in `Store\Api`, its service in `Store\Services`, the route in routes/api.php
  (inside the customer group, and the recent-sign-in group if it changes how the account is signed in to), and its
  operation in openapi.yaml.
- A new way in: a proof method returning an account DTO through `ProviderSignIn::prove()`, a `WayIn` case (its column on
  `users`), and how a merge carries it (`AccountMerger::takeOn()`).
- A new admin-API operation: add it to `$operations` and mark it `x-staff` (with its grant when it needs one).
