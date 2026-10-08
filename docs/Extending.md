# Extending

Where a new piece goes, and what comes with it. A new VPN panel, payment gateway, database, mail service or captcha is
a driver: [Drivers](Drivers.md). The rules every change keeps — code style, tests, the API contract, the Persian copy —
are in [Contributing](Contributing.md) and the engineers' handbook,
[`CLAUDE.md`](https://github.com/amir-aghajani/amo-bot/blob/main/CLAUDE.md).

## A panel screen and its API

Every shop feature is an API endpoint and a screen:

1. The controller in `app/Modules/Admin/Api` (it extends `ApiController`: load the row, call the domain service,
   present the answer — a refusal is the service's exception, answered by the error handler), and its route in
   `routes/api.php` — the shop's screens once, for both panels: its daily work in `$operations`, its configuration in
   `$configuration`; the owner's or an agent's own in that panel's group.
2. Its operation in `resources/api/openapi.yaml` — under `/api/{panel}/…` for both panels —, its request body (or
   `x-no-body: true`) and the schema of its answer, closed. The tests refuse a request or an answer it does not describe.
   Then `pnpm api:types` regenerates the panels' types, and `php scripts/docs.php` the API's reference and
   `docs/openapi.json` (CI fails while they are stale).
   - The shop's daily work is the website's admins' too: its operation says so — `x-staff: true`, or the grant the shop
     must give for it (`x-staff: refunds`, the route's `StaffGrant::ARGUMENT` naming the same); one that asks a sign-in
     of the last 15 minutes without a grant — approving a payment, which delivers on the admin's word alone — adds
     `x-staff-recent: true` (the route's `StaffMiddleware::RECENT_SIGN_IN`) — and `tests/Support/ApiDescription.php`
     gives it its path under `/api/store/v1/{store}/admin`; `ApiDescriptionTest` holds the markers to the routes,
     `RouteGuardsTest` walks the admins' routes. A decision takes the request's `Actor` —
     who decided, kept as the reviewer — and its rules hold whoever asks: an admin never decides about themselves
     ([the shop's admins](Store-API-Admins.md)). Configuration stays in `$configuration`, the panels' alone.
3. The React page — `resources/panel/src/pages` for both panels (each its own chunk, through `pages/lazy.ts`), or an
   app's own (`src/apps/<panel>/pages`) — built from the shared components, its route in each app's `app.tsx` and its
   entry in each app's `nav.ts`.
4. Its tests: an `HttpTestCase` for the endpoint — and a new list's reads in `ListQueriesTest`, which holds every list to
   a few queries however many rows it has; a route that takes a row's id gets its kind in `RouteGuardsTest`'s
   `ROW_KINDS`, which tries it with another shop's row — and a Vitest test beside the page.

A table the panels show joins the change feed — its area in `ChangeFeed::AREAS` and `ChangesResponse`, and the queries
it moves in `AREA_QUERIES` (`lib/use-live-updates.ts`) —, so open screens follow it.

## A bot command or screen

A `Handler` in `app/Modules/Telegram/Handlers`, mapped in `routes/bot.php` to its command, its keyboard label, its
callback prefix or its conversation state. Everything it says to a customer is a `BotText` the owner may reword: a case
of the enum, its entry in `TextCatalog` (its group, kind, title, where the customer meets it, its default wording and
variables), and the call through `BotTexts`. Then `php scripts/docs.php` brings the
[bot texts reference](Bot-Texts-Reference.md) up to date. A screen the main menu opens is an action of
`Keyboard\MainMenu`.

## A Store API endpoint

The controller in `app/Modules/Store/Api`, its route in the Store API's group of `routes/api.php` (public, a customer's
own behind their bearer token, or one that changes how an account is signed in to behind a recent sign-in), its
operation under `/api/store/v1/{store}` in `resources/api/openapi.yaml` — and the rule it applies lives in the domain
service the bot and the panels call too, never in the controller. Within v1 an answer only gains fields
([versioning](Store-API.md#versioning)). Then `php scripts/docs.php` brings the [reference](Store-API-Reference.md) up
to date, and the [Store API](Store-API.md) pages say how to use it.

## A setting

- One of the installation's own — the database, a token, the site's address — is a `config.php` setting: its entry in
  `Core\Config\ConfigKeys::SECTIONS` (its section, its default, the words the file says about it), its reading in
  `config/*.php`, and, when the owner's panel edits it, a field in its group in `Settings\Services\ConfigSettings` (a
  database's or a mail driver's own settings are its form: [Drivers](Drivers.md)). Then `php scripts/docs.php` brings
  the [config.php reference](Config-Reference.md) up to date.
- One of a shop's — every bot its own — is a field of a settings group (a `Core\Forms\Form`) a module declares
  (`DeclaresSettings`, with a typed getter for it), kept in the `settings` table: the module's settings class is
  registered on the bot's settings screen in `bootstrap/container.php` (`BotSettingsScreen`), its group's request in
  `resources/api/openapi.yaml`, and its card is a section of the panel's «تنظیمات ربات» (`components/bot/settings/`,
  on `useBotSettingsGroup`) — [Bot settings](Bot-Settings.md).

## A scheduled job

A class implementing `App\Core\Scheduling\Task`, added in `bootstrap/schedule.php` with its interval — `eachBot: true`
for a shop's work, run in every shop in turn. A task that works through a queue stops at its share of the run's time
(`Core\Scheduling\Budget`).

## A console command

A Symfony command with `#[AsCommand]`, listed in `app/Console/Kernel.php` — it is made only when it runs.

## A table

Edit `database/schema.php` and run `php bin/console db:rebuild` on your database — and, from the first release on, ship
the same change as an upgrade for the shops already installed: `database/upgrades/<version>.php`
([database/upgrades/README.md](https://github.com/amir-aghajani/amo-bot/blob/main/database/upgrades/README.md),
[Architecture](Architecture.md#data)). A column that points at a customer joins `AccountMerger::REFERENCES`, so a merge
carries it (a test fails until it does).
