# Internals: services

How the shop's services live on the VPN panels, and the pieces around them: the panel connectors, provisioning and
every later change of a service, grants, renewals and reminders, the shops of the agent bots, the notices a customer
gets, support tickets and reviews. What an owner sees of them is in [Servers](Servers.md), [Services](Services.md),
[Support tickets](Support-Tickets.md) and [Reviews](Reviews.md); how to add a panel connector is in
[Drivers](Drivers.md#a-vpn-panel-connector).

| Module | Holds |
|---|---|
| `app/Modules/Providers` | Servers, the connector contract, the 3x-ui and PasarGuard connectors, panel health |
| `app/Modules/Subscriptions` | Services, provisioning, the operations, grants, renewals, extras, reminders, the sync |
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
`UnsupportedOperationException`; the generic code branches only on `Capabilities` (inbounds, link rotation).

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
traffic ran out ends, one the panel extended runs again; support's switch and a deleted row stay.

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
decides itself (`ServiceTerms::columns()`: the term and the queued period; a renewal adds its plan and IP limit).

`provision()` makes **one client on every inbound** of the plan entry, so the customer has one link for all of them,
named after the customer by `ClientNaming` (`amir_3`, or `USER_17`; counters only go up, so a deleted service's name is
never reused; a name is unique across every bot's services on that server). A panel that answers without a link, or a
transaction that fails after the panel call, takes the client back off the panel.

## Operations from the screens

`SubscriptionActions` is the subscriptions screen's operations, for both panels and the website's admins: sync, extend,
switch off and on, move, delete. `allowed()` says what the state allows; the same rules then refuse a request made
anyway, worded by the state found. Who asks matters:

- a panel failure is worded for the reader (the owner the diagnosis, anyone else the summary);
- **leaving a client on its panel** (a delete or move that does not contact an unreachable panel) is the owner's call
  in the main shop; anyone else may do it only while that panel is in backoff — a client left on a working panel would
  be a second service nobody paid for;
- panel work from an agent's shop or the website's admins is rate-limited per shop (60 a minute); the owner in the main
  shop is not, so a batch never floods a panel every shop depends on;
- traffic added to an agent's customer's service comes out of the agent's pool (`TrafficPool::extending()`);
- a shop admin never changes their own service.

A move creates the new client first and deletes the old one second, so a failure at any step leaves the service where
it was (`MoveException` names the step; `errors.previous` is the screen's cue to offer leaving the old panel out).

## Renewals, extras and reminders

- **Renewal** (`CustomerRenewal`, the bot's and the website's): the service's own plan at today's price
  (`Orders\Services\Pricing`), delivered by `renew()`. The days left always carry; the traffic left carries only when
  the admin says so (`RenewalSettings`). Not carried, the renewal adds the plan's traffic at once and queues the end of
  the paid period (`period_ends_at`, `next_period_bytes`); `NextPeriodTask` caps what remains then.
- **Automatic renewal** (`AutoRenewal`, every 30 minutes): the customer's switch, renewed from the wallet within the
  admin's days of the deadline. The panel is read first (its deadline is the truth). The renewal order is made under a
  lock on the service row and only while no renewal of it is open, so two runs never charge twice; a short wallet is
  told once per window (`Transitions::claim()` on `renewal_notified_at`). A plan whose term is shorter than the window
  never renews itself: it would be due again at once.
- **Extras** (`CustomerExtras`, «حجم و زمان اضافه»): traffic or days bought for a service at the plan's or the shop's
  price list (`ExtrasSettings`). `problem()` is the one rule of what a service can take, asked at checkout and again at
  delivery against the panel's numbers.
- **Reminders** (`ServiceReminders`): a mark on the row (`expiry_reminded_at`, `traffic_reminded_at`) is claimed
  before a reminder goes and cleared once the service is back under its threshold, so each goes once per approach.

## Grants

`Grants` gives days and traffic to many services: a server's «افزودن زمان و حجم» or the [mass gift](Broadcasts.md#the-mass-gift).
A grant is split into one `GrantPart` per server, each worked through **one service at a time past a cursor** by
whoever holds the part's lease — the open screen a few seconds a request (`run()`, `runPart()`), or `GrantsTask` every
minute — so it finishes however many services there are, and an unreachable panel holds up only its own server
(`waiting_reason`). Each service is claimed (the cursor moved) before its panel is touched, so none is given twice; a
failure that may have reached the panel (a timeout) is counted failed, not retried. The database allows one running part per server (`running_server_id`, a
generated unique column); MySQL cannot cascade on such a column, so `ServerService::delete()` removes a server's parts
itself. Who gets it is decided per service at its turn, by the panel's word: running, not switched off, and — unless
ticked — not still waiting for the first connection.

## The sync and the tasks

`SyncSubscriptionsTask` reads every running service from its panel every 15 minutes: one `listClients()` per server,
then `apply()` per row. A client missing from the list is asked for on its own; only that answer marks a service
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
