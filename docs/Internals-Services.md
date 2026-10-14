# Internals: services

How the shop's services live on the VPN panels, and the pieces around them: the panel connectors, provisioning and
every later change of a service, grants, renewals and reminders, free test accounts, the automatic removal of what is
done with, the shops of the agent bots, the notices a customer gets, support tickets and reviews. What an owner sees
of them is in [Servers](Servers.md), [Services](Services.md), [Test accounts](Test-Accounts.md),
[Support tickets](Support-Tickets.md) and [Reviews](Reviews.md); how to add a panel connector is in
[Drivers](Drivers.md#a-vpn-panel-connector).

| Module | Holds |
|---|---|
| `app/Modules/Providers` | Servers, the connector contract, the 3x-ui and PasarGuard connectors, panel health |
| `app/Modules/Subscriptions` | Services, provisioning, the operations, grants, renewals, extras, reminders, free test accounts, the automatic removal, the sync |
| `app/Modules/Bots` | The bots, `CurrentBot` and the shop scope |
| `app/Modules/Notifications` | Every notice a customer gets, kept for their website and sent by Telegram or email |
| `app/Modules/Support` | Support tickets and their pictures |
| `app/Modules/Reviews` | Customers' reviews and their moderation |

Open first: `Subscriptions/Services/ProvisioningService.php`, `Subscriptions/Services/ServiceTerms.php`,
`Providers/Contracts/ProviderInterface.php`, `Bots/CurrentBot.php` and `Notifications/Services/CustomerNotifier.php`.

## Servers and connectors

A **server** (`Providers\Models\Server`) is one panel installation. Servers belong to the installation, not to a shop:
every bot sells on them, and a server's services are every shop's (`Server::subscriptions()` drops the shop scope).

A **connector** is a driver of the kernel ([Drivers](Drivers.md)): `Contracts\PanelDriver` describes itself (its card
and the form of a server's connection) and builds a client, `Contracts\ProviderInterface`, for one server. The contract
is panel-agnostic on purpose: clients go in as a `ClientSpec` and come out as a `ClientInfo`, a term is an `Expiry`
(never, a fixed deadline, or a term counted from the first connection), inbounds are `InboundInfo` with an opaque key
only the connector understands. What a panel cannot answer is a `null` on the DTO or an
`UnsupportedOperationException`; the generic code branches only on `Capabilities` (inbounds, link rotation, an IP
limit the panel enforces).

- **3x-ui** (`Drivers/ThreeXui`, v3+): `ThreeXuiClient` (token, or a cookie session behind the panel's CSRF guard with a
  TOTP code) → `ThreeXuiApi` (one method per endpoint, held to the panel's own OpenAPI file by `ThreeXuiContractTest`)
  → `ThreeXuiProvider`. A term from the first connection is a negative `expiryTime`.
- **PasarGuard** (`Drivers/PasarGuard`, 3.1+): `PasarGuardClient` (API key, or a password traded for a kept token) →
  `PasarGuardProvider`. Its groups are the shop's inbounds; a term from the first connection is "on hold"; it has no IP
  limit.
- **Shared** (`Providers/Support`): `PanelConnection` and `PanelCredentials` (the fields every connector's form shares,
  and how a stored server reads them back), `PanelHttp` (the one request shape — the connection's TLS rule and
  timeouts, redirects never followed — and the readings of an answer that is not the API), `Raw` (defensive readers
  for loosely typed panel JSON).

**A server's SOCKS5 proxy** (`servers.proxy_*`, its password encrypted) is a request option of its panel's requests
alone: `PanelConnection::requestOptions()` adds `proxy => socks5h://[user:password@]host:port` (the login URL-encoded,
the panel's name looked up by the proxy) and `PanelHttp::send()` merges it into each request — never into the shop's
Guzzle client or its transport, which Telegram, mail, the captchas, OIDC and the update check share. A transport
failure of a proxied request is classified with that in mind (`ConnectionFailure::of($message, proxied: true)`):
cURL's 5 and 97, a name not found (6: cURL looks a SOCKS proxy's name up as its host) or a connection that could not be
made (7, a timeout while connecting) — cURL connects to nothing but the proxy —, are `ConnectionFailure::Proxy`, said
as the proxy's and counted as a panel out of reach (`ProviderException::unavailable()`, the backoff); a timeout once
connected, or the panel's certificate, stay the panel's. `ConnectionException::tidy()` takes every address out of
cURL's words, a proxy's with its login, and `Core\Logging\Redact` takes any address's login out of a log line. The proxy
is part of the server's connection fingerprint, so a changed proxy gets a fresh client. `PanelConnection::of()` takes a
whole row — a row without the proxy's columns would reach the panel straight —; what needs only the panel's address and
its links' prefix reads them with `addresses()` from `base_url` and `meta` alone.

`ProviderRegistry::forServer()` reuses a server's client for five minutes while its connection is unchanged, so one
sign-in serves many calls; a 3x-ui session or a PasarGuard token is not renewed per call (PasarGuard notifies its admins
of every sign-in). A server not stored yet — a probe of the add-server form — gets a client of its own.

`ServerService` is everything the owner does with a server: one form for every connector (the server's own fields around
the connector's form, checked as one, every refusal at once), the probe (each question asked on its own once the
connection is good, so one flaky endpoint loses only its answer), the inbound copy, and the presentation without
secrets. **A kept secret is bound to the panel's origin** (scheme, host, port): moved to another host, it is never sent
there and must be typed again.

### Failures and health

Every connector failure is a typed `ProviderException`: `ConnectionException` (cURL's words with any address scrubbed,
since a panel's path can be its secret), `AuthenticationException`, `UnexpectedResponseException` (a wrong base path, a
redirect, a web page), `NotFoundException` (a client the panel does not have — never a route it does not have), and a
`PanelApiException` per connector for "the panel said no". The fixable ones carry a `hint` in the panel's own terms.

`ProviderErrorPresenter` words a failure **for two audiences**: the owner, who runs the servers, gets the diagnosis
(`describe()`, `explain()`); anyone else — an agent, a website admin, an agent's report group — gets `summary()`, a word
that names nothing of the panel. The caller decides by who reads it (`SubscriptionActions::panelWords()`, a failed
order's `notes` versus `diagnosis`).

`ServerHealth` records every contact (`last_checked_at`, `last_error`). A failure that means the panel cannot be worked
with at all (`ProviderException::unavailable()`) puts the server in **backoff** for ten minutes
(`Server::isBackingOff()`): the sync, grants, renewal periods and automatic renewals skip it and a customer's screen is
told the service could not be read — every try would cost a connect timeout, and under `bot:poll` the bot waits it out.
The owner's own buttons, sales and deliveries never wait. A panel that stops or starts answering is reported to the
main bot's report group once, by a compare-and-swap on the row in the same transaction as the report.

`ServerReadiness::problem()` is the one word on whether a server can take a new client (connector installed, switched
on, serving subscription links, room left); a server never checked is not sellable, because the customer gets one
subscription link and nothing else.

## Provisioning and the panel as the truth

A **service** (`Subscriptions\Models\Subscription`) is one client on a panel, with one subscription link. The panel is
the truth: `ProvisioningService` maps every answer onto the row through one function (`values()` → `mirror()`), stamped
with when the panel was asked (`last_synced_at`), and works out the status from the result — a service whose time or
traffic ran out ends, one the panel extended runs again; support's switch and a deleted row stay. With the status it
keeps when the service ended (`ended_at`, `endedAt()`), on every read and every change: null while it runs, so a
renewal or an extension clears it; once ended, the deadline it passed, else the moment the panel said its traffic was
used up or its client gone — an end already kept stays; support's switch leaves it as it was. The automatic removal
counts from it ([below](#the-automatic-removal)).

**The term starts at the first connection**: a new client gets `Expiry::afterFirstUse()`, and `expires_at`
stays null until the panel starts the clock (`Subscription::awaitsFirstUse()`). A term is counted in hours everywhere
(`duration_hours`), so hourly plans and daily ones share every rule; days given (a grant, an extension, extra time) are
turned into hours in `ServiceTerms`. The APIs also say `duration_days`, rounded up, for older clients.

Three rules keep concurrent work safe — keep them in any change here:

1. **A change holds the service.** Renew, grant, extra, the next period, rotate, switch, move and delete run inside
   `holding()`: a `Core\Database\Lease` on the row, as long as one panel call may take (`holdSeconds()`: the server's
   timeout × the requests one call makes, plus a margin), renewed before every call (`held()`). A second change waits a
   few seconds, then is refused with `ServiceBusyException` (409), which always means nothing was done.
2. **A read only writes over what it read.** The sync and `inspect()` write through `apply()`: only if the row still has
   the same server, client and status, nobody holds it, and its copy is not fresher than this answer. So a list read
   before a renewal never puts older numbers back, and never marks deleted a service that moved meanwhile.
3. **What the panel took is always recorded.** A change that lost its lease after the panel accepted it (it stalled
   past its time) still writes the answer by the row's key and logs it (`record()`): undoing or retrying would apply
   it twice. A move that loses its lease before deleting the old client takes the new one back.

The arithmetic of what a service gets is `ServiceTerms`: pure functions of the row and the plan — `renewal()`,
`grant()`, `extra()`, `nextPeriod()`, and `preview()` for a checkout — with no panel and no database, so every rule is
unit-tested in one place. `ProvisioningService::applyTerms()` sends them and records the answer with what the change
decides itself (`ServiceTerms::columns()`: the term and the queued period; a renewal adds its plan and IP limit). Each
change says whether the client ends up switched on (`ClientSpec::$enabled`): a renewal and an extra switch it on (the
service runs again), a grant unless support switched the service off, and a refund's take-back, the next period and
a relabel leave it as the panel had it — no change but `setEnabled()` ever undoes a switch.

`provision()` makes **one client on every inbound** of the plan entry, so the customer has one link for all of them,
named by `ClientNaming` (below). A panel that answers without a link, or a transaction that fails after the panel call,
takes the client back off the panel. A create that may have been done though no answer came
(`ProviderException::mayHaveBeenDone()`, below) has the panel asked at once (`makeOn()`, as a move's is): a client by
that name with the quota asked and nothing used is the one made, and the service is delivered on it — a retry would draw
the pattern's next name and leave that one behind; a panel that cannot say leaves the delivery failed and a warning in
the log to look for it.

### Client names

`ClientNaming` names a client by the shop's pattern (`ClientNameSettings`, the `client_names` group: `pattern()`,
`testPattern()`; `DTO\ClientNamePattern` holds the variables — one list for the setting's check (`problem()`: `{n}` or
`{random}`, or `{seq}` beside `{username}`, `{tg_id}` or `{user_id}`, so no two services are named alike), the naming
and the panels' hint, `BotSettingsResponse.name_variables` from `variables()`, each with a sample customer's `example`
the panel's example name is drawn from). `render()` takes a closure per variable and calls only those the pattern
holds, so a counter moves only for a pattern that uses it; each closure is worked out once per name, so a name over
`NAME_MAX` (64) is rendered again with its username shortened — never the part that tells names apart — and cut there
at worst. The counters are `sequences` rows: `{seq}` is `clients.user.<user id>` for a customer with a username
and `clients.anonymous` for every one without (the keys every shop's names had before the pattern, so the defaults
`{username}_{seq}` and `test_{username}_{seq}` go on where they were), `{n}` `clients.shop.<bot id>`; `{random}` is
`ReadableCode` lowercased, `{date}` `ClientNamePattern::date()` (`ymd` on the shop's clock). A name any bot's service
on that server carries is skipped for the next, `TRIES` (100) times at most, then `NoServerAvailableException` — a
delivery failure saying to check the pattern. `nameOn()` keeps a moving service's name unless taken on the target, or
one the target's panel would refuse (a name a client claimed by its link came with), then names it as `next()` would
(a test's by the test pattern) — and a client made by hand on the target under it is met as below (`named()`).

**The panel's own rule.** Every name is one the server's panel takes: its connector says what that is
(`Capabilities::$names`, a `Providers\DTO\ClientNameRule` — any name by default; PasarGuard's 3 to 128 letters, digits
and `_ - . @`, never two of those side by side). The shop's own names — the pattern's, a random one — are
`fit()` to it (characters it does not take dropped, a run of separators cut to its first, cut to its longest, too short
padded with leading zeros: `{n}` → `001`), and `render()` shortens a username to its longest when that is under
`NAME_MAX`. A typed name it would refuse is refused at the checkout under `client_name` (`PANEL_LENGTH`, `PANEL_RUNS`,
`PANEL_REFUSES`), and an order's name it would refuse — asked for on another server — gives way to the pattern.

A buyer's own name is one domain rule for every door — the bot's checkout (`CheckoutScreen`, `ClientNameHandler`), the
Store API's `POST /orders` (`CustomerCheckout::clientName()`) and the custom builder: `requested(ClientNameMode,
?typed, Server, ?seed)` is null for the pattern, a random name free on the server, or the typed one
(`[A-Za-z0-9_-]{3,32}`, free there), each way refused on `name_mode` while `ClientNameSettings::modes()` does not offer
it, a typed name on `client_name`. The order keeps it (`orders.client_name`, part of the order's shape: the same
purchase with another name is another order, and an Idempotency-Key's request must name the same —
`OrderService::same()` compares each shape column as the database keeps it, as `open()` finds an order by it). So the
website's random name is worked out from the customer and the key (the seed: two requests sent with one key at once
ask the same name), and a request made again with its key asks what its order was made with, judged no more.
`provision()` asks `forPurchase()`: the order's name while the panel takes it, no service on the server carries it and
the panel — one `findClient()` through `onPanel()`, asked only for an order with a name — has no client by it (one made
by hand); else the pattern's. The shop knows only the names of the services it keeps, so `ProvisioningService::named()`
makes every new client — a purchase's, a test's, a moved service's on its target — and, whenever the panel refuses its
name (`PanelApiException`) and has a client by it (one `findClient()`, asked only then) — made there by hand with the
pattern's next name or the moved service's own, or another order's asking the same name delivered at the same moment —,
tries the pattern's next (`next()`, a test's by the test pattern, each name drawn once), `NAME_TRIES` (10) names in
all; a refusal for another reason fails the delivery (or the move) as it came. The bot keeps a name per checkout in the chat's session (`_client_name`, as a discount code is kept), and `requestedName()` hands it to the
order while its way is still offered.

## Operations from the screens

`SubscriptionActions` is the subscriptions screen's operations, for both panels and the website's admins: sync, extend,
switch off and on, move, delete. `allowed()` says what the state allows; the same rules then refuse a request made
anyway, worded by the state found. Who asks matters:

- a panel failure is worded for the reader (the owner the diagnosis, anyone else the summary);
- **leaving a client on its panel** (a delete or move that does not contact an unreachable panel) is the owner's call
  in the main shop; anyone else may do it only while that panel is in backoff — a client left on a working panel would
  be a second service nobody paid for (a move's is written down and removed once that panel answers, below);
- panel work from an agent's shop or the website's admins is rate-limited per shop (60 a minute); the owner in the main
  shop is not, so a batch never floods a panel every shop depends on;
- traffic added to an agent's customer's service comes out of the agent's pool (`TrafficPool::extending()`);
- a shop admin never changes their own service.

A move creates the new client first and deletes the old one second, so a failure at any step leaves the service where
it was (`MoveException` names the step; `errors.previous` is the screen's cue to offer leaving the old panel out). A
call that may have been done though no answer came (`ProviderException::mayHaveBeenDone()`: a timeout or a cut
connection once the request was on its way — `ConnectionFailure::beforeRequest()` false —, or an HTTP 5xx page something
in front of the panel answered for it, `UnexpectedResponseException`; never the panel's own refusal) is asked about on
its panel before anything is undone: the old client's delete — gone, the move stands; still there, the new client is
taken back; no answer, the move stands and the old client is left behind (taking the new one back could leave the
customer with neither) —, and the new client's create (`makeOn()`: a client by that name with the quota asked and
nothing used is the one made, and kept, so the next name never makes a second; no answer, it is left behind).
Every client a move leaves on a panel — the old one it did not delete (the previous panel left out, or silent), a new
one the target did not say it made or would not take back — is written to `migration_leftovers` (`leave()`; the old one
in the transaction that points the row at the new client, so its record stands or falls with the move) and removed once
that panel answers ([below](#moving-a-servers-services)): the customer still has the old one's link. A failure to point
the row at the new client takes it back only while it is nobody's (the service was deleted meanwhile) or the old one is
still the customer's (`leavePrevious`); once the old one is gone, or may be, the new one is kept and the error logged.
`ProvisioningService::move()` takes three more arguments for a server's bulk move ([below](#moving-a-servers-services)),
each off for a single move: `switchedOff` (a service support switched off moves too, its new client made switched off),
`from` (only while the service is still on that server, asked under its hold) and `by` (the job its leftovers are
written down as).

## Service transfers

«انتقال سرویس» is `Subscriptions\Services\ServiceTransfers`, one rule for every door — the bot
(`Telegram\Handlers\ServiceTransferHandler`), the website (`POST /subscriptions/{id}/transfer`, its `GET` the
recipient), the panels and the website's admins (`Admin\Api\SubscriptionsController::transfer()`, the delete's grant):

- **Who asks** is an `Actor`: a customer is `Actor::customer()` (`ActorKind::Customer`), support any other. A customer
  needs `ServiceTransferSettings` (`service_transfers`, off by default) on, gives only their own service, never while an
  order of it they made is open (`Order::OPEN`, `refuseOpenOrder()`: a failed one is support's to settle —
  `FAILED_ORDER` sends the customer there —, since once the service changed hands it could never be delivered and a
  failed renewal would hold the new owner's renewals; a previous owner's left from support's transfer holds nobody), and
  is rate-limited: `Users\Services\CustomerLookup::LOOKUPS` recipients per `LOOKUP_WINDOW` (counted with the money they
  send, [wallet transfers](Internals-Commerce.md#wallet-transfers)), `TRANSFERS` per `TRANSFER_WINDOW`. Support is refused only
  its own service (`ActorRefusedException::ownService()`) and taking one itself.
- **What may go** — `transferable()`: no free test, its client still on its panel; the panels' `actions.transfer`.
- **The recipient** — `recipient()` finds the customer of that Telegram id in the service's shop through
  `Users\Services\CustomerLookup::find()`, the one lookup wallet transfers share (the id as typed — Persian digits too, up
  to 16 digits —, `CurrentBot::run()`, a customer's lookups counted): not its owner, not banned. Refusals are
  `ValidationException`s — the service under `status`, the recipient under `telegram_id` —, which the bot shows in its
  own texts (`%reason%`).
- **The change** — `transfer()`: holding the service's lease as a change on its panel does (`Lease::take()`, for
  `HOLD_SECONDS`, let go at the end): a change under way there — a renewal's or an extra's delivery, which checked the
  owner as it began (`ProvisioningService::renew()`, `addExtra()`) — makes it refuse with `BUSY` («try again in a
  moment»), so a paid order of the giver's never lands on the new owner's service; one that begins after finds the
  new owner. Then, under the service's row lock (the one `AutoRenewal` and `OrderService::holding()`
  make a renewal or an extra order under), its owner, status and open orders checked again, then the new owner's columns
  (`ProvisioningService::newOwner()`) with `ShopReports::serviceTransferred()` («خریدها») in the same transaction. After
  the commit `cancelLeftOrders()` cancels the previous owner's unpaid orders of it at once, as an idle one expires
  (`PaymentService::expire()`, its locks and receipts discarded; `NOTE_CANCELLED`): they could no longer be paid or
  delivered (`Order::serviceChangedHands()`), and a renewal of them would hold the new owner's `AutoRenewal` back. One
  whose money may be with support (`Payment::isWithSupport()`: its receipt in review, or its card transfer taken in
  support's private chat) stays — it may be the money — and fails at its delivery for support to give it back. Only
  support's transfer leaves any. Then `CustomerNotifier::serviceReceived()` tells the new owner (with «📊 مشاهده
  سرویس» and, where the panel can change the link — `ProvisioningService::rotates()` —, that the previous owner keeps it
  until «تغییر لینک») and, when support did it, `serviceTransferred()` the previous one, with the orders cancelled
  (`%cancelled%`) — a customer's own change is answered at its door, as a new link is.
- **A change of owner** is one path for a transfer and a claim by its link: `newOwner()` is what the row takes — the
  owner, `auto_renew` off, `renewal_notified_at`, `expiry_reminded_at` and `traffic_reminded_at` cleared (a claim's new
  row keeps a stale record's `auto_renew`, the same customer's) —, then `ProvisioningService::relabel()` after the
  commit: it holds the service, reads it, and sends the client back with its quota, term, IP limit and switch as the
  panel had them and the owner's `ClientSpec` (Telegram id, `ClientNaming::comment()`) — one update, never a switch on.
  A merge of two accounts (`Accounts\Services\AccountMerger`) relabels too, after its transaction: each service whose
  owner's comment before the merge is not the survivor's now — the merged account's, unless the survivor took its
  Telegram account, and the survivor's own once it did —, and so does a Telegram account linked to an account or taken
  off it (`Accounts\Services\Identities`): every service of the account (`relabelServicesOf()`). It never fails the
  change: a panel in backoff (not asked), a
  failure (`ProviderException`, `ServiceBusyException`, the service gone) is logged, and the next change of the service
  on its panel writes the owner anyway, since every `spec()` reads the service's customer. The client's name stays.

## Renewals, extras and reminders

- **Renewal** (`CustomerRenewal`, the bot's and the website's): the service's own plan at today's price
  (`Orders\Services\Pricing`), delivered by `renew()`, by a `RenewalMethod` (`ServiceTerms::renewal()` is the rule of
  each): `Extend` carries the days left, and the traffic left only when the admin says so (`RenewalSettings`) — not
  carried, the renewal adds the plan's traffic at once and queues the end of the paid period (`period_ends_at`,
  `next_period_bytes`), which `NextPeriodTask` caps then; `ResetTraffic` carries the days and counts the traffic afresh;
  `ResetTime` starts the term again (`starts_at` now for a running service) and carries the traffic. Neither reset
  queues traffic, and either drops what was queued before. The order keeps its method (`orders.renewal_method`, part of
  its shape in `OrderService::openRenewal()` and `openCustomRenewal()`: an open renewal by another method is another
  order, and a key's request must name the same), so the delivery adds what the checkout showed. `renew()` adds the
  order's package (`Order::package()`): its plan's, or a built one's own GB, days and devices.
- **A renewal's devices** are its package's, whatever the service had: extra devices last as long as the period they
  were bought for. A running service renewed by `Extend` or `ResetTraffic` goes on with the period in use, so the
  package's limit is queued behind it (`next_period_ip_limit` at `period_ends_at` — the period queued already, else the
  deadline; always, so its end is known) and `NextPeriodTask` brings it then (`ServiceTerms::nextPeriod()`,
  `periodOver()`; a period queued for its devices alone asks the panel only when the limit changes on a panel that
  keeps one). Any other takes it at once and stamps `ip_limit_reset_at` — a period over by time or traffic, a
  `ResetTime`, a service that never ends —, except one waiting for its first connection: it takes the package's with
  the devices bought for its term on top (`CustomerExtras::devicesBought()`: delivered since the last reset, not taken
  back — its checkout's preview counts them too), since they were charged for all of it and its term runs on. Extra
  devices bought while a period is queued are priced to `period_ends_at` and end with it (`ServiceTerms::extra()` keeps
  the next period's limit as it was to be; one whose end passed has begun, and they go onto it — a renewal then goes
  on from that period as begun, `ServiceTerms::begun()`: its limit, its traffic capped, queued behind the deadline).
  Days given or bought move the queued period with them. The order keeps what the renewal gave for its refund and the
  refunds of the orders before it (`ServiceTerms::given()`): `given_hours` and `given_bytes` (signed) — a reset's beyond
  what it replaced: `ResetTime` the term less the hours left to the deadline (or the term still waiting), `ResetTraffic`
  on a quota whose period has not passed the package's traffic less what was left; less than nothing when it replaced
  more (a week in place of 25 days left, 10 GB in place of 40), which its refund gives back —; what it gave where there
  was none, `gave_plan`, `gave_term` (a term to a service that never ended), `gave_quota` (a quota to an unlimited
  one); what a package without a term or a quota replaced, `term_was` and `expires_was` (the term in hours and its
  deadline, null while it waited for the first connection) and `quota_was` (the traffic left); what it started afresh,
  `reset_term` (`ResetTime`, a service whose time ran out or that never ended, or a term it ended), `reset_traffic`
  (`ResetTraffic`, a fresh count with nothing carried, or a quota it ended), `reset_ip_limit` (its package's limit at
  once, the devices bought before gone); and `given_ip_limit`, `ip_limit_was`, `ip_limit_queued`, `ip_limit_reset_was`
  (set at once: the service's `ip_limit_reset_at` before it). `given_hours` and `given_bytes` became signed in 0.4.0:
  the upgrade changes the two columns on MySQL and MariaDB while they are still unsigned (SQLite keeps no sign).
- **A refund's take-back** (`ProvisioningService::undo()`, `undone()`; `ServiceTerms::takeBack()`, `limitPutBack()`)
  takes what a renewal or an extra gave as far as the service still has it, and gives back what a reset replaced
  beyond what it gave, so a service's renewals and extras, refunded in any order, leave it exactly where it was
  (`tests/Feature/RefundsInEveryOrderTest` refunds them in every order):
  - what an order delivered after it, still standing, started afresh is no longer there to give back: nothing of it
    is taken, and it is handed on to the first such order — a renewal, or extra days that started a new term for a
    service whose time ran out (`ServiceTerms::extra()`; `addExtra()` keeps that restart on the order, `reset_term`
    and `given_hours`) —, its hours added to `given_hours`, its traffic to `given_bytes`, extra devices gone with a
    renewal's reset (`ServiceTerms::devicesGone()`) taken off its `ip_limit_was`, so its own refund takes it with its
    own;
  - what only the last renewal standing may undo goes back once none delivered after it stands, and passes to the next
    one until then, in place of what that one would undo of it: the plan, a term or a quota given where there was none
    (no plan, never ending, unlimited again); a term or a quota a package without them ended, put back whole
    (`term_was`, `expires_was`, `quota_was`) — less what was handed on to it, with the days and traffic of the extras
    delivered after it and still standing on top (`extrasAfter()`), which their own refunds take later; passed on, the
    next one keeps what was handed on to it less the extras between the two —; and a device limit set at once —
    undone on the limit as it is now (`ServiceTerms::limitUndone()`: devices bought or taken back since, and the
    panel's change, stay on top), with `ip_limit_reset_at` back to `ip_limit_reset_was` when it had reset it, so the
    devices bought before it count again; the next renewal set at once takes it over as what it replaced
    (`ip_limit_was`, `ip_limit_reset_was`), and a queued period that began since (a reset after the order) leaves it as
    it is. A limit queued behind the period in use goes back while no other was queued after it, else the next one
    replaces what it did;
  - with nothing taken — all of it gone or handed on — the panel is not asked and `undo()` answers false, so the
    refund's message names no service (`RefundedDeliveries`); the order is marked taken back all the same, with what
    it handed on, in one transaction.

  What a refund leaves as it finds it: a `ResetTime`'s refund does not bring back the period queued that it dropped
  (in the customer's favour), and leaves `starts_at` and `duration_hours` as the reset set them (cosmetic: the deadline
  is the panel's truth); traffic given back is the period in use's (a queued period's cap stays); the traffic left of a
  period whose time ran out, which a renewal not carrying it dropped, is not given back (it went with that period); a
  quota put back whole leaves out what was used while the service was unlimited, and a term put back whole the days
  support gave since (a grant is no order to refund); and hours given back to a term still waiting for its first
  connection wait with it.
- **The renewal's doors** — `CustomerRenewal::preview()` is the one every checkout goes through: the bot's
  (`RenewalHandler`), the website's (`Store\Services\CustomerCheckout`: the preview, the renewal and a code's check), a
  reminder's «پرداخت دوباره». It takes what the renewal is with — its own plan (null), a plan picked (its id) or a
  package built (a `CustomPrice`) — and answers a `DTO\RenewalPreview` whose `with` is the `Plan` or the `CustomPrice`
  and `package` what it gives, its `quote` today's price. It refuses (null) a method the shop does not offer
  (`resolveMethod()` against `methods()`: the admin's switches, one at least — `Core\Forms\Fields\Ruled` holds the form
  to that and the default to one of them), a service whose window is closed (`window()` → `DTO\RenewalWindow`:
  `windowDays()` before the deadline, or `windowPercent()` of the traffic left; an ended service always open, one
  without a deadline only by its traffic; `when()` words from when for the bot's popup and the website's `422`), and one
  whose renewal is under way. `refusal()` says why in an `Enums\RenewalRefusal`, asked in one order for both doors,
  which only put it in their words; `picks()` is what the service may be renewed on now (the website's
  `…/renewal-plans`, empty while it is refused), `priced()` the preview with a code typed. The three share their
  questions (`renewsWith()`) and only `preview()` prices, so a picker prices each plan once (`Pricing::renewalOffers()`).
  The lists (the bot's «♻️ تمدید سرویس», the website's `renewable`/`picks_plan`) do not ask the window: the tap
  explains, and the website gets `renewal_window`. The bot carries the method a checkout
  is drawn by in its callbacks (`renew:{s}:{f}:{pay}:{plan}:{method}`, the method's place among the cases, left out for
  `Extend`: `RenewalHandler::methodArg()`), so a pay button renews by what its checkout showed; the discount code stays
  kept for the checkout without its method, so switching keeps it. A package built has the builder's callbacks
  (`custom:r:{s}:{f}:{gb}:{days}[:{method}[:{pay}]]`, `CustomServiceHandler`), whose GB and days steps are the
  purchase's, parameterised by the service; once its amounts hold, it hands the checkout and the payment to
  `RenewalHandler::checkout()` and `pay()` with the `CustomPrice` — so the window, the methods, the code and the
  under-way popup are the plan's.
- **Automatic renewal** (`AutoRenewal`, every `AutoRenewTask::INTERVAL_MINUTES`, 30): the customer's switch, renewed
  from the wallet within the admin's days of the deadline, by the admin's default method
  (`RenewalSettings::defaultMethod()`, passed to `OrderService::createRenewal()`); the customer's window does not hold
  it back. The panel is read first (its deadline is the truth). By ResetTime, which starts the term afresh from the
  renewal and drops what was left of it, it waits for the last run before the deadline (`LAST_RUN_MINUTES`: the
  interval and 5 minutes for a late run — `opensAt()`, `renewsNow()`), its panel not read meanwhile, so it loses that
  much at most; a short wallet is still told from the window's start, whatever the method. `claim()` makes the order
  under a lock on the service row, only while the row is still due by the method (another renewal may have moved its
  deadline meanwhile) and no renewal of it by its owner is open or has `fulfilled_at` since that deadline's renewal
  could begin (`opensAt()`, refunded since or not, the customer's own too): two runs never charge twice, and a deadline
  a renewal left where it was (a panel that kept its own) is never renewed again — no loop, whatever the method. A
  short wallet is told once per window (`Transitions::claim()` on `renewal_notified_at`). A plan whose term is shorter
  than the window never renews itself: it would be due again at once.
- **Extras** (`CustomerExtras`, «حجم، زمان و کاربر اضافه»): traffic, days or devices (`Enums\ExtraKind`; each its own
  `OrderType`) bought for a service at the plan's or the shop's price list (`ExtrasSettings`, `DTO\ExtraPrices` of
  `DTO\ExtraOffer`s). `refusal()` is the one rule of what a service can take, asked at checkout and again at delivery
  (`ProvisioningService::addExtra()`) against the panel's numbers; devices also need a panel that enforces an IP limit
  (`Capabilities::$ipLimit`: 3x-ui yes, PasarGuard no), and time and traffic left (a renewal of a service ended on
  either count replaces its period at once). Devices are priced for 30 days (`ExtraOffer::PERIOD_DAYS`) and charged
  `prorated()` to `deviceDays()` — the whole days left of the period in use (at least 1): to `period_ends_at` while a
  renewal is queued, else to the deadline; its whole term while it waits for its first connection, 30 for one that
  never ends —, rounded up once to whole Toman (`Money::shareOf()`), so the bot, the website and a code's check all
  quote the same price. `ServiceTerms::extra()` raises the IP limit, `takeBack()` lowers it again — never below 1, and
  not at all once the limit went back to a package's since they were delivered (`devicesGone()`: `ip_limit_reset_at`
  after the order's `fulfilled_at`; they went with their period — a refund's take-back, above, hands them to the
  renewal that reset it). Days for a service whose time ran out start a new term from its next connection, which the
  order keeps as a renewal's restart (`reset_term`, `given_hours`): an earlier order's refund takes nothing of it. The
  order keeps the amount under the kind's column (`ExtraKind::columns()`, read back by `quantityOf()`). An agent's pool
  pays for traffic alone.
- **Reminders** (`ServiceReminders`): a mark on the row (`expiry_reminded_at`, `traffic_reminded_at`) is claimed
  before a reminder goes and cleared once the service is back under its threshold, so each goes once per approach.

## Grants

`Grants` gives days and traffic to many services: a server's «افزودن زمان و حجم» or the [mass gift](Broadcasts.md#the-mass-gift).
A grant is split into one `GrantPart` per server, each worked through **one service at a time past a cursor** by
whoever holds the part's lease — the open screen a few seconds a request (`run()`, `runPart()`, until
`Core\Scheduling\Budget::screen()`), or `GrantsTask` every minute — so it finishes however many services there are, and an
unreachable panel holds up only its own server (`waiting_reason`). The loop is `Core\Database\Lease::work()`: the service
past the cursor (`next`), its turn (`step`, false when the part must wait or is no longer this call's), the end
(`finish`), the lease let go however it ended. Each service is claimed (the cursor moved) before its panel is touched, so none is given twice; a
failure that may have reached the panel (`ProviderException::mayHaveBeenDone()`: a timeout, a cut connection, a proxy's
5xx page) is counted failed, not retried, as a refusal is — only a panel out of reach before the request went waits.
The database allows one running part per server (`running_server_id`, a generated unique column); MySQL cannot cascade
on such a column, so `ServerService::delete()` removes a server's parts itself. Who gets it is decided per service at its turn, by the panel's word: running, not switched off, and — unless
ticked — not still waiting for the first connection.

## Moving a server's services

`ServerMigrations` is a server's «انتقال سرویس‌ها»: every shop's services on it (`Subscription::acrossShops()`) moved to
another server, each by `ProvisioningService::move()`. A job (`ServerMigration`) covers the services on the source when it
was started (`upto_subscription_id`) — active ones, those switched off with `include_disabled`, of `plan_ids` when given —
and is worked through like a grant's part (`Lease::work()`, its `start` turning a queued job running): one service at a
time past a cursor, by whoever holds the job's lease (the page's `run()` a few seconds a request, `MigrationsTask` every
minute), each service claimed before any panel is touched.
Its status is queued (until its first turn), running, paused, then done, cancelled or failed; the database allows one open
job (queued, running, paused) per source (`open_source_id`, a generated unique column — `ServerService::delete()` removes
a server's jobs itself, as for grant parts, and refuses a server an open job is from or to). The lease holds while the
job is workable in the mode it was taken in: `pause()` and `toRescue()` write the status or mode alone — the holder stops
at its next step yet still puts the cursor back on a service it must come back to —, `cancel()` frees the lease too.

- **migrate** reads each client on the source (`move(leavePrevious: false)`): a source in backoff holds the job at that
  service (`waiting_reason`), as a target in backoff does in either mode. `toRescue()` switches an open migration to a
  rescue.
- **rescue** never asks the source (`move(leavePrevious: true)`): the row's last synced numbers are carried, and each old
  client is written to `migration_leftovers` in the transaction that moves the row (`move()`'s `leave()`, the job's
  `migration_id`) — as, in either mode, a client a panel did not say it deleted or made.
  `runAll()` then removes the leftovers of every server not in backoff — a single move's (no `migration_id`) too —
  (`ProvisioningService::removeLeftover()`, which holds the leftover's service meanwhile — a busy one waits for the next
  try — so a move of it back there cannot slip between the look and the delete), one gone already counting as removed
  and one by a name a service of any shop has there again only forgotten; `cleanNow()` is the owner's button, asking
  even a panel in backoff until it cannot be reached. A leftover outlives its job (`migration_id` set null when a deleted
  server takes the job).

The owner's job moves an agent's services onto a server kept from that agent's shop too: `Bot::hidesServer()` binds the
single moves of anyone but the owner (`SubscriptionActions::move()`), not `ServerSelector::moveTarget()`. Per service: a target that can take no
more by `ServerReadiness` (asked afresh before each service, and again when `moveTarget()` refuses) fails the job with the
reason; another `NoServerAvailableException` (the plan's inbounds there) fails the service alone. `MoveException`: the
service's own state passes it by (`skipped`); a panel that could not be worked with (`unavailable()`) puts the cursor back
and holds the job — a move takes back what it made and writes down what it could not be sure of, so trying again is
safe —; anything else is a failure, kept with why in `migration_failures`.
`ServiceBusyException` puts the cursor back for the next turn. A moved service's customer gets
`CustomerNotifier::serviceMoved()` in their shop's bot at the bulk pace (`Telegram\Api\Pacer`); `ShopReports` tells the
main bot's «خطاها» when a job starts and ends. The report and the card count a job's clients left on a panel alike:
`ServerMigration::leftovers()`, those not removed yet. The panels follow it in the change feed's own `migrations` area.

## Test accounts

A free test account («اکانت تست», [Test accounts](Test-Accounts.md)) is a service with `is_test` set and no plan.
`Subscriptions\Services\TestAccounts` gives them: `offer()` lists the servers a customer may take one on now, `issue()`
takes one.

- **The quota** (`TestAccountSettings`, the `test_accounts` settings group) is judged by `admit()`: the switch; then,
  for anyone but a bot admin, newcomers only (`Order::paidPurchases()`), the customer's tests for good or within a
  window (the refusal carries when the next one is possible), and the shop's cap a day of its clock — which counts
  every `test_accounts` row of the day, an admin's too. Each refusal is a `TestAccountRefusedException` with its
  `Enums\TestRefusal`.
- **Judged under a lock, claimed before the panel is asked.** `claim()` locks the shop's `bots` row while there is a cap
  a day (else the customer's `users` row), judges the quota again and writes the `test_accounts` row with `issued_at`
  null; so of two taps at once one is refused. The provisioning's transaction links the service and stamps
  `issued_at`; a failure deletes the claim (only while still unissued) and goes up as it came. A claim whose process
  died is deleted by the clean-up's first run 30 minutes after it was made.
- **The terms are the server's.** `TestServers` holds the owner's form for `servers.test_*` (read as `DTO\TestTerms`)
  and `choices()`: switched on for tests, able to take a client (`ServerSelector::wholeServer()` — `ServerReadiness`,
  then every inbound for sale), and in an agent's shop a pool holding the test's traffic.
- **Made like a sale.** `ProvisioningService::provisionTest()` goes through the same `create()` as `provision()` — one
  client on every inbound, the panel's link required, the client taken back if the row's transaction fails —, named by
  `ClientNaming` by the shop's test pattern (`test_amir_3` by default; a move that must rename it keeps to it), with no IP
  limit and `TestTerms::expiry()`: from the first connection, or a deadline from now.
- **An agent's pool** is drawn in the transaction that writes the service row and issues the claim (the closure
  `issue()` hands `provisionTest()`): `TrafficPool::drawTest()` writes one `sale` line «اکانت تست · سرویس …», so a test
  that falls through draws nothing and nothing is given back. A pool short at that moment (`TrafficShortException`)
  rolls the row back, takes the client off the panel and is the no-server refusal; the bot's tap then redraws the
  screen.
- **What a test never gets** is decided where each feature picks its services: renewals and «تمدید خودکار» need a plan,
  `CustomerExtras::refusal()` refuses it, and `ServiceReminders`, `Grants::services()` and `Rewards::takes()` leave
  `is_test` out. `Subscription::expiringWithin()` (and `isExpiringWithin()`) leaves tests out, so automatic renewal,
  the end reminders, the services list's «رو به انقضا» and the dashboard's count never see one; the dashboard's active
  services leave them out too. `Server::activeServices()` counts them: a test takes room in the server's capacity. A
  test makes no order, so it is never a purchase.
- **The panels' page** is `TestAccountDirectory`: the shop's tests with where each stands (`Enums\TestState`, worked out
  from the service as last seen, and asked of the database the same way for the filter) and whether the customer bought
  after it (one subquery for the page); every server with its terms and whether it gives a test now in this shop.
- **The clean-up** is [the automatic removal](#the-automatic-removal)'s: `Tasks\CleanupServicesTask` deletes the
  claims a dead process left, and — while the shop's clean-up is on (`TestAccountSettings::cleansUp()`) — removes the
  tests that ended, after the shop's hours from their end, and the ones on hold never connected, after its days.

The `test_accounts` row outlives its service (`subscription_id` is set null by `delete()`, which the change feed sees),
so the quota still counts a removed test; `AccountMerger` moves the rows to the account that stays.

## Services added by their link

A customer claims a client made by hand on a panel by sending its link ([Services added by their link](Service-Claims.md)).

- **The link** is `Providers\DTO\ClientLink`: `find()` reads the links in a text together, five at most — a
  subscription link's path parts (its `tokens`, the last first), or a config's credentials: a vless, vmess or tuic id, a
  trojan, Shadowsocks (a 2022 key whole and in its halves) or hysteria password. A web address stops at the first
  character no address is written in, and punctuation after a link (Persian too) is dropped. Values under eight
  characters are dropped, ids lower-cased.
- **Whose client it is** is each connector's to say: `ProviderInterface::findClientByLink()`. 3x-ui reads
  `/clients/list` once and matches a row's `subId` among the tokens, or its `uuid`, `password` or `auth` among the
  credentials — a `subId`, password or auth equal to the client's own name is no secret and matches nothing. PasarGuard
  first shows at most three parts of the links that can be its tokens (16 characters and more) to the panel's own public
  route `{path}/{token}/info`, without the API's credentials — its tokens are signed with a secret the shop does not
  have, so only the panel can say whose one is —, under the folder its links use (read off one user's
  `subscription_url`); any answer but a JSON object is no match, never a failure of the panel nor a message holding the
  token. Then it pages its users without their links for a config's credentials (`proxy_settings`' ids and passwords)
  and reads the one that matches.
- **The claim** is `Subscriptions\Services\ServiceClaims::claim()`: the main shop's, while `ServiceClaimSettings`
  (`service_claims`) is on; `RateLimiter` allows `TRIES` links within `WINDOW_SECONDS` per customer. The servers are
  asked in their order through `ProvisioningService::findByLink()` (each contact recorded by `ServerHealth`), a panel
  in backoff passed over and a failing one logged: not found is said only when every panel answered, else "try later".
  A client the shop keeps — any `subscriptions` row of that server and name, in any shop — is the customer's own
  already or someone else's, except the customer's own row marked `deleted` (its client gone, now back): that stale row
  is deleted, the panel untouched, and the client claimed afresh. One without a subscription link is refused.
- **The row** is `ProvisioningService::adopt()`: through `values()`, as a sync would write it, with `claimed_at`, no
  plan (a stale record's carried over), the panel's IP limit (`ClientInfo::$ipLimit`, so a later update does not wipe
  it) and, for a deadline still ahead, the hours left to it as its term — `starts_at` left null, since the panel does
  not say when its clock started (`ServiceTerms::startedAt()` keeps a start unknown while the clock runs). A client
  that ended comes `expired` — 3x-ui
  switches a used-up client off by itself — with `ended_at` the claim, so the automatic removal counts from it; one
  switched off while it runs comes `disabled`. The `(server_id, remote_name)` unique key settles two claims at once; the
  report (`ShopReports::serviceClaimed()`, «خریدها») is written in the row's transaction. The customer is its owner
  as a transferred service's new one is ([a change of owner](#service-transfers): `newOwner()`, then `relabel()`).
- **A refund of the renewal that gave it its plan** (`ProvisioningService::undo()`, `gave_plan`: a claimed service on
  the order's plan, no renewal delivered after it standing — while one does, that one takes it over) takes the plan and
  «تمدید خودکار» back with the time and traffic; one made by hand without an end or a quota never ends, or is
  unlimited, again (`gave_term`, `gave_quota`), so nothing ends it and the automatic removal leaves it.
- **Its renewal** — `CustomerRenewal::picksPlan()`: no plan, no test, active or ended. `choices()` are the active plans
  whose entries list its server — not `ServerSelector::offers()`: a server full or switched off for sales still renews
  what is on it — and, in an agent's shop, that the agent's traffic covers; `preview($service, $planId)` takes one of
  them, `buildable()` says whether a package built may renew it instead (the builder offered,
  `CustomServices::offered()`), `pickable()` says either for a page with the plans and the builder read once (the
  website's `picks_plan`, beside `renewable` on the own plan), and `candidates()` lists such services. The renewal order
  carries the plan picked, and `ProvisioningService::renew()` writes the order's plan onto the service, which then
  renews on it; a renewal with a package carries none, so the service keeps picking and `AutoRenewal::renews()` (a plan
  needed) never takes it.

## The automatic removal

`ServiceCleanup` is the one rule of when the shop removes a service by itself, by two settings groups:
`ExpiredServicesSettings` (`expired_services`, off by default: a service that ended, so many hours after its end, 0 to
8760) and `TestAccountSettings` (a free test so many hours after it ended, or so many days after it was made while it
still waits for its first connection). A service is over by `Subscription::isOver()` — `over()` asks the database the
same: ended, gone from its panel, or active past its deadline before a sync said so; a switched-off one never is. The
hours count from `ended_at`, else that passed deadline, else the row's last change.

- `removesAt()` — when it goes by the rules now, or null: what the bot's service screen (`SubscriptionHandler`), the
  panels' rows (`SubscriptionDirectory`) and the Store API (`SubscriptionPresenter`) show as `removes_at`. Open orders
  are left out of it: the removal itself waits for them.
- `due()` — the current shop's services whose time came, at most 100 of each rule (the ended tests, the tests never
  connected, the ended services), the lowest id first, none with an order about it in `Order::OPEN` (pending, paid,
  processing, or failed and waiting for support's retry — the list that also keeps `AutoRenewal` from ordering a
  second renewal).
- `stillDue()` — `removesAt()` passed and still no open order, judged on the row as its panel has it now.

`Tasks\CleanupServicesTask` (every 15 minutes, right after the sync) is no `eachBot` task: it finds the shops with
services over, test services or unissued test claims across every bot (`Subscription::acrossShops()`) — an agent's bot
that stopped running too, since its services sit on the installation's panels — and works each in its own shop
(`CurrentBot::run()`), within the run's share of the scheduler's time. In each it deletes the test claims a dead
process left, then takes `due()` one by one through `ProvisioningService::deleteIf()`: the service held, read from its
panel again (unless already gone from it), judged by `stillDue()`, and only then deleted as `delete()` does — so a
renewal, an extension or a first connection since the sync is never lost. A server in backoff waits unless the service
is already gone from its panel; a busy service or a panel failure waits for a later run, logged; one that breaks stops
no other. Nobody is told. The orders and the `test_accounts` rows lose their link to the service and stay.

## The sync and the tasks

`SyncSubscriptionsTask` reads every running service from its panel every 15 minutes: one `listClients()` per server,
then `apply()` per row — counters, quota, term, link and the IP limit where the panel keeps one (`ClientInfo::$ipLimit`;
PasarGuard's null leaves the shop's), so what was changed by hand on the panel is what the next renewal, extra or
grant builds on. A client missing from the list is asked for on its own; only that answer marks a service
deleted, never a list that came back short. Every task that talks to panels skips servers in backoff and stops at its
share of the scheduler's time (`Core\Scheduling\Budget`); one service that breaks is logged and stops no other.

## Shops: the Bots module

Every bot has a shop of its own in the one database ([Architecture](Architecture.md)).
`CurrentBot` is whose shop the code works in — static and per process, so `run()` and `everywhere()` always put it
back. Models with `BelongsToBot` get a global scope on `bot_id` and fill it on create; `everywhere()` lifts the scope
for installation-wide work and then refuses a new row that does not say whose it is. Relations that must reach across
shops say so explicitly (`Subscription::user()`, `Server::subscriptions()`, `Bot::agent()`).
`Bots\Services\Bots` knows which bots run (`serving()`, read afresh each time) and which shops the scheduler goes
through (`shops()`, behind `Scheduling\BotShops` so Core names no module).

## Notifications

`CustomerNotifier` has a method per notice. **Each notice is composed inside the recipient's shop** (`tell()` runs in
`CurrentBot::run()` of the customer's shop): an agent's customer hears from the agent's bot, in the agent's wording,
with balances read in that shop. That is why every method hands `tell()` a closure instead of finished words — the
words and the values (a balance, support's note as a bot-text part) must be worked out inside that shop.

`Notices::deliver()` keeps every notice for the customer's website feed (`notifications`, half a year), linked to the
order, service or ticket it is about, then sends it: Telegram through `CustomerChats` (which remembers a customer who
blocked the bot and stops writing to them), else email when the customer has an address and the shop's email works.
Changes of how an account is signed in to go to Telegram **and** email (`everyDoor`), since whoever made the change may
control one of them. The answer is a `Delivery`, which the screens report where telling was the point (a payment
reminder). The same words everywhere: the bot text, rendered for the web and email by `Telegram\Texts\WebText`.

## Support tickets

`Support\Services\Tickets` is one service for every channel — the website, the bot, both panels, the website's admins
and the report group — so they keep the same rules. Every status change is one conditional update (`Transitions`), and
a message is written with the status change it causes in one transaction **under the ticket's row lock**, so a close
arriving at the same moment lands before or after it, never between. Reopening drops the rating. The report group
hears every change except what was written in it, inside the change's transaction. A customer's tries are counted
against their windows (tickets an hour, messages per ten minutes, and uploads in the shop's one picture budget shared
with receipts) only once the request passed its checks.

`TicketAttachments` keeps a message's picture: an upload judged by its bytes and kept until its ticket has been closed
30 days (then deleted, the message keeping its name), or a Telegram file id, which Telegram keeps.

## Reviews

`Reviews\Services\Reviews` takes a review from the website and moves it between pending, approved and rejected with
conditional updates. What a review may cost the shop is held: a guest's only while the website asks a captcha, at most
200 waiting, and per-window limits by address network (IPv6 by its /48), signed-in customer and all guests together.
The windows are counted before the captcha is judged: a refused token keeps its network's count but gives the guests'
count back, so a bot cannot spend it for every guest. These are the reviews' own windows, never the sign-ins', so a
review bot behind a carrier's shared address locks nobody out of signing in.

## Tests

Above the connectors, `TestCase::fakePanel()` registers a fake connector (`Tests\Fakes\FakePanelDriver`) whose client
`Tests\Fakes\FakeProvider` keeps one panel per server and can be told to fail, refuse, race a change or stop listing.
The connectors themselves are tested against `Tests\Support\FakePanel` on the outgoing HTTP client. `ServiceTermsTest`
holds the term arithmetic; `ServiceHoldTest`, `SubscriptionSyncTest` and `ServerGrantsTest` hold the concurrency rules
above. See [Testing](Testing.md).
