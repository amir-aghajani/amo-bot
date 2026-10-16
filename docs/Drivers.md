# Drivers

What the shop does through someone else is a **driver** of a family: a VPN panel it sells on, a way it takes money, the
database it runs on, the way its email goes out, the captcha a website's forms ask. Every family stands on one small
kernel, so a new driver is a class, its registration and its request shape — the panels draw its form from its
description, with no code of their own but where a family says so below.

| Family | The interface | Registry (`bootstrap/container.php`) | Lives in | Today | Panel code for a new one |
|---|---|---|---|---|---|
| VPN panels | `Providers\Contracts\PanelDriver` (and its client, `ProviderInterface`) | `panel.drivers` | `app/Modules/Providers/Drivers/<Name>/` | `3x-ui`, `pasarguard` | none |
| Payment gateways | `Payments\Contracts\GatewayDriver` (and its gateway, `GatewayInterface`) | `payment.drivers` | `app/Modules/Payments/Drivers/<Name>/` | `wallet`, `outside` (paid outside the shop), `manual` (card to card), `cubepay`, `zarinpal`, `atlaspay`, `abangateway` | an icon |
| Databases | `Core\Database\Drivers\DatabaseDriver` | `database.drivers` | `app/Core/Database/Drivers/` | `mysql`, `sqlite` | none |
| Mail | `Core\Mail\MailDriver` | `mail.drivers` | `app/Core/Mail/Drivers/` | `smtp`, `native`, `resend` | none |
| Captchas | `Core\Captcha\CaptchaDriver` | `captcha.drivers` | `app/Core/Captcha/Drivers/` | `turnstile`, `altcha` | none |

## The kernel

**`App\Core\Drivers`** — a driver is a `Driver`: its `key()` (what `config.php`, a row or a request names it by) and
`describe()`, a `Descriptor`: its key, a Persian `label` and `description`, short `notes` (the versions it needs, its
caveats), the `form` it asks the admin to fill in, and `traits` — what its family says of it beyond that (a database's
`installable`, a mail driver's `sender`, a gateway's `kind` and `builtin`, a panel connector's `mark`, `vendor`,
`docs_url` and capabilities). A family's drivers are one `Registry`, by key, in the order they are registered: two
drivers of one key are a `LogicException` as the container builds it, and a key no driver has is an
`UnknownDriverException` — the code's mistake, or a hand-edited `config.php`'s, never a request's.

