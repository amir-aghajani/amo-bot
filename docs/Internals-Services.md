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
  and `choices()`: switched on for tests, able to take a client (`ServerSelector::testInbounds()` — `ServerReadiness`,
  then every inbound for sale), and in an agent's shop a pool holding the test's traffic.
- **Made like a sale.** `ProvisioningService::provisionTest()` goes through the same `create()` as `provision()` — one
  client on every inbound, the panel's link required, the client taken back if the row's transaction fails —, named by
  `ClientNaming` with `test_` before the customer's name (a move that must rename it keeps the prefix), with no IP
  limit and `TestTerms::expiry()`: from the first connection, or a deadline from now.
- **An agent's pool** is drawn in the transaction that writes the service row and issues the claim (the closure
  `issue()` hands `provisionTest()`): `TrafficPool::drawTest()` writes one `sale` line «اکانت تست · سرویس …», so a test
  that falls through draws nothing and nothing is given back. A pool short at that moment (`TrafficShortException`)
  rolls the row back, takes the client off the panel and is the no-server refusal; the bot's tap then redraws the
  screen.
- **What a test never gets** is decided where each feature picks its services: renewals and «تمدید خودکار» need a plan,
  `CustomerExtras::problem()` refuses it, and `ServiceReminders`, `Grants::services()` and `Rewards::takes()` leave
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