**`App\Core\Forms`** — a driver's form is a `Form` of typed fields: `Text` (a line, a path or a few lines), `Number`,
`Amount`, `Numbers` (a short list typed as text), `Toggle`, `Choice`, `Secret`, `EmailAddress` and `Url`. Each field has
the name a screen sends it under, the **key it is kept under** — a `config.php` setting (`DB_HOST`), a column or a JSON
key of a row — its default, and a `FieldSpec` saying how a generic form draws it: its label, hint, placeholder, unit,
whether it is Latin (drawn left to right), whether it sits under «تنظیمات پیشرفته», a choice's options, and `when` — shown,
read and required only while a field before it holds one of the values listed. A driver may bring a field of its own —
the card-to-card driver's `CardNumber` — whose type is one of `Core\Forms\FieldType`'s, each of which the panels know
how to draw (`card`: in groups of four, on a phone's number pad). Then:

- `Form::check()` reads a whole save: every field checked, every refusal at once under its field's name, in the admin's
  words, before anything is kept; `checkSent()` does the same for a partial save (a `PATCH`). `values()` is what a
  driver works with — each field's value read off what is kept, one the field would refuse at its default — and
  `present()` what a screen shows.
- A `Secret` is never shown back: a screen gets whether one is kept and a hint that gives nothing away; left blank, it
  keeps the one kept, and `clear_<name>: true` empties it. With `missing` a form left without one is refused. With
  `boundTo`, it belongs with other fields — a server's address, the account on it — and while one of them moves, a blank
  one no longer keeps the stored secret: the save is refused in `moved`'s words, so a kept password is never sent
  somewhere it was not given for.
- `describe()` writes every field out for the panels (`DriverDescription.fields` in the API: its type, words, whether it
  is required, a secret, bound to which fields, advanced, its options, `when`, bounds and default).

**The panels** draw any driver from that description alone: `resources/panel/src/components/driver-form` —
`DriverFields` (every field by its type, the `when` rules, the advanced fold, and for a secret kept for an address that
moved the server's own "type it again" words before anything is sent), `DriverPicker` (a family's drivers by name, the
one picked described under it) and `draft.ts` (the draft a form starts from, and what it sends).

**The API** describes each family's request as a `oneOf`, one closed shape per driver, in
`resources/api/openapi.yaml` — so a request is checked against exactly its driver's fields — and
`tests/Unit/Drivers/DriverFormsTest` holds each shape to its driver's form, both ways: the same fields, the same ones
required, a shape for every driver registered and for nothing else. It covers the database, mail, panel connector,
payment method and captcha requests, and holds the description's list of field types and of captchas to the code's.
After an edit to the description, `pnpm api:types` regenerates the panels' types.

**Words** — a driver's label, description, notes, hints and refusals — are Persian, written as the codebase writes the
rest ([Contributing](Contributing.md#language)): the panels show them as they are.

## A VPN panel connector

A panel the shop sells on. The contract is panel-agnostic on purpose: a new connector needs no edit outside its own
folder but its registration and its request shape.

1. **The driver** — `app/Modules/Providers/Drivers/<Name>/<Name>Driver.php`, implementing `PanelDriver`, a stateless
   definition:
   - `key()` — what `servers.driver` holds (`3x-ui`, `pasarguard`);
   - `describe()` — the add-server picker's card: the label, a description, `notes` (the versions it supports), and
     `traits`: `PanelDriver::MARK` (the two letters the card is drawn with), `VENDOR`, `DOCS`, plus
     `$this->capabilities()->traits()`. Its form is the server's connection, built from the lists every connector
     shares: `Support\PanelConnection::address(hint, placeholder, pages, pagesRefusal)` (the panel's address, refusing
     the panel's own pages your connector names — 3X-UI's `/panel`, PasarGuard's `/dashboard`), then
     `...Support\PanelCredentials::fields(token, tokenHint, tokenPattern, tokenMismatch, loginHint)` (an API token, or
     an admin's username and password), then fields of its own — one for the password way only takes
     `when: PanelCredentials::WITH_PASSWORD`, a secret `boundTo: PanelConnection::bound()` —, then
     `...PanelConnection::options(subscriptionHint, subscriptionPlaceholder)` (TLS, the timeout, another address for
     subscription links, and the SOCKS5 proxy the panel may be reached through, under «تنظیمات پیشرفته»). A field is kept
     under its key: a column of `servers` (`api_token`, `username`, `password`, `totp_secret`, `proxy_*` …), or
     `meta.<name>` for one that has none — a connector needs no column of its own. The server's own fields (its name,
     capacity, notes and switch) are `ServerService`'s, around yours;
   - `capabilities()` — a `DTO\Capabilities`: `inbounds` (clients sit on inbounds the owner picks; without, a server is
     sold whole), `linkRotation` (a client's credentials, and so its link, can be renewed — else no «تغییر لینک») and
     `ipLimit` (the panel holds a client to `ClientSpec::$ipLimit` — else no «کاربر اضافه» is sold on it), and `names`
     (a `DTO\ClientNameRule`: the length, characters and separators the panel takes in a client's name — any by default;
     the shop makes its own names fit it and refuses a typed one it would not take). What a connector does not claim,
     the shop does not offer;
   - `connect(Server, PanelHttp)` — the client of one server's panel, built on `PanelConnection::of($server)` and
     `PanelCredentials::of($server)`. `ProviderRegistry::forServer()` keeps it five minutes while the server's connection
     stays the same, so a session or token it signed in for serves the calls that follow.
2. **The client** — `<Name>Provider.php`, implementing `Providers\Contracts\ProviderInterface`: `testConnection()`,
   `status()`, `listInbounds()`, `servesSubscriptions()`, `createClient()`, `updateClient()`, `rotateClientCredentials()`,
   `setClientEnabled()`, `deleteClient()`, `findClient()`, `listClients()`, `findClientByLink()`,
   `resetClientTraffic()`. Clients go in as a `ClientSpec` (name, quota, an `Expiry` — counted from the first
   connection, fixed, or never —, an IP limit, the Telegram id or none, a comment, and `enabled`: `createClient()`
   always makes it on, `updateClient()` leaves it on or off as that says) and come out as a `ClientInfo` (name,
   enabled, counters, quota, expiry, the subscription link, presence, the IP limit the panel keeps or none — the sync
   copies it onto the service);
   `findClientByLink()` says whose a `DTO\ClientLink` is — a subscription link's tokens, or a config's credentials — for
   a customer claiming a client made by hand ([Services added by their link](Service-Claims.md)): match whatever your
   panel's links carry, and never trust a token you cannot verify (PasarGuard's are shown to the panel's own subscription
   route); inbounds are `InboundInfo` with an opaque `key` your connector understands. What a
   panel cannot answer is a `null` on the DTO, or an `UnsupportedOperationException` (`status()`, `listClients()` —
   the sync then asks client by client —, `rotateClientCredentials()`). Talk to the panel through `Support\PanelHttp`,
   the shop's one outgoing client with the connection's TLS rule, timeouts and SOCKS5 proxy, no redirects followed —
   never another client, which would pass the server's proxy by; it also reads an answer that is not the API
   (`PanelHttp::redirected()`, `notTheApi()`), and `Support\Raw` reads the panel's loosely typed JSON.
3. **Its failures** are the typed exceptions of `Providers\Exceptions`: `ConnectionException` (built with
   `fromTransport()`: dns, refused, timeout, TLS, the proxy — never repeating the panel's address or the proxy's login),
   `AuthenticationException` (its named constructors: a missing or refused token, a missing scope, a refused login, a
   two-factor code, a session, a CSRF token), `UnexpectedResponseException` (a wrong base path, a redirect, HTML instead
   of the API), `NotFoundException` (a client the panel does not have — never a route it does not have),
   `UnsupportedOperationException`, and a
   `PanelApiException` subclass of your own for "the panel said no, because …". Give the ones that describe a fixable
   setup a `hint` in your panel's own words (its menus, its pages): `ProviderErrorPresenter` words every failure for the
   owner, who runs the servers, and in one plain word for anyone else.
4. **Register it** in `bootstrap/container.php`: add `$c->get(<Name>Driver::class)` to `panel.drivers`.
5. **Describe its requests** in `resources/api/openapi.yaml`: a `Server<Name>Request` in `ServerRequest`'s `oneOf` —
   `driver` with your key as its one value, `<<: *serverFormProperties`, and your own fields, each secret with its
   `clear_<name>` — and a `Server<Name>TestRequest` (the same, with `id`) in `ServerTestRequest`'s. Then
   `pnpm api:types`.
6. **The panels** need nothing: the picker's card comes from your description and traits, the form from
   `DriverFields`.
7. **Tests**:
   - `tests/Unit/Providers/<Name>/<Name>DriverTest.php` — its card, every refused field reported at once, the address
     rule, a stored secret kept while the address stays on its host and refused once it moves to another (as
     `PasarGuardDriverTest`);
   - client and provider tests against the fake panel — `$this->panelHttp()` (`tests/Support/FakePanel`: queue answers
     with `ok()`, `fail()`, `raw()`, `refuse()`; read what was sent with `calls()`, `request(i)`, `params(i)`,
     `options(i)`) —, and, when the panel publishes a description of its API, a contract test that holds every request
     your client sends to it (as `ThreeXuiContractTest` does with `tests/Fixtures/3x-ui-openapi.json`);
   - a feature test through the servers API and a sale (as `PasarGuardConnectorTest`);
   - `DriverFormsTest` then holds your request shapes to your form by itself.
8. **Try it** on a real panel from a shell: `php bin/console panel:probe <key> <url> -t <token>`
   ([Console commands](Console-Commands.md)).

The two that exist:

- **3x-ui** (`Drivers/ThreeXui`) targets the v3 panel API: `ThreeXuiClient` (an API token as a bearer, or a cookie
  login — the CSRF token replayed, a TOTP code when a secret is kept — signed in again once on a refusal), `ThreeXuiApi`
  (one method per endpoint the shop calls), `ThreeXuiProvider`. A term from the first connection is the panel's own
  "expire on first use".
- **PasarGuard** (`Drivers/PasarGuard`, 3.1+): `PasarGuardClient` (an API key, 5.1+, or an admin's username and
  password traded for a token it keeps) and `PasarGuardProvider`. Its **groups** are what a server sells (a user reaches
  inbounds only through groups), a term from the first connection is its *on hold*, and it has no per-user IP limit, so
  a plan's device count is not enforced there. A username is 3 to 128 letters, digits and `_ - . @`, never two of
  those side by side; a user kept switched off by an update is `disabled` with its term (one still on hold keeps its
  duration for the switch back on).

## A payment gateway

A way the shop takes money. A payment method of a shop («روش‌های پرداخت») is a row of `payment_methods`: one gateway
driver, the label the checkout's button shows, and the driver's settings in the row's `config`.

1. **The driver** — `app/Modules/Payments/Drivers/<Name>/<Name>Driver.php`, implementing `GatewayDriver`:
   - `key()` — what `payment_methods.driver` holds;
   - `describe()` — the label and description the «افزودن روش پرداخت» picker shows, notes, and the form of a method's
     settings (each field kept in the row's `config` under its key; a secret a `Secret`, which a blank field keeps and
     the row keeps encrypted with `APP_KEY` — `Payments\MethodSettings` —, handed to the driver readable). Its traits:
     `GatewayDriver::KIND` — a `Enums\GatewayKind`: `instant` (it settles the moment the customer confirms, as the
     wallet does), `manual` (the customer pays outside the shop and a receipt is accepted, as a card transfer is) or
     `online` (the customer pays on the gateway's page and the gateway says so, as CubePay's do — below) — and
     `GatewayDriver::BUILTIN` (`true` only for a driver that is part of every shop: never added, never deleted);
   - `summary(settings)` — the one line the methods list shows about it from its settings, or `null` (the wallet; card
     to card, whose cards are rows of their own — the list shows them, and each payment the card it was shown);
   - `gateway(settings)` — the gateway of one method row, implementing `GatewayInterface`: `initiate($payment)` says what
     the customer does next to pay that payment (`PaymentInitiation::instant()`, or `transfer()` with the card it was
     shown), and `settle()` is its part of a payment's settlement, inside the settlement's transaction — database work
     only: that transaction is tried again when MySQL aborts it for a deadlock, so `settle()` may run more than once for
     one payment. A method's bounds (`min_amount`, `max_amount`) are the shop's, not the driver's: every method has them.
2. **Register it** in `bootstrap/container.php`: add `$c->get(<Name>Driver::class)` to `payment.drivers`.
3. **Describe it** in `resources/api/openapi.yaml`: a `<Name>MethodRow` (with its `<Name>GatewayConfig`) in
   `PaymentMethodRow`'s `oneOf`, a `<Name>MethodRequest` in `PaymentMethodUpdateRequest`'s, and — unless it is built
   in — a `<Name>MethodCreateRequest` in `PaymentMethodCreateRequest`'s. A form whose fields are all optional on an
   edit (secrets kept when blank, as AtlasPay's key is) would also fit the wallet's update: its `<Name>MethodRequest`
   says `not: { $ref: '#/components/schemas/WalletMethodRequest' }`, so the `oneOf` stays one — a form with a field
   every save sends (CubePay's `fee_fixed`) needs none. Then `pnpm api:types`.
4. **The panels**: give it an entry in `GATEWAYS` (`resources/panel/src/components/payments/gateways/index.tsx`) — its
   icon and, if the list should say something beside its summary, a `Note`; the type checker asks for it once the row
   type exists. The picker and the method's form (`gateways/method-form.tsx`) are drawn from the description — a
   required secret offers no «پاک کردن مقدار ذخیره‌شده».
5. **Tests**: a unit test like `tests/Unit/Payments/ManualDriverTest.php` — its description and traits, its form's
   refusals at once, a kept setting the form would refuse read back as its default, its summary, and its gateway's
   `initiate()` and `settle()`; `DriverFormsTest` holds the create and update requests and the row to its form by
   itself; a case in `tests/Feature/AdminPaymentMethodsApiTest.php` for adding and editing one through the panels' API.

A driver of one of the three kinds fits the checkout as it is: the bot and the website render "settled", "pay to this
card" and "pay on this page" alike.

### An online gateway

The customer pays on the gateway's page — CubePay's (`Drivers\CubePay`), Zarinpal's (`Drivers\Zarinpal`), AtlasPay's
mini app in Telegram (`Drivers\AtlasPay`), AbanGateway's (`Drivers\Aban`), the four in the box — and the gateway says
what came of it. Its gateway implements `Contracts\OnlineGateway` (the kind `online`):

- `open($payment, $request)` opens the payment on the gateway — the payment's id there (`$request->orderId`, the same
  every time for one payment, never another installation's), the amount in whole Toman, the address it calls back, a
  line saying what it pays, the customer's Telegram id and username — and answers an `OnlineInvoice`: the page, until
  when it takes money (`OnlineInvoice::until()` reads the gateway's end, else a time of the driver's own; `null` for
  none: the shop keeps the page an hour), the gateway's id of it, and the details it will read back (kept on the
  payment as `invoice`). The shop opens a payment once (`Services\OnlineInvoices`), outside any transaction, and keeps
  **one page a payment**: what `open()` answered is kept only while the payment has none yet, so of two opened at the
  same moment both show the one kept — the other's invoice is left unused at the gateway, and `open()` never needs to
  answer the same page twice. Tapped again while its page has a few minutes left, the same page is shown, else a new
  payment is opened;
- `check($payment)` asks the gateway what came of it — `OnlineVerdict::paid()` (with its reference), `waiting()` or
  `closed()` — never believing what a callback said. `waiting(moneyIn: true)` says the gateway took money on it that
  it has not settled yet (a receipt in review, a sum paid in part): the shop marks the payment (`invoice.money_in`) and
  asks about it past the usual two hours, up to 48 after its page closed — then it holds it for support;
- `initiate($payment)` reads what `open()` kept and calls nothing: `PaymentInitiation::link()` with a `PayLink` — the
  page, until when, the exact sum it asks (a gateway may add a few Toman of its own) and, if the gateway gave one to
  show in the bot, a card; `settle()` answers yes with the payment's reference (the gateway said paid before it runs).

Both calls talk to the gateway: throw `GatewayUnavailableException` with what it said as the detail (the log's; the
customer reads the exception's own words) when it is out of reach or refuses — with `held: true` when its answer does
not fit the payment (another order or amount than asked). The shop then **holds the payment for support**
(`OnlinePayments::isHeld()`): asked about no more, the report group's «خطاها» told once, and — while it still waits,
pending or failed with its page — its order neither expiring nor paid another way (`Checkout::pay()` throws
`PaymentHeldException`). Everything else is the shop's (`Services\OnlinePayments`, `PaymentService::settleOnline()`):
the asks about one payment taking turns — a lock file per payment, the payment read afresh once it is its turn, so
`check()` and what the shop does with its answer are one step and two at once never settle what the other holds —,
the callback address `/payments/callback/{payment}/{secret}` — a secret of the payment's own; a call there only makes
the shop ask the gateway, a few times a minute at most for one payment, and one past that is answered from the payment
as it stands —, the scheduler asking about every one not paid yet now and then (`Tasks\OnlinePaymentsTask`) until two
hours after its page closed — a gateway may take money late —, the bot's and the website's «check» for the customer
and the panels' for support; a paid one settles its order once and tells the customer once, and money the gateway took
after its order closed goes to the customer's wallet. A test puts a fake of the gateway's API under the shop's outgoing
client, as `Tests\Support\FakeCubePay`, `FakeZarinpal`, `FakeAtlasPay` and `FakeAban` do. A gateway that sends the
customer's browser back to that address (Zarinpal, AbanGateway) gets the browser a page of what came of it
(`Controllers\ReturnPage`, the one page PHP answers, with a policy that runs no script) — its server calls are
answered JSON.

## A database driver

A database the shop runs on: MySQL / MariaDB for a shop, SQLite for the tests and a developer's machine.

1. **The driver** — `app/Core/Database/Drivers/<Name>Driver.php`, implementing `DatabaseDriver`:
   - `key()` — what `DB_CONNECTION` holds;
   - `describe()` — its label, description and notes (the versions it needs), its form (its connection settings, each
     kept under its own `config.php` setting — `DB_HOST`, `DB_PORT` …; `DB_DATABASE` is shared, MySQL's name and SQLite's
     file —, the password a `Secret` bound to the fields that say where the database is), and the
     `DatabaseDriver::INSTALLABLE` trait: whether the web installer offers it;
   - `extensions()` — the PHP extensions it needs: the requirements list them, and a probe without one is refused;
   - `connection(config)` — the illuminate/database connection for those settings: charset, a UTC session, strict mode,
     its PDO options — the one description of it, for the shop and for a probe;
   - `probe(config)` — connect briefly and say what answered (`ProbeResult::probe($connection, $minimum)`: the version,
     refused below your floor, and the tables of the current schema), or why the shop cannot run on it, in the admin's
     words (`ProbeFailedException`) — nothing is written to `config.php` before it says yes;
   - `localDate(column, offset)` — the SQL of a UTC column's day in the shop's zone, for the dashboard;
   - `releaseNames(schema, table)` — what `db:rebuild` drops before a table steps aside: the names that are the
     database's rather than the table's (MySQL's foreign keys, SQLite's indexes).
2. **Register it** in `bootstrap/container.php`: add it to `database.drivers`. Add its PDO extension to `composer.json`'s
   `suggest`.
3. **Describe its request**: a `Database<Name>Request` in `DatabaseRequest`'s `oneOf` (`driver` with your key, and your
   fields, a secret with its `clear_<name>`), then `pnpm api:types`. The installer's database step and «تنظیمات پنل ›
   دیتابیس» draw it from its description.
4. **Tests**: a unit test like `tests/Unit/Database/Drivers/MySqlDriverTest.php` — its description and fields in order,
   every field refused in Persian under its own name, a secret kept, replaced or cleared, the connection it describes,
   the dashboard's day, a rebuild's names, a refusal worded from the server's error. `DriverFormsTest` holds its request
   to its form by itself; `tests/Unit/Database/SchemaTest.php` keeps `database/schema.php` within what every database
   takes; `Tests\Fakes\ProbedDriver` (`probedDatabase()`) plays a driver whose probe a test answers.
5. **Regenerate the reference**: `php scripts/docs.php` adds its settings to the
   [config.php reference](Config-Reference.md#database).

PostgreSQL will also need what the contract does not cover yet: its sequences set after a rebuild's copy (the copied
ids leave them behind), and a case-insensitive search (`Page::whereContains()` leans on MySQL's collation).

## A mail driver

A way the shop's email goes out — a website's sign-up codes and password resets, and the notices of a customer
Telegram cannot reach.

1. **The driver** — `app/Core/Mail/Drivers/<Name>.php`, implementing `MailDriver`:
   - `key()` — what `MAIL_TRANSPORT` holds (`none` is no driver: no email);
   - `describe()` — its label and description, its form (its own `MAIL_*` settings, each kept under its `config.php`
     setting; a password or a key a `Secret` — bound to the fields of the account it belongs to, when there are any —,
     with `missing` when it cannot go without one), and the `MailDriver::SENDER` trait: which address its emails may
     come from, worded for the owner;
   - `transport(values)` — the symfony/mailer transport those settings make, or a `MailSettingsException` when they
     make none (the log says why, and no email goes out). An email API is a transport of the app's own over the shop's
     one outgoing client, `GuzzleHttp\ClientInterface` (as `Core\Mail\ResendTransport` is), so the tests answer it as
     every other call.
2. **Register it** in `bootstrap/container.php`: add it to `mail.drivers`. `Core\Mail\Mailer` takes every mail driver's
   secrets out of what a mail server says by itself.
3. **Describe its request**: its key in `MailTransport`'s enum, and a `Mail<Name>Request` in `ConfigMailRequest`'s
   `oneOf` (`transport`, its fields, `from_address` and `from_name`), then `pnpm api:types`. «تنظیمات پنل › ایمیل» draws
   it from its description.
4. **Tests**: its transport in `tests/Unit/Core/MailTransportTest.php` (and a test of its own for a transport of the
   app's, as `ResendTransportTest`); a save in `tests/Feature/MailSettingsApiTest.php`. `DriverFormsTest` holds its
   request to its form by itself.
5. **Regenerate the reference**: `php scripts/docs.php` adds its settings to the
   [config.php reference](Config-Reference.md#mail).

## A captcha driver

A captcha a shop's website may ask of its forms — a sign-up, a password sign-in, a password reset, a review, and any
form of the site's own whose token its backend has the shop judge ([the captcha](Store-API-Sign-In.md#the-captcha)).

1. **The driver** — `app/Core/Captcha/Drivers/<Name>.php`, implementing `CaptchaDriver`:
   - `key()` — what the website keeps (`websites.captcha_driver`) and `GET /`'s `captcha.driver` says;
   - `describe()` — its label, description and notes, and its form: its fields kept in the website's encrypted
     `captcha_config` under their keys — a secret bound to the public key it belongs with;
   - `siteKey(values)` — the public key a page draws the widget with, `null` for one without;
   - `verify(values, CaptchaAttempt)` — a token judged: a `CaptchaVerdict` (whether it passed, the action and the host
     it was solved for, and why not, in the reason codes of [the captcha](Store-API-Sign-In.md#the-captcha)); the
     `Verifier` holds it to the form's action and the website's hosts. The attempt carries the token, the action, the
     website's hosts and the visitor's address when known (`visitor`: a sign-in form's sender, or the `remoteip` a site's
     backend names) — Turnstile hands it to Cloudflare. A `CaptchaUnavailableException` when it could not be judged — its
     provider out of reach — is a `503`, never a refusal.
   - A captcha whose widget fetches its challenge from the shop implements `IssuesChallenges` too: `challenge(values,
     action)`, served at `GET /captcha/challenge`.
2. **Register it** in `bootstrap/container.php`: add it to `captcha.drivers`.
3. **Describe it**: its key in `CaptchaDriver`'s enum and in the website's `captcha.driver` enum, and a
   `Website<Name>Request` in `WebsiteCaptchaRequest`'s `oneOf`, then `pnpm api:types`. «تنظیمات وب‌سایت» draws its
   form from its description.
4. **Tests**: a unit test like `tests/Unit/Core/Captcha/TurnstileTest.php` or `AltchaTest.php` — a token solved on the
   site's host for the form's action passes, one solved elsewhere or for another action does not, the provider's
   refusals, the provider out of reach; a fake of its provider in `tests/Support` when it calls one (as
   `FakeTurnstile`); the website's forms with it in `tests/Feature/Store/CaptchaTest.php`; and `DriverFormsTest`, which
   holds its request shape to its form and the description's list of captchas to the registered ones.
5. **Tell the websites' developers**: how a page draws it, in [the captcha](Store-API-Sign-In.md#the-captcha).
