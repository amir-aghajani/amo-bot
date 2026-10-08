# AmoBot — working notes for Claude

Open-source VPN config shop: PHP 8.2, Slim 4 + PHP-DI, Eloquent (MySQL — the database is a driver), Symfony Console, Telegram bot; two panels —
the owner's and an agent's — are one React project built to static files; a shop's own website talks to it through the
Store API (see Store API). PHP renders no HTML: everything it answers is JSON.
Must stay deployable on cPanel shared hosting (no build step for the user, no daemons required, the web installer).

## Commands

```bash
composer check                       # cs-fixer --dry-run + phpstan (level 6 and dead code, all the PHP) + phpunit  (run before finishing work)
composer fix                         # apply code style (PER-CS 2, strict types, ordered imports)
vendor/bin/phpunit                   # tests run on the SQLite driver, in memory (a config.php of their own, Tests\TestCase::CONFIG; random order, strict about deprecations/notices/output)
php bin/console list                 # CLI; add commands in app/Console/Kernel.php
php bin/console db:rebuild --force   # follow an edited database/schema.php, keeping the rows (stop the bot first)
php bin/console bot:poll --watch -v  # dev bot runner for every bot (main + agents'): supervisor + worker that reloads on code or config.php changes (--no-schedule / --once pass through)
php bin/console panel:probe 3x-ui <url> -t <token>   # or -u/-p (+ --totp), -k for a self-signed panel; any driver (pasarguard …): the add-server probe from a shell — status, inbounds, subscription server (dev tool)
php -d extension=intl scripts/timezones.php   # regenerate the Persian time-zone list (dev only)
composer serve                       # php -S 127.0.0.1:8080 -t public public/index.php (the API; /admin/*, /agent/* = the last build)
pnpm dev                             # both panels with HMR: http://127.0.0.1:5173/admin/ and /agent/ — /api goes to composer serve
pnpm build                           # production panels -> public/{admin,agent,assets} (git-ignored, shipped in releases)
pnpm api:types                       # regenerate resources/panel/src/lib/api-types.ts from resources/api/openapi.yaml
pnpm test                            # the panels' unit tests (Vitest + happy-dom: resources/panel/src/**/*.test.ts[x]); `pnpm exec vitest` watches
pnpm knip                            # the panels' files, exports, types and dependencies nothing uses (knip.jsonc; a test counts as a caller)
pnpm check                           # api-types current + typecheck + oxlint --deny-warnings + knip + prettier --check + the unit tests  (run before finishing frontend work)
pnpm format                          # prettier: import order + Tailwind class order (2-space, no semicolons) — the panels' sources, both index.html, vite.config.ts, scripts/*.mjs, knip.jsonc, .oxlintrc.json
php scripts/docs.php                 # write docs/'s pages generated from the code (Config-Reference.md, Bot-Texts-Reference.md) — after a change to what they read
php scripts/docs.php --check         # fail while a generated page is stale or a link, a page name or the sidebar is wrong (CI runs it; see Documentation)
php scripts/release.php              # the release zip: pnpm build + composer install --no-dev -> build/amobot-<version>.zip, build/release.json (+ .sig with RELEASE_SIGNING_KEY)
php -d extension=sodium scripts/release-key.php ~/amobot-release.key   # the release signing key pair: public -> resources/release-key.pub, secret -> the file (outside the repo; --rotate)
```
The panels' toolchain is pinned in package.json: Node 22.12 or later (`engines`, what Vitest needs) and pnpm as
`packageManager` names it (CI's pnpm/action-setup reads it from there — give it no `version` of its own).

**Two panels, one React project** (resources/panel, Vite + TS + Tailwind 4 + shadcn/ui), built to static files: the
owner's at `/admin/` (resources/panel/admin/index.html → `src/apps/admin/main.tsx`) and an agent's at `/agent/`
(resources/panel/agent/index.html → `src/apps/agent/main.tsx`) — by decision, two panels rather than one hiding things by
role. Each app owns its route table (`apps/<panel>/app.tsx`: route objects, `ADMIN_ROUTES`/`AGENT_ROUTES`, under
`AppReady` — /api/app answered — and `Installation` (components/app-status): before the shop is installed the owner's
panel has nothing but its installer, every other address going there, and once it is /install goes to the sign-in; an
agent's panel says the shop is not set up yet; the rest behind `RequireAuth` and `AppShell`, where an address no page has
is `NotFoundPage` (components/not-found — in the shell, with the way to the dashboard)), its navigation (`apps/<panel>/nav.ts`), its shell config
(`PanelShell`), its login and the pages only it has (`apps/<panel>/pages`: the owner's servers, server detail, agents, config
settings, installer, the login's recovery, and the dashboard and broadcasts pages with what only the owner has — the system card, «هدیه همگانی»
—, their parts in `apps/admin/{agency,settings,install}`; the agent's account); `src/pages` holds the shop's pages both have
(dashboard, the support tickets and a ticket's page, plans, categories, payment methods, users and a customer's page, orders,
payments, subscriptions, referrals, broadcasts, keyboards, bot texts, bot settings, website settings — each its own chunk through
`pages/lazy.ts`), `src/components` and
`src/lib` are shared, and
`src/root.tsx` (`mount(routes)`) is what both stand on: the theme, right-to-left Radix, react-query, tooltips, the
session, the toasts, the last error boundary, the fonts, and a data router (`createBrowserRouter` + `RouterProvider`, the
panel's folder its basename) whose root route holds the unsaved-changes guard over every navigation
(`<NavigationGuard />`, the router's blocker registered only while a draft is unsaved), the loading screen of a page
outside the shell (the sign-in, the installer) and, as its error
element (`RouteError`), the screen of a failure nothing under it caught — reported by the router's `onError` as the
boundaries report theirs (`reportFailure`). Tests render route elements in memory routers; the guard's own test drives
`createMemoryRouter` (and a browser router for a fragment the browser moves to itself). **No role checks in shared code, by
decision**: where the panels differ the app composes — `BroadcastsPage({more})` takes the owner's «هدیه همگانی» section,
`SubscriptionsPage({servers, serverPage})` reads the servers it narrows by and moves to the panel's way (the owner's
`/servers`, with each one's running services; by default the shop's `/plans/options` — components/subscriptions/
server-choices, one `serverChoices` key) and links a server to its page only where there is one, as `CustomerPage({servers,
serverPage, traffic, agentPage})` does for a customer's services — and links an agent's agency card to the owner's agents
list (`agentPage`), and `WebsiteSettingsPage({mailSettings})` sends email sign-up, while no email goes out, to the owner's
«تنظیمات پنل › ایمیل» (an agent's panel says the owner sets the email up); what depends on the
*shop* shown (an agent's plan needs traffic; the main bot's broadcasts reach its agents too) asks `useMainShop()` (lib/auth:
the session's shop is `MAIN_SHOP`, lib/config's). One `vite build` with both pages as inputs writes public/admin/index.html,
public/agent/index.html and the hashed files both pages share in public/assets — by decision: one module graph, so React,
the shell and the shop's pages are built and cached once; the agent's page never references the owner's chunks, and the
API is what keeps the panels apart. The build cleans only its three outputs (public/ also holds the front controller).
public/.htaccess sends any other `/admin/…` or `/agent/…` address to that panel's index.html and answers a missing
`assets/` file with a 404 (public/index.php does the same under `php -S`); `/` redirects to `/admin/`. Each page's first
script sets the page's `<base>` to its own folder read off `location.pathname` (the last `/admin` or `/agent` segment),
so one build works from any sub-folder install and any deep link; `lib/config` derives the router basename and the API
bases from `document.baseURI`. **The owner's tab keeps the shop it shows in its address**, by decision — never in the
session, which every tab of a browser shares: `/admin/…` is the main bot's shop, `/admin/s/<id>/…` an agent's (`/admin/s/1/…`
is put back to `/admin/…` in place). `lib/config` reads it once — `appConfig.shop` (null on an agent's panel: their
shop is their bot), `routerBasename` (the panel's folder and, in an agent's shop, its `/s/<id>`), `shopHome(id)` (a
shop's dashboard address), `MAIN_SHOP` —, and `lib/api` names it on every request of the owner's panel in the `X-Shop`
header; what the browser asks for itself — an `<img>`, a `<video>`, a download link — carries it in a `shop` query
parameter instead (`mediaUrl(path, query?)`, every media address). So a reload, a bookmark and a link opened in a new tab
are each in the shop they name, whatever another tab opened; opening another shop loads the panel afresh at its address
(`useOpenShop()`, see Agent bots), and an address that names a shop that is not there is `MissingShop`'s screen
(components/app-status — the server's 404 on `shop`, `shopMissing()` of lib/api —, with the way to the main shop). A
panel talks to PHP only through the JSON API (see API contract): its own `/api/admin/*` or `/api/agent/*` (session cookie
+ `X-Requested-With` CSRF header, + the owner's `X-Shop`), `/api/app` (the shop's name, whether it is installed, its
time zone — asked first, `lib/app-info`) and the owner's `/api/install/*` (the web installer, see Installation). Browser storage
keys are `amobot-panel-*` (theme, sidebar, folds, the stale-build reload), shared by both. components.json has
`"rtl": true`, so `pnpm dlx shadcn@latest add <name>` emits logical classes (ps-/ms-/start-) and icon flips by itself —
never hand-convert a ui/ component; reinstall it with `add -o` instead. Afterwards check the import is `@/lib/utils`,
not `cn`, and `pnpm remove cn` if the CLI added that stray package.
Every shop feature = API endpoint (app/Modules/Admin/Api, extends ApiController, tested via HttpTestCase) in
routes/api.php — the shop's daily work (`$operations`: both panels, and the website's admin API — see The shop's admins
on its website), its configuration (`$configuration`: both panels alone) or one panel's own group — + its operation, its
request body (or `x-no-body: true`) and schemas in resources/api/openapi.yaml (`/api/{panel}/…` for both, an operation of
the daily work marked `x-staff` — `true`, or the `StaffGrant` it asks; then `pnpm api:types`) +
React page (src/pages, or an app's own) +
route in each app's `app.tsx` + entry in each app's `nav.ts`. `components/shell/nav` is the shapes (`NavItem`,
`NavGroup`, `NavSection`, `NavArea`, `PanelNav` = `{menu, sections, settings}`), their readings (`areaFor(nav, path)`,
`pageTitle(nav, path)` — null at an address no page has, whose not-found page names itself —, `inSettings(nav, path)`,
`isSectionCurrent(sections, section, path)`, `useSection(sections)`) and
the entries and sections of the shop's pages
both panels have (`DASHBOARD`, `SUPPORT`, `SHOP_GROUP`, `SALES_GROUP`, `BOT_GROUP`, `SETTINGS`, `USER_SECTIONS`, `REFERRAL_SECTIONS`,
`BROADCAST_MESSAGES`, `BOT_SETTINGS_SECTIONS`/`BOT_SETTINGS`, `WEBSITE_SETTINGS_SECTIONS`/`WEBSITE_SETTINGS`); an app's nav builds its tree from them — one tree feeds
the sidebar, the phone topbar's title and the document's title. The panels follow the Claude Console's layout:
top-level pages داشبورد, پشتیبانی (the support tickets, `/tickets` — see Tickets), نظرات (the customers' reviews,
`/reviews` — see Reviews), then سرورها (the owner's) or حساب نمایندگی (an agent's); groups that fold open (icon + chevron,
their pages indented, text only, the fold remembered in localStorage, the group of the page on screen always open)
فروشگاه (plans, categories, payment methods — the catalogue), فروش (users, orders, payments, subscriptions, referrals and,
the owner's, agents — the ledger), ربات (keyboards, bot texts, broadcasts); and at the foot «تنظیمات». **A page made of
sections has a column of its own**, as the Console's settings do — by decision, instead of tabs: on its pages the
sidebar lists the page's sections where the menu was (`areaFor()` → a `NavArea`: groups, each a heading with its
sections indented), with «منوی اصلی» where the panel's picker sits — it shows the menu again without leaving the page,
until the next navigation. The areas: the settings (a group per page of them: `BOT_SETTINGS`, `WEBSITE_SETTINGS` — «تنظیمات وب‌سایت», every
panel's: /website-settings/connection, /website-settings/telegram, /website-settings/google, /website-settings/email (see
Store API), /website-settings/reviews («نظرات» — see Reviews), /website-settings/staff («مدیران سایت» — see The shop's
admins on its website) —, and «تنظیمات پنل» —
`panelSettings()`: the owner's `PANEL_SETTINGS_SECTIONS` — config.php's groups (the shop's email «ایمیل», /settings/mail,
among them — see Mail), their login «ورود به پنل», and
«ظاهر» —, an agent's «ظاهر» alone, `APPEARANCE_SECTION`), users (`USER_SECTIONS`: /users, /users/groups — a customer's page, /users/:id, is in their
column with «کاربران» current: `isSectionCurrent()` takes a subject's page under a page for the page's first section),
referrals (`REFERRAL_SECTIONS`: /referrals,
/referrals/invitees, /referrals/commissions) and, the owner's, agents (`AGENCY_SECTIONS`: /agents = requests,
/agents/list, /agents/levels, /agents/settings) and broadcasts (/broadcasts, /broadcasts/gifts — an agent's single
section makes no area). A `NavSection` is `{value, title, to}` — every section has its own address (a page's first one
is the page's own, the settings' all have a segment); the page reads the one on screen with `useSection(SECTIONS)`
(null for any other address under it: `Navigate` to the first), renders every section mounted and `hidden` but the
current one (so searches, pages and drafts survive), titles its `PageHeader` with the section (a program's paragraph
behind the header's `info` ⓘ), and puts `SectionPicker` (components/section-picker — the sections as a «بخش»
`FilterSelect`, phones only) under the header. The change of column is the panels' one entrance motion: the new level
slides 12px and fades in over 200ms (tw-animate-css `animate-in fade-in slide-in-from-end-3` going in,
`slide-in-from-start-3` going back — logical, so RTL mirrors itself; `motion-safe:`; nothing moves on the first paint),
and a press that swapped the column hands the focus to the new level's current item. A sectioned page's number to watch
rides beside its section (the owner's pending agency requests — the shell's `sectionBadge`); a menu entry's beside the
entry (`NavItem.badge`, a queue of `GET /queues`: «پرداخت‌ها» the receipts to review, «سفارش‌ها» the stuck orders, in both
panels, «پشتیبانی» the support tickets waiting on an answer, `open_tickets`, and «نظرات» the reviews waiting on
support, `pending_reviews` — components/shell/queue-badge: `useQueueCounts()`, read once by the menu, again when the
orders, payments, tickets or reviews move and every minute, since an order turns stuck with time alone; `QueueBadge` a `CountBadge` in the queue's tint;
where the count is out of sight — a folded group, the rail's icons — a dot of the most pressing one, `pressingQueue()`,
its words in the control's name). Status queues (orders, payments, subscriptions, bot texts) stay tabs.
The shell (components/shell) is hand-written, not shadcn's sidebar/sheet, and drawn for the panel an app hands it:
`AppShell({panel})` with a `PanelShell` — `nav`, `role` (the account row's word: «مالک فروشگاه», «نماینده»), and what only
that panel has: `picker` (the owner's `ShopSwitcher`, apps/admin), `sectionBadge`. A phone is a window below the
layout's `md`, 48rem: `lib/use-media-query`'s `PHONE`, in rem as the layout is. `ShellProvider` (the panel,
collapsed state — the admin's choice in localStorage, else a rail on a window below 64rem, a tablet's, following the
window until they choose —, Ctrl+B — the key by `event.code`, so a Persian layout's «ذ» is B too; not in a field,
where it is the text editor's bold, nor under a modal —, drawer state), `Sidebar` (a desktop's
only — a phone's column is the drawer's: one column a size, one menu, one account row —; 256px, the
lowest surface, brand wordmark — the name the panel goes by, `useShopName()` (lib/auth): signed in, the shop shown — an
agent's shop by its own name, the main bot's by the shop's (APP_NAME, /api/app's; «فروشگاه اصلی» is only the picker's word
for it) —, and APP_NAME before anyone signs in; `text-subtitle` semibold — + the column's toggle; the panel's picker under
it — the owner's shop picker (`ShopSwitcher`, apps/admin): in an agent's shop it is always there, naming the shop shown
whatever the list of shops does (while it is read, or when the read failed — said inside its menu, with «تلاش دوباره»),
so the way back never goes; in the main shop, once an agent has a shop; while a shop opens it is held (`aria-disabled`,
turning); the nav; the foot with the settings and `AccountRow` — who is signed in (the
mark, the name, the role) and «خروج از حساب», an icon button in the `danger-outline` look that signs out in one press:
no menu, by decision — the
theme (dark or light, there is no "system" theme) and the owner's login are settings, «تنظیمات پنل»; on the rail the
button alone; a group's toggle
points at its pages, there and `hidden` while it is shut (`aria-controls`). Collapsed it is an
icon rail: top-level pages as icons with tooltips, a group's pages as a flyout menu (`GroupFlyout`)), `MobileDrawer` on
a native `<dialog>` + `showModal()` (the same `SidebarBody`, focus trap/Esc/top layer for free, slide via
`@starting-style` in index.css — open, `translate: none`, not `0 0`, as the modal's `scale: none`; the menus opened in it —
the shop picker's — portal into it through `PortalContainerContext`, as a `Modal`'s do: portalled to the
body they would sit inert under the drawer; and while it is open it is the toasts' layer, `useModalLayer(…, {drawer:
true})` — the one layer that does not keep Ctrl+B, which shuts it), `Topbar` (phones only: the menu — its button
`aria-expanded`/`aria-controls` the drawer —, the page's name — `pageTitle()`, the not-found page's `NOT_FOUND_TITLE` at an
address no page has —, the theme; the
desktop has no topbar, as the Console). There is no search/command palette by decision — do not add one.
**Failures, one system** — no screen words or draws a failure its own way, by decision. `lib/api` keeps what a failed
request leaves, an `ApiError`: the status (0: no answer — the network, the browser offline, or the 90 s the panel waits;
such reads are retried twice, `isTransportError()`), the API's own words when it answered in its shape (`{message,
errors?, request_id}`), else a technical line (`facts.foreign`: a host's or a proxy's page, a 2xx that is not JSON),
the `facts` — the request's id (the body's `request_id`, else `X-Request-Id`), a 429's `Retry-After`, the request
(«GET /api/admin/servers», no query), APP_DEBUG's exception — and its moment (`at`); and it tells the session a 401 ended
it — but a sign-in's (`api.post(path, body, {signIn: true})`: the owner's password, an agent's link), which refuses the
attempt alone and leaves a session open meanwhile as it was. `lib/failure` is the one reading of any failure:
`describeFailure(error, ...fields)` → a `Failure` of a `FailureKind` — offline, unreachable (a proxy's 502 too: PHP behind
it not answering), timeout (a proxy's 504 too), unauthorized, forbidden (a host's firewall too), not-found, conflict,
invalid (a 422, any other 4xx), rate-limited, server (a 500), bad-gateway (the server's 502: a panel or Telegram out of
reach), unavailable, unreadable, stale-build, crash (the panel's own code) —, its words (`FAILURE_KINDS`: a title and a
description each, plain Persian, and whether asking again may help; the server's message wins where it is the admin's —
a refusal, a sign-in's answer, a panel out of reach —, the kind's words stand in for one missing or the server's generic
500), its code, the request's id and its moment; `messageOf(error, ...fields)` is a failure in one line (a form's, an
operation strip's, a confirmation's: the first message under `fields`, else the failure's), a failure of the server's own
ending «کد پیگیری: …»; `toastFailure(error, what?)` the same in a toast (the server's words as its title, or `what` /
the kind's title over the words; the code last); `failureFacts()`/`failureReport()` its technical details. `ErrorState`
(components/error-state) draws every failure, a variant per place: `screen` — outside the shell, on the sign-in pages'
dotted backdrop: /api/app or /auth/me without a usable answer (`AppReady`, `RequireAuth`), the root
route's `RouteError`, the root boundary, an agent's shop not set up yet —, `page` — in the shell: a page that failed to
draw, `NotFoundPage` —, `card` — the default: a read that failed, in place of what it would have shown, «X بارگذاری نشد.»
(a 404: «X پیدا نشد.» — then why, `notFoundReason()`: the server's words when they say it, a receipt Telegram no longer
has, never its bare «… پیدا نشد» a second time, which lib/failure knows as ErrorHandler's) with the reason; every list's
(`ListView`), card's, picker's and subject page's —, `inline` —
`FormError` (components/form-footer), the line of every form, operation strip and confirmation. Its ways on are the
kind's (`FAILURE_KINDS`' `retry` and `reload`, as its words say): «تلاش دوباره» where asking again may help (`onRetry`,
`retrying` while it runs; on a whole screen always), «بارگذاری دوباره» for a crash, a stale build or an unreadable answer
(on a card too), «ورود دوباره», «بازگشت», «رفتن به داشبورد» (a route
inside the router, a whole page above it); under a folded «جزئیات فنی» what support needs, the facts the failure has — the
status (a request's only), the code, the request's id, the request, the moment and its zone («منطقه زمانی» the shop's, or
«منطقه زمانی مرورگر» while /api/app has not said it), the address, the panel's build (`panelBuild` of lib/config: the
moment `vite build` made it, vite.config.ts `define`), the technical line — with «کپی جزئیات» (the moment in UTC too). A 429's wait
counts down (`useRetryWait()`): on the card's held «تلاش دوباره», on a form's line (`FormError`'s `failure` — `useForm`'s
`failure`, the agent's link page's), and the owner's sign-in holds its submit as long. `ErrorBoundary`
(components/error-boundary) is the last net around the app (root.tsx) — inside the router, its root route's `RouteError`
— and around the pages in `AppShell` — keyed on the page (`pageOf()`: the first segment, but one subject's page — a row's
number, /users/12, /servers/4 — is a page of its own, for the boundary and the unsaved-changes guard alike), so moving
between a page's sections keeps them mounted, and cleared by the next address (`resetKey`), so after a failure the
sidebar still leads anywhere — never a blank screen. **The panel's own failures reach the shop's log**
(`lib/error-reporting`, `startErrorReporting()` in `mount()`): what a boundary or the router caught (`reportCrash()`,
`render`, with React's component stack) and what no code handled — a press's error, a promise's rejection (the window's
`error`/`unhandledrejection`), a read's or a save's code that threw (`reportBug()` from lib/query-client's caches;
`useProbe` lets such a bug go on) — as `POST /client-errors`: the same failure once in 10 minutes, 5 a minute at most,
none while the server's 429 lasts; never the server's answers, the router's 404, an aborted request or the browser's
noise (a ResizeObserver loop, «Script error.»); the address as its path and its query's parameter names alone — no value
(a search holds a customer's name) and no fragment (an agent's sign-in code), which the server strips again
(`ClientErrors`). What no code
handled is told to the admin too, one toast a burst — «خطایی در پنل رخ داد» with «بارگذاری دوباره», a server's answer in
its own words. A tab opened before an upgrade asks for the old build's chunks, which are gone (the server answers 404,
never a page): `lib/stale-build` (`isStaleBuildError()`, `reloadForNewBuild()` — once, not again within 30 s,
`reloadedLately()`) reloads it, from `lib/lazy-page` (`lazyPage()`, every lazy page of both apps), Vite's
`vite:preloadError` (root.tsx: the reload it starts prevents Vite's error, and Vite then answers the import with nothing —
`lazyPage()` waits on such an answer for the reload, never drawing it as a failure), the boundary and the reporter; one the
reload did not help is said so — the build's files on the host are not whole — and told to the log. **Loading ahead**: every page is its own chunk (`lazyPage()` → a `LazyPage` with
`preload()`); lib/prefetch (`startPrefetching()` in `mount()`, by the route's element) starts loading the page the panel
opens on as the panel starts — beside its first questions to the server, not after them —, any other as its link is
about to be followed — the pointer resting on it `HOVER_MS`, the keyboard's focus, a press (every `<a>` of the panel) —,
and the page a sign-in leads to while its form waits (`preloadPage()`: both login pages). A page loaded by its turn is drawn at once — no skeleton, and none of the
300 ms React holds a fallback on screen —, else its skeleton until it is. A load ahead of need is quiet (`loadAhead()`):
its failure reloads nothing (`loadingAhead()` holds Vite's `vite:preloadError` back) — the drawing of the page reports
it. The server answers a panel's requests one at a time (one session — the live poll's alone lets go of it at once): the
shell's own reads, the menu's counts and the owner's shops, wait for the page drawn with it to ask first
(`useShellReads()`). **Offline**: react-query's onlineManager is the panel's word on the network —
offline, reads, writes and the live poll wait (paused, not failed) and go on once it is back —, and `ConnectionBanner`
(components/shell/connection-banner, `useOnline()`) is the shell's quiet strip over the page while the browser is
offline or the live poll's asks get no answer (`useLiveUpdates()`'s `unreachable`). `lib/auth` is the session both
panels share — `AuthProvider` (asks `GET /auth/me` of the panel's own API, which answers in the shop the tab names),
`useAuth()` (`session`, `enter()`, `renew()`, `logout()`), `useSession()` (`{name, shop}`, behind `RequireAuth`),
`useMainShop()`, `useShopName()`, `RequireAuth` (a shop the address names that is not there: `MissingShop`); how one
signs in is the panel's own (the owner's `useOwner()` in apps/admin/owner.ts — `login()`, `changeCredentials()`,
`recoveryKey()`, `recover()` —, an agent's link page), and goes through `enter()`, which drops everything the last session
read but the shop's name; a session the server renews in its shop (the owner's own login changed) goes through
`renew()`, which drops nothing. It tells signed-out (a
401) from unreachable (`failure` + `retry` — ErrorState's screen with «تلاش دوباره», not the login) and says when a session ended mid-work
(`expired` → the login page's notice; `from` keeps the query string too, and a sign-in at an agent's shop's address —
its `/s/<id>` under the router's basename — answers in that shop and goes back there). `lib/use-unsaved-guard`: `useUnsavedGuard(dirty)` — registered
by every edit surface's footer, `FormActions` (every form in a dialog) and `SaveFooter` (every settings card, the
keyboard editor) — asks before a reload or a tab close (the browser's own prompt: browsers allow no other there) and
before every navigation to another page — a link, a redirect, the browser's back and forward — through the router's
one blocker (`NavigationGuard`, drawn by the panels' root route; a router takes one — registered only while a draft is
unsaved: a router with a blocker warns of every move of the browser's own it cannot hold, an agent's sign-in link's
fragment) (a page's own sections, a fragment or a query of the same page keep their drafts, so they do not ask). Inside
the panel the question is the panel's own dialog, never `window.confirm`: `LeaveQuestion` (components/leave-question, a
`ConfirmModal` drawn once by the root route off `useLeaveQuestion()` — one question at a time, staying the default, over
a dialog it is asked from too). What drops every draft by its own doing — signing out, opening another shop
(`useOpenShop()`: the picker's, the agents list's «باز کردن فروشگاه») — asks before it acts (`await confirmLeave()`: null
to stay, else a release, so the action's own navigation asks nothing more, whose `keep()` gives the drafts their hold
back when the action failed); `useUnloadGuard(active)` asks before the tab closes alone (work the page carries on: a move
batch); each `Modal` keeps its own scope (`useUnsavedScope()`) and asks before its ✕/Esc/backdrop drops a draft of its
own — so does a form's «انصراف» (`useConfirmDiscard()` → `confirmDiscard(discard)`: `discard` at once with no draft of
the modal's own, else once the admin agreed) —; a dirty card behind it does not make it ask. `lib/query-client`: a read is fresh ten seconds and kept half an hour once no screen shows it
(`KEEP_UNUSED_MS`: a page opened again is drawn at once from what it last read, and read again behind it — the live poll
keeps it from being wrong meanwhile); none is read again for the window's focus, the live poll's own ask. Every write is a
mutation of the panels' cache — a form's save too
(`useForm`'s `submit()` runs its request as one, quiet, through `runMutation()`) —; its failure is a toast
(`toastFailure`) unless the screen shows it itself (`meta: {quiet: true}`) — a read's or a write's failure that is no
answer of the server's is the panel's own bug, reported (`reportBug`) —, and **what it changes elsewhere is named on it, one way:
`meta.invalidates`** — the reads it changes that its own screen does not write itself (an answer put into the cache —
`replace`, `upsert`, `setQueryData` — is no reason to read that again; the live poll catches the rest), read again once
it succeeds. The kits take it as their `invalidates`: `useOperations` (a dialog's: `CHANGES` beside each modal — an
order's, a payment's, a service's operations name the lists they move and the customer's page), `useRows` (a sortable
list's — its patch, reorder and delete; handed back as `list.invalidates` for its editor's form), `useSettingsGroup` (and
`useConfigGroup`/`useBotSettingsGroup`), `useGrants` (a grant's cancel, and its run — one mutation from start to end, whose
reach is named from its answer, `invalidates: (answer) => …`: nothing when its card left it unfinished), a form's
`submit(request, {invalidates})` (`BalanceAdjust`'s, `ExtendForm`'s, a row form's); the move batch is one mutation too. Where what a shared dialog's write changes depends on its screen (the customer's: the users table and the
customer's page each write their own copy), its host names it (`GroupsModal`, `WalletPanel`, `useAccountChange`). No
`invalidateQueries` after a success by hand, no `onError` toasts by hand; a refusal's re-read (`useOpenRow`'s `refresh`,
a list's own `refetch` on error) is a recovery, not this.
The live poll backs off (up to a minute) while the server does not answer; the grant runners stop on a refusal (4xx).
`document.title` is `lib/document-title`'s: the subject a page has open (`useTitleSubject()` — a server's name), where it
is (`useDocumentTitle()` — the shell's: the section unless it is the page's own name, then the page, `pageTitle()` —
nothing at an address no page has, where the not-found page's subject «صفحه پیدا نشد» names it —; the
sign-in pages', the installer's), then the name the panel goes by (`useShopName()`: the shop shown once signed in — two
tabs in two shops say which is which —, APP_NAME before). The build writes each page's
Content-Security-Policy into its index.html (vite.config.ts `production()`: only the build's files and the panel's own
origin, no frame at all (`frame-src 'none'`); the page's inline `<script>` allowed by its sha256, taken from the built page — keep every inline script in that
one block; styles may be inline, for Radix and the chart), each script's and stylesheet's brotli beside it (`x.js.br`,
the best level, once) and `assets/.htaccess` (the hashed files cached for good; a browser that reads brotli given the `.br` as it
is, its type set by name — `ForceType` for `.js.br`, `.css.br`, `.svg.br`: LiteSpeed, most Iranian hosts' server, types a
file by its last extension alone and sent the panels' scripts as `application/octet-stream`, which a browser never runs,
the panel stuck on its loading screen (0.1.0) —; tested on Apache with and without mod_brotli, mod_deflate, mod_headers,
mod_rewrite); each index.html sets
`theme-color` (lib/theme follows it), a `<noscript>`, and draws `AppLoading`'s markup in `#root` from its first paint.
The chunks (vite.config.ts `codeSplitting`, by how often they change and who needs them): `react` and `vendor` — the
libraries the first screen needs, changed only by a dependency, so a browser keeps them across upgrades —, `app` — both
panels' first-load code, the icons it draws —, the entry of each panel (its own code: src/apps/… never lands in a chunk
the other loads), `common` — what three pages or more share, loaded with the first of them —, and each page with what
fewer share. The fonts are not preloaded, by measure: their subsets load as the first text needs them (unicode-range,
`font-display: swap`), and a preload only delays the first paint behind them.
Overlays use `Modal` (native `<dialog>`, `.app-modal`: centred, its `size` — `SIZES`, sm/md/lg/xl — a width it may grow
to from the layout's `md` up; below `md`, a phone's, a sheet the screen's whole width rising from its bottom edge, its
actions by the thumb, and above the keyboard as it opens — both panels' viewport meta says
`interactive-widget=resizes-content`, so the page's viewport, and the sheet's height with it, give way to the keyboard,
its footer reached by scrolling inside it; what it showed stays on it through the fade-out, then its body
leaves, so the next opening starts afresh — an owner may let go of its row at once; while a request inside it runs, its
✕, Escape and backdrop wait — the control that runs it says so, `useHoldOpen(busy)`: `FormActions`, `ConfirmActions`,
`DetailFooter`, the move batch; and any other way out it shows waits as they do (`useHeld()`: a picker's step back);
the topmost open one is the toasts' layer — `useModalLayer`/`useTopModal` in
components/portal-container: the `Toaster` portals into it, above its backdrop, sonner's store replaying what is up as it
moves); `Modal` and `MobileDrawer` share `lib/use-native-dialog` (open/close sync; the initial focus on a
`[data-autofocus]` control, else the dialog's first field, else the browser's choice; the body's scroll locked, its
scrollbar keeping its room (`scrollbar-gutter: stable`) so nothing behind moves sideways; one question per Escape — a
second in a row, which the browser does not let refuse, asks in `onClose` alone; on close the focus goes back to what had
it as the dialog opened — a menu's item, gone with its menu, standing for the menu's trigger —, and when that is gone
meanwhile — a selection bar that emptied — `#main` takes it). While a modal
is open the body is inert and below the top layer, so ui/select, ui/dropdown-menu, ui/popover and ui/tooltip portal into
the dialog through `PortalContainerContext` (components/portal-container.tsx) — re-apply that one-line `container`
prop if you reinstall one of them with the shadcn CLI; likewise `SelectContent`'s defaults `position="popper"`,
`align="start"` (lists open under the field, not over it — by decision), never wider than the room from the field's start
edge to the window's (`--radix-select-content-available-width`: a long option wraps), and `SelectItem`'s `hint` (a line
under the option's words in secondary ink — a server's reason it cannot take a service —, wrapping in the list, out of
the option's name and of the field once picked). `.app-modal[open]` uses `scale: none` (not `1`) so the dialog
is not a containing block for those fixed-position layers; and a backdrop click is only a dismiss when the press
also started on the backdrop (a press on a control that ends on a portalled layer reports the dialog as target).
Reusable pieces — a screen is built from these, never beside them: `Page` (components/page — the column every page
lives in: `width` wide (lists, the default) / default (pages of cards) / narrow (settings) / dashboard) + `PageHeader`
(the title — 22px, medium —, an optional `count` chip and `info` ⓘ (`InfoTip`), the description under it, the `actions`
at the row's end; `voice` sets the overview's greeting at the display size); `InfoTip`/`InfoBadge` (components/info-tip —
what the panel explains behind a press, on ui/popover: a tap, a click or Enter opens it beside what it explains, Escape or
a press elsewhere closes it, the focus going in and back; the ⓘ beside a title or a label (24px either way), or a badge
whose words say more — a plan's server that cannot sell and why, an inactive category, the bot admin's role, a report
topic not made yet; never a hover or a `title` alone for information, which a phone cannot reach); `SectionedPage` (a page made of sections:
the one on screen read off the address, every section mounted and `hidden` but that one, each section's header actions
through `SectionActions`, the phone's `SectionPicker`; `useSectionShown()` — false in a hidden section, true outside a
sectioned page — keeps a hidden section's lists from following the shop: `usePagedList` and `useRows` read with
`subscribed: shown`, so the live updates pass them by and they read afresh once shown); `ListView` (components/list-view —
a list in exactly one state: the read's failure with «تلاش دوباره» (`ErrorState` — asked again, the failure stays, its
button turning, `retrying`, the focus on it, until the read answers: never the loading look in its place, nor the rows
of the view before that react-query keeps as a paged list's placeholder meanwhile — they are not this view's), pending, empty
(`empty`; `noMatch` when a search, a filter or a status tab found nothing — a page says an empty tab in its own words,
«…در این وضعیت نیست», and «با این جستجو یا فیلتر…» only while a search or a filter narrows it, `usePagedList`'s
`narrowed`) or rows; it takes the `useRows`/`usePagedList` result itself (`list`), names its
table after its `noun` for assistive tech (ui/table's `TableName`), dims a page while the next one loads, says nothing (the
loading look) of a page whose last rows were deleted while the list has rows elsewhere, and draws its `Pagination` (the
count; first, previous, «صفحه [x] از y» — a page's number typed, Persian digits too, goes there on Enter or as the field
is left, held to the list's pages, the field keeping the focus as the page turns; Escape puts the page back —, next, last;
numbered page buttons left out by decision; a button with nowhere to go, or waiting for the next page, keeps the focus —
aria-disabled; on a phone every control is 44px, a finger's size); `RowTitleButton`/`RowTitleLink`, `RowMenu` (the ⋮ row menu), `Dash` (the «—» of an empty
cell) and `SortableHead` beside it — a paged list's column header that sorts it on the server (`list` the
`usePagedList` result, `by` the API's key, `first` the direction of a first press — `desc` unless the column reads
soonest first, an end —, `label` when the header's words do not say what it sorts by): a press reads the list by the
column, the next turns it around, the one after gives the list its own order back (the way back on a phone, where most
columns are hidden; the own order's column just turns around); `aria-sort` on the th, a real button named «مرتب‌سازی
بر اساس …», lucide `ArrowUp`/`ArrowDown` when active and a faint `ArrowUpDown` in the same place otherwise; never on an
admin-ordered list); the sortable-list kit (components/sortable-list — `OrderHead`/`OrderCell` (the ▲▼ of an admin-ordered
row, `OrderControls`: side by side, 24px each; while a move runs every arrow holds — `aria-disabled`, keeping the focus —,
and the arrow pressed keeps the focus as its row moves, the other one once the row reached an end), `ActionsHead`,
`EditMenu` (⋮ «ویرایش / حذف»), `RemoveConfirm` — a row's delete asked first, cancel with the focus; a row that already
says it cannot go (`kept`: agents on a level, payments made with a method) is offered no delete, only why and «بستن»; a
refusal anyway is said in the dialog, which stays — the list's delete is quiet, no toast besides) with `useEditor`/`EditorModal`
(components/row-editor — a row's add/edit modal, its form keyed on the row; opened on no row it says its form makes
something new, `Creating`, below); `DetailModal` + `DetailFooter` (a row's
detail modal, which follows its row through `lib/use-open-row` — `useOpenRow({queryKey, read, list})`, `apply(row)`,
`refresh()` (a refusal because the row moved on — the operations' `onStale` — reads the row and its list again), `gone`
(the row's own read answered 404: deleted meanwhile — the dialog keeps what it showed under «این مورد دیگر وجود ندارد»,
every operation withdrawn, `useSubjectGone()`) —, with
the customer's `TelegramChatLink` and «بستن» at its foot, what the dialog adds before «بستن» — the payments' «بعدی», `lib/use-next-row`
— its children) and `CustomerFacts` (components/user-identity); `ApiPicture` (components/api-picture — a picture the
panel's API serves, a payment's receipt or a ticket message's, in a dialog's column or as a thumbnail: drawn by the
browser from its `mediaUrl()`, opening at full size in a `Modal` with its download; one the browser cannot draw is asked
about through `api.probe()` — the file, there to download, or the server's word on why there is none: gone, said so;
out of reach, with «تلاش دوباره»); `Reviewer` (components/reviewer — a row's `reviewer`: an
identifier set apart LTR, «پشتیبانی» as the Persian word it is); `components/operations`
(`useOperations({specs, allowed, perform, …})` + `OperationsSection`: the operations a subject's `actions` allow, a second
look on a strip for a worded or destructive one, withdrawn with a word when the row moved on meanwhile; one that asks more
than a note (its spec's `form`) gets the dialog's own form on its strip — `OperationsSection`'s `form`, which runs it and
closes the strip; while one runs every button is held — `aria-disabled`, the one pressed keeping the focus, turning —; a
refusal is said in the API's words, one that says the row moved on (a 422 on its state) being the dialog's one word on it
— said even when the row read again leaves nothing to do, never beside the withdrawal's —, a refusal of what the strip
sent on the strip; and once a run, a strip or an operation is over and the control that had the focus went with it, the
focus goes to the operation chosen — or the first one the new state offers: an approval whose delivery failed, the
retry — or to that word, never the document; the orders, payments and subscriptions modals are built on it); `ConfirmModal` (every
"are you sure" — cancel gets focus; `note` for a decision's note, `error` for its refusal); `NoteInput` (components/
note-input — every note a customer reads: a decision's, on a `ConfirmModal` or an operation's strip (`NoteField`), an
extension's, a grant's reason; held to the server's limit for a note — `Input::NOTE_MAX` (`NOTE_MAX`, 300), or the one
its `max` names (a refund's, `LEDGER_NOTE_MAX`: its wallet line's; a ticket's answer, `Tickets::BODY_MAX`) — with
`maxLength` and «۱۲ از ۳۰۰ کاراکتر» under it, so a long note is caught as it is typed, never refused
after it is sent; `NoteLine` the same in one line: a ledger's line set right by hand, `Ledger::NOTE_MAX`
(`LEDGER_NOTE_MAX`, 190), `BalanceAdjust`'s); `FormActions` (the
cancel/submit row of every form in a dialog, its `error` said above it, and «انصراف» asking first while the dialog has a
draft; **a revert only on an edit**, by decision: a form that edits what is saved (a row, a text, a customer's groups)
hands `onRevert`, and while nothing changed its revert and its submit are both held (`aria-disabled`, the one just
pressed keeping the focus); a form that makes something new hands none — or its dialog says it is new, `Creating` (the
context `EditorModal` sets opened on no row, `PickerModal`'s form step, the keyboard's dialog for a new button): there is
nothing to go back to, so its row offers no revert whatever it was handed, and the forms that only ever create (a
grant, the mass gift, `BalanceAdjust`, a service's extension) pass none; `disabled` holds the submit alone — nothing to
submit yet, a grant that would reach no service —, «انصراف» and the revert left free)
and `SaveFooter` (the same footer for a card that edits in place — its save and revert held, not disabled, while there
is nothing to save or revert, so the one just pressed keeps the focus); both, as a submit ends refused field by field (a
422), put the focus on the first field the form marks invalid (`aria-invalid`, which `Field` sets — or a group of controls
that is one field of the form, `data-invalid`, focusable: a plan's servers) on screen — however far
above, in whichever section, or inside a closed `Disclosure`, which opens for it and is waited for — and say beside the
buttons how many are left («۲ فیلد نیاز به اصلاح دارد»), counted as the
form shows them, so the word follows each fix; `SectionCard` (a card that is the form of one settings
group, on `lib/use-settings-group` — components/bot/settings' `useBotSettingsGroup`, apps/admin/settings'
`useConfigGroup` for the two kinds, the website's `useWebsiteGroup`); `Field` (label + hint +
error; it injects `id`/`aria-invalid`/`aria-describedby` into its single child control, or hands them to a render function
`(control) => …` for a select or an input beside a button — callers never repeat them); `SwitchRow` (an on/off setting as
one row, its hint and error told to the switch; `bordered={false}` inside a form); `SecretField`/`SecretInput` (a secret
with reveal; blank keeps the stored one; `autoComplete="new-password"` by default — a stored secret is no login of the
browser's, and a password manager, which ignores `off` on a password field, would fill the panel's own login into it); `Callout` (`tone` success/warning/danger/info — every strip of feedback that
is no failure: a test that passed, a domain fact such as a probe's verdict or a panel's last error, `ProgramOff` a
program switched off; a failure is `ErrorState`'s, above); `PageTabs` (the one tab/switch control — sliding underline, measured from the DOM, arrow keys,
the focus ring drawn in the row's own padding, since the row clips what reaches past it): as
tabs (`id` + `tabPanel(id, value)` on each view's panel, the others kept mounted-but-hidden so drafts survive) for a
status queue or a form's sections, `as="choice"` — a radio group — for a value picked in a form or a card (a mode, a
metric, a keyboard's type); `size="sm"` + `w-fit` for those inline pickers; a page's own sections are never tabs, the
sidebar lists them; a row wider than its place (a phone) scrolls sideways with no bar, the selected one kept in sight,
and fades the end whose tabs are out of sight (a mask, measured as it scrolls; none while all are in sight), so a tab
cut there reads as more to see, not a broken word;
there is no segmented control or ToggleGroup by decision; `statusTabs(vocabulary, order, counts)` (a status queue's tabs
in its vocabulary's words, a `CountBadge` beside the one that waits on a human); `FilterSelect` (a list's filter as the
Console's pill «برچسب  مقدار ⌄», '' = «همه», set apart by a rule — its face `FilterPillTrigger`, named by both its words,
«وضعیت همه», since a combobox takes no name from what it holds; the toolbar of a list is `SearchBox` + filter pills);
`DateFilter` (components/date-filter — a list's «تاریخ» pill on that face: «همه», the presets — امروز، دیروز، ۷ و ۳۰ روز
اخیر، این ماه، ماه قبل in Jalali months, counted from the shop's today (`today()` of lib/jalali: the day it is in the
shop's zone, lib/format's, whatever the browser's clock says — the days the server bounds `from`/`to` by) — and «بازه
دلخواه…», which opens `DateRangePicker` (components/date-range-picker:
a month of the Persian calendar in a small `Modal`, Persian digits, the week from Saturday, the shop's today marked, two
presses a range, the keys of a grid — arrows right to left, Home/End the week, PageUp/PageDown the month —, Escape to
leave; it opens once the pill's list has closed and handed the pill the focus — `onCloseAutoFocus` —, with the focus on
the range's last day or today (`data-autofocus`), and gives it back to the pill as it closes; a phone's day is 44px tall,
as wide as its column allows); it
holds the days themselves, `from`/`to` (the API's `Day`, Gregorian "YYYY-MM-DD"), as two of the list's `params`, and
says in its choices which date a row is filtered by («بر اساس زمان ثبت سفارش»); the calendar is `lib/jalali` on Intl's
own `persian` calendar, no date library) with `ListTotal` (the list's takings above it in one quiet line, «۱۲ سفارش
فروخته‌شده · جمع …»); `CustomerFilter` (components/customer-filter — a list narrowed to one customer says so in that look:
«مشتری  name ✕» — «معرف» on the invitees —, the name the customer's page's own read (`customerQuery`), leading back to
it, the ✕ letting go of the filter, one of the list's `params`; `customerList(path, id)` is the link that narrows a list
so — `?user=`, the invitees' `?referrer=`);
`PickerModal` (components/picker-card — the two-step "pick a driver, then its form" modal behind add-server and
add-method, its options a query of the family's `DriverDescription`s, each card drawn from what the driver says of
itself — a connector's `mark`, `vendor` and `docs_url` traits, a gateway's `kind`; a card that cannot be picked says why,
`disabledReason`: a built-in gateway, «داخلی؛ همیشه هست»; its form step is `Creating`, and its back arrow to the
picker asks first when the form holds a draft — `useConfirmDiscard()` — and waits while a request inside runs,
`useHeld()`); `DriverFields` and the rest of components/driver-form (any
driver's form drawn from its description — see Drivers); `Disclosure` (the
"تنظیمات پیشرفته" toggle: its part folded but kept in the page, so what was typed there survives; a field in it the form
marks invalid — any form's refusal — opens it, one place for every form); `EmptyState` (`framed` for a list with nothing to list — the Console's empty box); `StatCard`
in a `StatGrid` (components/stat-card — a headline figure: label with ⓘ, the number and its unit, its change or a hint;
a skeleton while its read runs (`loading`: the query's `isPending`), «—» when `value` is undefined — the read failed, or
there is no such figure — with no comparison; four, two or one across by the page's column, a container query, since
the sidebar takes its share of the window — a grid by the window would cut a figure such as «… تومان بدهی»: every
page's figures stand in one, the dashboard's, the referrals' and the agency's, an agent's account page's). **One rule for a card whose read failed with nothing to show**: `ErrorState`
(with «تلاش دوباره») or «—» — never a skeleton that pulses for ever, a zero it does not know, nor an "all clear"
(the dashboard, the referrals' and the agency's numbers, an agent's account, the report topics);
components/grants (`GrantCard`, `GrantItem`, `GrantTermsFields`, `useGrants` — a server's grants and the mass gift: the
list, the form — one that would reach no service (`reachOf()`) says so and holds its submit — and the runner that works
a grant while the card is open; `GrantAmountFields` and `GrantOption`, the
fields every form that gives days and traffic is made of — the grants' and one service's extension); `BalanceAdjust` + `LedgerList` + `Balance` (a
wallet or an agent's traffic: the adjust form — `whole` for a wallet's Toman, a whole number alone (`wholeOf()`), else up
to two decimals, an agent's GB (`decimalOf()`) —, the ledger's lines, a balance with its debt worded as the bot words it);
`PublicScreen`/`PublicPanel` (the sign-in pages and the installer); `ProgressBar`, `TrendChip` (percent change),
`TextLink` (the blue underlined in-app link; `searchLink(path, id)` opens another screen on `?search=#id`; a link out of
the panel built from data — a channel's, a bot's, a customer's chat, a connector's documents — goes through
`externalHref()`: https, http and tg addresses only, anything else no link), `BackLink`
(components/back-link — a subject's page's way back to its list, above its title: a server's, a customer's), `Kbd`,
`IconButton` (an icon-only control: the `quiet` Button, its label required), `Switch` (blue while on; `held` —
`aria-disabled`, keeping the focus, taking no press — while a change runs in its list), `StatusBadge` +
`StatusDot` (a state as a tinted chip with a dot, or a dot beside its words — the vocabularies of `lib/statuses`: one
`Tone`, a typed dictionary per domain, `serverStatus()`), `components/servers/server-status` (`ServerStatus` — with
`details`, a list's, the panel's last failure behind an ⓘ —, `UnsellableNote` — the server's own reason it cannot sell
now, wrapping), `components/user-identity` (`UserIdentity` — with `to`,
the name is the link to the customer's page —, `InitialsMark` (a handle's first letter, never its «@»: `initials()`),
`userLabel()` — the one line in a plain string, isolated — and `UserLabel` — the same in markup, in a `<bdi>` —,
`distinctUserLabel()` — the name with the handle or the Telegram id (a website customer's email), what a row's ⋮ is named
by, two customers of one name being two menus —, `TelegramChatLink` — the one way to a chat with the customer in
Telegram, «گفتگو در تلگرام» wherever it is offered (a dialog's foot, their page, a row's menu — the place's look through
`asChild`), nothing for a customer without a Telegram account —, `userPage(id)`), `FactList`/`Fact` (a modal's label/value facts), `lib/clipboard`
(`copyText()` with its toast), `lib/direction` (`isRtl()`, `contentSide()`, and a Latin run in a plain string:
`isolate()`, `handleLabel()`, `idLabel()`, and `isolateMarkup()` — every tag, entity, variable or attribute of a
sentence set apart, so «</b>» does not read «<b/>» — see Conventions), `lib/queries` (the reads more than one screen makes, each defined
once as `queryOptions`), `lib/storage` (every browser-storage key, read and written inside its try/catch). The ui/
primitives are restyled to the Console's and carry the sizes themselves — do not repeat paddings and text sizes on every
widget: `Button` (`default` = the one inverted primary — black on light, near-white on dark —, `secondary` the everyday
button, `ghost`, `quiet` (an icon alone), `destructive` solid red, `danger` a red label, `danger-outline` the same with a
red hairline, `link`; sizes sm/default (32px)/lg/icon/icon-sm; `icon` draws a lucide icon before the label and `busy`
turns it into a spinner while the button waits — held, as one its owner marks `aria-disabled` is: it keeps the focus (the
submit just pressed, «صفحه آخر» — `disabled` would drop the keyboard on the document) and swallows a press, a submit's
included; **a Button is `type="button"` unless it says `type="submit"`** — a form's
submit is `FormActions`/`SaveFooter`/an explicit submit), `Badge` (20px, 5px corners: neutral/info/success/warning/
danger/outline), `Card` (12px corners, hairline, `CardHeader` > `CardHeading` > `CardTitle` (an h2, under the page's h1) + `CardDescription`, an
optional `CardAction` at the header's end, `CardContent`, `CardFooter`), `Table` (frameless on the page: a header of
secondary text over one hairline, no row rules, a rounded hover fill reaching past the column edges (a pseudo-element on
the row), first and last cells without outer padding so the text lines up with the title; inside a card it still needs
no box of its own; by rule a cell keeps to one line — identifiers, numbers, amounts, dates, badges, the row's controls —
while a name (`RowTitleButton`/`RowTitleLink`) and free text (a note, a description, in a measure of its own) wrap, or are
cut short with the whole a press away, never in a `title`; and its last column — the row's controls, ⋮ or «جزئیات» —
stays in sight however wide the table: when it scrolls sideways that column sticks to the scroll's end (index.css, over
`--table-surface`, the surface the table sits on — the page's, a card's inside a `Card`), lit with its row),
`Input`/`Textarea`/`SelectTrigger` (one `fieldClasses` look: 32px, 8px corners, the focus ring).
shadcn primitives in ui/: badge button card checkbox dropdown-menu input label popover select skeleton sonner table
textarea tooltip, each exporting what the panel uses — re-add others with the CLI when a page needs them, then restyle
(index.css maps only the colour names the panel's classes use, none of the CLI's `accent`, `secondary`, `muted`,
`input`, `ring`: a fresh component's `accent` becomes `fill-hover`, `secondary` and `muted` `fill`, `input`
`border-strong`, `ring` the `focus-ring` utility; popover was taken from `shadcn add popover --dry-run --view`: the
CLI's own `pnpm add` would move radix-ui for every primitive and bring the stray `cn` package; checkbox carries two
local changes: a dash for `checked="indeterminate"`, the select-all over a partial selection, and its dark background transparent only
while unchecked — the CLI's `dark:bg-transparent` beats the checked fill and leaves a dark tick on a dark box; keep both
if you reinstall it). `cn()`
(lib/utils) runs a tailwind-merge that knows the type scale (`text-caption`…`text-stat` are sizes, not colours) — a new
size name goes there too, or a size and a colour on one element cancel. Tick boxes are always that `Checkbox`, never a
bare `<input type="checkbox">`. Dropdowns are always shadcn `Select` (never a bare `<select>`); the time-zone picker's
options come from `App\Support\Timezones` (Windows-style groups with Persian names, generated into
app/Support/data/timezones.php by `php -d extension=intl scripts/timezones.php` — the app itself needs no intl).
The overview's chart is plain SVG, by decision — no chart library, which would weigh half as much again as the rest of
the first screen:
`components/dashboard/area-chart` (`AreaChart`: one series over time, as wide as its column, time left to right in a
`dir="ltr"` frame; the figure axis in five rounded steps from zero, `valueTicks()` — none while nothing is above zero:
the baseline alone, no scale of figures it does not have —, as wide as its widest label needs (measured in the axis'
type), so «۱۲۰ میلیون» is never cut at the chart's edge; as many days as fit, the last one always, `dayTicks()`; the
labels Persian text read right to left in their places (a `<g direction="rtl">`, a figure anchored at its `start` — its
right edge at the tick —, a day centred; measured in a browser: inherited LTR, «۱۹ شهریور» read «شهریور ۱۹»); a
monotone curve that never swings past a day's figure, `monotonePath()`; a day's figure under the hand, or the
keyboard's — focus, ←/→, Enter — in a box beside it, right to left). The overview's dashboard words the figures on
screen by their own range (`range.days` of the answer) while another range's are read. A heavy widget a page needs only now and then stays behind a dynamic `import()`: the Lottie
player (lottie-web's light build, for animated premium emoji) in `components/bot-texts/premium-emoji`.
Design: the Claude Console's design language, by decision — near-neutral surfaces stepped a few percent apart
(`--surface-0` the sidebar, `-1` the page, `-2` cards, `-3` popovers and dialogs), hairlines and fills as alpha over
what is beneath (`--line`/`-strong`/`-stronger`, `--fill`/`-hover`/`-active`), text in three inks (`--fg` primary,
`--fg-2` secondary = `text-muted-foreground`, `--fg-3` tertiary = `text-faint` — each at least 4.5:1 on every surface of
its theme, the tertiary carrying real 12–13px words), one inverted primary, blue only for
links, focus and information (`--focus`, `text-link`) and for what is on or chosen (`selected` — a switch, a picked card,
a bar's progress: `bg-selected`/`border-selected`/`text-selected`, never `ring`, which is the focus), status families
green/amber/red each as text + soft background + line (`text-success`/`bg-success-soft`/`border-success-line`, …
`info`); no gradients/glows/entrance animations — the sidebar's slide between its levels is the one, the Console's. The
type scale is the Console's names with Persian line heights: `text-caption` 12, `text-footnote` 13, `text-body` 14 (the
base), `text-heading` 15, `text-subtitle` 17 (a dialog's or a public panel's title), `text-title` 22 (page titles),
`text-stat` 28 (stat figures), `text-display` 24 (the voiced titles: the overview's greeting, the sign-in pages' and the
installer's — as large to the eye as the 32px serif they were once set in, measured) — no size outside it; focus is
the `focus-ring` utility (buttons, links) or `focus-field` (fields). Every page = `Page` + `PageHeader` + content. Tokens
live in resources/panel/src/index.css (one value per theme on `:root`/`.dark`, mapped to Tailwind names in `@theme
inline`; the chart's colour --chart-1, --sidebar-width/--topbar-height geometry, and Telegram's own button
colours `--tg-primary/--tg-success/--tg-danger` on `:root` — the keyboard preview and the editor's swatches read them);
dark + light via the class on <html> (lib/theme.tsx). Radix `side` is physical: things that open away from the sidebar
use `contentSide()` (lib/direction).
Vite binds 127.0.0.1 (Node maps localhost to ::1, where the PHP dev server does not listen) and serves both panels from
the root (dev `base` `/`; built, it is `./`): vite.config.ts `panelPages()` sends any extension-less `/admin/…` or
`/agent/…` address to that panel's page and `/` to `/admin/`, and `/api` goes to 127.0.0.1:8080. Edit against
http://127.0.0.1:5173/admin/ or /agent/ (HMR); http://127.0.0.1:8080/admin/ and /agent/ are the last `pnpm build`, so an
edit shows there only after a build. After `pnpm add/remove` restart `pnpm dev` (`netstat -ano | grep 5173` finds a
leftover process).
Browser dev-server config lives in .claude/launch.json ("web" = PHP on :8080, "admin-dev" = Vite on :5173); PHP is at
C:/xampp/php/php.exe (not on PATH).

Local MySQL is XAMPP (`C:/xampp/mysql/bin/mysqld.exe --defaults-file=C:/xampp/mysql/bin/my.ini --standalone`), DB `amobot`, user root, no password.

## Architecture in one paragraph

`bootstrap/app.php` boots `App\Core\Application` (reads `config.php` — the shop's own configuration, see
Configuration; the test suite boots on a pinned one of its own, `TestCase::CONFIG` — into `config/*.php`'s parts, builds
the PHP-DI container from `bootstrap/container.php`, boots
Eloquent on the database the driver `DB_CONNECTION` names (`Core\Database\DatabaseManager`, see Database drivers), and
starts the change feed). Every bot — the main one and each agent's — has a shop of its own in the one
database; `Bots\CurrentBot` says whose shop the code is working in, and a shop's rows are held to it (see Agent bots).
`->http()` builds the Slim app (`App\Core\Http\HttpKernel`: routes from `routes/{web,api,webhooks}.php`, the global
middleware — the request's id, security headers, CORS (the shops' websites alone, see Store API), stray output kept out,
errors, routing, body parsing —, `Http\ErrorHandler` answering every failure as JSON; the
prefix requests arrive under comes from `Http\BasePath::detect()` and is the container's `http.base_path`). What a
request needs besides is its route group's middleware: the installation (`InstalledMiddleware`, `installer.open`), the CSRF
header, the session, a panel's sign-in, the shop it works in — or a website's store key and its customer's bearer token —
so the router decides what a request is, and no spelling
of an address escapes its guards (tests/Feature/RouteGuardsTest walks every route). Who works a shop is a **principal**
(`Auth\Principal`: its `PrincipalKind` — the owner on their panel, an agent on theirs, one of a shop's admins on its
website —, its `name`, the shop it works in, an admin's account), which its guard (`Auth\PanelAuthMiddleware`,
`Store\Http\StaffMiddleware`) puts on the request (`Principal::of()`) and makes the request's reader
(`Auth\CurrentPrincipal`: what an answer shows by who reads it) and the log's actor; who decided what a request decides
is an **`Auth\Actor`** (`Principal::actor()`; a report-group admin's `Actor::groupAdmin()`, a task's `Actor::system()` —
see The shop's admins on its website). routes/api.php registers the shop's screens once: its daily work (`$operations`)
under both panels and the website's admin API (`/api/store/v1/{store}/admin`), its configuration (`$configuration`) under
the panels alone. `->console()` is the CLI
(`App\Console\Kernel`: each command made only when it runs, `LazyCommand`). `Core\Installation` is the one answer to "is
the shop installed" (its lock is the container's `installation.lock`, storage/installed.lock). Every file the app writes
is such a container entry — `installation.lock`, `config.file` (config.php) and `config.lock`, `install.key` and
`recovery.key`, `schedule.state`
(the scheduler's file), `throttle.path` (the sign-in throttle's folder — RateLimiter's), `files.cache` (what another
service would hand over again unchanged — a picture Telegram keeps —, `Core\Support\FileCache`'s), `jwks.path` (the
OpenID providers' keys), `qr.backgrounds`, `receipts.path` (the receipts customers upload from the websites), `tickets.path` (the pictures of
the support tickets, uploaded from a website or a panel), `poller.lock` (bot:poll's) —,
and outgoing HTTP goes through a transport that is one too (a Guzzle handler stack: `http.transport` under
`ClientInterface` — the panels, the checks, the OpenID providers, Cloudflare Turnstile, Resend —, `telegram.transport` under the Bot API's own client, `telegram.polling`
under bot:poll's long polls), and so does email (`mail.transport`, the configured mail driver's, under `Core\Mail\Mailer` — see
Mail): the test suite points the files at a folder of the run's own and swaps the transports for
its fakes, so nothing built around them is built twice. `LoggerFactory` falls back to `info` for an unknown `LOG_LEVEL`
instead of dying before the error handler exists. The addresses the shop hands out are `Http\Urls`' (APP_URL + a path —
see Production). Domain code lives in `app/Modules/<Module>/{Controllers,Models,Services,...}`; Core and Support name no
module (the scheduler's shops are `Core\Scheduling\Shops`, the Bots module's `BotShops`; a panel's sign-in guard is
`Auth\PanelAuthMiddleware`, the shop scope `Bots\ShopScopeMiddleware`; what is no module's — a web origin,
`Core\Http\Origin` — lives in Core), and a module another leans on does not lean back: the website's API (Store) calls
the customer's account (Accounts), whose sign-ins ask what they need of the website through an interface of their own,
`Accounts\Contracts\SignInSite`, which `Store\Models\Website` implements (tests/Unit/Core/LayersTest holds both rules).
Between the modules, what each names is a map, LayersTest's `MODULES` — exactly, by decision: a module that comes to name
another, or stops naming one, is an edit of the map (with why, beside an edge that is no plain use of the other's rows
or services), never an accident — Settings naming Auth, say: the owner's password (`AdminAccount::confirm()`) asked
before every bot's token goes to another Bot API address —; the doors to the whole shop — Admin, Store, Telegram (the
bot, its report group's `ShopReports`, the Bot API) — name most of it. Everything done through someone else is a driver of a family on one kernel (`Core\Drivers` — see Drivers): the databases
(`Core\Database\Drivers\DatabaseDriver`), the ways email goes out (`Core\Mail\MailDriver`), the captchas
(`Core\Captcha\CaptchaDriver`), the panel connectors (`Providers\Contracts\PanelDriver`, whose runtime client is a
`ProviderInterface` — clients addressed by their panel-wide name, the 3x-ui "email") and the payment gateways
(`Payments\Contracts\GatewayDriver`, a method row's runtime gateway a `GatewayInterface`), each family a `Registry` in
`bootstrap/container.php`.

## Production (what keeps a live shop safe and alive)

docs/Running-In-Production.md is the operator's guide (shared hosting — cPanel, DirectAdmin… — is the one supported
way: the web root, the host's cron, Telegram's webhooks, file ownership — with docs/Backups.md and docs/Upgrading.md;
see Documentation); these are the rules the code keeps.
- **Time is UTC everywhere it is kept**: `Application` sets PHP's clock to UTC and the database connection speaks UTC
  (the driver's `connection()`: MySQL's session `+00:00`), so a stored moment means the same whatever `APP_TIMEZONE`, the
  host's zone or its daylight saving. `App\Support\LocalTime` is the shop's zone (`APP_TIMEZONE`), applied only where a
  time is shown — `Persian::date()` (every Jalali date the bot says), the dashboard's days (`DashboardStats`: the range in
  the shop's midnights, the queries in UTC, `perDay()` buckets with the driver's `localDate()` at the zone's offset), the log's timestamps, `bot:poll`'s
  console — and the panels: `GET /api/app` says the zone (`timezone`, `LocalTime::zone()`), `lib/app-info` hands it to
  `lib/format` (`setShopTimeZone()`), and every date and time the panels show reads in it, whatever the browser's zone
  (`formatDay()` shows a dashboard day — already the shop's — as that very day). Never hand a query a Carbon in another
  zone (bindings are formatted in the value's own zone): `->utc()` first.
- **Secrets never reach a log line or an error**: `Core\Logging\Redact::text()` (bot tokens in Telegram's URLs or bare,
  and the secret each machine address ends with — `Http\Urls::SECRET_PATHS`, the webhooks' and the cron token's) runs on
  every formatted line (`RedactingLineFormatter`, set by `LoggerFactory`), on `BotApi`'s transport errors at the source,
  and on the debug block of an error answer (`Http\ErrorHandler`); a mail server's words lose every mail driver's secret
  config.php keeps (the SMTP password, Resend's key) before they are logged or shown (`Core\Mail\Mailer`); a panel that
  could not be reached keeps no transport exception — cURL's words name the request's whole address, whose path may be
  the panel's secret — and its own words carry `[address]` in its place (`Providers\Exceptions\ConnectionException`). An exception keeps no argument of the calls that led
  to it (`zend.exception_ignore_args`, set at boot): no trace — logged or shown — carries a password or a token handed
  down the stack. A record is one entry of the log, whatever it holds: the formatter indents every line after its first
  (a trace, a customer's text with line breaks — nothing written into a record begins a line that reads as a record of
  its own) and writes the control codes a viewer acts on (escape sequences, bidi overrides, other line separators) as
  `?`, the trace's files from the app's folder. A log that cannot be written (storage/logs not the server's to write, a
  full disk) never fails the request, the update or the run: `Logging\LogFile` hands the line to PHP's own error log.
  PHP's own warnings never print into an answer — `display_errors` is off from public/index.php's first line, before the
  app (or its vendor/) is even loaded, and what prints anyway while a request runs (a host that forces display_errors with
  php_admin_flag, a stray echo) is caught and left out by `OutputGuardMiddleware`, cut short in a warning of the log —
  and go to `storage/logs/php-errors.log`, not the host's `error_log` beside the script. No answer names what runs it:
  public/index.php takes PHP's `X-Powered-By` off first thing (`header_remove()`).
- **Failures and request ids**: every request gets an id of the app's own (`Http\RequestId`, 16 hex characters, made by
  `RequestIdMiddleware` — the outermost — never taken from the request), held for the rest of the request: every answer
  says it in `X-Request-Id`, every error answer in its body too (`request_id`, `Json::error()`), and every log line the
  request writes carries it (`Logging\RequestIdProcessor`: the line's extra `request_id`) — the panel shows it on a failure
  of the server's own («کد پیگیری»), and the owner finds its lines by it —, and, once its guard admitted a principal,
  who it acts for: `actor` among the line's extras (`Logging\ActorProcessor` reads `Logging\Acting`, which
  `Auth\CurrentPrincipal::run()` begins with `Actor::label()` — the kind, the name a decision keeps, an admin's account
  number —, held to that request's id, so the error handler's line after the guard let go names them too and the next
  request names nobody; Core names no module: what it holds is the guard's words). Every failure is answered in the one shape
  (`Http\ErrorHandler`): a refusal in its words and status, an unexpected throwable as the generic 500 — logged once, at
  error, with its trace and the request's id. **The details are no visitor's**: an answer (any status under 500 — an
  address no route has, a refusal, a sign-in missing; a 502 the shop words) never carries a `debug` block, whatever
  APP_DEBUG says; a failure of the server's carries one only with APP_DEBUG on and only to a request straight from this
  machine (`RequestOrigin::fromThisMachine()`: a loopback REMOTE_ADDR and no forwarding header at all — X-Forwarded-*,
  Forwarded, Via, X-Real-IP, CF-Connecting-IP… —, since a reverse proxy or a tunnel makes every visitor look local) —
  the class, the redacted message, where it was thrown and `DEBUG_FRAMES` (20) calls, every path from the app's folder
  (an anonymous class's or a closure's name carries its file's too), no argument (tests/Feature/Security/ErrorDetailsTest).
  The settings screen's APP_DEBUG switch says so. What the app's own handling cannot reach is public/index.php's last
  resort — an app that cannot start (a config.php that does not parse), a throw outside the error middleware, or a fatal error (memory, the
  time limit; a shutdown function, on memory it set aside): the generic JSON 500 with the request's id, in its header and
  its body, every answer's headers (`SecurityHeadersMiddleware::HEADERS`), and a redacted line in php-errors.log naming
  the id (without vendor/ — an upload cut short — PHP's bare 500 stands, its warning unprinted). The webhook answers Telegram a 200 whatever a handler did (the dispatcher logs a handler's failure once; one that
  escapes is the 500, and Telegram's second try is not served twice), the cron URL its JSON. **The panels' own failures
  reach the log** (`POST /api/{panel}/client-errors`, `Admin\Services\ClientErrors`, behind the session, sign-in and CSRF
  guards): one error line a report — what was thrown, where (the panel's address: its path and its query's parameter
  names, values and fragment stripped again here), the top of its stack and React's component stack, the panel's build,
  the shop's version, who (the principal and the shop), their IP and the browser —,
  nothing kept anywhere else; held short as the browser's word it is: a body over `MAX_BYTES` (16 KB) is a 413
  (`Core\Exceptions\TooLargeException`), each part cut to its length, the secrets taken out (`Redact`), control codes out,
  the message one line and every line of a stack indented so nothing it holds can begin a line of the log, and a
  principal or an address (by its /64) sends `MAX_REPORTS` (20) in `WINDOW_SECONDS` (10 minutes) and `MAX_REPORTS_A_DAY`
  (100) in a day through the throttle's `RateLimiter`, then a 429 — what an agent can write into the owner's log stays a
  few megabytes a day.
- **HTTP**: `SecurityHeadersMiddleware` (around the error handler) puts nosniff / `X-Frame-Options: DENY` / `Referrer-Policy:
  same-origin` / noindex / `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox` /
  `Cache-Control: no-store` (each unless the answer set its own — a streamed picture keeps its caching) on every answer
  PHP gives — JSON and the bytes the panels show (the panels' pages are static files, with a policy of their own from the
  build) —, and HSTS over HTTPS. `ApiController::bytes()` sends a picture (jpeg/png/webp/gif) or a sticker's video as
  itself, anything else as `application/octet-stream` + `attachment` — a document a customer sent is saved, never
  rendered. `Http\RequestOrigin` is the one reading of where a request came from: `isHttps()` (the scheme, or a proxy's
  `X-Forwarded-Proto`/`CF-Visitor` from anyone — it only ever adds protection), `clientIp()` (REMOTE_ADDR, or the
  X-Forwarded-For chain read back only through the proxies `TRUSTED_PROXIES` lists — addresses or CIDR ranges; Cloudflare
  appends the visitor too, so its ranges are all a shop behind it lists) and `fromThisMachine()`. Every guard is
  route-group middleware: Slim routes on the decoded path, so an encoded spelling of an address (`/api/%69nstall`) is the
  same route behind the same guards. **A body is JSON, read to a limit**: `Middleware\JsonBodyMiddleware` (in Slim's body
  parser's place, inside the error handling) reads a request's body — anything but a multipart upload, which is PHP's to
  its own limits and its route's (a QR background's 5 MB) — no further than `MAX_BYTES` (1 MiB) before any of it is
  decoded: past it, by its Content-Length or as it is read (a chunked body says none), the error shape's 413
  (`TooLargeException`), signed in or not, on any route, Telegram's webhooks too. Only JSON is parsed (an object or a
  list, else no body); a form or XML body is never read. A POST past php.ini's `post_max_size` PHP takes nothing of — no
  field, no file, no byte —: one that came so (a form with nothing parsed, a JSON body read empty, its Content-Length
  past that limit) is the same 413, never its route's «nothing was sent» (tests/Feature/Security/FormBodyTest). A form's
  text is held to UTF-8 as JSON's always is (`Input`, see Conventions). The Store API takes JSON alone
  (`Store\Http\JsonBodiesMiddleware` on its group: a POST, PUT or PATCH that says it carries a body of any other type is
  the error shape's 415 — a form or a text body is what another site's page may make a browser send without a preflight),
  but a route registered with `JsonBodiesMiddleware::UPLOAD` takes a multipart form too: one that takes a picture (a
  receipt, a new ticket, a ticket's message — see Store API).
- **Public addresses**: `Http\Urls` builds every address the shop hands out — the webhooks, the cron trigger, an agent's
  login link (`panel('agent', '/login')`), a screen's masked webhook address, a website's API base (`store(key)`) — as
  APP_URL (the address the shop is reached at, its sub-folder included) + the path, never adding `APP_BASE_PATH` (the
  request prefix PHP sees, a config.php-only override for a web server that does not say it). The machine paths are
  spelled once there (`Urls::TELEGRAM_WEBHOOK`, `AGENT_WEBHOOK`, `CRON`, `STORE`), which routes/webhooks.php and
  routes/api.php route; `Urls::PLACEHOLDER` reads a pattern's placeholders (a quantifier's braces too).
- **Sign-in throttle**: `Auth\Services\SignInThrottle` over `Core\Security\RateLimiter` (a locked file per key under
  storage/cache/throttle — no cache server on shared hosting; written under an exclusive lock, read under a shared one,
  `Files::readShared()`, so a count is never read half written; `attempt(windows)` the one way to hold a try to several
  windows at once — each window counted first, under its lock, and judged by the count it came to: one over its limit
  gives back what this try counted (`release()`) and answers the wait; so requests at the same moment never pass a limit
  together (RateLimiterTest races six processes) —, which the caller words with
  `TooManyAttemptsException::wait()`/`minutes()`: the website's issuing and its emails, the panels' error reports, a
  customer's tickets and their ratings, the pictures they upload — receipts and tickets' one budget,
  `CustomerPictures::budget()` — and the pictures their website asks for, their ordering requests, services read from
  their panels and new links, the reviews an address network or a customer writes, an agent's shop's — and a website's
  admins' — panel operations on the subscriptions screen (`SubscriptionActions::PANEL_WORK`) — see Store API, Tickets,
  Reviews;
  it also counts what no 429 answers: a shop's uploads of a day, `CustomerPictures::DAY_MEGABYTES` (a 503), ALTCHA's
  spent challenges, an agent's webhook's updates, `AgentWebhookBudget` (dropped)) gives a browser (`RequestOrigin::clientNetwork(request,
  bits?)`: an IPv6 address counted by its /64 — a review's by its /48 —, which one host may try from address after
  address — and would leave a file per try; `RequestOrigin::network(address, bits?)` the same of an address the request
  names, a website's visitor's `remoteip`) `SignInThrottle::MAX_ATTEMPTS` failures per `DECAY_SECONDS` on the owner's
  password — `/api/admin/auth/login`, and the current password typed again (`AdminAccount::confirm()`: the owner's own
  change of the login from a session, `/api/admin/auth/credentials`, and the Bot API's address moved: one count, so it
  is no way around the throttle) — and an agent's `/api/agent/auth/link` each, then a 429 with `Retry-After`
  (`check()` throws `Core\Exceptions\TooManyAttemptsException`, the one refusal to try again yet: the error handler
  answers it with its wait in `Retry-After`; a password is judged through `password(way, request, account, verify)`:
  the try counted before it is judged — `attempt()`, so guesses sent at once never pass the limit (PasswordTriesTest) —,
  its count given back when it is right, kept when wrong: the owner's login and current password, a website's sign-in and
  its customer's password typed again); success clears it — but a website's sign-ins (way `website`,
  `SignInThrottle::WEBSITE`, `WEBSITE_ATTEMPTS` (30) an address — a carrier's shared address is many customers —, see
  Store API), where a success clears nobody's count. What a website's sign-in costs the
  shop before anything is tried — a nonce or a redirect's state kept, an email sent, a captcha's token judged — is held to
  `ISSUE_MAX` (120) in `ISSUE_SECONDS` (10 minutes) an address (`issuing()`); an email has budgets of its own —
  the address's turn, its day from every shop, the asking network's hour, a signed-in customer's day, the shop's and the
  installation's hour and day (`emailing()`: each counted before the email goes — requests that come while the mail
  server talks send none of their own —, whether or not the address has an account, and given back by `emailNotSent()`
  when none went; see Store API) —; a website's second step (`secondStep()`) and the codes it emails (`emailedCode()`)
  have budgets of their own per account and address. A throttle that
  cannot keep count fails closed: with its folder not writable it lets nobody try
  (`ThrottleUnavailableException` — a 500, and the error log says which folder). Every successful sign-in, in either
  panel, and the owner's own change of the login get a new session id. Its folder is the container's `throttle.path`: in
  the tests, the run's own, empty at the start of every test; its windows run on the shop's clock (`now()`), which a test
  moves.
- **Panel sessions**: one PHP session per browser, a key per panel (`auth.owner` — the fingerprint of the login it was
  opened under, nothing more —, `auth.agent`), so the owner and an agent stay signed in side by side and signing out of
  one leaves the other. **The session says who is signed in, never in which shop**, by decision: each request names its
  own (`Auth\NamedShop::of()`: the `X-Shop` header, or — a GET or a HEAD, what a picture, a video or a download link asks
  for — its `shop` query parameter; none named is the main shop), so nothing on the server remembers a shop between two
  requests and every tab of one browser works in the shop its address shows. No bot's id, two that differ, or `shop` in
  the query of a write is a 422 on `shop`; the owner naming a bot that is not there a 404
  (`Auth\Exceptions\ShopRefusedException::notFound()` — never the main shop in its place), an agent naming another bot than
  theirs a 403 (`notYours()`); a request nobody is signed in to gets its 401 before any shop is looked at. The owner's
  sign-in, their login's recovery and their own change of the login answer in the shop the request names (asked once the
  password is found right: a right one at the address of a shop that is not there opens nothing, its try counted). An
  agent's session is checked on every request (the bot still theirs and switched on, their agency standing, the
  `panel_epoch` they signed in under) and their shop is their bot. Their login link carries its code in the URL's fragment
  (`/agent/login#code=…`): no browser sends it, so no access log holds it, and the panel takes it off the address bar
  before spending it; one opened where another agent is signed in is refused before it is spent — a 409 on `replace`
  (`SignInRefusedException::ANOTHER_AGENT`) — until the panel has asked and sends it again with `replace: true`; `POST
  /api/agent/auth/sessions/end` (204, their account page's «خروج از مرورگرهای دیگر») signs every other browser of theirs
  out — the bot's `panel_epoch` raised, this session held again under the new one.
- **A website's admins** (the shop's customers whose role is admin, on its admin API with their bearer token — see The
  shop's admins on its website): let in only while the website says so (`staff_enabled`, off by default), with a strong
  sign-in while it asks one (`staff_strong_sign_in`, on by default — Telegram, Google, or a password and its second
  step), a sign-in of the last 12 hours for anything at all, each operation beyond the shop's daily work granted one by
  one (`staff_grants`, none by default) and asked a sign-in of the last quarter of an hour — a payment's approval too,
  which delivers a service —; never the shop's configuration (a 404); deciding nothing about themselves and
  touching no admin's or agent's account (`Auth\Exceptions\ActorRefusedException`, a 403); a panel's failure — an
  operation on a service, a failed order's delivery — told to them as its summary, never the owner's diagnosis; every
  change they make logged with their name.
- **Sessions** (`Core\Session\Session`, opened by `SessionMiddleware` on the panels' API alone): files in
  storage/sessions unless `SESSION_SAVE_PATH` says otherwise (PHP's collector switched on for it), the cookie secure
  whenever the request came over HTTPS (`RequestOrigin::isHttps()`; `SESSION_SECURE_COOKIE` forces it behind a proxy that
  hides it), an idle session (past `SESSION_LIFETIME`) starts empty whatever the host's collector does, and one that
  keeps nothing — nobody signed in, or they signed out — is destroyed with its file as the request lets go of it
  (`Session::release()`, at the latest `end()`): PHP makes the file as it opens a session, and anonymous requests would
  otherwise fill a shared host's file quota. PHP holds a session's file locked while a request has it open, so the
  browser's requests would run one after the other: **a read lets go at once** — `SessionMiddleware` releases every GET
  and HEAD right after opening it (its idle mark written first when due), the sign-in still read from what it held — and
  a page's reads run side by side; only the writes (signing in or out, changing the login, every POST, PUT, PATCH,
  DELETE) hold it to their end. A change after the session was let go would be lost, so `set()`, `remove()`
  and `regenerate()` refuse it with a LogicException (tests/Feature/SessionTest plays both under php-cgi).
- **Files of the app's own** (`Core\Support\Files`, `FileLock`): config.php, the scheduler's state, the
  install lock are written whole or not at all (a file beside them renamed over them; a write a full disk cut short is no
  write), and everything the app makes — config.php, the host keys, the scheduler's state, the throttle's counts, the
  pictures it keeps, the sessions' folder, the log (`Logging\LogFile::at()`: its folder made and each file's permission
  by the same rule) — is **its owner's alone** wherever PHP runs as the account that owns the app (PHP-FPM, suEXEC,
  LSAPI, a shell — the common case): a file `Files::FILE_MODE` (0600 — the copy beside it restricted before its first
  byte), a folder `FOLDER_MODE` (0700). Where PHP runs as another user (mod_php) the owner must still read what the app
  made — the install key, the recovery key, config.php — in their host's file manager as that account, and every site's
  PHP is that one user anyway, so owner-only would protect nothing and lock the owner out: there a file is
  `SHARED_FILE_MODE` (0644), a folder `SHARED_FOLDER_MODE` (0755). The app decides as it boots, by who owns its folder
  (`Files::ownerOnly(Files::processOwns(basePath))`: no posix — Windows — or an owner it cannot read counts as its own;
  `fileMode()`/`folderMode()` answer the rule), and scripts/release.php makes the release's storage folders the same
  way. A file that was there keeps its mode (an owner who made theirs readable to their group keeps it so), a mode the
  host will not set breaks no write, and PHP's own php-errors.log is made by PHP with its own default. A folder that
  cannot be written is an answer there, never a PHP warning — the `@`s live in those two classes only (`Files::load()` is
  a PHP file's value, null when it is gone or does not parse; `Core\Support\Picture`, the one place a picture is sniffed,
  sized, decoded, scaled, turned and encoded again, keeps GD's and getimagesize()'s warnings out with an error handler
  scoped to the call). config.php's writers take their turns on its lock (`FileLock::wait()`; `take()` is the try that
  does not wait — the scheduler's, the poller's). `Core\Support\FileCache` keeps what another service would hand over
  again unchanged — a picture Telegram keeps: a receipt, a ticket's, a premium emoji's — `KEEP_SECONDS` (10 minutes),
  by its key's hash in the container's `files.cache` (storage/cache/files), the stale ones going as new ones come; a
  folder it cannot write keeps nothing, never an error.
- **What a request costs before its controller** (measured warm, with the opcode cache: ~1.35 ms for `/api/app`): the
  config.php and config/*.php (~0.4 ms), the container and Eloquent (~0.13), the routes (~0.17), the middleware. The router's
  table is kept between requests (`Http\RouteCache`, storage/cache/routes, the container's `routes.cache`): Slim would
  compile every route's pattern again for each request (~0.3 ms of it); the file is named after the version, the prefix
  and the route files and Urls as they stand, so an edit, an upgrade or another prefix makes a new one (the old ones go
  after an hour); written whole, one that does not read back is dropped, and an unwritable folder keeps none — never an
  error. Not cached, by measurement: PHP-DI compilation (it compiles only the entries container.php lists, ~0.08 ms, and
  goes stale on a constructor edit) and the config (a config.php the settings screen rewrites).
- **Long work in a web request**: the webhook and `/cron/{token}` keep going when the caller hangs up, within a time
  limit of their own (`Http\LongRunning::keepGoing()`, the controller's `TIME_LIMIT`; never set on the CLI). The scheduler
  runs one at a time (a lock beside its state file — the cron, the URL and the poller's tick never overlap), writes a
  task's time before running it, and shares one run's time (`Scheduler::RUN_SECONDS`, 45) over its turns through
  `Core\Scheduling\Budget`: each turn — a task, or a shop's task in one shop — gets what is left over the turns still to
  come (`seconds()`, a float: a turn under half a second keeps its share, never rounded to nothing), and a task that works
  through a queue for as long as it may (broadcasts, the report groups, grants) stops at `deadline()`, so a run does not
  grow with the number of agent bots (outside a run, `Budget::OUTSIDE_A_RUN`, 25 s). The
  shared Guzzle client gives up connecting after 10 s.
- **Uploads**: a QR background is judged by its header before anything is decoded (`QrBackground::MAX_PIXELS`) and
  shrunk once to `MAX_SIDE` with GD; `QrCard` refuses to decode one over the limit, judged by its header (text instead),
  and decodes the rest through `Core\Support\Picture::decode()`, the one decoding of a picture. A picture a
  customer or support sends — a receipt from a website, a ticket's from a website or a panel — goes through one rule,
  `Users\Services\CustomerPictures`: none is taken while the disk its folders are on has less than `ROOM` (200 MB) free
  (`Core\Support\Files::freeSpace()`; a host that does not say takes it), nor once the shop's uploads — a customer's or
  support's, every picture it keeps, counted by the megabytes each begins — took `DAY_MEGABYTES` (1024) that day — a 503
  (`Users\Exceptions\StorageFullException::disk()`, `::today()`: by decision the shop's status for "the host cannot do
  this now, its owner fixes it", which the panels word in the server's own words; not WebDAV's 507 — every upload
  operation of the API description says both), the log hearing it (the day's, once) —; judged by its bytes (`Core\Support\Picture::typeOf()`: JPEG, PNG, WebP), read no
  further than `CustomerPictures::MAX_BYTES` (one limit with the bot's, `isPicture()`); what a customer uploads is ONE
  budget, receipts and tickets' pictures together (`CustomerPictures::budget()`: `uploads|<shop>|<customer>`, `UPLOADS`
  (10) in `UPLOAD_WINDOW` (an hour), counted with the caller's own windows in one `RateLimiter::attempt()`, then a 429
  «تصویر زیادی فرستاده‌اید»; support is never held); kept under storage/ (deny-all) in its kind's folder
  (`Users\Enums\PictureFolder` — the container's `receipts.path`, `tickets.path`) by a name of the shop's own — the
  device's name is only ever a label (`CustomerPictures::cleanName()`) — as GD writes it again when the host has GD
  (`keep()`: decoded once — only when what PHP's memory has left holds it, `Requirements::memoryLimit()`; its longest side
  `MAX_SIDE` (2560) at most, turned as its EXIF Orientation says — read from the JPEG itself, no exif extension —, a JPEG
  and a WebP at 85, nothing of the sender's file but its pixels: no metadata, nothing appended; as it came where GD cannot);
  deleted only by `discard()` — once what made it is undone or over, a closed ticket's a month on (see Tickets); the panels
  hand anything they would not show as an attachment (`ApiController::bytes()`). One sent in the bot stays Telegram's,
  its bytes asked for as a screen shows it and kept `FileCache::KEEP_SECONDS` for the reads that follow
  (`CustomerPictures::fromTelegram()`; a premium emoji's picture and animation alike, `CustomEmojis`).
- **The web root**: `public/` is it; `app/`, `bin/`, `bootstrap/`, `config/`, `database/`, `docs/`, `resources/`,
  `routes/`, `scripts/`, `storage/`, `tests/` each carry a deny-all .htaccess, the root .htaccess forwards into `public/`
  and refuses `config.php` (and any copy an editor left beside it, `config.php.bak`), composer/phpunit files and
  version-control folders even without mod_rewrite; `public/.htaccess`
  serves the panels, answers 404 for a missing `assets/` file (never a page — see the panels' stale-build reload),
  compresses text (brotli with mod_brotli, else gzip; the build's own brotli for its files — assets/.htaccess) and
  refuses an `error_log`. `/health` names no server detail (no PHP version, and a database that does
  not answer is `unavailable` — why is the log's, `DatabaseManager::answers()`).
- **Releases**: `scripts/release.php` (and `.github/workflows/release.yml` on a `v<version>` tag that names
  `Application::VERSION`, after `composer check` and `pnpm check` again) copies only what runs — app, bin, bootstrap,
  config (every part of it, admin.php included — config/*.php is code; the shop's settings are config.php's), database,
  public with the built panels, resources/assets, routes, an empty storage/ with its .htaccess (its folders made
  through `Files`, by the app's mode rule, after the autoloader is loaded) — and runs `composer
  install --no-dev --classmap-authoritative`; `.github/workflows/ci.yml` (every push to main, every pull request to it)
  runs `composer check` and `php scripts/docs.php --check` on PHP 8.2, 8.3 and 8.4 — every version a shop may run, each
  a required job held to the whole suite, which fails on any deprecation — and `pnpm check` + build (pnpm as
  package.json's `packageManager` names it). composer.json names its authors («AmoBot contributors», as LICENSE does)
  and its support addresses (the repository, github.com/amir-aghajani/amo-bot, as package.json does), and requires in
  development the `pdo_sqlite` the test suite runs on. The release carries resources/release-key.pub, and beside its
  zip go release.json and its signature — what the shops' updater installs from (see Updates). The installer's lock
  records the version it installed (`Installation::markInstalled()`), which every upgrade and update moves
  (`moveTo()`): where the database's next upgrades start from.

## Configuration (config.php)

**The shop's own configuration is one PHP file, `config.php`, beside the app — by decision, as WordPress keeps
wp-config.php: shared hosting has no shell and no environment of its own to set, and its File Manager edits a file.**
It returns a flat array of UPPER_CASE settings (`APP_*`, `DB_*`, `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH`, `TELEGRAM_*`,
`MAIL_*`, `CRON_TOKEN`, `LOG_*`, `SESSION_*` …) as PHP values (strings, whole numbers, booleans); git-ignored, never in a release
(the installer makes it), refused to the web by the root .htaccess with any `config.php*` copy beside it. There is no
`.env`, no phpdotenv and no `env()`: nothing of the configuration is read from the environment, so no request can spell
a setting (tests/Feature/Security/ConfigurationSourceTest). The pieces (`App\Core\Config`): `ConfigKeys::SECTIONS` —
every setting by section with its default (its type is the setting's) and the words the file says about it; a
driver's own settings are its form's (see Drivers) and never listed there: a database driver's DB_* follow DB_CONNECTION
in the file, a mail driver's MAIL_* end the mail's section (`ConfigKeys::sectionOf()` places a setting no section lists
by how its name starts) —; `ConfigValues` — the file as config/*.php
read it: `string()`, `int()`, `bool()` coerce a hand-written value by the rules the settings screen reads the file with
(`Input::integerOf()`, `isBoolean()`/`truthy()`: `'60'` is 60, `'false'` false, anything a setting cannot be its
default) and `prefixed('DB_')`/`prefixed('MAIL_')` hands a family its drivers' settings as text, uncoerced (a driver
reads its own through its form, `Form::values()`, a setting the file lacks as its field's default) —; `ConfigFile` — `load()` (a missing file
is no settings; one that does not parse stops the boot with its line — the front controller's last resort answers and
php-errors.log has it), `all()`/`get()`, `setMany()`: the whole file rendered anew in the sections' order with their
words — a declared setting the file does not set shown commented out at its default, `// 'APP_TIMEZONE' => 'UTC',`, for
the owner to see and set (a driver's are written only once set, at its section's end); a setting of no section kept
under «Other settings» — written whole (`Files::writeAtomically()`) under the lock its writers wait their turns on
(`FileLock::wait()` on the container's `config.lock`, storage/cache/config.lock), the opcode cache told; a value keeps
its type (a driver's port is a number, `'DB_PORT' => 3306`); `isWritable()` (the folder takes a new file, and the file
allows it), `UNWRITABLE` (what to fix, in the owner's words). Each file of config/ returns a closure, `static
fn(ConfigValues $settings): array => [...]`, that makes its part (`Repository::fromDirectory()`); `Application::boot(
$basePath, ?$configFile)` reads the file (`Application::configFile()`, the container's `config.file`). The writers: the
installer, `ConfigSettings` (the owner's settings screen), `AdminAccount` (the login), `BotLifecycle` (the main bot's
@username and webhook secret). `bot:poll --watch` reloads its worker when config.php changes.

## Drivers (app/Core/Drivers, app/Core/Forms)

Everything the shop does through someone else — a database, a way email goes out, a captcha, a panel, a payment
gateway — is a **driver** of a family, every family on one kernel, by decision (the owner's: connectors not for servers
alone, "the database for example"): a family is one interface extending `Core\Drivers\Driver` and one
`Core\Drivers\Registry` of its drivers, an entry of bootstrap/container.php — `database.drivers`
(`Core\Database\Drivers\DatabaseDriver`, see Database drivers), `mail.drivers` (`Core\Mail\MailDriver`, see Mail),
`captcha.drivers` (`Core\Captcha\CaptchaDriver`, see Captcha), `panel.drivers` (`Providers\Contracts\PanelDriver`, see
Adding a connector), `payment.drivers` (`Payments\Contracts\GatewayDriver`, see Payment methods) —, so a new driver is
a class and its line there, and the generic code names none (no `if mysql`, no `if 3x-ui` outside a driver's own
class). A `Driver` is `key()` — what config.php, a row or a request names it by — and `describe()` → a `Descriptor`:
the key, a Persian label and description, short `notes` (the versions it needs, its caveats), the `Form` it asks the
admin to fill in, and `traits` — what its family says of it beyond that (a database driver's `installable`, a mail
driver's `sender`, a gateway's `kind` and `builtin`, a connector's `mark`, `vendor`, `docs_url`, `inbounds` and
`link_rotation`); `toArray()` is the API's `DriverDescription` (its form's fields described, `traits` always an
object, whose description names every family's traits). A driver is a stateless definition: what it holds for one config.php, one row or one website is its form's
values, handed to it (`Form::values()`) — a mail driver's `transport(values)`, a captcha's `verify(values, attempt)`, a
gateway's `gateway(settings)`, a connector's `connect(server, http)`. A `Registry<T>` keeps its drivers in the order
they are registered (`all()`: the order a screen offers them), `has()`, `find()` (null for none) and `get()` —
`UnknownDriverException`, a `LogicException`, for a key no driver has: the code's mistake, or a hand-edited
config.php's, never a request's (a request names one of the drivers offered, else a 422 under its field); two drivers of
one key are a LogicException as the registry is built.
**Forms** (`App\Core\Forms` — the one field engine, every settings card's too: see Conventions): a `Form` is a key and
its fields; a field (`Fields\Field`) is the name a screen sends and shows it under, the key it is kept under (a
config.php setting, a settings-table key, a row's column, a key of a row's JSON), its default, `type()` (a `FieldType`:
text, number, amount, list, toggle, choice, secret, email, url, path, textarea, card — a bank card's number, which the
panels draw in groups of four on the number pad), `required()`, `read(input, kept)` (the
value, or `FieldRefused` in the admin's words), `cast(kept)` (a kept value it would refuse reads as its default),
`present(value)`, `conflict(values)` (what its value says against the others') and `leftBehind(input, values, before)`
(what keeping the stored value says against the save while what it belongs with moved — a field that stands in for
another hands both on). With a `FieldSpec` — `label`, `hint`, `placeholder`, `advanced` (drawn under «تنظیمات
پیشرفته»), `options` (a choice's values and their words, in order), `when`, `unit`, `ltr` — it describes itself to a
generic form (`describe()`: `{name, type, label, hint, placeholder, required, secret, bound_to, moved, advanced, options,
when, unit, min, max, ltr, default}`, never a secret's default; a field without a spec in a described form is a
LogicException). `Form::check(input, kept)` reads every field, then what they say about each other, and is refused as
a whole — every refusal at once, each under its field (`ValidationException`) — before anything is kept: the values to
keep, by key. `checkSent(input, kept)` is a partial save — a PATCH that sends only what it changes: only the fields the
input names (or their `clear_<name>`) are read, the rest keep what is kept, and what the fields say about each other is
judged on the whole form as the save would leave it. `values(kept)` is each field's value as it reads what is kept —
what a driver works with —, `present(kept)` the screen's, `describe()` the generic form's, `only(...names)` a step's
part of it. A field's `when` (fields before it and the values each must hold — a switch as "true" or "false", a number
its digits) shows it, and so reads it and asks for it when it is required, only while it holds: not shown, it is
neither read nor refused, and what is kept of it stays. A `Secret` is never sent back (`present()` → `{set, hint}`):
left blank or out it keeps what is kept, `clear_<name>` empties it (`Input::secret()`), `missing` asks that one end up
kept, and `boundTo` — the fields it belongs with, each with what identifies it (a URL by its origin) or as it is —
keeps a stored one only while they stay: one of them moved, a blank secret is refused in `moved`'s words, to be typed
again or cleared, so a kept secret never goes where it was not given — a database's password (host, port, socket),
SMTP's (host, port, encryption, username), the main bot's token (the Bot API's address, by its origin:
`ConfigSettings::TOKEN_MOVED`), a panel's token, password and TOTP secret (its address, by its origin), Turnstile's
secret (its site key) — an address's by its origin, `Secret::byOrigin()` (scheme, host and port, `Core\Http\Origin`'s
form: another path is the same server), the one such reading for a panel's secrets, the main bot's token and the
Bot API's move. The constructor refuses a `when` that names no field before it and a `boundTo` that names no
field of the form. What a driver's form reads as kept is `Form::keptFor(driver, keptFor, values)`: the values kept, for
the driver they were kept for, only while that driver is the one chosen — another starts with nothing kept, so one
driver's secret never stands in for another's field of the same name (the database settings, the website's captcha;
mail's drivers keep their settings under keys of their own). The kernel's fields: `Toggle`, `Number`, `Amount` (whole
Toman, `Input::amount()`), `Numbers`, `Text` (a line, a `path` or a `textarea` — its `type` —, required or not, of a
`pattern`), `EmailAddress`, `Url` — every web address of the shop, one field: http(s) with a host, kept without its
last slash, never credentials, a query or a fragment; its `label` naming it in its refusals, `required`, `max`,
`credentials` (the words for an address carrying them where the form has fields of their own) and `pages` (a pattern
on its path for the pages it must not reach into, with their `pagesRefusal`) —, `Choice`, `Secret`; a family adds what
they do not cover (`Core\Database\Drivers\SqliteFile`, `Payments\Drivers\Manual\CardNumber` and `ReviewWindow`,
`Providers\Forms\Capacity` and `TotpSecret`, `Store\Forms\Origins` and `Ruled` — a website field with a rule of the
form's own, the field it wraps answering for the rest, its `leftBehind()` too).
**A family's API**: every driver described (`GET …/drivers` answering `DriverDescription[]` — the connectors', the
gateways' —, or the descriptions inside the screen's own read — the database's and the mail's `drivers`, the
website's captcha), what is kept as `DriverValues` (`Form::present()`), and a request that is a `oneOf` of one closed
shape per driver (`DatabaseRequest`, `ConfigMailRequest`, `ServerRequest`/`ServerTestRequest`, the payment methods'
create and update requests, `WebsiteCaptchaRequest`); tests/Unit/Drivers/DriverFormsTest holds every family's shapes —
the database's, the mail's, the captcha's, the connectors' and the gateways' — to their registered drivers' forms (the
same fields, both ways, and what a form cannot leave out), the description's `FieldType` to the kernel's, and its
captcha lists (`CaptchaDriver`, the website's `captcha.driver`) to `captcha.drivers`; then `pnpm api:types`. A new
driver needs nothing of the panels.
**The panels** draw any driver from its description alone (resources/panel/src/components/driver-form): `DriverFields`
(each shown field by its kind — a line; a number, its unit beside its label; an amount; a list typed as text; a switch
(`SwitchRow`); a choice — three or fewer side by side, a `PageTabs as="choice"` radio group that is one field of the
form (`data-invalid`, focused whole when refused), more in a `Select` —; a secret (`SecretField`, kept unless typed
again or cleared, its hint `moved`'s words while a field it is bound to differs from the saved one); an email, a web
address, a path, a few lines; a bank card's number in groups of four on the number pad, its digits alone kept —, Latin
content left to right, the advanced ones under a `Disclosure` «تنظیمات
پیشرفته»), `DriverPicker` (which driver a setting goes by: a `Select` of their labels — no driver at all first, where
that is a choice («خاموش») —, the one picked described under it with its notes; a single driver with nothing else to
pick is said, not offered) and draft.ts — `driverDraft(driver, stored?)` (what a form starts from: what the server
keeps, else each field's default; every secret blank, not cleared), `shownFields()` (each whose `when` holds, as the
server reads the form), `driverPayload()` (the fields shown, as typed — a secret only when typed, beside its clear
flag: the driver's own closed request), `leftBehind()` (the server's bound-secret rule ahead of the save: a number by
its digits, an address by its origin — `originOf()` of lib/utils, the panels' one reading of an address's origin, as
`Core\Http\Origin` is the server's: the Telegram card's and the Google card's too). The installer's database step and the database section
(apps/admin/settings/database-form.ts: `databaseDriver()`, `databaseDraft()`, `databaseRequest()`), the mail section,
the website's captcha card, a server's form and a payment method's (`MethodForm`) are each a picker — `DriverPicker`, or
a `PickerModal`'s cards — and `DriverFields`. Tests: tests/Unit/Core/Drivers/RegistryTest, tests/Unit/Core/Forms/
FormTest and FieldDescriptionTest, tests/Unit/Drivers/DriverFormsTest; the panels' driver-form/draft.test.ts and
driver-fields.test.tsx.

## Mail (app/Core/Mail)

The shop sends email — a website's sign-up codes and password resets, and its notices to a customer Telegram cannot
reach: no Telegram account, or one that turned the bot away (see Store API) — by config.php's `MAIL_*` (the `Mail`
section, config/mail.php): `MAIL_TRANSPORT` is `none` (the default, `MailTransport::NONE`: no driver, no email) or a
mail driver's key — a `Core\Mail\MailDriver` (see Drivers) of the container's `mail.drivers`: `smtp` (`Drivers\Smtp`,
an account on a mail server — one of the host's Email Accounts in cPanel, or any other: `MAIL_HOST`, `MAIL_PORT`,
`MAIL_ENCRYPTION` tls (STARTTLS, required) / ssl (TLS from the first byte) / none, `MAIL_USERNAME`, `MAIL_PASSWORD`),
`native` (`Drivers\Native`, the host's own mail as PHP's mail() sends it — php.ini's sendmail_path —, no settings of
its own) or `resend` (`Drivers\Resend`, Resend's API — resend.com: `MAIL_RESEND_API_KEY`, the sender's domain verified
there), each driver's form its own MAIL_* settings and its `sender` trait (`MailDriver::SENDER`) which address its
emails may come from, in the owner's words —; `MAIL_FROM_ADDRESS` (empty: no email goes out) and `MAIL_FROM_NAME`
(empty: the shop's name — an agent's shop's, its bot's, `Bots\Services\Bots::name()`). config/mail.php hands the
drivers every MAIL_* setting as written, as text (`mail.settings`); `MailTransport::fromConfig()` builds the transport
once a process — the driver MAIL_TRANSPORT names reads its settings through its form (`Form::values()`: one config.php
lacks is its field's default) and makes it (`transport(values)`): SMTP's symfony/mailer `EsmtpTransport` built as it is,
no DSN (`ssl` TLS from the first byte, STARTTLS required with `tls` and not tried with `none`; a connection that gives
up after 15 s — a customer waits for the mail they asked for), native's `native://default`, Resend's
`Core\Mail\ResendTransport` (an `AbstractTransport` of the app's own: the email posted to Resend's /emails as JSON with
the key as a bearer, through the shop's one outgoing client — `ClientInterface`, so the tests answer it as every other
call —, its own `http_errors` off and no redirect followed, Resend's id kept as the message's, a refusal Resend's words
with its status, the key in none) — the container's `mail.transport`, null for none and, logged, for settings no
transport can be built of (`MailSettingsException`: a key no driver has, SMTP without its server, Resend without its key
— a config.php edited by hand; the settings screen refuses them).
`Core\Mail\Mailer` is the one way an email goes: `ready()` (a transport, and an address to send from — what email
sign-up, a password reset and the owner's test ask first), `send(MailMessage)` (to, subject, HTML, plain text; from
MAIL_FROM_ADDRESS by MAIL_FROM_NAME, else by the message's `fromName` — the shop it is about —, else APP_NAME), to the
message's address alone (`new Address($message->to)`: never a display name read out of it — an address that reads as a
name with another in angle brackets, `"a<victim@x>"@y`, writes to nobody else; `App\Support\Email` refuses such an
address in the first place) — a transport that does not take it is `MailFailedException` (502, a customer's words —
«ایمیل فرستاده نشد…» —; `reason` the transport's, `explained()` the owner's), logged once, every mail driver's secret
config.php keeps (its form's secret fields: the SMTP password, Resend's key) taken out of the words. `MailBody` is the
one look of an email: Persian, right to left, a small card of inline styles (the shop's name, a heading, then the words)
and the same words as plain text — `message()` paragraphs and a code set apart left to right, every value escaped
there; `formatted()` words that come as safe HTML already, which their maker escaped (`Telegram\Texts\WebText::html()`:
a notice). The emails are `Accounts\Mail\SignUpCode` (nothing in it the requester typed: a sign-up may name anyone's
address), `AlreadyRegistered`, `PasswordResetCode`, `NoAccount` (a reset asked for an address without an account — an
email either way, so its time tells nothing; once a day an address), `LinkEmailCode`, `EmailRemoved` (an address taken
off an account: it is no way in any more), and `Notifications\Mail\NoticeMail` — a notice to a customer Telegram cannot
reach, or one of how their account is signed in to, which goes to every door (see Store API, notifications); what the
website's sign-ins send is held to the email budgets (`SignInThrottle::emailing()`, see Store API).
The owner sets it up from «تنظیمات پنل» (`ConfigSettings`' `mail` group, `Settings\Services\MailSettings`): its read
is `ConfigMailSettings` — the way out, who the emails come from, every mail driver described (its form, its `sender`)
and each one's settings by its key as config.php holds them (a secret as whether one is kept) —, and `PUT
/api/admin/settings/config/mail` takes `ConfigMailRequest`, a closed shape per way out (`MailNoneRequest`,
`MailSmtpRequest`, `MailNativeRequest`, `MailResendRequest`): none alone, or a driver's own form with who the emails
come from — an address required (no email goes from none), a name 64 characters at most —, checked whole by
`MailSettings::validate()` (the chosen driver's form and the sender's, every refusal at once). What the other drivers
keep stays as it is, for a way back, and a secret goes nowhere new: SMTP's password is bound to the host, the port, the
encryption (a password kept for TLS never goes in clear text by itself) and the username — left blank with one of them
moved, a 422 on `password` (`Smtp::PASSWORD_MOVED`), typed again or cleared —; Resend's key is a secret too (`re_…`, its
hint its last characters), required while resend is the way out (`Resend::KEY_MISSING`). It tries it (`POST
/api/admin/settings/config/mail/test {to}` → `{sent: true}`, by the settings as saved: 422 `MailNotReadyException` while
none goes out, 502 with the transport's reason): «تنظیمات پنل» › «ایمیل», /settings/mail
(`apps/admin/settings/mail-section`): `MailSection`, a card on `useConfigGroup` as the other config.php groups are —
the way out a `DriverPicker` («خاموش» first, its hint what none means: no email sign-up, no reset code, and no notice by
email to a customer Telegram cannot reach; then the drivers by their labels — «SMTP», «ایمیل خود هاست», «Resend»), the
chosen driver's form drawn by `DriverFields` (SMTP's encryption three choices side by side — «STARTTLS (پیشنهادی)»,
«SSL», «بدون رمزنگاری» —, its password's field saying to type it again while a field it is bound to differs from the
saved one and none is typed), and who the emails come from under any driver (the address's hint its driver's
`sender`); another driver picked starts from what config.php keeps for it, and only the chosen one's fields are sent
(`requestOf()`), so what the others keep is neither refused nor changed; a save reads the website's settings again,
whose email sign-up waits for it (`email.mail_ready`) —, and `MailTestCard` («ارسال ایمیل تست»: an address and «ارسال»,
a `useForm` — busy while it runs, a toast once the transport took it, the address refused under it, any other failure
on its `FormError` line in the server's words, until the next send). Tests: the container's `mail.transport` is
`Tests\Fakes\RecordingMailTransport` from the boot on (`sent()`, `to(address)`, `codeIn(email)`, `failing(reason)`),
and `TestCase::mail()` sets the shop's email up for a test (MAIL_FROM_ADDRESS `shop@example.com`) — without it no email
can go; `MailSettingsApiTest`, `tests/Unit/Core/MailTransportTest`, `MailerTest` (the address alone, a driver's secret
out of a server's words), `ResendTransportTest` (`FakePanel` standing alone as Resend: the request, the key, refusals,
out of reach), tests/Unit/Drivers/DriverFormsTest (each driver's request its form).

## Captcha (app/Core/Captcha)

A website's forms are guarded by the captcha its owner picks — a verifier of its own, by decision, not a part of the
sign-ins (the owner's: "a separate verifier, so our API clients can use it — to verify reviews as well"). A captcha is a
`Core\Captcha\CaptchaDriver` (see Drivers) of the container's `captcha.drivers`: `turnstile` (`Drivers\Turnstile`,
Cloudflare Turnstile — the page draws Cloudflare's widget with the site key; its token is checked with Cloudflare's
siteverify, `Turnstile::SITEVERIFY` — the secret, the token, the visitor's address when it came with them —, through the
shop's outgoing client, no redirect followed; its form a `site_key` (public, printable Latin) and a `secret_key` bound
to it, a new site key asking the secret again; Cloudflare takes a token once and says the host it was solved on and the
action its widget named; out of reach, or not answering as its API does, it is `CaptchaUnavailableException` — a 503,
logged —, and a secret it does not know refuses everyone, which the log says as an error, for the owner; reachable from
Iran) and `altcha` (`Drivers\Altcha`, altcha.org's open-source proof of work: no third party, no keys, any host, from
Iran too; it `IssuesChallenges` — its widget fetches a challenge from the shop: a salt (`SALT`: 24 random hex
characters, `?`, what it carries — its expiry and, when asked for one, its action — and the delimiter `&` that ends it),
the SHA-256 of the salt and a number up to `MAX_NUMBER` (100,000), signed with an HMAC-SHA256 key derived from APP_KEY
for it alone (`Encrypter::mac(challenge, 'amobot-altcha')`) —, the visitor's browser finds the number, and the form's
token is the payload as base64 JSON, judged by hash alone: the shop's signature, the number's hash, the salt's expiry
(`CHALLENGE_SECONDS`, 15 minutes), each challenge taken once until it expires — a `RateLimiter` window of
`CHALLENGE_SECONDS` on the salt's 24 random characters. The hash covers the salt and the number one after the other, so
the delimiter, by decision: without it the number's first digits moved onto the salt's end would make another salt of
the same hash — a solution passing again under a "new" challenge whose expiry never comes —; a salt without it, even
one the shop signed, is `invalid-input-response`. It says nothing of where it was solved). A driver's `siteKey(values)` is
what a page draws its widget with (never a secret; null for one without), and `verify(values, CaptchaAttempt)` → a
`CaptchaVerdict` — `passed`, `action`, `hostname`, `reasons`: the provider's codes (Turnstile's `timeout-or-duplicate`,
`invalid-input-response` …) and the shop's own, `hostname-mismatch` and `action-mismatch` (`CaptchaVerdict::held()`
holds a token the driver took to the site's hosts, when the driver says where it was solved, and to the form's action,
when the form names one). The site is a `CaptchaSite` — `captchaDriver()` (a key; null for none), `captchaConfig()`
(what the driver's form keeps for it), `captchaHosts()` (its pages' hosts, in lower case) —, which the Accounts module's
`SignInSite` extends and `Store\Models\Website` implements: `captcha_driver` and `captcha_config` (the driver's form's
values by key, JSON encrypted at rest: `Core\Database\Casts\EncryptedArray`, beside `Encrypted`), its hosts its `url`'s
and its `origins`'. `Core\Captcha\Verifier` is the one place a captcha is asked — in the driver the site names, with its
values, against its hosts: `drivers()`/`driver(key)`, `asks(site)`, `widget(site)` (`{driver, site_key, challenges}`),
`challenge(site, action)` (a widget that asks the shop for one), `verify(site, token, action, visitor?)` (null while the
site asks none; a token that is none, or past `TOKEN_MAX` (4096), refused unasked) and `check(site, request, input,
action)` — a form of the shop's own: its `captcha` judged for the action from the request's visitor, else a 422 on
`captcha` (`Verifier::REFUSED`).
**Where it is asked**: the website's sign-up, sign-in with a password and password reset (`Accounts\Services\Captcha`;
the forms' actions are `Accounts\Enums\CaptchaAction` — `sign_up`, `sign_in`, `password_reset`, the action the widget
names, each operation's `x-captcha` in the API description —), after the form's own fields passed, each token counted
first against what the address network may cost the shop (`SignInThrottle::issuing()`): a passing one, or one that could
not be judged, gives its count back (`issuedNothing()`), a refused one keeps it — a bot's guesses cost it what asking
for codes does, and once that is spent its next token is refused unjudged, its provider not asked; a customer's review
(`POST /reviews`, its action `review` — `Reviews\Services\Reviews::CAPTCHA_ACTION` —, `Verifier::check()` after the
review's own fields, its tries counted against the reviews' own windows, never the sign-ins' — see Reviews); and any
other form of the site's own — a contact form — through the site's backend, server to server, so the site never holds a secret
(`Store\Services\WebsiteCaptcha`, `Store\Api\CaptchaController`): `GET /api/store/v1/{store}/captcha/challenge?action=`
(ALTCHA's challenge, never cached; 404 `NoCaptchaException::noChallenges()` while the website asks none or one whose
widget draws its own; an `action` is 1–32 of `[A-Za-z0-9_-]`, Turnstile's `data-action`, else a 422) and `POST
/api/store/v1/{store}/captcha/verify {token, action?, remoteip?}` → `{passed, action, hostname, verified_at, reasons}`,
200 passed or not (a token is taken once; 409 `NoCaptchaException::off()` while the website asks none; 422 on `token`,
`action`, or `remoteip` — the visitor's address the backend names, an IPv4 or IPv6 address, handed to the captcha's
provider too); its budget is the website's own, never the sign-ins' (`SignInThrottle::verifyingCaptcha(website,
network)`, counted before the token is judged, either spent a 429 and the provider not asked): every check against the
website, `CAPTCHA_CHECKS` (3000) in `CAPTCHA_SECONDS` (10 minutes) — its backend is one address for all its visitors —,
and every refusal against the visitor's network, `CAPTCHA_REFUSALS` (120) in the same window — `remoteip`'s by its
network (`RequestOrigin::network()`), else the backend's own —, a token that passed or could not be judged giving the
network's count back (`captchaNotRefused()`), so a bot behind the site's forms spends its own network's; 503 while it
could not be judged).
`GET /` says what a page draws: `captcha` `{driver, site_key, challenge_url}` (null while none is asked; `challenge_url`
the website's base address and `Storefront::CHALLENGE` for a widget that asks the shop, null for Turnstile).
ApiDescriptionTest holds every request body with a `captcha` field to an `x-captcha` — a `CaptchaAction`, or a review's
— and only such a body. The panels:
the website's captcha card (`components/website/captcha-card`, `CaptchaCard`, in «ورود با ایمیل» — see Store API) — a
`DriverPicker` («خاموش» — its hint: the forms then held by the request limits alone —, «Cloudflare Turnstile»,
«ALTCHA») and the chosen driver's form by `DriverFields`, saved as `PATCH /website {captcha}` (`WebsiteCaptchaRequest`:
`none`, or a driver and its form whole, checked by it — a secret left blank keeps the one kept while the driver and the
field it is bound to stay —; refused under `captcha.<field>`, which the card lets go of as each is typed); the website's
read answers `captcha: {driver, drivers, values}`. Tests: tests/Unit/Core/Captcha/TurnstileTest and AltchaTest,
tests/Feature/Store/CaptchaTest (each form for its action with each driver; a token solved elsewhere or taken before;
Cloudflare out of reach; what a refused token costs) and WebsiteCaptchaTest; `TestCase::turnstile()` →
`Tests\Support\FakeTurnstile` (Cloudflare's siteverify on the outgoing transport: `passed(action, hostname)` a token
that passes once, `UNKNOWN_SECRET`, `checks`, `down()`) and `Tests\Support\AltchaWidget::solve(challenge, changes)` (a
visitor's browser solving it, `changes` a payload tampered with).

## Installation

`Core\Installation` (the `storage/installed.lock` file) is the one answer to "is the shop installed", asked once a
request by its route group's `Http\Middleware\InstalledMiddleware`: until the lock exists the shop's routes — the panels'
API, the webhooks, the cron address — answer a JSON 503 (`NOT_INSTALLED`), and once it does the installer's (the
container's `installer.open` on `/api/install`) a 404 (`INSTALLER_GONE`); `/`, `/api/app` and `/health` answer either
way. The owner's panel, seeing `installed: false` on `/api/app`, has nothing but its installer (`apps/admin/pages/install.tsx`
at `/admin/install`, a step per file in `apps/admin/install/`, its requests through `install-api.ts` with the key; an
agent's panel only says the shop is not set up yet); once installed, `/admin/install` goes to the login.
**The install key**: a fresh upload answers its installer to whoever reaches it first, so `/api/install/*` also needs
`X-Install-Key` (`Installer\Middleware\InstallKeyMiddleware`, inside `installer.open` and the CSRF guard): the text of
storage/install-key.txt (`Installer\Services\InstallKey`, the container's `install.key`), which the first request makes
(four groups of four characters without look-alikes — `Core\Security\ReadableCode`, a website customer's two-factor
recovery codes' alphabet too —, ~79 bits) and only someone with the host's files can read — the
owner opens it in the file manager and the installer page asks for it first; without it, or with another, a 403 that
says so on `key` (`MISSING`, `WRONG`; a storage/ that cannot be written, a 503). `finish()` deletes it.
The web installer is `Installer\Services\Installer` behind `Installer\Controllers\InstallerController` (`GET /api/install`
= `status()`, every step answers with it; POSTs carry the CSRF header): **requirements** (`Support\Requirements::check()`:
a list of `{name, label, ok, optional}` — the name its key (`ext-curl`), the label the installer's words, a driver's
extension worded with the driver's name; PHP's `memory_limit` ≥ 64M among them; the panel's own update's sodium and zip
`optional`, shown and holding nothing up — `ready` is `Requirements::met()`), **database** (`saveDatabase()`: config.php made
with an `APP_KEY` of its own (`prepareConfigFile()`), then `ConfigSettings::saveDatabase()` — `DatabaseSettings`:
a driver the installer offers and its fields, written only once the driver's probe answers; a blank secret is empty
here, the installer shows nothing stored — see Database drivers), **tables** (`createTables()` →
`Core\Database\Schema::create()`, the tables the database lacks — a request after the one that wrote config.php, so it runs on
the new database), **admin** (`saveAdmin()`: the one rule for the panel's login, `Auth\Credentials::fromInput()` —
`USERNAME_PATTERN`, and a password by the one password rule — `App\Support\Password::problem()`, a website customer's too:
`MIN_LENGTH` characters and `MAX_BYTES` (72, what bcrypt reads — a longer one would open the panel with its start alone;
a Persian letter is two bytes); `Password::hash()` is PHP's own (PASSWORD_DEFAULT) —, the confirmation; the owner's own
change from their panel reads it too —
then `Auth\Services\AdminAccount::save()`: bcrypt hash into config.php's ADMIN_USERNAME / ADMIN_PASSWORD_HASH — a
config.php the server may not write is a 422 on `file` saying what to fix (`ConfigFile::UNWRITABLE`), nothing changed
—, the in-memory config updated), **site** (`saveSite()`: APP_NAME and APP_URL alone, `ConfigSettings::saveSite()`,
and the bot's token when given — `ConfigSettings::saveBotToken()`: `Telegram\Api\BotToken::identify()` first, then the
token and its @username), **finish** (`finish()`: refused with what is missing — `requirements`, `tables`, `admin` — else
the lock, and the key gone). Every step can be taken again until then. The status shows the database step's
`DatabaseSettings::present()`, probes only once config.php names a driver (`DB_CONNECTION`, which the step writes) and
checks the tables with `Schema::missing()`. **The web installer is the one way to install, by decision** — the shop runs
on shared hosting (hosts only), which has no shell: there is no console installer, no console command for the login (the
sign-in page's recovery, see Users) and none for `APP_KEY` (the installer's database and site steps give config.php
one, `prepareConfigFile()`).
Tests: `InstallerApiTest` plays a fresh shop (`installed(false)`, `configureAdmin(null)`, the run's own config.php, lock
and key), its requests carrying the key (`install()`), MySQL as the running driver with its probe answered by
the test (`probedDatabase('mysql')`).

## Updates (app/Modules/Updates)

The owner's one-press update to a newer release from GitHub — **only a release signed with the Ed25519 key built into
the shop, by decision**: a hijacked GitHub account, or anyone between GitHub and the host, cannot hand the shops code of
their own. A shared host keeps nothing running and gives a request half a minute, so the update is a run of steps, a
request each, its state kept between them.
- **Releases** (`Releases`, `REPOSITORY` amir-aghajani/amo-bot): `GitHub::latest()` reads `/repos/{repo}/releases/latest`
  through the shop's one outgoing client (its User-Agent `AmoBot/<version>`) — a draft, a pre-release or a tag that names
  no version is none (`Release::fromApi()`), a 404 none published, a 403 of the rate limit or a 429 `GitHub::LIMITED`, no
  answer `UNREACHABLE` (502s). What was read is the installation's runtime state, the main bot's settings row
  `update.latest` (`{release, checked_at, tried_at}`), read again by a screen once `KEEP_SECONDS` (6 h) old — a failed try
  kept too, so GitHub out of reach is not asked again on every screen (GitHub takes 60 calls an hour from an address, and
  a shared host's is every site's on it) —, at once by the owner's «بررسی دوباره» (`check()`), and daily by
  `Tasks\CheckReleasesTask` (the dashboard's system card says when one is out).
- **Trust**: `ReleaseKey` — resources/release-key.pub (base64; the container's `release.key`), the key the next update is
  checked with being the one the release installed carries. A release's files: `amobot-<version>.zip`, `release.json`
  (`Manifest`: version, file, size, sha256, php, extensions, paths — scripts/release.php's) and `release.json.sig` (the
  detached signature of its very bytes, base64). `Manifest::verified()` believes nothing before
  `sodium_crypto_sign_verify_detached()`, then holds it to the release asked for (its version and its zip's name — a
  release signed for one version offered as another is none), `MAX_BYTES`, and paths of the release's own: plain
  top-level names, always `ESSENTIAL` (app, bootstrap, public, vendor), never config.php nor storage. `GitHub::download()`
  builds each file's address from the repository and the tag (never the API's), follows GitHub's redirects by hand
  (`allow_redirects` off) to https on github.com or `*.githubusercontent.com` alone, reads no more than the manifest's
  size and writes the file whole (`.part`, renamed); the zip's size and sha256 must be the manifest's
  (`Updater::checkZip()`, again before it is unpacked).
- **The run** (`Updater`; `UpdateRun` in storage/updates/state.json, written whole — `FORMAT` 1, which a later version's
  updater reads: an install a crash cut short is finished by the code it put in place —, one request working it at a
  time, `Workspace::lock()`, a second a 409 `Workspace::BUSY`): `start(version)` — the newest release, newer than the
  code (else a 422 on `version`), no blocker, no run under way (409) — then `step()` by step: **Download** (what an earlier
  run left cleared but previous/; the manifest and its signature fetched and verified — refused, both deleted: nothing
  unsigned is kept —; the disk's room, 4× the zip; the zip fetched and checked), **Extract** (`Package::check()` first:
  every entry under `amobot-<version>/` and inside one of the manifest's paths, none absolute, none with `..`, `.` or an
  empty segment, a backslash or a drive, no link, within `MAX_ENTRIES` and `MAX_UNPACKED` — the zip-slip guard, before a
  byte is written —; then `extract()` a slice a request, `Core\Scheduling\Budget`'s deadline, at least one entry each,
  each a plain file of the app's (`Disk`: folders 0755, files 0644 — the web server serves public/), the zip's storage/
  left out; the release checked whole: its paths, its loader, boot, front door, and app/Core/Application.php naming its
  version), **Preflight** (the blockers again; PHP ≥ the manifest's `php`; its extensions loaded;
  `AppFolders::unchangeable()` — `Files::processOwns()`, each path writable —; previous/ cleared; `renames()`: a folder
  moved into the app's folder and back), **Install**, in ONE request: the scheduler's lock (the container's
  `schedule.lock`) waited for up to a minute and held, so no scheduled run overlaps; `loadWhatTheSwapNeeds()` — every class
  the rest of the request runs, and the schema builder, loaded while the old files are in place
  (`LOADED_BEFORE_THE_SWAP`: one loaded after the swap would come from the other version's files) —; `Maintenance::hold()`;
  the run saved `installing`; `AppFolders::install()`; `forgetCompiledCode()` (the router's table files, `opcache_reset()`
  where `opcache.restrict_api` allows it); `Core\Database\Upgrades::run()` from `Installation::version()` to the release's,
  `moveTo()` after each and the flag held again; the release's version recorded; the flag released; Done, the downloads
  cleared. Anything after the swap failing is `takeBack()`: `AppFolders::restore()`, the code forgotten again, the flag
  released, the run at Install not installing — or still installing when the restore failed too (logged critical, the
  owner told previous/ holds the old files) —, the refusal in the owner's words (an upgrade's names it). A run refused
  keeps its words (`run.error`). `cancel()` while nothing is swapped (`UpdateRun::isCancellable()`: what it fetched goes);
  `rollback()` of a Done run that changed nothing of the database (`upgraded` empty, else `DATABASE_CHANGED`: a backup's)
  while the version it replaced is kept (`AppFolders::kept()`) — the same swap the other way under the same lock and
  flag, the lock's version back to `from`. **A database behind its code** — files put in place by hand (the documented
  manual way), or an install whose run is gone — is a run of its own (`UpdateRun::upgrading()`: Install, installing, no
  paths), which `run()` makes while `Installation::version()` < `Application::VERSION`: its step runs the upgrades and
  records the version, no blocker asked (a host that cannot update itself still upgrades its database).
- **The swap** (`AppFolders`, over `Workspace`'s storage/updates — the container's `updates.path`: state.json, state.lock,
  download/, `<version>/` the release unpacked, previous/ the app's paths a release replaced, kept until the next update's
  preflight): a folder moved aside, then the release's moved in — renames on one file system, at once —; a file copied
  aside (`Disk::copy()`, written beside and renamed, its mode kept) and replaced in one rename, never missing; `restore()`
  the other way, the release's copies back in its folder (for another try). Both read where each path stands off the
  disk itself (the release's copy still in its folder or not, the app's aside or not), so either picks up wherever a
  crash left it. What a host's tools wrote into the .htaccess files a release replaces (the root's, public/'s: the
  «cPanel-generated» blocks of the MultiPHP Manager and INI Editor — the shop's PHP version and settings) goes on into
  the release's, once (`HOST_FILES`, `keepHostLines()`): without it the shop could wake up on another PHP. `Disk` is the updater's file system, its no an exception with PHP's reason (set_error_handler — never a
  warning; `Core\Support\Files` is the app's own files'). A clone of the repository (`.git` in the app's folder) is git's
  to update (`CHECKOUT`).
- **Maintenance** (`Maintenance`, storage/updating.flag — `FLAG` — holding the time its work last said it is alive):
  public/index.php, before the app is loaded (through vendor/'s autoloader when it is there), answers every request a
  JSON 503 of `MESSAGE` with `Retry-After` (≤ 10 s) and every answer's headers while the flag is younger than `SECONDS`
  (120); an older or garbled one is ignored, so a crash never shuts the shop for good (`retryAfter()`). The request
  installing started before its flag: it is never held.
- **Blockers** (the screen's `blocker`; `start()` and the steps refuse with them, with the way out — an update by hand,
  docs/Upgrading.md): no release key (`NO_KEY`), no ext-sodium (`NO_SODIUM`), no ext-zip (`NO_ZIP`), a git checkout, PHP
  that may not change the app's folders (another account's, or not writable), a newer release without its signed files.
  sodium and zip are composer.json's `suggest` and `Support\Requirements`' optional ones.
- **API** (the owner's alone, beside /system in the owner's group — an agent's panel and a website's admins have no such
  route, a 404): `GET /system/update` → `{update: UpdateStatus}` (`current`, `latest` `{version, published_at, notes,
  url}` | null, `checked_at`, `available`, `blocker`, `run` `{version, from, step, progress, error, cancel, rollback,
  finished_at}` | null); `POST …/check` (502 while GitHub does not answer), `…/start {version}`, `…/step`, `…/cancel`,
  `…/rollback` answer the same, the session released first and the work kept going when the owner hangs up
  (`LongRunning`, 300 s).
- **The panel**: «تنظیمات پنل › به‌روزرسانی» (`/settings/update`, `PANEL_SETTINGS_SECTIONS`; apps/admin/settings/
  update-section, read only while on screen — `useSectionShown()`): the version, the newest release and its notes as
  plain text (never HTML), «بررسی دوباره», «به‌روزرسانی به نسخه X» behind a `ConfirmModal` (a backup first; the shop
  answers «در حال به‌روزرسانی» a moment), then the run driven a step a request by one mutation until it is over and the
  page reloaded on the new build (`reloadForNewBuild()`); a refusal the run's `FormError`, with «تلاش دوباره» and, while
  `cancel`, «لغو به‌روزرسانی»; a run found under way «ادامه به‌روزرسانی»; done, «بازگرداندن نسخه قبلی» while `rollback`.
  The dashboard's system card says «نسخه تازه» with the way there while `available` (`updateQuery`, lib/queries).
- **Making a release** (docs/Releasing.md): scripts/release.php writes build/release.json beside the zip and, with
  `RELEASE_SIGNING_KEY` in its environment (base64 of the 64-byte secret; the app itself never reads the environment),
  build/release.json.sig — only once the signature verifies with the key the shops trust (resources/release-key.pub, or
  `--trusted-key`: the last release's, when this one brings a new key) —; without it the release is said to be unsigned.
  scripts/release-key.php makes the pair: the public key into resources/release-key.pub (never over one without
  `--rotate`), the secret, base64, into the file named — refused inside the repository, never printed. A new key
  reaches the shops in a release signed with the old one. `.github/workflows/release.yml`, on a tag `v<VERSION>`:
  CHANGELOG.md's section for the version as the notes, the secret required (no unsigned release is ever published), the
  checks, the release built and signed — the previous tag's key the trusted one —, and the GitHub release with the zip,
  release.json and release.json.sig.
- **Database upgrades** (`Core\Database\Upgrades`): from the first release on, every change of database/schema.php ships
  database/upgrades/<version>.php returning `static function (Builder $schema, Connection $db): void` (the folder's README
  is the rule: the schema builder and the connection alone, runnable again after stopping half-way), run in version
  order, newer than the lock's version (`Installation::version()`; a lock that says none is `FIRST_RELEASE`, 0.1.0) and not
  newer than the code's; a PHP file there no version names stops it; `UpgradeFailedException` names the one that failed.
  A fresh install makes schema.php's tables and records the code's version: it runs none.
- **Tests**: tests/Feature/Updates — `UpdateTestCase` (this code installed in a scratch folder at its own version, never
  this repository: every version a test names is the code's — `CURRENT`, `Application::VERSION`, the database recorded at
  it — or reckoned from it — `$next` and `$later` its next minors, `OLDER` a database behind it —, so a release of the
  shop's own moves nothing a test means; the run's folders in its storage/; a release key made for the test, `Tests\Support\FakeRelease` — zip, manifest, signature
  —; GitHub, `Tests\Support\FakeGitHub`, the outgoing transport: the API, a file's redirect to GitHub's storage,
  `down()`, `answer()`, `storeOn()`; an Updater made over them swapped in), `UpdateApiTest` (the whole run, the swap and
  the rollback, a failing upgrade taken back, a crash finished, files by hand, every refusal, the owner's alone),
  `ReleasesTest`, `MaintenanceAnswerTest` (public/index.php under php-cgi) —, tests/Unit/Updates (Manifest, Package's
  zip-slip guard, AppFolders, Maintenance), tests/Unit/Database/UpgradesTest, tests/Unit/Core/InstallationTest; the
  panel's update-section.test and system-card.test. What needs sodium and zip skips without them (CI has both); locally
  `php -d extension=sodium -d extension=zip vendor/bin/phpunit` runs them (XAMPP's PHP has neither on by default).

## Database drivers (app/Core/Database/Drivers)

The database is a driver of the kernel (see Drivers): `DatabaseDriver` extends `Core\Drivers\Driver` and is everything
the application asks about one, so a new database is one class registered in bootstrap/container.php's
`database.drivers` — the installer, the settings screen, the requirements, the dashboard and the schema tooling ask the
driver and name no database (no `if mysql` outside a driver class). `DB_CONNECTION` names it (config/database.php:
`driver`, and `connection` — every `DB_*` setting of config.php as text, uncoerced, since a password may read "null":
`ConfigValues::prefixed()`); `DatabaseManager::driver()` is the one the shop runs on (a key no driver has stops the
boot — `UnknownDriverException`, as a config.php that does not parse does) and `boot()` hands Eloquent its
`connection()`, named after its key. A driver is handed `$config` — a set of config.php settings: the file the shop
booted with, the one the installer is writing, or what the admin's form just made — and reads its own through its form
(`Form::values()`), a setting that is missing as its field's default. The contract:
- `key()` and `describe()` → a `Descriptor`: a Persian label and description, `notes` (the versions it needs), the
  `installable` trait (`DatabaseDriver::INSTALLABLE`: the installer offers it) and its form — its connection settings,
  each field kept under its config.php setting: MySQL's `host` (DB_HOST), `port` (DB_PORT, a `Number`, kept as one),
  `database`, `username`, `password` (a `Secret` bound to `host`, `port` and `socket`: one kept never goes to another
  server — `MySqlDriver::ADDRESS_MOVED`), and under «تنظیمات پیشرفته» `socket` (a path) and `prefix` (its pattern,
  `PREFIX_MAX`); SQLite's `path` (DB_DATABASE, a `SqliteFile`: relative to the application or absolute, never in
  memory, never under public/). The panel draws any driver from that alone (components/driver-form; the installer's step
  and the settings section share apps/admin/settings/database-form.ts — `databaseDriver()`, `databaseDraft()`,
  `databaseRequest()`).
- `extensions()`: its PHP extensions — `Requirements` lists those of the driver the shop runs on, and a probe without one
  is refused (`ProbeFailedException::missingExtension()`, checked by `DatabaseSettings::probe()`).
- `connection(config)` → the illuminate/database connection (charset, the UTC session, strict mode, options) — the one
  description of it, for the shop and for a probe.
- `probe(config)` → `ProbeResult` {version "MariaDB 10.4.32", tables} or `ProbeFailedException` in the admin's words, before
  anything is written: `ProbeResult::probe(connection, minimum)` opens a connection of its own through Illuminate's
  connector (which tries a refused or timed-out connect twice: MySQL's probe waits 3 s a try), refuses a server older
  than the driver's floor (by the connection's name for it: MySQL, MariaDB, SQLite) and counts the current schema's
  tables; the driver words a failure (`MySqlDriver::explain()`: the server's error number → what to fix).
- `localDate(column, offset)`: the dashboard's day of a UTC column (MySQL `DATE(DATE_ADD(col, INTERVAL n SECOND))` — not
  CONVERT_TZ, which stops at +13:00 before 8.0.19 —, SQLite `DATE(col, '+n seconds')`).
- `releaseNames(schema, table)`: what `Schema::rebuild()` drops before a table steps aside — the names that are the
  database's rather than the table's (MySQL's foreign keys, SQLite's indexes).

`Settings\Services\DatabaseSettings` is the database part of config.php for both screens (ConfigSettings' `database`
group, the installer's step and status): `present()` → `{driver, drivers, values}` — the driver config.php names (else
the one the shop runs on; one no driver has → the first offered), the drivers offered (the installable ones and that
one, each described), its fields as config.php has them (`Form::present()`), else (for the driver the shop runs on) what
it runs with, else their defaults —; `chosen(input)` (`driver`, one offered, else a 422 on `driver`); `validate()`
(DB_CONNECTION and the chosen driver's form checked, `Form::check()`: a blank secret keeps the stored one only for the
driver the screen showed — another starts with nothing stored — and only while the fields it is bound to stay, as the
driver reads them (so «۳۳۰۷» is still 3307): moved, saved or only tried, a 422 on the secret asks for it again, so a
kept password never goes to another server — tests/Feature/Security/DatabaseSecretAddressTest); `probe(config)` (a
DB_CONNECTION no driver has refused, the driver's extensions, then its probe). A switch is validated and probed like
any save; the other driver's settings stay in config.php (`DB_DATABASE` is shared: MySQL's name, SQLite's
file). The API: `DatabaseSettings` (the read) and `DatabaseRequest`, a closed shape per driver (`DatabaseMysqlRequest`,
`DatabaseSqliteRequest`), the drivers' forms' fields (tests/Unit/Drivers/DriverFormsTest).

**MySQL** (`MySqlDriver`, installable; MariaDB through the same connection): MySQL 5.7.8 (JSON columns) or MariaDB 10.3
(Laravel 12's floor); host (default localhost — a cPanel user is `@localhost`), port, database, username, password, and
socket and prefix (`PREFIX_MAX`, 16) as advanced fields. What schema.php may hold, by what MySQL takes where MariaDB is
laxer (tests/Unit/Database/SchemaTest; db:rebuild from the live schema rehearsed on MariaDB 10.4 with DYNAMIC and COMPACT
rows and the longest prefix): a key's name within 64 characters after that prefix (a long one gets a name of its own:
`payments_takings_index`, `wallet_transactions_balance_index`, `telegram_updates_pace_index`; a primary key has none),
an indexed string within 191 characters (767 bytes of utf8mb4 — MySQL 5.7.8 makes COMPACT tables), and no cascading
foreign key on a column a stored generated column is computed from. **SQLite** (`SqliteDriver`, not installable): the tests' in-memory database
(phpunit.xml) and a developer's file — `DB_DATABASE` relative to the application or absolute, never under public/; a file
gets WAL and a 5 s busy timeout, and the probe makes it (Illuminate's connector opens only a file that exists). Not offered,
by decision: PHP before 8.4 cannot begin the IMMEDIATE transactions that keep the bot, the panel and the cron from failing
each other's read-then-write transactions with SQLITE_BUSY (`lockForUpdate()` is a no-op there; pdo_sqlite 8.2 does not
track an `exec('BEGIN IMMEDIATE')`), its locking is unsafe on the network filesystems some shared hosts use, one
transaction holds every writer (a gateway settling over HTTP inside a settlement's transaction would stop the shop), and
a live file needs the backup API, not a copy.
**Adding a driver** (PostgreSQL, …): a class implementing `DatabaseDriver` — its form (fields with their specs, each
kept under its config.php setting, a password bound to its address), connection, a probe with its floor and its words,
`localDate()`, `releaseNames()` —, registered in bootstrap/container.php's `database.drivers`, its request shape in
openapi.yaml (`DatabaseRequest`'s oneOf; `pnpm api:types`), its extension in composer.json's `suggest`, a unit test
like `MySqlDriverTest`. What the contract does not cover yet and PostgreSQL needs:
a rebuild's copied ids leave its sequences behind (a `setval` hook after the copy, added with that driver), and the
search's LIKE is case-sensitive there (`Page::whereContains()` leans on MySQL's collation). `ChangeFeed::WRITE` reads the
backtick- or double-quoted table names MySQL, SQLite and PostgreSQL write. Tests: `tests/Unit/Database/Drivers` (each
driver) and `Tests\Fakes\ProbedDriver` — a real driver whose probe can answer from a queue: one stands in for every
database driver of the registry from the boot on (the registry takes no other later), passing everything to the real
driver until a test answers its probes (`TestCase::probedDatabase(key, installable?)`: the installer and settings API
tests), back to the real driver after every test.

## The shop a panel request works in (Auth\NamedShop)

The owner may work in any shop — the main bot's or any agent's — and a browser's tabs may show several at once, so by
decision the shop is each **request's** own, never the session's (every tab of a browser shares one session: a shop it
remembered would move under the tabs that did not pick it), and the owner's tab keeps the shop it shows in its address:
`/admin/…` the main bot's, `/admin/s/<id>/…` an agent's (see the panels' structure, above). `Auth\NamedShop::of(request)`
reads the bot a panel request names — the `X-Shop` header (`NamedShop::HEADER`) or, on a read (GET, HEAD), its `shop`
query parameter (`QUERY`: an `<img>`, a `<video>` or a download link carries no header) —, null for none: the main
shop; no bot's id, two that differ, or `shop` in the query of a write (`QUERY_ON_A_CHANGE`) is a 422 on `shop`.
`PanelAuth::principal(request)` — `OwnerAuth`'s, `AgentAuth`'s — answers who is signed in, in the shop the request is
worked in: the owner's any bot there is (`OwnerAuth::shop()`; one that is not there `ShopRefusedException::notFound()`,
a 404 on `shop` — never the main shop in its place), an agent's their bot alone (another named: `notYours()`, a 403);
`PanelAuthMiddleware` works the request in it (`CurrentBot::run`), read by its principal (`CurrentPrincipal::run`), a
request nobody is signed in to getting its 401 before any shop is looked at. Nothing on the server remembers a shop between requests: two requests of one session
naming two shops are each worked in their own. `GET /auth/me` answers in the shop the request names, and so do the
owner's sign-in (`OwnerAuth::attempt(username, password, request)`: the shop asked once the password is found right — a
right one at the address of a shop that is not there opens nothing, its try counted), the login's recovery and the
owner's own change of the login. `GET /api/admin/system` names the shop's bot by its id (`bot`) and carries the main
bot's beside it (`main_bot`: how every bot of the installation gets its updates is the main bot's way, and its token is
the installation's — the webhook card and the Telegram settings read it). The API description puts the `X-Shop` header
parameter on every `/api/admin/…` and `/api/{panel}/…` operation, and `shop` on the media reads (a receipt, a ticket's
picture, the QR background, a premium emoji's picture and animation); ApiDescriptionTest holds every path of the
owner's panel to it.
The panel: `lib/config` reads the shop off the address once (`appConfig.shop`, `routerBasename`, `shopHome(id)`,
`MAIN_SHOP`; `/admin/s/1/…` put back to `/admin/…` in place), `lib/api` names it on every request of the owner's panel
(`X-Shop`) and in every media address (`mediaUrl(path, query?)`), `shopMissing(error)` tells a 404 on `shop`, and
`api.probe(path)`/`api.blob(path)` read a file's address as requests of the panel's (a picture the browser could not
draw; a premium emoji's animation); `lib/auth` has `useMainShop()`, `useShopName()` and `RequireAuth`'s `MissingShop`
(components/app-status: «این فروشگاه پیدا نشد», with `ErrorState`'s `home` — «رفتن به فروشگاه اصلی»). Opening a shop is
one function, `useOpenShop()` of apps/admin/owner.ts (`{open, opening, link}`): the unsaved-changes question
(`confirmLeave()`), then a full page load of the shop's dashboard (`shopHome(id)`) — nothing of the last shop stays on
screen, in the cache or under way; `link(id)` makes a real link (`href` and a plain press's handler), so the agents
list's «باز کردن فروشگاه» opens in a new tab as any link does; the shop picker opens with `open` and is held while
`opening`. Tests: `HttpTestCase::openShop($bot)` makes the requests that follow carry the header, as `bearer()` does a
token; tests/Feature/PanelShopTest (one session's requests naming two shops, each in its own; a change landing in the
shop it named and nowhere else; a shop that is not there, a name that is no shop's; signed out, whatever shop is named;
a sign-in in the shop it names; a picture's address naming its shop in its query) and AgentPanelTest (an agent naming
another bot, by the header and by the query); RouteGuardsTest walks every shop route that names a row (`{id}`) with
another shop's row — under an agent's session, and under the owner naming the agent's shop — and expects a 404 (a new
route that names a row of a new kind adds the kind to its `ROW_KINDS`); the panels' fake server checks a request's
described header and query parameters too (src/test/contract), and lib/config's, lib/api's and the shop picker's
tests read the address, the header, `mediaUrl()` and opening a shop.

## Live panel (the change feed)

Both panels follow the shop without a reload. `Core\Database\ChangeFeed` keeps a number per **area** (`AREAS`: table →
area — users (with `account_merges`: a merge is the customer's page's news), servers, grants (`grants` and `grant_parts`: a running grant's cursor moves every second, so only its cards
read again), plans, payment_methods, orders, payments, referrals, agency (with `bots` and `traffic_transactions`),
subscriptions, channels, broadcasts, reports, emoji, tickets (with `ticket_messages`; a customer reading support's answer
is written quietly), reviews; tables the panel does not show, like sessions, move nothing) in
`change_versions` — one count for every shop (a screen refetches its own shop's rows) —, and bumps an area's number once a write to one
of its tables is **committed** — read off the connection's own events (`QueryExecuted`, `TransactionCommitted`,
`TransactionRolledBack`), so every process counts (panel requests, the bot, scheduled tasks, the report group's buttons)
and no service reports anything. A write inside a transaction counts at the commit and not at a rollback; the numbers are
written after the commit, outside its locks, and **never from inside the event of the statement that wrote** — the
driver's last insert id belongs to that INSERT until its caller has read it (a bump there handed Eloquent the wrong id) —
but just before the connection's next statement (`beforeExecuting`), after a commit, or at shutdown — every area waiting
then in one statement (`… WHERE area IN (…)`, the missing rows made after it): each is a write of its own, and a sale's
commit moves three. A bump that fails is logged, never thrown. A write no screen needs to follow at once is made inside `ChangeFeed::quietly(fn)` and counts for
nothing (only what the closure writes — the rest of its transaction counts as ever): a customer's visit alone
(`UserResolver`'s `last_seen_at` touch), so the `users` area moves with real changes only — a newcomer, a profile, a
block, a ban, a wallet line. `GET /api/{admin,agent}/changes` (`Admin\Api\ChangesController`, releases the session lock first —
`Session::release()`) answers `{versions}` — its areas named in the API description (`ChangesResponse`, closed;
`ChangeFeedTest` holds `AREAS` to it), which the panel's `Area` type is read from. In a panel, `lib/use-live-updates`
(mounted by `AppShell`) asks every 4 s while the tab is visible and at once on focus, and invalidates the queries
`AREA_QUERIES` maps each moved area to — every read whose rows or figures come from the area's tables (a plan's entries
from `servers`, its counts from `orders`/`subscriptions`, a method's from `payments`, the agent's account from `users`,
`plans` and `agency`, the dashboard from every area it counts), not a name a row borrows from another area (a customer's
on an order) —, only those on screen refetching; forms keep their drafts — they read their data once. An open modal follows its row through
`lib/use-open-row` (the list's copy until the row's own query — `GET /payments|orders|subscriptions/{id}` — has read it
again since), so it stays right after the row left the list; an operation on the confirm strip that the new state no
longer allows is withdrawn with a word (`useOperations` in components/operations — the admin's own operation in flight
is not a withdrawal); one the server refused because the row moved on — while the tab was hidden and the poll paused —
reads the row and its list again (`refresh()`), the refusal said once in the server's words (it stands for the
withdrawal) even when the row read again leaves nothing to do, and a row deleted meanwhile (its read a 404: a service deleted elsewhere,
an unpaid order that expired) stays on screen as last read, said to be gone, nothing more offered on it. The dashboard still polls each minute for what changes with time alone. A new
table the panel shows = an `AREAS` entry (a new area: its property in `ChangesResponse`) + its `AREA_QUERIES` entry; a
screen that starts reading an area = its key there. Tests: `DatabaseTestCase` runs every case inside a transaction, so
it tells the feed that transaction is the database (`countAsCommitted()`); `ChangeFeedTest`; `use-live-updates.test`
writes out, per area, every query a screen derives from it.

## API contract (resources/api/openapi.yaml)

The JSON API is described once, in OpenAPI 3.0.3 — every operation of routes/api.php, the schema of every success
answer (errors are the one `ErrorResponse` shape, under `default` — a Store API operation a customer signs in for also
names its 401, `responses/SignedOut` with its `WWW-Authenticate: Bearer`, and one asking a recent sign-in its 403,
`responses/SignInAgain`) and the body every change takes; objects are
**closed** (`additionalProperties: false`), so a field the server sends, or a request carries, that the description lacks
is a failure, not a silent extra. Answers: every field `required`, nullable where it may be null. **Request bodies**: every
POST, PUT and PATCH has a `requestBody` — `required: true`, `application/json` (the QR upload's `multipart/form-data`;
a write that may carry a picture takes both — a ticket's message, `TicketMessageRequest` or `TicketMessageUploadRequest`
—, its generated body the union of the two), its schema a named one so the generator makes a type (`…Request`) — or says it
takes none (`x-no-body: true`);
`ApiDescriptionTest` holds every change to exactly one of the two. A request field is
`required` when the server refuses the request without it; one left out means what its description says (a secret kept —
`clear_<name>: true` empties it —, a switch's default, blank); no field the server does not read. Numbers come as a form
sends them — a JSON number or its text, Persian digits too: `WholeNumberInput`, `AmountInput`, `NumbersInput` (a list or
typed text) —, switches as JSON booleans (`Input::isBoolean()`'s leniency for "1"/"true" is the server's, not the
contract), closed sets as enums — but a choice the screen offers from its own `meta` (a time zone, a log level), text the
server refuses in its words when it is none of them; a decision's note is `NoteRequest`, a reorder `ReorderRequest`. A path parameter cannot
pick a schema in 3.0, so a screen that saves one group at a time takes a `oneOf` of each group's closed form
(`BotSettingsRequest`, `ConfigSettingsRequest`); a family of drivers (see Drivers) is saved by a `oneOf` of one closed
shape per driver that names it (`driver`; mail's `transport`) — `DatabaseRequest`, `ConfigMailRequest`,
`ServerRequest`/`ServerTestRequest`, a payment method's create and update requests, `WebsiteCaptchaRequest` —, its
fields its driver's form's (tests/Unit/Drivers/DriverFormsTest), and what a driver's form keeps is answered as
`DriverValues`, a map by field name — the one open shape, its keys the fields its driver describes (a server's `form`:
AdminServersApiTest). An operation a website's captcha guards says its action, `x-captcha` (see Captcha). A screen both
panels have is described once, under
`/api/{panel}/…` with the path-level `Panel` parameter (enum admin, agent); what only one panel has is under
`/api/admin/…` or `/api/agent/…`; every operation of the owner's panel takes the `X-Shop` header parameter, and a media
read its `shop` query parameter (see The shop a panel request works in). **An operation of the shop's daily work is
marked `x-staff`** — `true`, or the `StaffGrant` the shop must give for it; `x-staff-recent: true` beside a `true` where
it asks a recent sign-in without a grant (`StaffMiddleware::RECENT_SIGN_IN`: a payment's approval) —: the website's admin API has it too, under
`/api/store/v1/{store}/admin/…`, never written out in the file — `tests/Support/ApiDescription` (`openApi()`, read once a
run: what `HttpTestCase::apiDescription()` answers) expands each marked operation to its admins' path (`STAFF`): the
store key (`Store`) in place of the panel and of the owner's `X-Shop`/`shop`, the `customer` bearer scheme, its 401
(`SignedOut`) and its 403 (`responses/StaffRefused`, whose `WWW-Authenticate` names a sign-in too weak or not recent
enough) — the one place the admins' paths are made; the file's info says what the admin API answers (see The shop's
admins on its website). Both sides are held to it — the admins' paths with the rest — in **every HTTP test** (`HttpTestCase::handle()`,
league/openapi-psr7-validator): the request before it goes in — path, query, headers and body; an operation without a
`requestBody` takes none, so a body sent to it fails too — and the answer on the way back — status, content type, every
field; an address that is nobody's route may only 404/405. A test whose point is a request no panel sends (a wrong type, a
required field missing, a value outside its enum, a field the operation does not take, a query value outside its schema)
sends that one request through `$this->unchecked()->postJson(…)`: it goes in as it is, its answer still checked, and an
unchecked request the description would take fails the test; a test that does not care what a body holds sends what the
panel sends (`[]` for an empty note). `RouteGuardsTest` walks every route with a field no operation takes (`ask()`).
`tests/Unit/Http/ApiDescriptionTest` checks it is valid OpenAPI, names exactly the app's routes (a `{panel}` path once per
panel its parameter names), that the validator finds every operation, under each panel, as itself, that every path of
the owner's panel takes the shop its request names, the bodies, that a body with a `captcha` says its `x-captcha`, and
that routes/api.php mounts for the websites' admins exactly the operations marked `x-staff`, each route asking the grant
its marker names (`StaffGrant::ARGUMENT`) and the recent sign-in `x-staff-recent` marks (`RECENT_SIGN_IN`) — only an
operation both panels have, its marker `true` or a `StaffGrant`, `x-staff-recent` only beside a `true` — and
the panels' types are **generated** from it: `scripts/api-types.mjs` (`pnpm api:types`)
writes resources/panel/src/lib/api-types.ts — one exported type per schema, answers and requests alike (a binary string,
an uploaded file, is a `Blob`), descriptions as doc comments, and each API written to as one union of its
writes (`PanelWrite`: `/api/{panel}`, `/api/admin`, `/api/agent`; `InstallWrite`: `/api/install`; `StoreWrite`: a
website's `/api/store/v1/{store}`) — a member an
operation: its method, its path under the API's address with a parameter as the type of its value (`/plans/${number}`,
`/bot/settings/${BotSettingsGroup}`), the body it takes (`undefined`: none) and its answer (`void`: none), a write under
no API there failing the run; never edit it by hand; `pnpm check` fails while it is stale. **The panels' requests are held
to it both ways.** At compile time, `lib/api`'s `apiClient<W>` (`api`: `PanelWrite`, the installer's: `InstallWrite`)
types every `post`/`put`/`patch`/`delete` by its path — by decision a typed map, not a type named at each call: the path
picks the operation, so a body with a field the operation does not read (written out, or a form's values handed over
whole: the body is held exact, `Exact`), one missing a required field, a body to a write that takes none, none to one that
takes one, an address no write has — each is a compile error, and the answer is the operation's own, named nowhere
(`await api.post('/plans', values)` is a `PlanResponse`); a path that stands for several (`/orders/${number}/${action}`)
takes any of their bodies, optional when one takes none. A read names its answer (`api.get<PlansResponse>('/plans')`). A
kit that runs a request takes it from its caller as a function (`useRows`' `reorder`/`remove`/`patch`, `useSettingsGroup`'s
`save`, `useProbe`'s probe, `useGrants`' `run`/`cancel`), so every write is written out where it is made and typed there.
At run time, every request a panel's test sends is checked against the description by the fake server (`src/test/contract`:
the operation of its method and path — the most literal template it fits —, its path's parameters and its query's and
its headers' described ones (the owner's `X-Shop`, a media read's `shop`) as the server casts them, its body by its
media type and schema, none where it takes none; the subset of
OpenAPI the generator reads — `type`, `format`, `enum`, `nullable`, `properties`, `required`, `additionalProperties`,
`minProperties`, `items`, `maxItems` (a reorder's 1000 ids), `allOf`, `oneOf`, `anyOf`, `pattern`, `minimum` —, read as
league reads 3.0 — a null taken where `nullable` says so, and nothing more asked of it; exactly one of a `oneOf` —, any
other keyword failing loudly (a new one the description starts using is taught to the checker with its test); ours rather than a JSON Schema library, which reads
`nullable` its own way) — the description read once a run (vite.config.ts
`globalSetup`: `src/test/global-setup`, handed to every file by `inject('apiDescription')`); a drift fails the test, and
a test whose point is a request no screen sends lets that one through with `server().unchecked(method, path)`, which —
as `unchecked()` in PHP — fails the test when the description takes it after all, or when no such request came. Changing
an answer = the presenter + the schema + `pnpm api:types` (+ the screens TypeScript then points at); changing
what an endpoint reads = the service + its request schema + `pnpm api:types` (+ the screen that sends it). YAML gotchas:
in a one-line `{ … }` mapping a description with a comma must be quoted (else the rest becomes keys or swallows the
mapping), a `$ref` takes no sibling (a property's description wraps it: `{ allOf: [{ $ref: … }], description: … }`), and
shared property sets use anchors with `<<:` merge keys, the anchor above its aliases (both symfony/yaml, which the PHP
side reads with, and the `yaml` package agree on them). The generator is ours because openapi-typescript needs the
TypeScript 5 compiler API and the project runs TypeScript 7.

## 3x-ui connector (app/Modules/Providers/Drivers/ThreeXui)

Targets 3x-ui **v3.x** — the panel's own description of its API is tests/Fixtures/3x-ui-openapi.json (190 operations);
the connector wraps only what the shop calls. `ThreeXuiDriver` is the connector (see Adding a connector): its card (mark
«3X», MHSanaei, its GitHub page, the notes — v3 and later, the token's place in the panel, the address with its web base
path), its form — the address (`PanelConnection::address()`, its `pages` `#/panel/|/[^/]+/panel$|/login$#`: refused
with a page of the panel after its web base path — its dashboard, `/panel`, and what is under it, its sign-in, `/login`
— while a web base path that is itself `/panel`, the address's one segment, is taken), the way in
(`PanelCredentials::fields()`: a token without whitespace, or a username and password), `totp_secret` (a
`Providers\Forms\TotpSecret`, normalised as an authenticator shows it, shown with the password's way in alone and bound
to the address's origin: a panel moved to another host asks it again, or for it to be cleared) and the options —, its
capabilities and `connect()`. Layers of its client: `ThreeXuiClient` (transport over `Support\PanelHttp`: a Bearer
token, or a cookie login — `GET /csrf-token` opens the anonymous session and its token must be replayed on `POST
/login` itself, which sits behind the panel's CSRF guard (bare 403 otherwise) — with the one-time code when a TOTP
secret is kept (`Core\Security\Totp::code()`, the app's one TOTP — a website customer's two-factor sign-in's too), and one re-login on a 401 or a redirect to the login page; the envelope `{success,msg,obj}` →
`ThreeXuiApiException` with the panel's `msg`; every request carries `X-Requested-With: XMLHttpRequest` because 3x-ui
only answers a refused credential with 401 for XHR callers — older v3 builds reply a bare 404 otherwise,
indistinguishable from a wrong base path; where the client throws a typed exception it passes the 3x-ui remedy as its
`hint` — «Settings → Security → API Token» and friends live here, never in the generic presenter) → `ThreeXuiApi` (one
method per endpoint the shop calls: inbound options — the admin-rights check —, `inbounds/list/slim`, server status,
`setting/all`, and `/panel/api/clients/*`: list, get, traffic, onlines, add, update, del, resetTraffic, and
bulkEnable/bulkDisable for the one switch; a client the panel does not have is a `NotFoundException` however the
endpoint words it) → `ThreeXuiProvider` (the generic contract). DTOs in `DTO/` map with `Support\Raw` (tolerant of
int/string/bool/JSON-string values; `totalGB` is bytes; times are unix ms); `Support\ExpiryTime` turns `expiryTime` into
the generic `Expiry` and back (a negative value is the panel's "expire on first use" term). v3 clients are first-class
rows attached to N inbounds; `GET /clients/get/{email}` answers an envelope — `{client: {…row…}, inboundIds,
externalLinks, …}` — while `/clients/list` rows are flat: `ThreeXuiApi::client()` lifts the row out (a flat answer still
maps), and every read-back after add/update goes through it (a client the panel took but cannot show comes back as
sent, without a link, so the sale takes it back). The generic `findClient()` is get + traffic + settings (the
subscription link), plus `/onlines` only when presence is asked; `listClients()` is `/clients/list` — every row with its
traffic block (a row without one asks `clients/traffic`) and one settings read for all the links; inbounds are listed
slim, so no client secret crosses the wire for a probe. Capabilities: inbounds, and link rotation
(`rotateClientCredentials()`: a new UUID/password/hysteria auth on a copy of the row plus a new subscription id, through
`clients/update`, which replaces the row). `tests/Unit/Providers/ThreeXui/ThreeXuiContractTest` holds every request the
connector sends — each `ThreeXuiApi` method and the sign-in handshake — to the fixture: a described method and path, a
JSON body only where one is taken and only with fields it describes; a new method needs its case there (a reflection
guard fails otherwise); `ThreeXuiDriverTest` holds its card, its form's refusals and its kept and moved secrets. Servers
store `api_token` (preferred) or `username`/`password` (+ `totp_secret` for 2FA panels), all encrypted;
`meta.verify_tls`, `meta.timeout`, `meta.subscription_url` tune the connection (`Support\PanelConnection` and
`Support\PanelCredentials`, the fields every connector's form shares — see Adding a connector).
`servers.serves_subscriptions` (nullable bool) is what the last check learned from `ProviderInterface::
servesSubscriptions()` — null = never checked, false = the panel serves no subscription links: `ServerService::check()`
records it (`subscription_probed` says whether the probe could ask) and `Server::servesSubscriptions()` is the one
question — a server without it **cannot be sold** (see Plans), and a freshly added server is unsellable until its
first check.
Naming: user-facing text says **3X-UI** (the display label); code, keys, files and comments say **3x-ui**
(namespace `ThreeXui`, classes `ThreeXui*`, driver key `3x-ui` — `panel:probe 3x-ui …` —, fixture
`3x-ui-openapi.json`) — never "x-ui".

## PasarGuard connector (app/Modules/Providers/Drivers/PasarGuard)

Targets PasarGuard **3.1+** (read against 5.4.1: FastAPI, the API under `/api` at the panel's root, the dashboard at
`/dashboard/`, no `/openapi.json` unless DOCS is on; what was learned is in the connector's doc comments).
`PasarGuardDriver` is the connector (its card — mark «PG», its GitHub page, the notes —, its form: the address, refused
with `/dashboard` or `/api` on it, the way in — an API key, `pg_key_` and a UUID whole, or an admin's username and
password —, the options; its capabilities and
`connect()`); its client is two layers, no API surface beyond the contract: `PasarGuardClient` (transport: an API key as `X-Api-Key` — 5.1+ —, or an admin's
username and password traded at `POST /api/admin/token`, an OAuth2 password **form**, for a JWT the client keeps — it
lives as long as the registry keeps the server's client (see Adding a connector), since every sign-in costs the panel a
bcrypt and its admins a login notification; a kept token refused with 401 is renewed once, a fresh one refused is
`sessionRejected`; JSON in and out; a refusal's `detail` — a sentence,
the panel's own 422 map `{field: msg}` or the framework's list — is read into one line; only a 404 saying `User not
found` is `NotFoundException`, the framework's `Not Found` (an unknown route: a wrong address, a panel before 3.1) is an
`UnexpectedResponseException` — a findClient() that took a wrong address for a missing user would mark services
deleted; a 403 is `insufficientScope` for an API key and a `PasarGuardApiException` with the panel's reason for an
admin; the hints name PasarGuard's places — API Keys, Admins, `/dashboard`) → `PasarGuardProvider` (the generic
contract). Users are addressed as `/api/user/by-username/{name}` (the bare `/api/user/{x}` turns id-based in v6).
**Groups are the inbounds**: a PasarGuard user reaches inbounds only through its groups (`group_ids`; a group holds
`inbound_tags`), so `listInbounds()` is `GET /api/groups` — key = group id, remark = its name, tag = its inbound tags
(cut to 128), no protocol or port (null), enabled = not `is_disabled`, client count = `total_users` — and a plan entry sells
groups (a user needs at least one). Term: `Expiry::afterFirstUse(s)` ↔ status `on_hold` + `on_hold_expire_duration`
(the panel sets `expire` at the first connection after the last edit), `at()` ↔ `expire` (unix seconds out, ISO in — no
zone = UTC), never ↔ `expire: 0` (null on a PUT means "leave it"); a user switched off before its clock started still
reads as a waiting term, and `setClientEnabled(true)` reads the user and sends `on_hold` with its duration itself
(panels before 5.0 make a plain `active` endless). The panel cannot clear a duration (0 = no change), so a pending client
changed to "never" keeps a stale one and goes back on hold if switched off and on — its rule, not worked around. Quota ↔
`data_limit` (0 = unlimited; a new user gets `no_reset`, an update leaves the admin's strategy); one counter,
`used_traffic`, reported as download; the comment (it carries the Telegram id) ↔ `note`; **no IP limit** — PasarGuard's
HWID device limit is another rule (it refuses to drop below the devices already registered), so `ipLimit` is not sent.
Presence: `online_at` within 2 minutes, the panel's own window. Links: `subscription_url` is `{url_prefix}/{path}/{token}`,
a bare path while the panel's subscription settings name no prefix — resolved against `base_url`; `meta.subscription_url`
replaces everything before the token. The token is signed afresh into every answer, each valid until a revoke, so the
kept link changes with every sync and every one keeps working. `listClients()` pages `GET
/api/users?sort=username&load_sub=true` 500 at a time (without `load_sub` the list carries no links) until a short page
or the reported total, at most 100 pages — a bigger panel, or one that never stops answering full pages, throws
`UnsupportedOperationException` and the sync asks for each service. There is no subscription switch:
`servesSubscriptions()` is true without asking. `rotateClientCredentials()` is `revoke_sub` (new
proxy secrets, every earlier link dead). `status()` is `GET /api/system` (the panel host's CPU/RAM/disk/uptime; the cores
run on its nodes, whose rows carry node keys and are not read, so the core state is unknown); `testConnection()` reads
one user (users.read). Servers store the API key in `api_token`, or an admin's `username`/`password`. Tests:
`tests/Unit/Providers/PasarGuard/*` (FakePanel answering raw JSON), `tests/Feature/PasarGuardConnectorTest` (the servers
API, a sale, a sync). Naming: user-facing **PasarGuard**; driver key `pasarguard`.

## Plans (app/Modules/Catalog)

`PlanService` owns validation (Persian messages, Persian/Arabic digits accepted via `Persian::latinDigits`, a price in
whole Toman — `Input::amount()`), create/
update/duplicate/reorder, deletion (refused with `PlanInUseException` once an order or subscription references the
plan — deactivate instead) and presentation (one `presentInbound()`/`presentServer()`, counts computed once for a
page, a grouped read a table: its services — running, and all — and its orders — sold, and all; the row's
`counts.orders`/`subscriptions` are the delete's own rule, so the plans page offers a plan with history no delete, only
why — `RemoveConfirm`'s `kept`); `Admin\Api\PlansController` is a thin wrapper. A plan is sold on one
or more servers — `PlanServer` entries (`plan_servers` + `plan_server_inbounds`, keyed by the pair): either the whole server
(`all_inbounds`: every inbound the server marks `is_selectable`, resolved at purchase time by
`PlanServer::sellableInbounds()`) or a pinned set of its inbounds (must belong to the server and be enabled). A plan
needs at least one entry; the API takes `servers: [{server_id, all_inbounds, inbound_ids}]` and replaces the set.
**The admin may attach any server; the customer is shown only what can deliver today.** `Catalog\Services\
ServerSelector` is that judgement, on top of `Providers\Services\ServerReadiness::problem()` (the one word on a server:
its connector installed, switched on, serving subscription links — `Server::servesSubscriptions()` —, with room —
`Server::hasCapacity()`; the servers screen shows it as `unsellable_reason`, a move is refused with it): `choices(plan)`
= entries whose server is ready and — for a driver with inbounds (`ProviderRegistry::capabilities(driver)->inbounds`)
— has something sellable, in the plan's order; `problem(server, entry)` says why not; a driver without
inbounds sells the whole server with an empty key list; `judge(plans)` says why the bot does not show each plan, its
switch aside — no server on it, none of them able to sell, or (an agent's bot) traffic that does not cover it
(`covers()`; `coverage(plans)` for a list, the agent's traffic read once) —, and `offers(plans)` = the plans it has
nothing against, by id, each with its choices — one weighing of the plans gives both (`weigh()`) —:
`PlanCategoryService::catalogue()` (the groups and each plan's choices: the website's catalogue; `groups()`, the bot's
shop, is its groups) lists only those, so a plan none of whose servers can deliver is simply absent, never "unavailable"; the plan's
entries are read afresh every time, never kept on the `Plan` (one read a while ago is judged by today's servers) — for
every plan of a list at once (`judge()`/`offers()`: the entries, their servers with their running services counted, and the
inbounds, a query each however many plans and servers; the plans screen, the bot's menu and the website ask the same);
`resolve(plan, serverId)` settles the customer's pick (Persian `Catalog\Exceptions\NoServerAvailableException`
otherwise). The plan form and the plans table only *flag* what the customer will not see: an entry (amber badge, its
`unsellable_reason`, `server.serves_subscriptions` on entries and options) and a whole plan — its row's
`unsellable_reason`, the same judgement as the bot's, «در ربات دیده نمی‌شود» beside its name with the reason behind a
press (a plan on no server has a dash for its servers). `orders.server_id` records the choice, and provisioning creates
ONE panel client attached to every inbound of that entry (their `server_inbounds.remote_key` — an opaque string the
driver understands: a 3x-ui inbound id, a Marzban tag, a Remnawave uuid) and **the customer gets one subscription link
and nothing else** (never the per-inbound config links: a panel with thirty inbounds would be a wall of them, and the
link carries them all; `ProviderInterface` has no per-inbound links for that reason). The link is the driver's to
report (`ClientInfo::$subscriptionUrl`) and the shop's to keep: `subscriptions.subscription_url` is written at
provisioning, overwritten by a rotation and refreshed whenever the panel is asked (`apply()` — a panel whose
subscription server moved gives the new link; one that stopped serving links leaves the last one known), and every
message reads the column — no panel round-trip to send a link.
`ProvisioningService::provision(order, complete)` refuses a panel that reports no link (the order fails with that
reason in `order.notes`, the client is deleted again, the admin re-checks the server and retries) and writes the
subscription row, `orders.subscription_id` and the order's completion (`complete`, OrderService's) in one transaction
after the panel call — a transaction that fails takes the client back off the panel too. The
client is **named after the customer** (`Subscriptions\Services\ClientNaming`):
`<username>_<n>` with n from that customer's own counter, across all servers (`amir_1`, `amir_2` …; `sequences` row
`clients.user.<user id>`, from 1), or `USER_<n>` for an
account without a username, n drawn from one counter shared by every nameless purchase (`Core\Database\Sequence`,
`sequences` table — locked read-and-increment, only goes up, gaps are normal): a deleted service's number is never
handed out again. A name a subscription on that server already carries (a username that moved between accounts) is
skipped for the next number — every bot's services on that server count (`ClientNaming::taken()` looks across shops: a
panel's names are one). The client's comment is
`<telegram id> | <username or USER>` — `web#<user id> | USER` for a customer without Telegram (signed up on the
website) — (and `| @<agent bot>` for a customer of an agent's bot) and its `tgId` the
Telegram id (none for one without: `ClientSpec::$telegramId` null, which 3x-ui sends as 0 and PasarGuard does not
send), so the panel's list points back at the account;
a renewal re-sends both. 0 means unlimited for traffic/devices and "no expiry" for duration; `sort`
is the customer-facing order (`POST /plans/reorder`). **The term starts at the customer's first connection**, by
decision: `provision()` sends the plan's days as `Expiry::afterFirstUse(seconds)` (3x-ui: a *negative* `expiryTime`,
which the panel turns into a deadline on the client's first traffic tick; `Expiry::at()` is a fixed deadline,
`Expiry::never()` no term — one value object on `ClientSpec` and `ClientInfo`), so `subscriptions.duration_days`
is the term (0 = never) and `expires_at` / `starts_at` stay **null until the panel has started the clock**
(`Subscription::awaitsFirstUse()`); a panel without delayed expiry answers with the deadline it set itself and the row
takes it. `inspect()` / `syncServer()` take the panel as the truth through one mapping (`apply()`, its row's values
`mirror()`): counters, quota, the link, the deadline once it exists (`starts_at` by `ServiceTerms::startedAt()` — the
row's start, else the deadline less the term —, the one reading of a start, a renewal's preview's too), or a term /
"never" an admin set on the panel —
and the status: an active service whose time or traffic ran out ends, an ended one the panel extended runs again
(support's switch and a deleted row stay). `apply()` writes only over the row as it was read — same server, client and
status, no change holding it, its copy not fresher than the answer (`last_synced_at`) — so a read that raced a renewal
never puts older numbers back and never marks deleted a service that moved meanwhile. Every change made on a panel
(renew, grant, the next period, rotate, switch, move, delete) holds the service first (a `Lease` on its row,
`subscriptions.lease_token`/`leased_until`): a second change waits a few seconds for the first, then is refused with
`ServiceBusyException` (409) — which always means the change was not made. The hold lasts as long as one call to the
panel may take (`ProvisioningService::holdSeconds(server, …others)`: the server's timeout for each of the requests a
call makes, `REQUESTS_A_CALL` (8), and a margin; a move's, the slower of its two servers') and is renewed before every
panel call (`Lease::renew()`), so a change still at work never loses it; one that loses it all the same after the panel
took the change (it stalled past its time, and another took the service) writes what the panel answered on the row by
its key (`record()`, logged) and is done — never undone, never made again; a move that loses its hold before deleting
the old client takes the new one back first. `inspect()` also answers the customer's screen (`?ClientInfo`, null = the client is gone and
the row is marked deleted; presence — `ClientInfo::$online` — only when asked, `presence: true`, null when the panel
cannot tell). `Messages::expiry(at, days)` is the one wording — «۲۷ آذر
۱۴۰۵ (۹۰ روز مانده)», «⏳ در انتظار اولین اتصال (۹۰ روز)», «نامحدود» — for the service screen and `BotText::LinkRotated`;
`BotText::PaySuccess` words the term (`duration()`). What a renewal, a grant and a renewed period give is
`Subscriptions\Services\ServiceTerms` — numbers from the row alone, no panel, no database (`renewal()`, `grant()`,
`nextPeriod()`, and `startedAt()` for when the clock started; `tests/Unit/Subscriptions/ServiceTermsTest`). `renew(order, complete)` reads the panel first (its
deadline and counters are the truth), then: the days left always carry — a running service gets the plan's
days on top of its deadline, one not started a longer term (still from the first connection), an expired one starts the
new term at its next connection; a plan without a term makes it never expire. The traffic left is the admin's call
(`RenewalSettings::carriesTraffic()`, see Renewal): a service whose period has not passed gets the plan's traffic **on
top** of its quota at once, counters untouched — nothing is taken away early. Carried, that is all; not carried (the
default), what the paid period leaves unused goes when it ends: the renewal records `subscriptions.period_ends_at` (the
old deadline) and `next_period_bytes` (the plan's traffic; a renewal queued behind a pending one adds to it), and
`Tasks\NextPeriodTask` (every 10 minutes) runs `startNextPeriod()` then — the panel asked for the counters, what remains
capped at `next_period_bytes` and counted afresh (reset + update; less when the customer already dipped into it; a
service that used everything, or whose client is gone, has nothing to cap; a panel out of reach is tried again — once
the server's backoff is over (`Server::isBackingOff()`, see Sync) — and the customer keeps what they had meanwhile; one
service that breaks is logged and stops no other). An expired service starts a fresh count: the plan's traffic, plus what was left
when carried; an unlimited plan makes the service unlimited (nothing queued). `rotateLink()` («تغییر لینک») asks the driver for fresh
credentials and stores the new link — only for a service `rotatable()` (active, and its driver can: `rotates()`, the
bot's button), else a 422 on `status` before any panel is asked (`ROTATE_INACTIVE`, `ROTATE_UNSUPPORTED`). A customer's
own screen reads its service with `look()` — the bot's and the website's alike: `inspect()` with presence, but never a
panel `Server::isBackingOff()` (a customer does not wait out its timeout) and a failure logged; either way
`Subscriptions\Exceptions\ServiceNotReadException` (a 502 in the customer's words), so the row's copy is never passed
off as fresh. Screen: `pages/plans.tsx` (table, inline is_active switch,
up/down ordering, row menu) + `components/plans/plan-form.tsx` in a `Modal`, with `components/plans/server-entries.tsx`
as the adder (pick server → whole server or tick inbounds → add). Tests fake a panel with `TestCase::fakePanel()`
(it registers `Tests\Fakes\FakePanelDriver`, the connector `fake` with nothing of a connection to fill in, whose client is
`Tests\Fakes\FakeProvider` — one panel per server, each with its own clients (`$clients[server id]`),
so a service can move between two: records `$created` (`{server, inbounds, spec}`), `$updated/$rotated/$enabled/
$trafficReset`, `$deleted` (`{server, name}`) — `updatedNames()`, `lastUpdate(name)` read the updates —; `put(server,
client)` places a client with counters, `mirror(subscription, enabled)` the client a row describes (quota, counters, term,
link), `$online`, `$unreachable` (every call on every panel throws), `$lists` false (panels that cannot list their
clients at once), `$down` (server ids whose panel alone is out of reach), `$failing`
(per server, calls whose connection fails, and how — a `ConnectionFailure`), `$refusing` (per server, the calls
its panel refuses with a `PanelApiException`), `$capabilities`, `$subscriptionBase` null = panels serving no links,
`$whileListing` (a change made elsewhere while a panel lists its clients — the race a sync must survive); reset in
tearDown, since the registry is one per process).

**Categories** (`PlanCategory`, `plan_categories` — just a name, a switch and an order; `plans.category_id` nullable, `nullOnDelete` on MySQL — a deleted
category leaves its plans uncategorised): `PlanCategoryService` (CRUD/reorder with Persian validation; a name unique in
its shop — `unique(bot_id, name)`, the index deciding through `Core\Database\UniqueName`)
and its `groups()` — every active category in order (an empty one is still offered and answers
`BotText::CategoryEmpty` when opened), then the uncategorised (or off-category) sellable plans as one "سایر پلن‌ها"
group. The bot's "خرید اشتراک" shows the category step whenever `isGrouped()` (any active category exists) and lists
plans directly otherwise (`MenuHandler::categoryCallback()`, `cat:0` = other); plan details go "back" to the plan's own list
(`PurchaseHandler::listOf`). API: `/plans/categories` (GET/POST/reorder/PUT/PATCH is_active/DELETE); plans take
`category_id` (blank = none) and present `category`. Screens: `/categories` (`pages/plan-categories.tsx` +
`components/plans/category-form.tsx`, its own nav item under فروشگاه); the plan form (`plan-form.tsx`) is split into
three `PageTabs` sections — مشخصات / قیمت و سهمیه / سرورها — laid out in one cell, the ones not shown `inert` and
invisible (the dialog as tall as its tallest section, so a tab never moves under the pointer), and a refused save jumps
to the first section with an error (`SECTION_OF`), every refusal about the servers listed. Its servers section
(`server-entries.tsx`) says why there is nothing to pick: the options not read (`ErrorState` with «تلاش دوباره»), or no
server — the owner's app hands the plans page where servers are added (`PlansPage({addServers: '/servers'})`, «اول یک
سرور اضافه کنید»), an agent is told to ask support; what is done on a server's page (inbounds marked for sale, read
again) it words the same way — the owner's server page, support for an agent, who has none (no `addServers`). It is one
field of the form: refused, marked invalid as a whole (`data-invalid`, counted with the form's other fields), its
«افزودن به پلن» held, not disabled, while there is nothing to add — the add that took the last server hands the focus to
its «همه سرورها…».

## Users (app/Modules/Users)

"Users" are the shop's customers and nothing else — of its bot, or of its website: a customer's ways in are each unique
in their shop and each may be missing — `telegram_id` (null for one who signed up on the website: the bot cannot write
to them), `email` (lower case, kept only once proven), `password_hash` (only with an email; `$hidden`), `google_sub`.
`UserResolver` creates the row on a customer's first update — the
registration is `Users\Services\Customers::byTelegram(telegramId, profile, referralCode)`, which the website's Telegram
sign-in calls too, beside `byEmail()` (an address proven by its code) and `byGoogle()` (by the Google account, else the
address Google speaks for — an @gmail.com or Workspace one, `GoogleSignIn`'s judgement —, else a newcomer) — see Store API: one way in, whichever door, every newcomer reported and
attributed to their invite code's owner, who is told (`register()`: the row, whoever brought them and the report group's
word in one transaction — a report the database refuses takes the newcomer back, their next arrival registers them
afresh —; the referrer told once it stands, `CustomerNotifier::referralJoined()`) — (`createOrFirst` on `unique(bot_id, telegram_id)`: two
first arrivals at once make one row, and only the one that made it reports the newcomer and their referral; `profile()`
the handle and names as kept, an invisible-only name none) and keeps it fresh without a write per update — the row is
saved only when the profile changed, a block is lifted (whoever writes to the bot has not blocked it: `bot_blocked` false;
a my_chat_member update sets it either way) or `last_seen_at` is a minute old; a visit alone is written quietly
(`Customers::seen()`, `ChangeFeed::quietly()` — a website's request too), so it does not move the panels' users area.
**The owner's panel login is not a user** — it is config.php's `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` (the
password's bcrypt hash — by decision a password kept in plain text is refused: the panel stays closed, the login
answers the not-set-up 503 and the log says why, until a new one is set), read by `Auth\Services\AdminAccount`
(through config/admin.php's part, `admin.username`/`admin.password`) and written by its `save()` (`ConfigFile::setMany()`)
— from the web installer, the owner's own panel or its recovery. **A lost login has its way back in without a shell**
(shared hosting has none — and the shop has no console command for it), by decision: the sign-in page's «رمز را
فراموش کرده‌اید؟» → `/recover` (`apps/admin/pages/recover`) →
`POST /auth/recovery/key` has the panel write a one-time key to storage/recovery-key.txt (`Auth\Services\RecoveryKey`,
a `Core\Security\HostKey` as the installer's `InstallKey` is: four groups of four, no look-alikes; good for
`RecoveryKey::LIFETIME`, an hour from when it was made — the same key while it works, so nobody turns it over while
its owner copies it; the container's `recovery.key`), which the owner reads in their host's File Manager; `POST
/auth/recovery` `{key, username, password, password_confirmation}` (`Auth\Services\LoginRecovery`) sets a new login
by the installer's rule — every refusal at once, a wrong key (`LoginRecovery::WRONG_KEY`) a failed sign-in counted per
address under the throttle's way `recovery`, apart from the password's —, signs this browser in under it, in the shop
the request names (`OwnerAuth::changeCredentials(credentials, shop)`), ends every other session, spends the key and
logs who did it (the panel's `useOwner()`: `recoveryKey()`, `recover()`); a storage/ the key
cannot go to is a 503 saying what to fix (`SignInRefusedException::KEY_UNWRITABLE`). Both routes are public, as the
login is (tests/Feature/LoginRecoveryApiTest).
`Auth\Services\OwnerAuth` is the owner's panel session: it stores a fingerprint of the credentials, so a new login
signs every open session out. Failed sign-ins are counted by `Auth\Services\SignInThrottle` per address
(`MAX_ATTEMPTS`) and, for the owner's password, per username tried too (`MAX_ACCOUNT_ATTEMPTS` from every address
together, not cleared by a sign-in that works); a refusal is `Auth\Exceptions\SignInRefusedException` (401 / 503).
**The owner changes their login from the panel** — «تنظیمات پنل» › «ورود به پنل», /settings/login
(`apps/admin/settings/login-section`, a settings card — held while config.php cannot be written —,
`useOwner().changeCredentials()`) → `PUT /api/admin/auth/credentials`
`{current_password, username, password, password_confirmation}` (`OwnerAuthController::credentials()`): the login's
throttle first (the current username's count, the login's 429); then every refusal of the form at once — the one rule,
`Credentials::fromInput(keepPassword: true)` (a password left blank, its confirmation too, keeps the hash kept), a change
that changes nothing, and the current password: blank, or not the one kept — `AdminAccount::confirm(request,
password)`, the one judging of the owner's password typed again to change what it guards (the Bot API's address moved
too — see Configuration's settings): counted with the login's failures under `AdminAccount::WAY` (`login`), per address
and per username (`SignInThrottle::password()`), so it is no way around the throttle; `AdminAccount::WRONG_PASSWORD`, a
422 on `current_password`, never a 401, which would sign the panel out —;
then `OwnerAuth::changeCredentials()`: `AdminAccount::save()`, and this session held under the new fingerprint with a
new id (`hold()`, the sign-in's own), answering in the shop the request names — every other session ends with the old
fingerprint. The
panel takes the answer's session through `useAuth().renew()`, and the card starts again from the login as it is now,
the passwords typed gone. An agent's panel has nothing of the kind: an agent signs in by link.
`users.role` (`UserRole`, customer|admin; set from a panel — the users list's row menu or the customer's page —, `PUT
/users/{id}/role` `{role}` → `UserActions::setRole()`: the shop's configuration, so never the website's admins') is the
**bot's** admin: `Context::isAdmin()` grants the privileged commands and exempts them from the gates (bot off, phone,
channels), only a bot admin may press the report group's buttons (a receipt's «تایید» / «رد», a delivery's retry, a
ticket's close) or answer a ticket there, and — while the shop's website lets its admins in — a bot admin works the shop
from the website too (see The shop's admins on its website). The two are unrelated by decision — the panel owner is one
login; who may run `/broadcast` is a role.
A customer is shown by **three separate identifiers, never blended**: the Telegram name (`User::name()`, null when
the account shows none — the name slot then reads «بدون نام» so rows keep the same two-line shape), the handle (`@username`, or the words «بدون نام کاربری»),
and the numeric Telegram id (its own column / row, mono). A customer without Telegram (signed up on the website) has
their email where the handle and the Telegram id would be — set apart LTR, «بدون ایمیل» without one —, a dash in the
Telegram id's column, and no way into Telegram (no «گفتگو در تلگرام»: `TelegramChatLink` draws nothing).
APIs send them apart through one shape,
`UserDirectory::presentRef()` (`{id, name, username, telegram_id, email}` — `telegram_id` and `email` nullable; the dashboard, the payments rows and the users
rows all carry it); screens use `components/user-identity.tsx` (`UserIdentity` for two-line cells, `CustomerFacts` for a
detail modal's rows, `userLabel()` for the one-line places in a plain string — toasts, a dialog's title, the tab's name —
where only one identifier fits (isolated: «@amir» stays whole in Persian words; the Telegram id, else the email, else
«#12») and `UserLabel` for one in markup (the
dashboard's latest orders, a list's customer filter),
`TelegramChatLink` for the way to their chat — the public handle's t.me address, else the `tg://user?id=` only the apps
understand —, `userPage(id)` for the customer's page — a name leads there: `UserIdentity`'s `to`,
`CustomerFacts`' name but on that page itself). On the PHP side nothing blends them either: `BotTexts::welcome()` greets without a name when there is
none, the panel client's name and comment are `ClientNaming`'s (username or `USER`, plus the Telegram id — or
`web#<user id>`), and the report group names one without Telegram by their name, their email and «#12»
(`ShopReports::customer()`; no tg:// link).
`Services\UserDirectory` lists and presents the admin's table ("کاربران", `pages/users.tsx` — the list
`components/users/users-list` — → `Admin\Api\UsersController`: `GET /users?search=&status=&role=&group=&sort=&dir=&page=`);
what support decides about an account is `Services\UserActions`', each decision taking the `Auth\Actor` who makes it and
logging them (`PATCH /users/{id}` `{status}` → `setStatus()`, a ban or its end; `PUT /users/{id}/role` → `setRole()`; the
wallet set right by hand, `adjustWallet()`; the website account's `disableTwoFactor()` and `endSessions()` — see Store
API; an admin never their own wallet, and from the website never an admin's or an agent's account, `mayTouch()` — see
The shop's admins on its website): server-side paging (`Page::PER_PAGE`), newest first — or by `sort` `last_seen`, `balance` (the
balance read with the row, `User::balanceOrder()`: no ledger is 0), `orders`, `services` (running), the table's headers —,
the «وضعیت» filter (`status`, active|banned) and the «نقش» one (`role`: «مدیران ربات» — admin, the link of the website's
«مدیران سایت» — or «مشتری‌ها»), and one `search` box (`Page::term()` normalises it: Persian digits, trimmed to 64) over
name / handle (a leading `@` is fine) / email (a piece of it, however it is cased) / Telegram id — `User::matching()` is that one LIKE block, shared with the
payments directory — plus phone (4+ digits) / own id (a bare number); «#12» is the customer numbered 12 alone
(`PageRequest::search()`), the number the table shows («شماره», on a wide screen) and their page says («مشتری #12»). A
search or a filter that finds nobody says which to change (the search, the filters, or both). Rows carry `counts`
(orders, subscriptions, active_subscriptions via `withCount`). Banning and the bot's admin role are the edits here (the bot's dispatcher
answers a banned user with `BotText::Banned`; both are `components/users/account-actions` — `useAccountChange()` (the
role by its own address, a ban by the row's `PATCH`), the menu's `AccountMenuItems`, the second look `AccountChangeConfirm`, the bot admin's `AdminMark` —, the row menu's and the
customer's page's alike). Paged lists in the admin keep search/filter/sort/page in one view, in
step with the list's address (`usePagedList` — the search once typing pauses, `keepPreviousData` while a page loads); the search
`Input` is `dir="auto"` with a physically-left icon so a handle reads LTR and a name RTL; a row's name opens the customer's
page.
**A customer's page** (`/users/:id`, both panels; `pages/customer.tsx`, its cards in `components/customer/`), the one
place support answers a customer from: `GET /users/{id}` (`UsersController::show()` → `Admin\Services\CustomerProfile`:
the shop's own customer — another shop's is a 404 through the shop scope —, `{user, referral, agency}`: their row as the
users table has it (`presentWithCounts()`), the referral program's facts — whose link brought them (a `presentRef()`),
how many theirs brought and what those earned them (`ReferralService::statsFor()`) —, and an agent of the main bot's
agency as the agents list presents it (`AgencyDirectory::presentAgent()`; null for anyone else, so in every agent's
shop)). The page (`Page width="default"`, a page of cards as a server's is; keyed on the customer, so nothing of one is
left on another; an address that names no number is `NotFoundPage`): `BackLink` «کاربران», the header — the name (or
«بدون نام») with the status badge, `AdminMark`, «نماینده» for an agent; under it their number («مشتری #12»), the handle
and «شناسه تلگرام» apart (one without Telegram: «بدون تلگرام»), their email, each set apart LTR, the verified phone, when they joined and were last seen —, «گفتگو در تلگرام» (none without a Telegram account) and the ⋮ of
`AccountMenuItems`; the main column a card each of their latest services, payments, orders and support tickets (`useLatestRows()`: the
list's own read and key, narrowed by `user=`, five shown — each row opening the list screen's own dialog, `useOpenRow`
on the card's copy — its `gone` and `refresh` as on the lists —, the payments' «بعدی» through the card, a service's move in `MoveDialog`;
a ticket's row leads to its own page, `components/customer/tickets-card` —, «همه …» →
`/subscriptions?user=`, `/payments?user=`, `/orders?user=`, `/tickets?user=`), each in its own state (`ListView`: a failed read is its
`ErrorState` with «تلاش دوباره», the others stand; the customer's own read failing later leaves the page as it was, under
its `ErrorState`); the side the wallet (`WalletPanel`, the wallet dialog's own body:
`BalanceAdjust` without «انصراف», the ledger; an agent's credit in its description), the referral facts (the referrer's
page; «n نفر» → `/referrals/invitees?referrer=`), the groups (`GroupsModal`) and an agent's agency (level, bot, traffic,
what it sold — «مدیریت نماینده» → the panel's `agentPage`, the owner's agents list searched by the Telegram id — else the email). What an
operation on a card changes of the customer reads them again (`customerQuery`, `queryKeys.user(id)`, the live updates'
`everyUser`). The list screens take the customer as a filter (`PageRequest::id('user')` in `OrderDirectory`,
`PaymentDirectory` — by the payment's order —, `SubscriptionDirectory`) and say so beside their filters
(`CustomerFilter`); ways in: the users list's names, every dialog's `CustomerFacts`, the dashboard's newcomers and
latest orders' customers, the referrals lists, the agents list (an agent is the main bot's customer: while its shop is the
one open).
`referral_code` / `referred_by` are the referral program's (see Referrals), `agency_level_id` / `credit_limit` the
agency's (see Agency), `totp_*` the website's two-factor sign-in's (see Store API). Two accounts of one person in a shop
— the one they signed up with on the website, the bot's they then linked — are made one (`Accounts\Services\
AccountMerger`, see Store API: the older stays, everything the other owned is its own, `account_merges` records it); the
customer's page shows their website account and the merges in its «ورود به وب‌سایت» card.
**Customer groups** («گروه‌ها», the admin's own: VIP, colleagues, a campaign — a broadcast can go to one):
`Users\Models\CustomerGroup` (`customer_groups`: a name unique in the shop, `sort`; members in `customer_group_user`, `User::groups()`
in the admin's order), `Users\Services\CustomerGroups` (create/rename/delete/reorder with Persian validation, the name
through `Core\Database\UniqueName`; `assign()`
sets a customer's groups as a whole — an unknown id is a 422 on `group_ids`; deleting a group takes nobody's account away,
its customers just leave it). API: `/customer-groups` (GET/POST/`reorder`/PUT/DELETE, `Admin\Api\CustomerGroupsController`)
and `PUT /users/{id}/groups` `{group_ids}`; user rows carry `groups` (`CustomerGroups::presentRef()`) and the list takes
`group=`. Screen: the users page is two sections in the sidebar — «کاربران» at /users (a «گروه‌ها» column, the «وضعیت»
and — once any group exists — «گروه» filter pills beside the search, the row menu's «گروه‌ها» →
`components/users/groups-modal`: with no group yet it says so, linking «گروه‌ها», its save held; a row's dialog closes
as the list's section goes out of sight) and «گروه‌ها» at /users/groups (the list on `useRows`, `components/users/group-form`;
«افزودن گروه» in its header).

## Adding a connector (panel driver)

1. **The connector**: `Drivers/<Name>/<Name>Driver` implementing `Providers\Contracts\PanelDriver` (a driver of the
   kernel, see Drivers) — a stateless definition registered in bootstrap/container.php's `panel.drivers`; a new
   connector must need **no edit outside its own folder** (and that line, and its request shapes in openapi.yaml):
   - `key()`: what `servers.driver` holds;
   - `describe()` → a `Descriptor`: the Persian label and description, the notes (the versions it needs), and its traits
     — `PanelDriver::MARK` (the two letters the add-server picker draws, «3X»), `VENDOR`, `DOCS` (`docs_url`, its
     pages), and `Capabilities::traits()` (`inbounds`, `link_rotation`) —, and the form of a server's connection, made of
     the shared lists: `PanelConnection::address(hint, placeholder, pages, pagesRefusal)` (`base_url`, the kernel's `Url`
     — http(s) with a host, no query or fragment, credentials refused in words of their own (they have fields), none of
     the panel's own pages: 3x-ui's `/panel` and what is under it, `/login`, PasarGuard's `/dashboard`, `/api` —; a panel path in a Persian hint set apart in
     isolates, U+2068…U+2069, or a right-to-left line reads «/panel» as «panel/»), then `...PanelCredentials::fields(token,
     tokenHint, tokenPattern, tokenMismatch, loginHint)` (`auth_mode` token|password — `Enums\AuthMode` —, `api_token`
     shown with the token's way, `username` and `password` with the password's), then its own fields (a field of the
     password's way `when: PanelCredentials::WITH_PASSWORD`; every secret `boundTo: PanelConnection::bound()` — the
     address by its origin: a kept secret never goes to another host, it is typed again or cleared — 3x-ui's
     `totp_secret`), then `...PanelConnection::options(subscriptionHint, subscriptionPlaceholder)` (`verify_tls`,
     `timeout` 5–120 s, `subscription_url`, under «تنظیمات پیشرفته»). A field's key is where it is kept: a column of
     `servers` (`base_url`, `api_token`, `username`, `password`, `totp_secret` — the secrets encrypted), else
     `meta.<name>`; a connector needs no column of its own;
   - `capabilities()` (`DTO\Capabilities`: `inbounds`, `linkRotation`) — what the generic code branches on: a panel
     without inbounds is sold whole, one that cannot rotate credentials shows no «تغییر لینک»;
   - `connect(Server, PanelHttp): ProviderInterface` — its runtime client of one server's panel, built on
     `PanelConnection::of(server)` and `PanelCredentials::of(server)` (`AuthMode` token while a token is kept, else
     password): `ProviderRegistry::forServer()` keeps it (`KEEP_SECONDS`, 300) while the server's connection — driver,
     address, credentials, `meta` — stays the same, so a session or token it signed in for, and what it read of the
     panel's settings, serve the calls that follow; a changed connection gets a fresh one at once (`flush()` /
     `unregister()` for tests; a server not saved yet — a probe of the form — one of its own). `ProviderRegistry` keeps
     the connectors too (`all()`, `has()`, `find()`, `label()`, `capabilities(driver)`; a key no connector has is
     `UnknownDriverException`). `Support\PanelHttp` is the shop's one outgoing client with the request shape every panel
     gets (the connection's TLS rule and timeouts, `PanelConnection::requestOptions()`; a redirect never followed; a
     transport failure → `ConnectionException::fromTransport()`) and the log.
2. **The runtime client**: `<Name>Provider` implementing `ProviderInterface` — the runtime contract alone, panel-agnostic
   on purpose: clients are `ClientSpec` (name, quota, `Expiry`, ip limit, Telegram id — null for a customer without one —,
   comment — a client is made and updated switched on; `setClientEnabled()` is the switch) in and `ClientInfo` (name,
   enabled, counters, quota, `Expiry`, `subscriptionUrl`, `online`, `lastOnlineAt`) out — one at a time (`findClient()`)
   or every client at once (`listClients()`, `online` null: the periodic sync's one read per server; a panel that cannot
   list throws `UnsupportedOperationException` and the sync asks for each); inbounds are `InboundInfo` (opaque `key`,
   tag, protocol, port, remark, enabled, network, security, clientCount); anything a panel cannot answer is a null on the
   DTO (`online`, `subscriptionUrl`, a fixed `Expiry::at()` from a panel that cannot defer the clock) or an
   `UnsupportedOperationException` (`status()`, `rotateClientCredentials()`).
3. **The generic side** — nothing to add: `ServerService` and `Admin\Api\ServersController` are connector-agnostic. A
   server's form is one, every connector's (`ServerService`'s `form()`): its name, the connector's form, then — under
   «تنظیمات پیشرفته», after the connection's options — `capacity` (`Providers\Forms\Capacity`: blank, no limit), `notes`
   (1000 characters at most) and `is_active`; checked as one (`Form::check()`, every refusal at once), each value to its
   column or `meta.<name>`, and the fields the check did not read emptied — the other way in's credentials: a server
   keeps one way in. `GET /servers/drivers` answers each connector described with that whole form
   (`ServerDriversResponse`, `DriverDescription[]`); a server is presented with its `form` — `Form::present()` of what it
   keeps (`ServerRow.form`, `DriverValues`: a secret as whether one is kept; null while this installation has not its
   connector) —; create, update (`driver` named, and a saved server's own: another is a 422 on `driver`), delete
   (`ServerInUseException` → 409), `setSelectable`, `presentInbounds`, probe / check / syncInbounds — a saved server
   whose connector this installation does not have is a 422 on `driver` for a check or an inbounds sync
   (`ServerReadiness::NO_CONNECTOR`), nothing asked nor recorded —; `POST /servers/test`
   probes the form unsaved (`probeInput()`, `id` a saved server's whose stored secrets the blank ones stand for — while
   the address stays on its host). A probe asks each question on its own once the connection is good, so one flaky
   endpoint loses only its answer (`status`, `inbounds`, `serves_subscriptions` null); a check keeps the inbounds only
   when they were listed — a listing that failed changes none —, and an inbound the panel no longer lists is switched
   off, kept with the owner's choice (`is_selectable`) for when it comes back. Every contact is recorded on the server by
   `Services\ServerHealth` (`last_checked_at`, `last_error`; the errors topic hears a panel stop and answer again, on the
   transition only).
   Failures must be the typed exceptions in `Providers\Exceptions`: `ConnectionException` (`failure`, a
   `ConnectionFailure`: dns/refused/timeout/tls/other — `beforeRequest()` says whether a write cannot have reached the
   panel —, built with `fromTransport()`, which keeps no transport exception: its message never repeats the panel's
   address, whose path may be a secret — `[address]` in its place), `AuthenticationException` (`failure`, an
   `AuthenticationFailure`: missing/token/scope/login/two_factor/session/csrf, via its named constructors),
   `UnexpectedResponseException` (404 = wrong base path, redirect, HTML instead of the API), `NotFoundException`,
   `UnsupportedOperationException`, and a `PanelApiException` subclass for "the panel said no, because …";
   `unavailable()` is true for the ones that mean the panel cannot be worked with at all (they put the server in
   backoff). The ones that describe a fixable setup carry a `hint` the driver fills in (its own menu paths and wording).
   `ProviderErrorPresenter` words them for two audiences: the owner, who runs the servers, gets the diagnosis —
   `explain()` (message + detail), `describe()` on one line (`last_error`, a grant's failures) —, anyone else
   `summary()`: what happened in a word, nothing of the panel (an agent, the shop's admins on its website, an agent's
   report group). Which one a reader gets is decided where it is read: a failed order keeps both — `notes` the summary,
   `diagnosis` the owner's — and a screen shows by who reads it (`OrderDirectory::notes()`, `CurrentPrincipal`), a report
   by whose group it goes to (`ShopReports::failure()`), the subscriptions screen's 502 and a move's refusal by who asked
   (`SubscriptionActions`, `MoveException::worded()`). Never hand a raw `$e->getMessage()` to a user. `panel:probe <driver> <url> -t|-u/-p`
   (`PanelProbeCommand`) is the same probe from a shell (`probeInput()`, any connector).
4. **The API**: the connector's closed shape in both `ServerRequest` and `ServerTestRequest` (the latter with `id`) —
   the shared `serverFormProperties` anchor, its own fields and their `clear_<secret>` —, which
   tests/Unit/Drivers/DriverFormsTest holds to its form; `pnpm api:types`.
5. **The panel**: nothing to add. `components/servers/server-form.tsx` (`ServerForm`) draws any connector's whole form
   from its description with `DriverFields` (the name, the way in — the fields its `auth_mode` shows —, its own fields,
   and «تنظیمات پیشرفته» with the connection's options and the server's capacity, notes and switch, closed by default)
   — a kept secret's field says to type it again while the address is on another host —, then «تست اتصال» and the
   probe's outcome (`lib/use-probe`), then the actions; it sends the connector and the fields shown
   (`driverPayload()`) — a new server's to the list, a saved one's to its own address, a test's to `/servers/test`, a
   saved server's under its `id`. The add-server modal (`AddServerModal`, a `PickerModal`) draws each connector's card
   from its description and traits (`mark`, `vendor`, `docs_url` — a link through `externalHref()`), then its form. The
   server page's status card names its way in by the connector's own words (its form's `auth_mode` option: «توکن API»
   for 3X-UI, «کلید API» for PasarGuard).
6. **Tests**: a `<Name>DriverTest` (its card, its form's refusals, kept and moved secrets) and the client's own
   (tests/Unit/Providers/<Name>/, `FakePanel` answering the panel's HTTP); above the connector, `TestCase::fakePanel()`
   registers `Tests\Fakes\FakePanelDriver` (`fake`) with `FakeProvider` as its client (see Plans).

## Payment methods (app/Modules/Payments)

A payment method is a **row** of `payment_methods` — an instance of a gateway *driver* with that driver's `config`
and the admin's `label` (what the checkout button says: "کارت به کارت (ملت)"). Several rows may share a driver: one
per card today, one per merchant account of an online gateway later. `enabled` is the switch, `sort` the checkout
order; the wallet is the built-in row every shop has (a starting row of database/schema.php; an agent's shop gets its
own when it opens, `PaymentMethods::createBuiltins()`; never added again or deleted — `BuiltinMethodException`). A payment records its
`payment_method_id` (required) and reads its driver, card and customer through it and its order; so a method payments
were made with is not deleted — switched off instead (`MethodInUseException` → 409).

A gateway driver (`Payments\Contracts\GatewayDriver`, a driver of the kernel — see Drivers) is a stateless definition
registered in bootstrap/container.php's `payment.drivers`: `Drivers\Wallet\WalletDriver` (`wallet`, built in, no fields)
and `Drivers\Manual\ManualDriver` (`manual`, card-to-card). Its `Descriptor` is what the panels show of it — its words,
its notes, the form of a method row's settings (each field kept in `payment_methods.config` under its key) and two
traits: `kind` (`GatewayDriver::KIND`, a `GatewayKind`: instant | manual — how it settles) and `builtin` (`BUILTIN`: part
of the shop itself, one row in every shop, made with the shop, never added again nor deleted) —; `summary(settings)` is
the one line a payment row and the method's own row show about it (the masked card and holder; null for the wallet;
the panels draw it with `components/payments/method-summary`, the card set apart LTR); `gateway(settings)` is the runtime
gateway of one method row, a `GatewayInterface`: `initiate()` says what the customer does next (`PaymentInitiation`:
instant — the wallet settles at once —, or a `CardTransfer`: the card, its holder, the method's note, which the bot words
as `BotText::CardInstructions`) and `settle(payment)` is the gateway's part of the settlement (`PaymentResult`: the
wallet's debit, or yes for an approved transfer). A row's settings are what its driver's form reads of its config
(`Form::values()`: every field, one the row lacks — kept before the field existed — at its default, so every row matches
its closed schema). `Payments\GatewayRegistry` (over the `Registry<GatewayDriver>`) is what the shop reads of a method
through its driver: `all()`, `find()`, `kind(driver)`, `builtin(driver)`, `isManual(method)` (the one "is this a card
transfer", by the `kind` trait — the payments' rules, the notifier's reminder, the report of a receipt and its review
window), `summary(method)`, `forMethod(method)` and `forPayment(payment)` (the payment's own
method) — a row whose driver is no longer installed has no kind, no summary and no gateway (`forMethod()`:
`UnknownDriverException`). The rest of the shop names the two built-in drivers by their static helpers,
`WalletGateway::key()`/`label()` and `ManualGateway::key()`/`reviewWindow()` (database/schema.php's starting row, the
payment models and the shop's wallet row — queries by the driver's key —, the review window's task, a method's window
read by the receipt handler and the reports); the generic code never knows a driver by class — the payments and
methods screens get `kind` and `summary` from the registry.
`Services\PaymentMethods` owns the label (required, 60 characters at most), the settings checked by the driver's form
(`Form::check()`, every refusal at once with the label's: a blank secret keeps the stored one, a field the form does not
show keeps its stored value), create/update/setEnabled/delete/reorder, the checkout's `forOrder(type)` /
`payable(id, type)` (every enabled method whose driver is installed — a row of one no longer installed has no gateway to
pay with, and the methods page says beside it «به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.» — but the wallet for a
top-up, `pays()`) and `present()` (a row as its driver
describes it: `kind`, `builtin`, `summary`, `config` — its form's `present()`: the API's `PaymentMethodRow` is one closed
shape per driver — `ManualMethodRow` with `ManualGatewayConfig`, `WalletMethodRow`, and `UnregisteredMethodRow` for a
driver no longer installed, `kind` null and no settings shown, since nothing says which of them are secrets — so the
panel narrows a row by its driver, no cast);
`Admin\Api\PaymentMethodsController` = `GET /payment-methods`, `GET /payment-methods/drivers` (`DriverDescription[]`),
`POST` (`PaymentMethodCreateRequest`: a closed shape per driver that is not built in, `ManualMethodCreateRequest`),
`POST /payment-methods/reorder`, `PUT` (`PaymentMethodUpdateRequest`: a closed shape per driver)/`PATCH` (`{enabled}` — a
strict switch, `ApiController::switch()`)/`DELETE /payment-methods/{id}` — every request and row held to its driver's
form by tests/Unit/Drivers/DriverFormsTest. Card numbers go through `App\Support\BankCard` (normalize Persian
digits/dashes, 16 digits + Luhn, mask). Screen: `pages/payment-methods.tsx` (table like plans: ▲▼ order, switch, row
menu edit/delete — a method payments were made with is offered no delete, `RemoveConfirm`'s `kept`; a method is
edited while its driver is installed and not built in) + `components/payments/add-method-modal.tsx` (a `PickerModal` of
the drivers' descriptions — each card its icon and its kind, the built-in one listed but not to be picked → its form)
+ `components/payments/gateways/method-form.tsx` (`MethodForm`, any driver's: the label, the driver's form by
`DriverFields`, the switch; a new method names its driver) + `components/payments/gateways/index.tsx` (`GATEWAYS[key] =
{ icon, Note? }`, typed by the driver's own row — `Note` what the list says beside the summary (a card's automatic
approval) —; `gatewayIcon()`, `gatewayKind()` — a description's `kind` trait — and `MethodNote` read it). Adding a
driver = the driver class and its runtime gateway, its registration in `payment.drivers`, its request and row shapes in
openapi.yaml (`pnpm api:types`) and its icon in `GATEWAYS`. Card-to-card (`Drivers/Manual`): its form is `card_number`
(`CardNumber`, a `card` field: BankCard's normalising and Luhn rule, kept as 16 bare digits — `DriverFields` draws it
grouped in fours, `formatCardNumber()`, with the numeric keyboard), `card_holder` (required, 100 characters at
most), `instructions` (a few lines, 1000 at most — shown in the bot: the customer's text is `BotText::CardInstructions`,
which the checkout fills from the gateway's `CardTransfer`) and `auto_approve_after` (`ReviewWindow`: whole minutes, a
week at most; blank or 0 = only by hand): a receipt sent in the
bot (`payments.receipt_at`, set by `PaymentService::submitReceipt()`; a Telegram file id) nobody reviews inside that window is accepted by
`Payments\Tasks\AutoApproveReceiptsTask` (`PaymentActions::autoApprove()`, `reviewer` stays null — a paid receipt
without one is what `Payment::wasAutoApproved()` reads; a receipt whose order was paid another way is left to the
admin) — **one uploaded from the website (`submitUpload()`) never is, by decision: it always waits for support**, since
its account may be an email address made a moment ago (the report group's line says so; the method's form says it under
the window, the manual driver's notes on the picker card) — and the customer gets the
subscription through `Notifications\Services\CustomerNotifier`
(`paymentSettled()`; the service goes as `ServiceCard::sendSettled()` — `BotText::PaySuccess`, Amir's template, filled
with `ServiceCard::values()`: client name on the panel, plan, server as «لوکیشن», duration, traffic, then the bare
subscription link — the same the checkout sends; `Messages::traffic(bytes)`/`duration()`/`expiry()`/`percent(part, whole)` are the one way to word a
quota, a term, an end date and a share («۸۳٪») everywhere in the bot). The customer is told only the longest wait ("حداکثر تا ۳۰ دقیقه دیگر"), never that
acceptance is automatic.

**One checkout for every door** (`Payments\Services\Checkout` — in Payments, not Orders: it is how an order gets paid,
over PaymentService, which already hands paid orders to OrderService; Orders depends on no service of Payments). The
bot's `Telegram\Handlers\CheckoutScreen` and the website's Store API (`Store\Services\CustomerCheckout`) go through it
and only render what came of it — by decision, never two copies of a rule. `shortfall(user, method, price)` → a
`DTO\Shortfall` (`balance`, `price`, `missing`: the price less what the wallet can pay, an agent's credit counting; the
whole price when it can pay nothing) while the wallet cannot cover it, null for any other way to pay; `pay(user,
method, price, $open, ?key, ?guard)` → a `DTO\CheckoutResult` of a `Enums\CheckoutOutcome`: with a key, the order that
key made first — answered as it stands once it may not be paid any more (below) —; then the shortfall (`short`: nothing
ordered); then `$guard` — the door's own hold, asked once nothing else stands in the way (the bot's: a checkout's wallet
pays once, its chat state claimed — `closed` when refused; after the shortfall, so a short wallet consumes no claim, and
inside pay() so the wallet's balance is read once: BotQueryBudgetTest's 51); then `$open(OrderKey|null)` — OrderService's
`openPurchase()`/`openRenewal()`/`openTopUp()`/`openTraffic()`: the order made, or the open one of the same thing found
— and `PaymentService::createForOrder()`: a card is `transfer` (the order, the payment awaiting its receipt, the
`CardTransfer`); the wallet settles at once — `settled` (the order as it ended: fulfilled, or failed with support's
retry to come), `refused` (the gateway said no as it charged: the payment failed with its note, the order open),
`processing` (paid, its delivery taken elsewhere) —; an order paid or closed elsewhere in the same moment
(`OrderNotPayableException`) is `closed`, nothing charged. `CheckoutResult::order()`/`payment()`/`card()`/`shortfall()`
hand a door what its outcome carries (a `LogicException` for what it does not). **Idempotency** (the website's
`Idempotency-Key`): `request_keys` (`Orders\Models\RequestKey`, BelongsToBot — `user_id` cascade, `key` string 64,
`order_id` cascade, `payment_method_id` cascade — the way to pay the request asked for; nullable, so `db:rebuild` takes
the keys kept before it, which answer whatever way is sent —, `created_at`; its primary key
`(user_id, key)`: one order a key, several keys may name one order) keeps every keyed request's order — whichever it
came to: the one it made, the open one of the same thing it found (made in the bot, or under another key), or the one its
key had made —, written in the transaction that makes or finds it (`OrderService::open()`, the key an
`Orders\DTO\OrderKey` — the key and the method's id —, what `Checkout::pay()` makes of the door's key and its method),
so a request made again finds it however it was paid (a card's order paid by the wallet under another key, made again,
is that order — never a second charge). `open()` looks for the order of that key first (`OrderService::keyed()`, a
subquery of `request_keys`), before any rule of a new order (a renewal under way, a top-up's bounds), and answers it
while it is the same thing asked again — its type and shape (plan, server or service; a top-up's amount, which is what it
buys) — **never its price, by decision**: a request made again after a price change is answered by its own order, at the
price it was made at (`Checkout::pay()` judges the wallet's shortfall by that order's amount) — and its way to pay the
key's (**a key is one checkout attempt, its way to pay part of it, by decision**: else the same order would be paid
another way than first asked) —, else `Orders\Exceptions\RequestKeyReusedException` (422 on `idempotency_key`:
`MESSAGE`, or `otherMethod()`'s `OTHER_METHOD`); of two requests with one key in the same moment, the key's row turns
the second's away (`UniqueConstraintViolationException`, its order rolled back with it → the first's) and the order's
compare-and-swap its payment, and the one that lost answers the order as it stands (`Checkout::standing()`): `settled`
once paid and done with (delivered, failed, refunded), `processing` while its delivery runs, `closed` once cancelled in
that moment, `transfer` while its receipt is with support — and a request made again whose order was cancelled since,
by support or unpaid past its time, `cancelled` (`CheckoutOutcome::Cancelled`: the website's 409 of its own words,
`CheckoutRefusedException::CANCELLED`, never the «همین حالا» of `CLOSED`; the bot's checkout, keyless, never meets it).
`Checkout::replayed(user, OrderKey, $open)` is that first step alone, for a door that judges a new request before paying
it — the website's, the request's `method_id` as sent (one sent without a number goes to the rules): a request made
again is answered by its order whatever changed since (the plan switched off, the wallet spent, the way to pay switched
off), never refused by the rules a new one meets. A key answers for its order `RequestKey::KEEP_DAYS` (7):
`ExpireOrdersTask` forgets older ones. Two accounts merged keep the survivor's key where both used one
(`AccountMerger::REFERENCES`' union on `key`). A keyed request — the website's — makes no new order while its customer
has `OrderService::UNPAID_MAX` (5) pending orders, whichever door made them (`Orders\Exceptions\TooManyUnpaidOrdersException`,
422 «سفارش‌های پرداخت‌نشده شما زیاد است؛ …», counted under the customer's row lock): each takes a receipt kept on the host;
an open one of the same thing asked again is still found. The bot's checkout is not held to it.

**Settlement is a state machine with compare-and-swap.** Every transition is a conditional `UPDATE … WHERE status IN
(…)` through `Core\Database\Transitions::move()` (and `reclaim()` for a stale claim), acted on only when it moved
one row — so an admin approving while the auto-approve task fires, two tabs, or two taps in webhook mode provision
once and credit once — and **only the call that made a move tells anyone**. The settlement (`PaymentService::approve()`
for a transfer, `createForOrder()` for the wallet, which settles at once) runs the gateway's `settle()`, the payment's
claim (`{pending, awaiting_review, failed} → paid`, `PaymentSettledException` on loss) and the order's claim
(`OrderService::markPaid()`, `pending → paid`, `Orders\Exceptions\OrderNotPayableException` on loss — the whole
transaction, a wallet debit and a new payment row included, unwinds) in **one transaction**, the order's other ways
of paying without a receipt going with it (`NOTE_PAID_OTHERWISE` — unless one has a note already: a refused receipt's
reason stands; a receipt in review stays support's); then, after the
commit, the referrer's commission and `OrderService::deliver()`: `{paid, failed} → processing`
(`OrderStatus::Processing`, «در حال ساخت»; a claim older than `OrderService::STALE_PROCESSING_MINUTES` may be reclaimed
by a retry), the panel call outside any transaction, and `fulfilled` **in the transaction that writes what it
delivered** (the service row, the wallet's or the traffic's line — the completion closure `provision()`/`renew()`
take), or `failed` with `notes` — for a panel error the word anyone of the shop may read
(`ProviderErrorPresenter::summary()`) and, apart, `orders.diagnosis` the owner's (`describe()`: the panel's address, its
answer), which only the owner reads (`OrderDirectory::notes()` by `CurrentPrincipal` — the payments rows' order too —;
the main bot's report group, the owner's, `ShopReports::failure()`), both cleared as a delivery is claimed again —, a
rule's refusal (no server, the agent's traffic short), or `DELIVERY_BROKEN` for a fault of the shop's own (logged,
rethrown). `submitReceipt()` (`pending → awaiting_review`),
`reject()` (`awaiting_review → failed`), `cancel()`, `refund()` are the same shape, each a bool / `CancelOutcome` the
caller acts on. **Refunds, by what the order bought** (`refund()`, one transaction, order `{paid, failed, fulfilled} →
refunded` — a delivery under way is a 422 until it is over): a purchase or a renewal — the amount to the customer's
wallet, a delivered service stays (support switches it off from its screen); an agent's traffic — the amount to their
wallet and the GB back out of their pool (`TrafficPool::takeBack()`; refused, `RefundRefusedException`, once sold); a
wallet top-up — support gives the money back outside the shop, so the top-up is debited back out of the wallet
(refused once spent, an agent's credit counting); an undelivered top-up or traffic has nothing to take back. The
refund's note is the customer's wallet line's description too, so it is held to the ledger's limit
(`PaymentService::REFUND_NOTE_MAX` = `Ledger::NOTE_MAX`, 190; `RefundRequest`, the panel's strip counting to
`LEDGER_NOTE_MAX`). A referrer's commission stands. **A delivery whose process died is resumed**: `Orders\Tasks\ResumeDeliveriesTask` (every
minute, every shop) takes the orders paid with nobody delivering them (`Order::stale()` — `paid` or `processing`,
untouched for `STALE_PROCESSING_MINUTES`: the bot restarted between the payment and its delivery, or during it), the
longest-waiting first, one at a time while the run's share of time lasts (`Budget`), through `OrderActions::resume()` →
`OrderService::resume()` — `deliver()`'s claim and work, but only from `paid` or a stale claim, **never a `failed` one**
(a failure waits for support, by design) — and the customer hears it as from a retry: only when it worked.
**Orders nobody pays expire**: `Orders\Tasks\ExpireOrdersTask` (hourly, every shop)
drops an order and its unpaid payments once nothing happened to either for `EXPIRE_HOURS` (48) —
`PaymentService::expire()`, under the order's and its payments' locks, quietly: cancelled with `NOTE_EXPIRED` (a payment
that has a note keeps it — a refused receipt's reason), the rows kept; a receipt uploaded from the website for one of those payments (one support refused) goes once that is committed
(`Receipts::discard()`) —; one whose receipt waits for review is support's whatever its age. The same hourly run forgets
the website's request keys a week old (`RequestKey::KEEP_DAYS`, see the checkout's idempotency). `Order::sold()` (paid|processing|fulfilled) is the sales predicate, `Order::payable()`
(pending, no receipt in review) what a customer may still pay, `Payment::moneyIn()` the money that came in and stayed
(not from the wallet; paid, or refunded but a top-up's) — the dashboard's revenue and the referral commissions go by it.
`reviewer` — the name the deciding `Auth\Actor` keeps (`Actor::name()`): a panel's principal — the owner's login, an
agent's `@bot` —, one of the shop's admins as `Reviewers::forAdmin()` keeps them — their `@handle`, else `tg:<id>`, else
`user#<id>` —, in the report group or on the website; null for the timer, `Actor::system()` — is written once, with the
transition — shown through `Auth\Services\Reviewers::present()` (payments, the wallet's and the traffic's ledger lines,
broadcasts, tickets' messages) **by who reads it** (`CurrentPrincipal`): the owner reads it as kept; anyone else — an
agent, one of the shop's admins on its website — reads the owner's login as «پشتیبانی», since the name would let anyone
lock the owner out (the per-username sign-in limit; tests/Feature/Security/OwnerLoginHiddenFromAgentsTest), and the
shop's own people as kept — and `note` with it — the one word a verdict leaves (a rejection's reason, a cancel's or a refund's note,
the gateway's refusal, `NOTE_PAID_OTHERWISE`, `OrderService::NOTE_EXPIRED`). Ledger lines are `WalletService::LINE_*` /
`describe*()`, order notes `OrderService::NOTE_*` («لغو توسط پشتیبانی» — «مدیر» is never used).

**Payments screen** (`/payments` → `Admin\Api\PaymentsController`: `GET /payments?search=&status=&user=&from=&to=&sort=&dir=&page=`
— newest first, or by `amount`; the «زمان» column is when the payment was made, its receipt's time under it; `from`/`to`
the days it was made (see the list kit's dates), `meta.paid`/`paid_amount` what the list took in — its paid ones and
their sum (`Page::tally()`), the quiet line above it —, `GET /payments/{id}/receipt`,
`POST /payments/{id}/{approve|reject|cancel|remind|retry|refund}`; remind answers `{payment, delivery}`).
`Services\PaymentDirectory` lists and presents (`search(PageRequest): Page`); what may be done and the doing are
`Services\PaymentActions`' — by decision, one service per subject decides **and** tells, wherever the decision is made
(the payments screen of either panel, the report group's buttons, `AutoApproveReceiptsTask`), and the surfaces only
render. `allowed(Payment)` says which operations the state allows (approve = a card transfer —
`GatewayRegistry::isManual()` — & unpaid & order open — a receipt,
or by hand, including after a rejection; reject = awaiting review; cancel = unpaid (`PaymentStatus::isOpen()` —
pending, awaiting review, failed; `PaymentStatus::open()` is that list for a compare-and-swap), drops the open order
too and, with it, the order's other open payments under the same note (`PaymentService::cancel()`, one transaction) —
but not while another payment of that order waits on its receipt's review: that receipt may be the money (the
customer gave up on this way and paid another), so this payment goes alone (`CancelOutcome::Alone`, nobody told) and the
order and that receipt stay support's, which the screen's confirmation says; remind = manual pending/failed with an open order; retry = the payment that paid an order whose delivery stalled
(paid, so standing — its order's `OrderActions::stuck()` —; `OrderActions::stalled()`: failed, or `OrderService::isStale()`
— a processing claim, or a paid order whose delivery never started, untouched for `STALE_PROCESSING_MINUTES`); refund =
paid while its order's delivery is not under way), the row carries them as `actions`, and every operation refuses with a
422 on `status` otherwise, worded by the state the payment and its order are in — most often decided elsewhere a moment
ago: approved, cancelled, refunded, its order paid another way… (`PaymentActions::refusal()`, the report group's stale
buttons say it too) — (the modal then reloads the
list) — also one that lost to another decision in the same moment (`PaymentActions::DECIDED_MEANWHILE`), so the customer
hears only the decision that stood. Each decision takes the `Auth\Actor` who makes it (approve, reject, cancel, refund;
`autoApprove()` is `approve()` by `Actor::system()`), and **who decides is held too**, whatever the state allows: one of
the shop's admins — its customer too — never approves nor gives back their own payment
(`Auth\Exceptions\ActorRefusedException::ownPayment()`, 403: another admin's, or a panel's, to decide — in the report
group the same), and on the website approves only a receipt in review (`receiptFirst()`: approved there without one, a
service is given away) — the settlement's own compare-and-swap moving it only from awaiting review then
(`PaymentService::approve(payment, reviewer, receiptOnly)`), so a receipt rejected or cancelled in the same moment is not
approved after all; the owner and an agent are refused none of it. Rows carry `kind` (the driver's) and `summary` (its one line), the customer as
`presentRef()`, and `receipt` as `{name, note, sent_at}`. `PaymentActions` tells the customer through
`CustomerNotifier` (`paymentSettled`, `paymentRejected`, `orderCancelled`, `paymentRefunded` — `BotText::TopupRefunded`
for a top-up taken back, `TopupRefundedUndelivered` for one never delivered: the balance did not change —,
`paymentReminder`): a cancel speaks only when it dropped an order that was still open, a
retry (`OrderActions::retry()`) only when the delivery worked (the failure was announced once, at approval). A reminder
is telling and nothing else, so it says whether it did: `Notifications\Services\Notices::deliver()` answers a
`Notifications\Enums\Delivery` (told; emailed — a customer Telegram cannot reach, without a Telegram account or turned away
from the bot, the shop's email took it to their address; turned_away — and no email went —, unreachable — Telegram, or
the mail server —, refused; no_telegram — no Telegram account and no email went, nothing asked of Telegram),
`PaymentActions::remind()` hands it on, and the toast words it — «پرداخت #7
یادآوری با ایمیل فرستاده شد» (`EMAILED`, `isDelivered()` in lib/statuses), «یادآوری فرستاده نشد» with why
(`NOT_DELIVERED`, told of whom and whether they have Telegram: «سرور ایمیل در دسترس نبود» for one without); an agent's
new terms the same (`AgencyActions::change()`, `PUT /agency/agents/{id}` answers `delivery` — null when the terms
are the ones they had: nothing changed, nobody told, and the toast says so). The modal (`components/payments/review-modal.tsx`) renders the
operations from `actions` — approving worded by what it brings («تایید و شارژ کیف پول» for a top-up, «تایید دستی و …»
without a receipt) —; worded or destructive ones open an inline confirm strip first; an approve/retry whose delivery
failed (`order.status` failed) shows an error toast with `order.notes` and stays open, since the retry button is right
there — the focus on it. **«بعدی»** at its foot opens the next payment of the list the admin is working through (`lib/use-next-row` —
`useNextRow({queryKey, list, open, onOpen})`: the row after the open one in the list's order, the next page's first at
the end of a page; once a decision took the open row out of its tab, the row that came to its place; a press while the
list is read again waits for it), so receipts are reviewed in a row: a decision keeps the dialog on the decided payment
while a next one waits, «بعدی» in focus (after the last, it closes as before); with none after it «بعدی» is
`aria-disabled`. The tab "در انتظار بررسی" carries the queue count (`meta.awaiting_review`; the dashboard and the
sidebar link here with `?status=awaiting_review`). A receipt is a picture under the shop's one rule for them
(`Users\Services\CustomerPictures`, which `Services\Receipts` speaks for the payments — a ticket's pictures are its own
folder's, see Tickets): one sent in the bot is never kept on disk for good — fetched from Telegram on demand
(`CustomerPictures::fromTelegram()`, `BotApi::fileBytes()`, by `receipt_file_id`) and kept a few minutes for the reads
that follow (`Core\Support\FileCache`) —; one uploaded from the website is a
file of the shop's own, served from the receipts' folder (`receipt_path`); a 404 (`ReceiptUnavailableException`: none
sent, a file Telegram no longer hands over, an upload deleted — `removed()`, its order expired) is told apart from
Telegram out of reach (a 502, `Telegram\Api\TelegramUnreachableException`) — and the endpoint answers it through
`ApiController::bytes()`: a picture the panel shows (by its sniffed type) inline, anything else as
`application/octet-stream` + attachment under a safe file name, never rendered; the modal draws it as an `ApiPicture`,
which asks again about a receipt the browser does not draw (`api.probe()`), offers the download only when the server has
the file and says the server's words otherwise (with «تلاش دوباره» while Telegram is out of reach). A payment takes a receipt while it awaits one —
`PaymentService::awaitsReceipt()`, the one rule of the bot and the website: a card transfer's (a manual gateway's),
pending, its order pending (read off the payment as it was read: a second picture's compare-and-swap decides the
race) —; `ReceiptHandler` accepts **pictures only** — a Telegram photo, or a picture sent as a file, judged by its bytes
(`CustomerPictures::isPicture()`: it says it is an image, is not too big — by what the update says and by the size Telegram
knows, `BotApi::fileBytes(id, maxBytes)`, which fetches nothing larger (an update posted to an agent's own webhook says
what it likes) —, and finfo agrees); anything else (PDF, video,
sticker…) gets `BotText::ReceiptNotImage` and the state stays — and stores what was sent on the payment
(`receipt_file_id`, `receipt_name`, the customer's caption as `receipt_note`, `receipt_message_id`) by a compare-and-swap
(`submitReceipt()`; the website's upload is `submitUpload()`, the same private `submit()`: the move pending → awaiting
review, `receipt_name` as `CustomerPictures::cleanName()` keeps a device's name — its last part, no control codes or bidi
overrides, 255 characters —, `receipt_note` cut to `RECEIPT_NOTE_MAX` (1024), the report to the group): a second picture
in the same moment changes nothing.
**Every message about a decision — settled, rejected, cancelled, refunded — is a bare reply to the receipt message**
(`receipt_message_id`, remembered by `ReceiptHandler`; `reply_parameters` with `allow_sending_without_reply`, a
plain message when there is no receipt) with **no keyboard**, by decision: the customer is not being asked anything.
The admin's note (rejection reason, cancel note, refund note) is one `BotText::AdminNote` line («توضیح پشتیبانی: …»),
or nothing when they wrote none; user-facing wording says «پشتیبانی», never «مدیر». The one message with buttons is
the reminder, which does ask: «ارسال رسید» (`ReceiptHandler::stateFor()`, which `ReceiptHandler` takes as a callback to
re-enter the receipt state) for a payment still waiting for its receipt, and «پرداخت دوباره»
(`PurchaseHandler::reopenCallback()`) — a rejected payment gets only the latter (`BotText::PaymentReminderRejected`). The
rejection text itself points at the menu.

**Orders screen** (`/orders` → `Admin\Api\OrdersController`: `GET /orders?search=&status=&type=&user=&from=&to=&sort=&dir=&page=` —
newest first, or by `amount` —, `POST /orders/{id}/{retry|cancel}`). `Orders\Services\OrderDirectory` lists/presents, `OrderActions` holds the rules
(`allowed()`) and does the work, telling the customer: tabs by status, the first of them **«نیازمند رسیدگی»** — `stuck`
(`OrderDirectory::STUCK`, `Order::stuck()`: paid and not delivered — the delivery failed, or nobody is delivering it,
`Order::stale()` — while the payment that paid it stands: one refunded since gave the money back, nothing is owed; the
one rule of the orders support can act on, `OrderActions::stuck()` of a row, what its retry is offered on), the queue
that waits on support, its count beside it (`meta.stuck`; the dashboard's attention card
and the sidebar link here with `?status=stuck` / `?status=pending`) —, a «نوع» filter pill (`type` param: purchase /
renewal / wallet_topup / traffic — the last in the main bot's shop alone, another the list's `choices` leave out of the
view and the address; the words are `ORDER_TYPE` of lib/statuses, the API sends the type's key only), the «تاریخ» pill (`from`/`to`: the days it was placed), what the list sold above it
(`meta.sold`/`sold_amount`: its orders that are sales, `Order::SOLD`, and their sum),
and one search box — the customer (`User::matching()`), the plan's name, the service's name on the panel, a bare
number; `#id` is that order alone (`PageRequest::search()`, every screen's link to a row). A row
is the order with its customer (`presentRef()`), plan, server (a renewal's is where its service is), service
(`{id, name, status}`), `notes` (why it failed — by who reads it, `OrderDirectory::notes()`: the owner a panel's
diagnosis, anyone else its word —, or why it was cancelled), its payments (`Order::payments()`, newest first)
and `actions`. Two operations belong to the order rather than to one payment: **retry** (paid, but the delivery failed
or nobody is delivering it — `OrderService::isStale()` — while the payment that paid it stands: `OrderActions::retry()`
— the payments screen's retry is the same call —, and the customer hears `paymentSettled()` only when it worked; a retry that finds the
order taken — another process delivering it, or just done; `OrderService::deliver()` answers whether this call delivered
— is a 422 with `OrderService::DELIVERY_TAKEN`, on both screens and the report group's button, so nobody is told twice) and **cancel**
(`OrderActions::cancel()`: nobody paid it — through its newest open payment, `PaymentService::cancelWithOrder()`, which
takes the order and every other open payment of it with it under the admin's note, a receipt in review among them (the
screen's confirmation counts those it drops) — the payments screen's `cancel()` is the same work but spares an order
whose other receipt waits for review —; the order alone when the customer never picked a way to pay; the customer hears
`orderCancelled()` about that payment, and nothing when there was none). A refused state is a 422 on `status` worded by the state the order is in (already cancelled, paid — with the
way to give the money back —, delivered, its delivery under way…), a note too long a 422 on `note` before anything
changes. No path leaves an order failed or paid with its paying payment refunded: a refund moves both in one transaction.
Receipts, refunds and reminders stay the payments screen's: the modal (`components/orders/order-modal.tsx`, on
`components/operations`) lists the payments as links to it. Screens link each other with `?search=#id` (`searchLink()`,
taken in by the list's `usePagedList({address})`): an order's payments → `/payments`, its service → `/subscriptions`, a
payment's order → `/orders`.

**Subscriptions screen** (`/subscriptions` → `Admin\Api\SubscriptionsController`: `GET /subscriptions?search=&status=&server=&user=&sort=&dir=&page=`
— newest first, or by `expires` (a first press reads it soonest first; one that never ends or has not started comes
after every deadline) or `used` (the traffic) —, `POST /subscriptions/{id}/{sync|extend|disable|enable|move|delete}`). `Subscriptions\Services\SubscriptionDirectory` lists
and presents; what may be done and the doing are `Subscriptions\Services\SubscriptionActions`' (below): the tabs are the
statuses plus `expiring` — active services ending within `EXPIRING_DAYS` (3),
`Subscription::expiringWithin()`, the same window the dashboard's attention card counts and links to with
`?status=expiring` (read once into the tab state, like the payments queue); `server` narrows the list to one server's
services (the «سرور» filter pill beside the search box, «همه» = every server; the server page's active-services count links
here with `?server={id}&status=active`, taken in the same way — a server's count is every shop's (`counts.active_subscriptions`,
what its capacity counts) and the list the open shop's, so with other shops' there too the page words both, the link
labelled as the open shop's (`counts.shop_active_subscriptions`: `CurrentBot`, still the panel's shop under
`everywhere()`; components/servers/status-card) — `usePagedList` carries such a filter through its
`params` / `setParam`); the search takes a bare number, the client's name on
the panel, a piece of a link the customer pasted, or the customer (`User::matching()`) — `#id` is that service alone
(`PageRequest::search()`, how the orders' and the payments' dialogs link here); `SubscriptionActions::allowed()` — sync anything
whose client the panel still had, extend an active one that ends some day or has a quota, disable an active one, enable
one that was switched off (an expired one needs a renewal, not a switch), delete anything — rides on the row
(`actions`), and every endpoint refuses with a 422 on `status` otherwise, worded by the state the service is in
(switched off, ended, gone from its panel, already on),
answers a note that is too long with a 422 on `note` *before* the panel is touched, a panel failure with a 502 in
words for whoever asked (`Providers\Exceptions\PanelFailedException`: `«پنل «name»: »` + `panelWords()` — the owner,
who runs the servers, the diagnosis, `ProviderErrorPresenter::describe()`, whichever shop they have open
(`Actor::isOwner()`); an agent and the shop's admins on its website the summary, `summary()`; also under
`errors.panel`), a service another change holds right now with a
409 (`ServiceBusyException`), and the screen's panel work past `SubscriptionActions::PANEL_WORK` (60) operations in
`PANEL_WORK_WINDOW` (a minute) — a sync, an extension, a switch, a move, a delete — with a 429 and its wait
(`TOO_MUCH_PANEL_WORK`): an agent's shop's count (the owner's too while they view that shop), and the website's admins'
in any shop, the main bot's included — the owner works their own shop as they please. Each operation of
`SubscriptionActions` (`sync()`, `extend()`, `disable()`, `enable()`, `move()`, `delete()`) takes the `Auth\Actor` who
asks, and one of the shop's admins — its customer too — never changes their own service: extended, switched off or on,
moved, deleted (`notTheirOwn()` → `ActorRefusedException::ownService()`, 403 — a move that left their old client behind
would be a second service); reading its panel again decides nothing. The panel work is
`ProvisioningService` — `inspect()` (an ended service the panel extended runs again), `setEnabled()`, a compare-and-swap
on the row after the panel call, and
**`delete()`, which leaves no trace, by decision**: the client off the panel (one the panel no longer has counts as
deleted — `ProviderInterface::deleteClient()` throws `NotFoundException` for it, by contract) and the row removed, in
one transaction with the orders that sold or renewed it losing their `subscription_id` (the sale stays on record); the
endpoint answers a bare 204 and the screen drops the row (`usePagedList`'s `remove(id)`). Status `deleted` is now only
what a sync or the customer's screen learns — the panel no longer has the client (`apply()`) — and such a row offers
nothing but delete, which does not ask the panel again. A panel that fails a **delete** does not end it: the modal
turns the confirm strip into `components/subscriptions/skip-server-prompt` (`SkipServerPrompt` — what the panel said,
then «حذف فقط از فروشگاه» / «لغو حذف»; cancel has the focus and changes nothing), and the skip re-sends the delete with
`leave_panel` — the row goes without contacting the panel, the client stays there for the admin to remove (logged; the
screen says whose: the owner's, in an agent's shop support's — the agent reaches no panel; a move's question alike), and
the customer hears as for any delete. Leaving a client on its panel (this, and a move's `leave_previous`) is the owner's
call in the main bot's shop; in an agent's shop, and for the shop's admins on its website in any shop, only while that
panel is out of reach — `Server::isBackingOff()`, which the screen's first, failed try records
(`SubscriptionActions::mayLeave()`, else a 422 on `status`, `LEAVE_REFUSED`): a client left on a panel that answers keeps
working, a second service nobody paid for. The screen says that refusal where it was asked: the delete's question goes back to its confirm strip with it, a move
batch stops on it. The customer hears
through `CustomerNotifier` (`serviceDisabled`/`serviceEnabled`/`serviceDeleted` — plain messages, the admin's note as
the usual `BotText::AdminNote` line, no keyboard): a switch always, a delete only when it took away a service that was still
active (tidying away an ended one is quiet), a sync never — `SubscriptionActions` decides and tells, as `PaymentActions`
does. The modal
(`components/subscriptions/subscription-modal.tsx`): the link with a copy button (a click selects it) — a link that no
longer works (the client gone from its panel, `deleted`, or the service from the shop, `gone`) kept for what it was,
said dead, nothing to copy —, the facts
(`components/subscriptions/service-usage` — `UsageMeter` and `ServiceExpiry`, worded like the bot, `timeLeft()` in
lib/format), the operations (disable and delete open the confirm strip with a note for the customer; «افزایش زمان و
حجم» a form on its strip), the customer's `TelegramChatLink`; it stays open after sync/extend/enable/disable and closes after a
delete. A whole server's services get days and traffic from its page (Server grants, below), every server's at once from
the broadcasts page (Mass gift).

**Extending one service** («افزایش زمان و حجم», `POST /subscriptions/{id}/extend` `{days, traffic_gb, note?, notify?}`,
`SubscriptionActions::extend()`; on the website, the `extend` grant): support makes one customer whole — days and/or GB on top of what the service has,
exactly what a grant gives it (`ProvisioningService::grant()`, never a second copy of `ServiceTerms::grant()`'s
arithmetic). The amounts are a grant's, read the one way (`Subscriptions\DTO\Gift::fromInput()` — the grants' bounds and
words, Persian digits, at least one above zero, else a 422 on `grant`; `note` the one note rule; told unless `notify` is
false), and what the service cannot take is a 422 on its field: days for one that never ends (`hasTerm()`), traffic for an
unlimited one — a service with neither is not offered the operation. The panel is read first (`inspect()`, as a grant's
turn reads it): one it has ended, or switched off itself (`grant()` sends the client switched on), is a 422 on `status`;
then the grant, under the service's lease (409 busy), a panel failure the screen's usual 502. **In an agent's shop the GB
is the agent's, by decision (else an agent mints traffic)**: `TrafficPool::extending()` draws it from their bot's pool —
a line of its own kind (`extension`, its `reviewer` the actor's name) — before any panel is asked (refused with
`TrafficShortException::EXTENSION`'s words under `traffic_gb` when the pool is short) and gives it back (`refund`) when the
extension does not go through, as a failed delivery's is; the days cost nothing, and the main bot's shop draws on nobody.
Logged with the reviewer; the customer hears `CustomerNotifier::serviceGranted()` — the message a grant sends, with the
note as the usual `BotText::AdminNote` line. The screen: `components/subscriptions/extend-form` on the operation's strip
(`GrantAmountFields` — each amount worded for this service, off when it cannot take it —, the note, `GrantOption` for
notify); a refusal under its field, the dialog following the fresh row, a toast; in an agent's shop the GB's hint says it
comes out of the bot's traffic — with what is left in the agent's panel, which reads its account (`apps/agent/queries` —
`accountQuery`) and hands the number to `SubscriptionsPage({traffic})`.

**Moving a service to another server** (`POST /subscriptions/{id}/move` `{server_id, leave_previous?}`, an active one
only) is `ProvisioningService::move()`: the target is judged by `ServerSelector::moveTarget()` (`ServerReadiness`'s
word — switched on, serves subscription links, has room —, and — for a driver with inbounds — the plan's entry for that
server, else every sellable inbound it has; refused with `NoServerAvailableException` → 422 on `server_id`, ««name»:
…», which stops a batch: no other service can go there either), while what concerns this service alone (not active,
already on that server) is a 422 on `status`.
The previous panel is read first (`inspect()`, so the numbers carried over are current), then ONE client is made on the
target — named by `ClientNaming::nameOn()` (the same name unless the target already has it), quota = what is left
(unlimited stays unlimited), expiry = the deadline once the clock started, else the term still from the first
connection — and then the old client is deleted (one already gone counts as deleted). Nothing is half-done: a target
that answers without a link, or a previous panel that refuses the delete, gets its new client taken back (`takeBack()`)
and the service stays where it was. `leave_previous` skips the previous panel entirely — the row's last numbers are
carried (fresh when a first try already read the panel) and the old client stays there for the admin to remove (in an
agent's shop, and for the website's admins, only while that panel is out of reach, as `leave_panel`).
Failures are `Subscriptions\Exceptions\MoveException` with the `step` that failed (`Enums\MoveStep`) and the server it
names: `previous`/`target` → 502 with `errors.previous`/`errors.target` — the panel's failure kept on it and said in a
word (`summary()`) until whoever answers words it for its reader (`worded(words)`: `SubscriptionActions` hands it the
owner's diagnosis or the summary, as for any panel failure on the screen) —,
`service` → 422 `status`. The row takes the new server, name,
link and quota with its counters at zero, and the customer gets `BotText::ServiceMoved` through
`CustomerNotifier::serviceMoved()` (a delivery like any other: the QR card or the text). On the screen, rows have
check boxes (the header's box is the page, a dash for part of it); the selection survives paging and another order and
is dropped by another tab, server, customer or search; the selection bar (sticky to the bottom of the screen, so ticking a row never shifts the
rows) and the modal's «انتقال به سرور دیگر» open `components/subscriptions/move-dialog.tsx` (the batch itself —
queue, progress, the pause on a question — is `use-move-batch`, its one source of truth; while it runs the dialog stays
and closing the tab asks first, and its page going away — the browser's back — stops it after the service under way, a
question it waited on answered «لغو»): the target (inactive or
link-less servers disabled) and the list of services, moved one request each in order with a line of progress per
service («توقف بعد از این سرویس» between two); inactive services and those already on the target are skipped. A previous
server that fails **pauses the batch on a question** (`SkipServerPrompt`: «انتقال بدون حذف از سرور قبلی» / «لغو
انتقال»): the skip moves this service with `leave_previous`, and the rest of the batch from that server goes the same
way without asking again; cancel stops the batch there — what moved stays moved, the rest stays put. A target that
refuses a service or fails to take it stops the batch too, since the next one would fail the same way (`stopped`, that
service: why is said once, on its line; the dialog says what it meant for the rest). The dialog's one way out —
«انصراف», «توقف بعد از این سرویس» while it runs, «بستن» once it is over — is one button throughout, so the focus stays
on it and never drops to the dialog; it takes the focus as the batch starts and as the prompt is answered. A moved service
leaves the selection, a failed one stays ticked, and the batch is one mutation: once it is over — done, stopped or
cancelled — the list (a client found gone on the way is marked deleted), the servers' counts and the dashboard are read
again (its `meta.invalidates`).

**Server grants** («افزودن زمان و حجم», the card on a server's page, `components/servers/grants-card.tsx` →
`Admin\Api\ServerGrantsController`: `GET /servers/{id}/grants` — the latest grants and the `audience` a new one would
reach (`{running, unstarted}`, by the rows: `Subscription::runningCount()` of the active ones, in one grouped read — the
mass gift's every server at once —, and the rest), `POST /servers/{id}/grants`
`{days, traffic_gb, reason?, notify?, include_unstarted?}`, `POST /servers/{id}/grants/{grant}/{run|cancel}`): days
and/or traffic for the services on a server — an outage made good, a gift — with the admin's reason in the customer's
message (`BotText::ServiceGranted` through `CustomerNotifier::serviceGranted()`, the gift worded by `Messages::gift()`,
the reason as the usual `BotText::AdminNote` line; `notify` off = quiet; the terms read by `Subscriptions\DTO\Gift`, as
one service's extension reads them). `Subscriptions\Services\Grants` own it, for
this card and the mass gift alike: a `Models\Grant` (`grants` — the terms, the `audience`, `upto_subscription_id`, the
reviewer: `start(input, actor)`'s `Auth\Actor`) is given as a `Models\GrantPart` per server it reaches (`grant_parts` — the cursor, the tallies, the lease);
one issued here is a grant to this server's services (`audience` server, one part), and this API's `id` is the part's.
**Who gets it**, by Amir's call: the services on the server when it was
issued (`upto_subscription_id` — none bought afterwards) that are **running**; those still waiting for their first
connection only when the admin ticks them in (`include_unstarted`, «سرویس‌های در انتظار اولین اتصال هم شامل شوند»,
off by default — an outage cost them no days); ended ones never. Running is the **panel's** word, read as each service's
turn comes (`candidates()` is every active row, `share()` decides): a customer who connected since the shop last asked
is running though the row still waits, and a row that says active but whose panel has it ended (deadline, traffic) is
passed by. One switched off by support, or on the panel itself (a running row whose client reports `enabled` false), is
left alone; a service without a term (`Subscription::hasTerm()`) takes no days and an unlimited one no traffic — given
nothing, it is passed by (`skipped`). **What it gets** is `ProvisioningService::grant()`: the days onto its deadline or
onto a term still waiting for the first connection; the traffic on top of `max(quota, used)`; a queued renewal period
moves with the days (`period_ends_at`) and keeps the traffic given (`next_period_bytes`). **How it runs:** one service at
a time past a cursor, by whoever holds its lease (`lease_token`, until `leased_until` — as long as a service's hold,
`ProvisioningService::holdSeconds()` of its server, renewed before each panel call —; every cursor move is a
compare-and-swap on it, and a holder that goes quiet loses it) — the card while the page is open (components/grants'
`useGrants`: `run` in a loop, `BUDGET_SECONDS` of work a request, every 30 s while it waits, stopped by a refusal) and `Subscriptions\Tasks\GrantsTask`
(`Grants::runAll()`, every part under way) every minute otherwise. Each service is claimed before its panel is
touched, so none is given twice: a panel out of reach when read — or an update that never reached it (dns, refused,
tls, credentials, not the API) — holds the grant at that service (`waiting_reason`: the owner's diagnosis of what keeps
the shop from the panel, `ProviderErrorPresenter::describe()` or the server's `last_error` while it backs off — shown
on the card under a note that it waits and is tried again by itself, never claiming the panel does not answer —, retried once the
server's backoff is over: meanwhile it is not even asked); an update that timed out is counted failed and not retried
(the panel may have taken it); a service the panel refuses is counted failed (`last_failure`, «سرویس <name>: …») and
the grant goes on; one another change holds is come back to on the next turn. One part runs per server at a time, the
database's word: `grant_parts.running_server_id` is the server while the part runs (a generated column) and unique, so
two grants started in the same moment cannot both run there (a second is a 422 on `grant`, as is a server with nobody to
reach); `cancel` stops it there and the services reached keep what they got. The part's `server_id` cascades on
nothing — MySQL takes no cascading foreign key on a column a stored generated column is computed from (MariaDB does;
SchemaTest holds the schema to MySQL's rule) —: a server deleted takes its parts with it in `ServerService::delete()`.
The numbers read together: a part's `total` is every active service it goes through (the candidates as issued), so the
form says how many it reaches *of* them («از ۳ سرویس فعال روی این سرور، به ۲ سرویس اضافه می‌شود» — the reach by the
rows, the panel's word deciding per turn) and the running card how many of them were checked and what came of those
(components/grants' `progress()`: «۱ از ۳ سرویس فعال بررسی شد · به ۱ سرویس اضافه شد»; `outcome()` once it ends).

**Mass gift** («هدیه همگانی», the owner's second section of the broadcasts page, /broadcasts/gifts, `components/broadcasts/mass-grants-card.tsx` →
`Admin\Api\MassGrantsController`: `GET /mass-grants` — the latest gifts with each server's part, and whom a new one
would reach: `{all, agents}` as `{running, unstarted}` and each server with services —, `POST /mass-grants` `{days,
traffic_gb, reason?, notify?, include_unstarted?, audience: all|agents|server, server_id?}`, `POST
/mass-grants/{id}/{run|cancel}`): the same gift on every server at once — or the agents' services only (what their bots sold), or one server's.
`Grants::start()` issues it as **one part per server it reaches** (`audience` agents narrows `candidates()` to the
services of the bots of agents whose agency stands, `Bot::activeAgents()` — in the reach it counts and in the parts
alike; a server with nobody to reach gets no part), so every rule above holds, each part
is worked through on its own — a panel out of reach holds up its own server only — and one that reaches beyond a server
shows on its server's card («بخشی از هدیه همگانی #n», `mass_grant_id` = the grant's id). Refused (422 on `grant`) while a
server it would reach has a part under way, or when no service could take it; the amounts are the server card's. The
card works it while open (the same `useGrants` runner as a server's card, `run` = a few seconds over its running parts) and `GrantsTask`
otherwise. A grant keeps no status of its own: it is running while a part runs, then cancelled when a part was stopped
(here, or one from its server's page), else done (`Grant::status()`; `finishedAt()` is its last part's end); `cancel`
stops every part still going. The page lists every grant, those issued from a server's page too.

Scheduled work (`bootstrap/schedule.php`, `Core\Scheduling`; the scheduler's state file is the container's
`schedule.state`): `schedule:run` / the `/cron/{token}` URL for webhook
deployments, and `bot:poll` ticks the scheduler itself about once a minute while it polls (`--no-schedule` when a
real cron exists), so in polling mode the bot process is the shop's timer — the run goes in a process of its own
(`Core\Scheduling\BackgroundRun`: `bin/console schedule:run` through `proc_open`, the container's `schedule.background`;
none starts while the last is going; what it ran is printed, a run that broke logged, its process reaped) and the polls
go on meanwhile. It runs inline where no process can be started — PHP's not the command line's (under a web server
PHP_BINARY is php-cgi or php-fpm, which run no console command: `available()` says none there), `proc_open` disabled,
refused at the time, or a PHP that would not run (its run ending with the shell's 126 or 127): the run says why
(`failure()`), the log hears it once, the minute it owed runs at once and every later one inline — the cron URL and the
webhooks never start one. A task that talks to panels keeps its work to one read per server or a time budget either way. The tests run it inline (`schedule.background` null; BackgroundRunTest and BotPollerTest
cover the process). Whichever starts a run, only one runs at a time (see Production). A paid order whose delivery a dying process left is resumed every minute
(`ResumeDeliveriesTask`, see Settlement).

**Sync** (`Subscriptions\Tasks\SyncSubscriptionsTask`, every 15 minutes): the shop's copy of every running service is
refreshed from its panel — `ProvisioningService::syncServer()`: one `listClients()` per server with active services
(every bot's), each row through the same `apply()` as `inspect()` (counters, quota, link, a deadline the panel started at
the first connection, a service that ended) — and only over the row as it was read, so a list that raced a renewal or a
move changes nothing of it. A client the list does not show is asked for on its own (`inspect()`) — only that answer
marks a row deleted, never a list that came back short. Each read is recorded on the server like a check
(`ServerHealth`: `last_checked_at`, `last_error` — the server's page and the dashboard's «سرورهای دارای خطا» show it);
a panel whose last contact failed is left alone for `Server::BACKOFF_MINUTES` (10, `Server::isBackingOff()`), since every
try costs its connect timeout and under `bot:poll` the bot waits that long — by every task that talks to panels (the
sync, grants, the next period, auto-renewal) and by the customer's own screen (the row's last numbers, flagged); the
admin's own «بررسی اتصال» and the subscriptions screen still ask. A task stops at its share of the run's time
(`Core\Scheduling\Budget`).

**Reminders** («یادآوری», `Subscriptions\Services\ServiceReminders`, run by `Tasks\SendRemindersTask` every 15 minutes,
registered right after the sync so it reads what the sync just brought). The admin's rules are
`Subscriptions\Services\ReminderSettings` (settings keys `reminders.*`, the «یادآوری» section of the bot settings,
group `reminders`): `expiry_reminder` + `expiry_reminder_days` (1–30, default 3) — a service ending within that many
days (`Subscription::expiringWithin()`) — and `traffic_reminder` + `traffic_reminder_percent` (50–99, default 80) — a
service with a quota that used that share of it; **both off until the admin turns them on**. Each is sent once while
the service stays past its threshold: a marker on the row (`subscriptions.expiry_reminded_at` / `traffic_reminded_at`)
is claimed (compare-and-swap) before the message goes, and cleared (`rearm()`) once the service is back under the
threshold — renewed or extended past the window, given more traffic, counters reset, no longer running — so the next
time it gets near it is reminded again. A service whose «تمدید خودکار» is on and offered hears about its deadline from
the renewal instead (not about its traffic: the renewal is by date); a banned customer hears nothing; at most
`BATCH` a run, paced as a broadcast is (`Telegram\Api\Pacer`, the bot's one pace for bulk sends: `PER_SECOND`, 20). Messages: `BotText::ExpiryReminder` (the deadline, what is left, and
`BotText::ReminderAutoRenewHint` when the service could renew itself but the switch is off) and `BotText::TrafficReminder` (the share
used — `Messages::percent()` —, what is left of the quota, the deadline) through `CustomerNotifier::expiryReminder()` /
`trafficReminder()`, each with one button, «📊 مشاهده سرویس» (`SubscriptionHandler::serviceCallback()`).

**Renewal** (`Subscriptions\Services\RenewalSettings` — the «تمدید سرویس» section of the bot settings, two cards):
the `renewal` group is the rule for every renewal, by the customer or automatic — `renewal.carry_traffic` (default off:
the traffic a period leaves unused goes when it ends; on: it is added to the renewed period; the days left always
carry — see `renew()` under Plans); the `auto_renew` group is «تمدید خودکار» below. The renewal messages (`BotText::AutoRenewed`,
`BotText::Renewed`) show what is left (`%remaining%`, one of `ServiceCard::values()`) and `%leftover%` under it
(`ServiceCard::renewalText()`): the queued period (`periodNote()` — `BotText::RenewalLeftoverUntil`: how much of what is left goes, when, and what
the renewed period starts with; `Subscription::expiringBytes()`) or `BotText::RenewalLeftoverCarried`; the service screen shows
the same `periodNote()` line while a period is queued, and the admin's subscription modal a «دوره بعدی» fact
(`next_period` on the row).

**Automatic renewal** («تمدید خودکار», `Subscriptions\Services\AutoRenewal`, run by `Subscriptions\Tasks\AutoRenewTask`
every 30 minutes). `subscriptions.auto_renew` is the customer's switch — a new service starts as the admin set it
(`RenewalSettings::autoRenewByDefault()`, default off, written by `provision()`), and the service screen flips it. The
rules are the admin's (`RenewalSettings`, settings keys `auto_renew.days_before` (1–30, default 2) and
`auto_renew.default`, the `auto_renew` card of the «تمدید سرویس» section). The switch is offered (`offeredFor()`;
`offeredForAll()` for a list, the wallet asked once) while
the service is active, ends some day (`duration_days > 0`), still has its plan, and the wallet method is on
(`PaymentMethods::enabledWallet()` — the wallet is what pays; switched off, nothing is renewed), and only then set:
`set()` refuses otherwise (`Subscriptions\Exceptions\AutoRenewUnavailableException`, 422 on `auto_renew`; the bot words
it as its popup). `due()` =
`Subscription::expiringWithin(days)` with the switch on, customers not banned. `renew()` asks the panel first
(`inspect()`: the panel's deadline is the truth — an admin's extension there moves it out of the window; a panel out of
reach, or one the shop leaves alone a while, waits for the next run, nothing charged), then: a wallet short of the
plan's price (an agent's credit counting — `User::spendable()`) is reported **once per renewal
window** (a claim on `subscriptions.renewal_notified_at` against `deadline − days`, so the next window tells again) and
retried every run until it can pay; one that can pays a renewal order (`OrderService::createRenewal()` → the wallet via
`PaymentService::createForOrder()` → `OrderService::deliver()` → `ProvisioningService::renew()`: the plan's days on top of the
deadline, the traffic as the renewal rule says). `claim()` creates that order under a row lock on the subscription and only while no
renewal of it is open — pending, paid, processing, or failed (paid but not delivered: it waits for the admin's retry
from the payments screen, never for a second charge). A service still waiting for its first connection has no deadline
yet, one that never expires needs none, an expired one is renewed by hand (Renewal from the bot, below) — none is due;
a renewal the customer is paying by card (pending) holds the automatic one back like any open one. The task tells the customer
(`CustomerNotifier`): `autoRenewed()` (`BotText::AutoRenewed` — the amount, the balance left, the new deadline), `autoRenewShort()`
(`BotText::AutoRenewShort` with the «➕ افزایش موجودی» button, `TopUpHandler::START`) or `renewalFailed()` (`BotText::RenewFailed` — a
customer charged for a renewal whose delivery failed hears it, whatever broke); an order support cancelled in the same
moment is passed by quietly, nothing charged, and one service that breaks stops no other (`AutoRenewTask` logs it and
goes on, as `NextPeriodTask` does); a
renewal delivered later (the admin's retry) is `ServiceCard::settledText()`'s `BotText::Renewed` — a renewal is always a plain message, its
link did not change. The admin's subscription modal shows the switch as a fact.

**Renewal from the bot** («♻️ تمدید سرویس», `Telegram\Handlers\RenewalHandler`, `Subscriptions\Services\CustomerRenewal`):
the customer renews one of their services on its own plan at its price today, through the checkout every purchase
goes through (`CheckoutScreen`). Ways in: the service screen's «♻️ تمدید سرویس» (`sub:{id}:renew`), the menu's
«♻️ تمدید سرویس» (`menu:renew[:page]`, `MenuHandler::renewals()`: the services the customer may renew —
`CustomerRenewal::candidates()`, active **or ended**, an ended one being what «سرویس‌های من» leaves out —, newest
first, paged as «سرویس‌های من» is, `servicePage()`), and a renewal order's payment reminder («پرداخت دوباره» →
`PurchaseHandler::reopen()`). Who may: `CustomerRenewal::planFor()` — the service active or ended (not switched off
by support, whose switch a renewal would turn back on — `renew()` sets it active), its plan still there (one taken
off sale still renews, as «تمدید خودکار» does) and renewing something (a term or traffic), and in an agent's bot
their traffic covering it (`ServerSelector::covers()`; the delivery draws it) — `plansFor()` judges a list at once (the
website's page, an agent's traffic read once). `renew:{service}:{from}` is the
checkout (`BotText::RenewCheckout`): the plan's term, traffic and price, and the service **as the renewal would
leave it** — `CustomerRenewal::preview()` → `Subscriptions\DTO\RenewalPreview` (the plan, its price today, `after`:
`ServiceTerms::renewal()->preview()`, a copy of the row with the terms applied, nothing saved, no panel asked — the
delivery reads the panel; the service screen the customer came from just did), the one the website's
`GET /subscriptions/{id}/renewal` shows too —, its end and traffic left
(`ServiceCard::values()`) and the traffic of the period in use that goes when it ends (`periodNote()`); «بازگشت» to
where it was opened: `from` 0 the service's screen, n the menu list's page n. `renew:{service}:{from}:{method}` pays:
`OrderService::openRenewal()` — under a lock on the service's row, as `AutoRenewal::claim()` takes it, the payable
renewal of the same plan and price found or one made; refused (`RenewalUnderWayException`) while
`renewalUnderWay()`: its receipt with support, or paid and being delivered or waiting for support's retry — never a
second charge. The wallet renews it at once (the checkout deleted, `BotText::Renewed` sent with «📊 مشاهده سرویس»);
a card waits for its receipt (`BotText::ReceiptOutcomeRenewal`), and support's approval sends `Renewed` as a reply
to it; a delivery that fails is `RenewFailed`. A service the customer may not renew, or one being renewed, is said
so in a popup on the button (`BotText::RenewUnavailable`, `RenewUnderWay`) and nothing is ordered. Tests:
`BotRenewalTest` (the checkout's preview is checked against the renewal the payment made).

**Wallet** (`Users\Services\WalletService` is the only writer of `wallet_transactions`, through `Core\Database\Ledger` —
the one way a ledger is written, the traffic's too: the owner's row locked, the last line read under the lock; two
owners made one — a customer's two accounts merged, `WalletService::takeOver()` — by its `merge()`: both rows locked, the
lines one owner's, every balance counted again in id order).
The balance is the ledger's last line (`balance_after`) — no copy on `users`: `User::balance()` reads it, a list reads it
with its rows (`addSelect(User::balanceColumn())`), and a write reads it again under the lock. A debit may take the balance below zero by the `$credit` its caller passes — an agent's credit (`WalletGateway` passes
`User::credit()`; every other debit passes none), and a credit always lands, debt or not; `User::spendable()` is
balance + credit.
In the bot, "کیف پول" (`MenuHandler::wallet`) shows the balance (`Messages::balance()` — a debt reads «… تومان بدهی»),
an agent's credit (`BotText::WalletCredit`) and the last ledger lines; "افزایش موجودی" is
`Handlers\TopUpHandler` (`TopUpHandler::START` → preset buttons from `WalletSettings::topUpPresets()` + "مبلغ دلخواه",
and — as the screen invites — an amount typed straight away: the screen itself awaits it, state `topup.amount`, as
«مبلغ دلخواه» does; the typed amount takes Persian digits, separators and «تومان» (`Input::amountOf`) and is held to
`OrderService::topUpAllowed()` — the bounds are the shop's wallet rules, `Users\Services\WalletSettings` (the `wallet`
group, on the bot settings screen: `topUpMin()`, `topUpPresets()`, `TOPUP_MAX`), worded once by
`topUpBounds()`, which the website's `POST /wallet/top-up` refuses with too) → `topup:amt:{amount}`, the
checkout (`CheckoutScreen`: every enabled method *except the wallet*, "back" to the amounts) → `topup:amt:{amount}:
{method}` orders it (`OrderService::openTopUp()`) and pays. Settlement is the usual one
(`OrderService::deliver()` credits the wallet with `WalletService::describeTopUp()` in one transaction with the status);
the customer's text comes from `ServiceCard::settledText()/failedText()` (subscription or
`BotText::WalletCharged`/`BotText::TopupFailed` by order type), and `ReceiptHandler` tells them what the receipt brings
(`BotText::ReceiptOutcome*` — `ReceiptHandler::outcome(type)`, the one mapping of an order's type to what it brings,
which a checkout's paid-but-elsewhere outcome words too). From the panel, the users table's row menu
opens `components/users/wallet-modal.tsx` (on `BalanceAdjust` + `LedgerList`, as the agent's traffic modal is): balance, a credit/debit with a note (`POST /users/{id}/wallet`
`{type, amount, description?}` → `UserActions::adjustWallet()`, the amount whole Toman (`Input::amount()`), a debit past
the balance a 422; the note, as an
agent's traffic's, within `Core\Database\Ledger::NOTE_MAX` — 190, the `description` column with room to spare; the line
keeps who wrote it, `wallet_transactions.reviewer` — `WalletService::credit()`/`debit()`'s `reviewer`, null for the shop's
own lines — shown by `Reviewers::present()` beside its balance) and the ledger (`GET /users/{id}/wallet`). Query keys come from `lib/query-keys.ts` (flat by decision — `users`, `userWallet(id)`,
`plans`, `planCategories`, … — so no invalidation catches a sibling by prefix; `dashboards` is the one prefix, so a
review drops every range).

**Referrals** («زیرمجموعه‌گیری», `app/Modules/Referrals`; off until the admin turns it on). Every customer has an invite
code (`users.referral_code`: eight lower-case characters without look-alikes, made the first time
`ReferralService::codeFor()` is asked — the bot's screen) and a link, `https://t.me/<bot>?start=ref_<code>`
(`linkFor()`; null while `telegram.username` is unknown). **Only a customer not in the database yet becomes a
referral, by decision**: `UserResolver`, as it registers someone whose first update is `/start ref_<code>`
(`Update::commandArgument()` is the payload, `ReferralService::codeOf()` its code) — before any gate, so a first /start
the phone or channel rule holds back still counts — makes them the code owner's (`users.referred_by`, through
`ReferralService::attribute(newcomer, code)`, which the registration — `Users\Services\Customers` — calls with the
website's sign-in `referral_code` too: program on, the code whatever its case, an owner who is neither banned nor the
customer) and tells the owner (`CustomerNotifier::referralJoined()`); a
customer the shop already knew never becomes anyone's referral, whatever link they open later. Telegram sends a link's
code only with the /start that link opens — with several accounts on one client the link often opens in another
account, and an account that pressed Start without the link first is known from then on. `bot:poll -v` prints a
command's text (`/start ref_…`) with each update, which shows whether a link's code arrived. **A commission is earned on money that came
in**: the settlement, once it has won the payment's claim, calls `ReferralService::reward()` after the
commit (a failure is logged and never stands in the payment's way): a payment not made from the wallet — card-to-card
or a gateway, for a purchase, a renewal, a wallet top-up or an agent's traffic — of a customer with a referrer who is not banned earns
that referrer `ReferralSettings::rate()` percent of it (`Money::percentOf()`: whole Toman, rounded down; nothing when
that is 0), or only the customer's first money in with `first_only` — decided under a lock on the customer's row: no
money of theirs came in before it (`Payment::moneyIn()`, by `paid_at`, then number) and none of their payments earned
a commission yet, so of two settled in the same moment one earns; the `referral_commissions` row (`payment_id`
unique — once per payment; `referrer_id` whom it was credited to, the customer's `referred_by` as it was earned — **kept,
by decision**: two accounts merged since may give the customer another referrer, and what was earned stays its referrer's
(`AccountMerger::REFERENCES` moves it with that referrer's own account); the rate and the commission; the customer and
the amount are the payment's: `ReferralCommission::withPayer()` joins them) and the wallet
credit (`WalletService::describeReferral()`) go in one transaction. Every reading of what was earned goes by
`referrer_id` (its index `referral_commissions_earned_index`, summed from the index alone): `statsFor()` — the bot's
screen, the website's `/referral`, the customer's page —, the referrers list, the commissions list's referrer, the
referrer's notice and the report's line; an invitee's `earned` is what their payments earned the referrer they have
(`referrer_id` = their `referred_by`). A purchase from the wallet earns nothing — its money was counted when the wallet
was charged — and a refund leaves the commission standing: it was earned for bringing a customer who paid (a purchase's
money stays in the shop as the customer's balance; giving a top-up back is the shop's own call). The referrer hears it with the payment's own notice: `CustomerNotifier::paymentSettled()` ends with
`commissionEarned()`, which claims the row's `notified_at` so a retry's second notice does not tell them twice. The
rules are the bot settings' `referral` group (`Referrals\Services\ReferralSettings`: `referral.enabled`,
`referral.rate` 1–100, default 10, `referral.first_only`; the «زیرمجموعه‌گیری» section). In the bot, «👥 زیرمجموعه‌گیری»
(`MainMenu::AFFILIATES` → `MenuHandler::referral()`) shows the link in a `<code>` span, the terms (`ReferralTermsEvery`
/ `ReferralTermsFirst`, with the rate) and the customer's numbers (`statsFor()`), with a share button (Telegram's
`t.me/share/url`); `ReferralOff` while the program is off or the bot's @username unknown. The admin's page is
`/referrals` (`pages/referrals.tsx` → `Admin\Api\ReferralsController`, `Referrals\Services\ReferralDirectory`):
`GET /referrals` (the rules and the numbers, over every section) and three paged lists — the page's sections in the
sidebar, /referrals, /referrals/invitees, /referrals/commissions —, searched by the customers on either side —
`/referrals/referrers` (most referrals first — or by `earned`, `last_referral` —, with what they earned; the count opens
the next list narrowed to that referrer), `/referrals/invitees` (who brought whom, newest first — or by `earned` —, with
what each one's payments earned their referrer; `referrer=` one referrer's alone — the ones their link brought, never
their own row, which a search by their handle finds too —, said by the list's «معرف» `CustomerFilter`) and
`/referrals/commissions` (newest first — or by `commission` —, also searched by a payment's number — «#12» payment 12's
alone: the list numbers its rows by their payments —, linked to the payments screen); each list's `sort=&dir=` its
table's headers, every customer's name their page; numbers that could not be read are the page's `ErrorState` over
«—». One level only: a referral's
own referrals earn their referrer nothing. The «زیرمجموعه‌گیری» button is on the default start menu, and
`MainMenu::markup()` leaves it out while the program is off (`KeyboardLayout::without()`), so it can stay on the admin's
layout; a layout saved without it (one from before the feature) gets a warning on the referral section and the referrals page
while the program runs (`components/bot/menu-button-notice` — `MenuButtonNotice action="affiliates"`, read off
`GET /keyboards`; the agency's «نمایندگی» button gets the same).

**Agency** («نمایندگی», `app/Modules/Agency`; off until the admin turns it on). An agent runs **a bot of their own** —
its own customers, plans, categories, payment methods, texts, keyboards and settings, built on the shop's servers (see
Agent bots) — and buys the traffic it sells from the main bot, at their **level's price per GB**; their wallet with the
shop may go below zero by the credit support gives them. Agents are customers of the main bot. Tables: `agency_levels`
(name, `price_per_gb` — whole Toman, `Input::amount()` —, `sort`), `users.agency_level_id` (restrictOnDelete — a level agents are on is not deleted,
`LevelInUseException` → 409) and `users.credit_limit`, `agency_requests` (the customer's `note`, `status`
pending|approved|rejected, the level given, the `reason` of a rejection, `reviewer`, `decided_at`), the agent's bot (a
`bots` row, `user_id` the agent — `User::ownBot`) with its traffic (`traffic_transactions`; what it may still sell is the
last line's `balance_after`, `Bot::trafficBalance()`). `User::isAgent()` = a level (the program's switch only takes
requests and shows the menu's button to customers — an agent keeps their account and their bot either way);
`User::credit()` / `spendable()` (balance + credit) are what `WalletGateway` debits with; `AgencyLevel::priceOf(gb)` =
the level's price × GB. The work is split by what it touches: `AgencyActions` decides — a request, a verdict, an agent's
terms, their traffic set right, the agency ended — each in one transaction and told to the customer by the call that
made it (a request and its verdict are reported to the group too); `AgentBots` is the agency's one part that talks to Telegram (`connect()`,
`loginLink()`, `resume()`, `takeDown()`); `AgencyDirectory` lists and presents; `TrafficPool` is the traffic's ledger.
**Joining is a request**: «نمایندگی» (`MainMenu::AGENCY`, the menu
action `agency`; on the default start menu, left out by `markup(user)` while the program is off — but not for an agent —;
never on an agent's bot's menu, `MainMenu::absent()`) shows a customer the terms with the levels (`BotText::AgencyTerms`, a level a line —
`BotText::AgencyLevelLine` — with its price per GB) and «درخواست نمایندگی» → a few words about themselves (state
`agency.note`, ≤ `AgencyActions::NOTE_MAX`) → `AgencyActions::request()` (under a lock on the user row: none while one
waits or they are an agent already — `mayRequest()`) → the report group's
**نمایندگی** topic (`Topic::Agency`, `ShopReports::agencyRequested()`) with «✅ تایید» / «❌ رد» (`Reports\AgencyReview`,
callback `ag:<ok|lv|no|bk>:<request id>[:<level id>]`, the main bot's admins only through `GroupButtons::admin()` — a press
an agent's bot receives is only acknowledged: callback data is the client's to write, and an agent is their own bot's
admin): «تایید» turns
the buttons into one per level (named with its price per GB), a level approves with the program's default credit; «رد»
rejects with no note (the page takes one). `approve(request, actor, level, credit)` / `reject(request, actor, reason)`
take the `Auth\Actor` who decides (the group's buttons: `Actor::groupAdmin()`), and a bot admin's own request is another
admin's to approve (`ActorRefusedException::ownRequest()`, 403 — the button stays for the others). Both are a
compare-and-swap on the request
(`Transitions::move()` from pending), so the group and the page decide once — the other is a 422 on `status` saying
the verdict that stood (`AgencyActions::decided()`: «این درخواست تایید شده است.»; a stale button's popup too) —, and
the verdict goes to the topic as a reply that takes the buttons off
(`agencyDecided()`, `clears_buttons`). **Approval opens the agent's shop**: their bot's row (no token yet) with its
built-in wallet (`PaymentMethods::createBuiltins()` in that bot's shop), or the one they had, switched on again
(`AgentBots::resume()` after the commit). An agent's terms are `AgencyTerms` (`fromInput()`: a level, and a credit held
to the default credit's own rule, `Core\Forms\Fields\Amount`: whole Toman).
An agent's bot runs exactly while its agent has a level (`Bot::status()`, `Bot::activeAgents()` — nothing stored), so
`revoke()` ends it by taking the level and credit away (a debt stays owed): their bot stops (the poller and its webhook
drop it), `panel_epoch` goes up (their panel
sessions end), its webhook is taken down; the shop and its traffic are kept for when the agency is given back.
`change()` and `revoke()` write only while the customer is an agent (one conditional update — an agency ended in the
same moment is neither given back nor ended twice); anything else is a 422 on `status` (`AgencyActions::NOT_AGENT`, or
`AGENCY_ENDED` for one whose agency ended — their shop is kept, which tells them apart). The
customer hears through `CustomerNotifier`: `agencyApproved()` (level, price per GB, credit), `agencyRejected()`
(support's note as the usual `BotText::AdminNote` line), `agencyChanged()`, `agencyRevoked()`. **The agent's account**
(`Handlers\AgencyHandler`, prefix `agency:`, the main bot only): `BotText::AgencyPanel` — level and price per GB, the
traffic their bot may still sell, wallet and credit, their bot and what it sold (`Bot::stats()`: customers, services
sold, running — read with the row for a list, `Bot::statCounts()`), a problem their bot has (`BotText::AgencyBotProblem`) — with «💾 خرید حجم» (`AgencyHandler::TRAFFIC`), «🤖 ربات
من», «🔐 ورود به پنل» and the top-up. **Buying traffic**: `agency:traffic` → the presets as buttons
(`AgencySettings::trafficPresets()`, each «%traffic% · %amount%», `agency:tb:{gb}`) or any amount typed (state `agency.gb`,
«۲۰ گیگ» fine, `OrderService::trafficAllowed()`) → the checkout (`CheckoutScreen`, `BotText::AgencyTrafficCheckout`; the
wallet pays it like a purchase, with the agent's credit) → `agency:tb:{gb}:{method}` orders it
(`OrderService::openTraffic()`: an `OrderType::Traffic` order, `orders.traffic_bytes`, at the level's price) and pays →
settled, `OrderService::deliver()` adds the traffic (`TrafficPool::add()`) in one transaction with the
order's completion; the agent reads `BotText::AgencyTrafficAdded` (a receipt: `BotText::ReceiptOutcomeTraffic`), the
shop's group a report under نمایندگی. **Their bot**: `agency:bot` → `BotText::AgencyBotNone` (make one in @BotFather and
send its token here) or `BotText::AgencyBotInfo` (the bot, running or its problem); the token typed in state
`agency.token` is taken off the chat at once (`Context::deleteIncoming()`) and handed to `AgentBots::connect()`: not the
main bot's (`TOKEN_MAIN`), asked who it is (`Telegram\Api\BotToken::identify()` — the shape, Telegram's refusal and its
silence, in its words), not
another agent's bot (`TOKEN_TAKEN`), and the same bot again when they had one (a token rotated in @BotFather; another bot
in its place is refused, `TOKEN_OTHER_BOT` — its customers know the old one); saved (token encrypted, `telegram_id`,
`username`, `title`, `connected_at`) and `BotLifecycle::follow()` — a webhook of its own when the shop runs on webhooks,
taken off any webhook otherwise (bot:poll takes it up on its next round) → `BotText::AgencyBotSaved` with «ورود به پنل».
**Their panel** (`/agent/`, see Agent bots): `agency:login` → `AgentBots::loginLink()`: a one-time code
(`bots.login_code` is its sha256, good for `LOGIN_MINUTES` = 10, a new link replaces the last) in the fragment of
`{APP_URL}/agent/login#code=…` — never sent to a server, so no access log holds it (`BotText::AgencyLogin`, with a URL
button when the address is https). The rules are the shop's, not a bot's (`AgencySettings`, the main bot's settings
whichever shop reads them: `agency.enabled`, `agency.default_credit`, `agency.traffic_presets` (GB list, default
50/100/200/500), `agency.traffic_min` (GB, default 10)) — the «تنظیمات» section of the owner's agents page
(`GET|PUT /api/admin/agency/settings` `{enabled, default_credit, traffic_presets, traffic_min}`,
`Admin\Api\AgencySettingsController`) —, and the report topic's switch (`reports.topic.agency`). The texts are the
`agency` group of the bot texts (`BotText::Agency*`, plus `WalletCredit` of the wallet's group — an agent's credit is on
their wallet with the main bot) — the main bot's alone: an agent's shop neither lists nor rewords them
(`TextCatalog::groups()`/`says()`, `MAIN_BOT_ONLY_TEXTS`). The owner's page is `/agents` («نمایندگان»,
under فروش; `apps/admin/pages/agents.tsx` → `Admin\Api\AgencyController` + `AgencyLevelsController` +
`AgencySettingsController`, `Agency\Services\AgencyDirectory` / `AgencyActions` / `AgencyLevels`): `GET /agency` (the
numbers alone — `{levels, agents, bots, pending}`, `bots` = agents' bots that run; the rules are `GET /agency/settings`;
the pending count also rides beside «درخواست‌ها» in the sidebar; `MenuButtonNotice` while the program runs, in the main
bot's shop), four sections in the
sidebar — requests at /agents
(`GET /agency/requests?status=&search=&sort=&dir=&page=`, newest first — or the oldest, `dir=asc`; the «زمان» column is
when a request came, who decided it and when under its status —, `POST /agency/requests/{id}/approve`
`{level_id, credit_limit}` → `components/agency/approve-modal`, `…/reject` `{note?}` → `reject-modal`), agents
at /agents/list (`GET /agency/agents?level=&search=&sort=&dir=&page=`, newest first — or by `balance`, `traffic` (their
bot's), `sold`, each read for an agent's row as the row shows it (`User::balanceOrder()`, `Bot::trafficBalanceColumn()`,
`Bot::soldColumn()`; none is 0) —: each with their bot — `presentBot()`: username, title, status, connected,
problem, traffic — and what it sold (`counts`: customers, sold, active), the balance in red while in debt, the name
their page as a customer (while the main bot's shop is open; the page's agency card leads back here by the Telegram id);
`PUT /agency/agents/{id}` `{level_id, credit_limit}`, `POST /agency/agents/{id}/revoke` `{note?}`, `GET|POST
/agency/agents/{id}/traffic` (the ledger; `{gb, note?}` sets it right — `AgencyActions::adjustTraffic(agent, actor,
input)` → `TrafficPool::adjust(bot, actor, bytes, description)`, the line's `reviewer` the actor's name, a negative gb
takes away, never below zero) — a customer who is no agent is a 422 on `status`, one who does not exist a 404 —
from `agent-modal` (`AgentModal`, `TrafficModal`) and the row menu's «باز کردن فروشگاه» (the
owner opens that agent's shop in their panel: a real link, `useOpenShop().link()` — a new tab as any link; see The shop
a panel request works in), levels at /agents/levels («افزودن سطح» in its header;
`GET/POST /agency/levels`, `POST /agency/levels/reorder`, `PUT/DELETE /agency/levels/{id}` `{name, price_per_gb}` — a
unique name, `Core\Database\UniqueName`: checked with the form's other mistakes, and a name another save took in the same
moment refused the same way by the table's index (customer groups too) —,
`level-form`) and the rules at /agents/settings (a settings card; a save also reads the numbers again) — each section a
list of `apps/admin/agency/` on the page's `SectionedPage`, their reads in `components/agency/queries`;
`components/agency/agency-terms-fields` is the level + credit pair the approval and the agent modal share,
`components/agency/traffic-ledger` the traffic's lines (here and on the agent's account page). Live: the `agency` area
(`agency_levels`, `agency_requests`, `bots`, `traffic_transactions`; an agent's level is a `users` write, which refreshes
the agents list too).

**Agent bots** (multi-tenant, `app/Modules/Bots`). The installation runs the main bot and every agent's, each with **a
shop of its own** in one database. `bots` is the list (#1 = the main bot, a starting row of database/schema.php, its token
and @username in config.php only — its row holds none; an agent's row holds its encrypted token, `telegram_id`, `username`,
`title`, `problem`, `webhook_secret` (encrypted too, `Encrypted` — a text column), `panel_epoch`, the login code — whether it runs and its traffic are read, not kept:
`status()` is its agent's level, `trafficBalance()` its ledger's last line). Every row of a shop says whose it is with `bot_id`
(default 1): users (one person who talks to two bots is two customers — `unique(bot_id, telegram_id)`), customer groups,
wallet lines, plan categories, plans, subscriptions, payment methods, orders, payments, referral commissions, settings
(`unique(bot_id, key)`), telegram sessions (`unique(bot_id, chat_id)`), the updates it took (`telegram_updates`, keyed
`bot_id` + `update_id` — each bot numbers its own), required channels, broadcasts, report topics and
messages, custom emoji, support tickets (their messages through them). The servers, their inbounds, the grants and their parts, sequences, the change feed, the agency's
levels and requests, the bots and their traffic are the shop's as a whole. **`Bots\CurrentBot`** (static, per process) is
whose shop the code works in: `get()` / `id()` / `isMain()` (nothing set = the main bot's — the CLI, the tests), `run(bot,
fn)` (do the work in that shop and come back, even on a throw — a panel's request is worked so, `PanelAuthMiddleware`, and
a website's, `StoreMiddleware`), `everywhere(fn)` (no shop hidden: the servers' work), `main()` (the main bot's row),
`reset()` (between tests). **`Models\Concerns\BelongsToBot`** (on those models) adds the
global scope `CurrentBot::SCOPE` — a query sees the current bot's rows only, none hidden `everywhere()` — and fills
`bot_id` on create (`forNewRow()` throws everywhere: such a row must say whose); `$row->shop()` is its bot (the main one
without a query), for `CurrentBot::run($row->shop(), …)`. A relation to a main-bot customer from anywhere (`Bot::agent()`,
`AgencyRequest::user()`, `AgencyLevel::agents()`) drops the scope. **`Settings`** keeps each bot's rows (`get/set/read/
present/save(…, ?int $bot)`, the current bot's by default — `forget(key)` the current bot's alone; `AgencySettings` names
the main bot); so are `BotState`, the texts,
the keyboards, the bot settings, the report group (`ReportGroupState`'s row, `ReportSettings`' topic switches), the QR background (an agent's file is
`background-<id>.<ext>`). **`BotApi`** speaks as the current bot (the main one with config.php's token, an agent's with its
row's) — the same services serve every shop; `forToken()` speaks with a token no bot has yet, `withHttp()` over the
poller's client, the premium-emoji "no" is each bot's own (`PremiumEmojiStatus`, its settings). `Bots\Services\Bots`: `username(bot)` (main → config.php),
`hasToken()`, `serving()` (main when it has a token, then every agent's bot whose agent has a level, with a token — read afresh),
`shops()` (main always, then the serving agents' — the scheduler's list). **Updates**: `bot:poll` (a thin command:
options, the one-poller lock, the `--watch` supervisor) runs `Telegram\Polling\Poller`, which polls every serving bot at
once (one long poll per bot over a curl multi handle of its own, `LongPolls`, `getUpdatesAsync()`; the list read again
every `REFRESH_SECONDS`, a bot taken up gets its webhook taken down and its identity asked; every bot whose poll has
answered is served in the same round — each answer dispatched in its bot's shop, then that bot's report queue sent. A poll
moves only while the poller waits on the handle, so its HTTP time limit is its own plus `BotApi::LONG_POLL_SLACK` (60 s):
the time the poller spends serving other bots counts against it — before, a batch that waited on a panel (10 s connect
timeouts) and the scheduler's inline run let agents' polls time out at 20 s with nothing read (cURL error 28); an agent's bot whose token Telegram refuses or that another program polls
waits `REFUSED_SECONDS` with the reason on its row — `Telegram\Services\BotHealth` (`TOKEN_REFUSED` / `TOKEN_IN_USE`,
cleared once it runs again), which `bot:webhook:set` and `BotLifecycle::follow()` keep too in webhook mode; the main
bot's 409 ends the poller); webhooks (`Telegram\Controllers\WebhookController`): `POST /webhooks/telegram/{secret}`
(main) and `POST /webhooks/telegram/bot/{id}/{secret}` (an agent's, its own `webhook_secret`). The secret — in the path,
and in Telegram's `X-Telegram-Bot-Api-Secret-Token` when it sends one — is checked first of all, in constant time: a
wrong one is a 403 whether or not there is such a bot, or it serves, so the answers tell nobody which bots are here, and
the log hears of `REFUSALS_LOGGED` (10) of them in 10 minutes a webhook (a bot's own window; the calls naming no agent's
bot share one) — anyone may call, and must not fill the log. A bot whose agency ended answers 200 and does nothing. An
agent reads their webhook's address — its secret with it — from Telegram with their own token, so what arrives there may
be theirs: their bot takes `AgentWebhookBudget::UPDATES` (600) updates a minute and `NEWCOMERS` (300) an hour from
people it has never seen (each would register a customer), past either acknowledged and dropped, the log told once a
window (polled updates and the main bot's are never counted); Telegram holds an agent's webhook to
`BotLifecycle::AGENT_CONNECTIONS` (4) calls at once (`setWebhook`'s `max_connections`; the main bot keeps Telegram's
default); and the dispatcher serves a private update only when its chat is its sender's — anything else is no update of
Telegram's, left alone, every bot's. `bot:webhook:set|delete` and `bot:info` go through every serving bot (`Bots::eachServing(work)`: each
in its own shop, one bot failing keeps it from none of the others, each bot's `Bots\BotOutcome` — what the work gave
back, or what stopped it —, which `Console\Commands\EveryBotCommand::report()` prints), and `BotLifecycle::follow(bot)`
puts a newly handed-over bot in the main bot's mode. Putting every bot on webhooks or taking them off is
`Telegram\Services\BotWebhooks` (`enable()`/`disable()`: an APP_URL that is not HTTPS — `BotLifecycle::webhookBase()` —
is refused before any bot, `Telegram\Exceptions\WebhookAddressException`, a 422 with no secret made; an agent's bot
Telegram refuses gets the reason on its row), the work of the two commands and of the owner's panel, since a shared host
has no shell (`POST|DELETE /api/admin/system/webhook`, `Admin\Api\BotWebhooksController` — the session released, the
work kept going when the owner hangs up —: `{bots: [{bot: {id, username}, done, message}]}`, `present()` wording each
outcome for the owner — the settings' token words for the main bot's refused token, `BotHealth`'s for an agent's,
`BotToken::UNREACHABLE` for a Telegram out of reach, else Telegram's own description through `Redact` —, never a token
nor a webhook's secret). `UserResolver` makes the agent the admin (`role` admin) of their own bot on their first update there.
**Scheduled work**: `Scheduler::everyMinutes(n, task, eachBot: true)` runs a shop's task in every shop in turn
(`Core\Scheduling\Shops` — the Bots module's `BotShops`: `Bots::shops()`, `CurrentBot::run`; one shop failing does not
stop the next; shops that cannot be read hold the shops' tasks back to the next tick) — auto-approval, unpaid orders'
expiry, broadcasts, auto-renewal, the next period, reminders, the report groups —, and the servers' tasks once
`everywhere()` (sync, grants). Work
that is the servers' as a whole does not lean on the shop it runs in either: it asks across shops by name —
`Subscription::acrossShops()`, `Server::subscriptions()` (every bot's), a service's `user()` and `plan()` (its own
shop's, whatever shop the code works in) — so the grants screen, a check or a sync see the same services from anywhere.
**Notifications and reports**: every
public `CustomerNotifier` method runs in the recipient's shop (`tell()`: its texts, its rules, its token) — a server
grant reaches an agent's customer by the agent's bot, and the notice is kept in the agent's shop, for the agent's
website (`Notifications\Services\Notices`, see Store API) —; `ShopReports::report(bot, topic, …)` queues in the group of the
bot it is about (a sale in an agent's bot → the agent's group; servers and the agency → the main bot's group). **Across
shops on purpose**: the panel's client names (`ClientNaming::taken()` checks every bot's services on that server), a
server's capacity (`Server::hasCapacity()`), the client's comment (`ClientNaming::comment()` adds `| @<agent bot>` for
an agent's customer). **The traffic** (`Agency\Services\TrafficPool`): every purchase and renewal an agent's bot
delivers draws its plan's traffic first (`OrderService::withTraffic()` → `draw(order, bot)` — once per order: an order
whose net lines are out already draws nothing more, so a retry after a crash does not draw twice; `bytesFor()` reads the
plan regardless of the shop), and a delivery that then fails gives it back (`giveBack()`); a pool that cannot cover it
fails the order with `TrafficShortException` (its Persian message is the order's notes; an unlimited plan is
`UNLIMITED`) and the agent hears it in the main bot (`CustomerNotifier::agencyTrafficShort()`, with «💾 خرید حجم») — the
order waits for the retry. The GB the shop gives one of the bot's services from the subscriptions screen comes out of it
too (`extending()`, see Extending one service). The balance never goes below zero; every move is a `traffic_transactions`
line (`purchase`, `sale`, `renewal`, `extension`, `refund`, `adjust` — `TrafficTransactionType`, worded in both panels'
ledgers by components/agency/traffic-ledger —, signed `bytes`, `balance_after` — the balance; a move locks the bot row, then
reads the last line again; a list reads it with its rows, `addSelect(Bot::trafficBalanceColumn())`). In an agent's shop a plan needs traffic
(`PlanService` 422 on `traffic_gb`, worded for the shop: no «0 یعنی نامحدود» there), and a plan its traffic cannot cover is not offered (`ServerSelector::covers()` —
`offers()`, the purchase screens and the website's catalogue; a delivery never asks it). An agent's bot has no agency of its own (no «نمایندگی»
— its menu's actions, `MainMenu::absent()`: the keyboard editor neither offers nor takes it, a layout kept from before
reads without it —, none of the agency's bot texts, no «نمایندگی» report topic and no server alerts in its «خطاها»,
no «نماینده‌ها» broadcast audience); the mass gift's «agents» audience is the services of every bot whose agent's
agency stands (a grant's `audience` agents: `Bot::activeAgents()`). **The panels**: two, each with its API and its session (see the panel's structure up top). The owner's, `/api/admin`
(`Auth\Services\OwnerAuth`: the login config.php keeps, `POST /auth/login` — or its recovery; any shop, the one each
request names — see The shop a panel request works in —, from `GET /shops`: main, then each agent's, `OwnerAuth::shops()`); an agent's, `/api/agent`
(`Auth\Services\AgentAuth`: `POST /auth/link` `{code, replace?}` → `attemptCode(code, replace)`, the code taken by a
compare-and-swap — one of another agent's bot than the one signed in in this browser refused unspent, a 409 on `replace`,
unless the request says to replace it —; void once the bot is no longer theirs or switched off — their agency ended — or
its `panel_epoch` moved, asked on every request; `POST /auth/sessions/end` → `endOthers()`: the epoch raised, this
session held again under it). Both implement `Auth\PanelAuth` (`principal(request)`, what the guard reads); `Auth\PanelAuthMiddleware` (the
container's `panel.admin` and `panel.agent`) answers a request without that panel's principal with a 401, and works one
with it in the principal's shop (`CurrentBot::run`), read by them (`CurrentPrincipal::run`), carrying an `Auth\Principal`
(`kind` — `PrincipalKind::Owner`/`Agent` —, `name`, `shop`; `Principal::of()`) — its `actor()` is who decides the shop
screens' decisions, `name` (the owner's login; the agent's `@bot`, else their own `@handle`, else `agent#<bot id>`) the
`reviewer` they keep. routes/api.php registers the shop's screens once under both — its daily work (`$operations`, the
website's admin API's too) and its configuration (`$configuration`); the owner's group adds the shop's own sections, worked where
they belong by `Bots\ShopScopeMiddleware` — servers, server grants and mass gifts across every bot's services
(`shop.everywhere`), the agency, its settings and the config settings in the main bot's shop (`shop.main`); the agent's
adds `POST /auth/sessions/end`, `GET /account` + `GET /account/traffic?page=` (`Admin\Api\AccountController`: their bot, level, wallet, whether
the traffic sells nothing — `traffic_shortage`, see The dashboard —, the traffic's lines). Nothing of one panel is reachable from the other: an agent's session is a 401 on `/api/admin`, an
owner-only route does not exist under `/api/agent` (404). `GET /auth/me` = `{session: {name, shop: ShopRef}}` in both
(`Auth\Services\SessionPresenter`, the one naming of a shop: the main bot's «فروشگاه اصلی», an agent's by its bot's
title, else — not handed over yet — «نماینده: » and the agent's name; its handle said apart, `username`; `status`
`BotStatus` — an agent's whose agency ended is `disabled`, its shop kept and still opened). In the SPA the owner's app
(apps/admin/owner.ts) has `useOwner()` (`login()`, `changeCredentials()`, `recoveryKey()`, `recover()`), `useShops()`
and `useOpenShop()` (`{open, opening, link}`: the unsaved-changes question, then a full load of the shop's dashboard);
its `ShopSwitcher` under the sidebar's brand (in an agent's shop always, in the main one once an agent has a shop; on
the rail a store icon with the same menu; a shop that is off marked «غیرفعال» — `BOT_STATUS` of lib/statuses — in the
menu, and on the picker while it is the one open; a failed read of the shops said in its menu, «تلاش دوباره»; held while
a shop opens) and the agents page's «باز کردن فروشگاه» (a real link) both open one so. The agent's login page takes the
code off the address bar's fragment and spends it once (a ref guards StrictMode's second mount) — where another agent
is signed in, only once the agent says to take their place (the panel's own question; staying keeps the open session,
the link unspent) —, after the session probe answered (two first answers would otherwise race their session cookies), and signed in
goes back to the page the session ended on, its query too (`from`, kept while a fresh link lands in that tab), as the
owner's does; without a link
it says to press «🔐 ورود به پنل» in the main bot — a used or expired link's refusal says it the same way
(`SignInRefusedException::LINK_REFUSED`); the agent's account page signs every other browser of theirs out («خروج از
مرورگرهای دیگر», behind a `ConfirmModal`). Tests: `Fixtures::agent()` (an agent of the main bot with their shop
open), `agentBot(agent, traffic, overrides)` (their bot handed over: `FakeTelegram::AGENT_TOKEN`, @agent_shop_bot),
`BotTestCase::sendTo(bot, update)`, `HttpTestCase::loginAsAgent(bot)` (`/api/agent`) / `loginCode(bot)` /
`openShop(bot)` (the requests that follow name the shop, `X-Shop`), `FakeTelegram::tokenOf(i)` (which bot spoke),
`CurrentBot::reset()` in `TestCase::tearDown()`; `AgentBotsTest`, `AgentPanelTest` (each panel closed to the other's
session, both signed in side by side, the link once and not for long, throttled, another bot named refused, a link where
another agent is signed in, every other browser signed out), `PanelShopTest`, `AgencyBotTest`, `AdminAgencyApiTest`.

**Broadcasts** («ارسال همگانی», composed in the bot, watched and controlled from the bot and the panel). `/broadcast` (bot
admins only — `Handlers\BroadcastHandler` answers nothing at all to anyone else, and starts afresh each time): the admin
sends the message (any kind — state `broadcast.compose`), and the bot answers with a **draft card** (state
`broadcast.draft`, the draft in the session under `broadcast`, the card's id with it): `broadcast:mode` — copy
(`BotApi::copyMessage`, from the bot, media and formatting kept, the admin's link buttons under it) or forward
(`BotApi::forwardMessage`, "Forwarded from" its first sender — a channel post forwarded to the bot keeps its channel, and
such a message starts as a forward; a forward takes no buttons); `broadcast:aud[:key[:id]]` — the audience, each option
with its size: `Telegram\Broadcasts\Audience` (always customers not banned and with a Telegram account — one who signed
up on the website alone is neither counted nor reached: all, buyers — a purchase sold (`Order::sold()`);
a wallet top-up alone is no purchase —, non-buyers, inactive («غیرفعال‌ها»: no service running now), one of the admin's
customer groups (see Users), agents (an agency level — the main bot's only), one server's customers (a service running
there — of the servers this bot's own customers are on, `Audience::servers()`: an agent's bot never learns the shop's
other servers, nor reaches one by naming its id); `labelOf()` words one whose group or server has gone, and such an
audience reaches **nobody**, never everyone — `Audience::of()` is null: the card offers no «ارسال», `start()` refuses it
(422 on `audience`), a run under way ends at its next batch); `broadcast:pin` — each message pinned quietly in its chat
(`BotApi::pinChatMessage`, `broadcast_pins` keeps where); `broadcast:btn` — the link buttons, typed a row a line, buttons
on a line apart by «|», each «text - link» (state `broadcast.buttons`, `BroadcastService::parseButtons()`: http(s) or tg://
links, `BUTTON_ROWS`×`BUTTONS_PER_ROW`; a line that is not right is said, the instructions keep their «بازگشت»; a new card
comes under the lines, the old one deleted); `broadcast:send` — claimed once (`ChatSession::claim()`), refused with a
popup when the audience has nobody or is gone, the tap answered before the first batch goes; `broadcast:drop` drops the
draft. Sent, the card **is the run's live progress message**
(`progress_message_id`): its state and numbers (sent, blocked, failed, how far), redrawn by the worker every
`PROGRESS_SECONDS` and at every change, its buttons the run's controls — `broadcast:pause|resume|cancel:<id>`
(`Broadcasts\BroadcastControl`) and, once a
pinned run is over, `broadcast:unpin:<id>`, which starts an **unpin run** (`kind` unpin, `source_id`): the same machinery
over the source's pins (`BotApi::unpinChatMessage`; a pin Telegram no longer has counts as off, and so does one of a
customer with no Telegram account any more, without a call), with its own progress
message — its message, mode and audience are the source's (`Broadcast::message()`, none of its own). A run's message is
in its admin's chat (`adminChat()` = `users.telegram_id`, `original()`). `Telegram\Broadcasts\BroadcastService` works a `broadcasts` row in batches past the `last_user_id` cursor, by
one worker at a time (`Core\Database\Lease` on `lease_token`/`leased_until`), each customer **claimed before their
message goes** (a lease write of the cursor, under the lease and status sending), so a pause or cancel (a compare-and-swap
on `status`, a `BroadcastStatus`: sending ↔ paused, open → cancelled; `kind` and `mode` are `BroadcastKind` and
`BroadcastMode`) stops it at the next customer and nobody gets it twice; it paces its calls
(`Telegram\Api\Pacer`, the bot's one pace for bulk sends — `PER_SECOND`, 20, through `Core\Support\Sleeper` —, the
reminders' too), counts a known blocker (`users.bot_blocked`, which `UserResolver` keeps
from my_chat_member too) without a call, remembers a new one (`Notifications\Services\CustomerChats::turnedAway()`: a 403, or a
chat that is gone), and on a flood limit Telegram imposes longer than `BotApi` waits (429) puts the customer back and stops
the batch for the next one. The handler sends the first `INLINE_LIMIT` (and again after a resume), and
`Tasks\SendBroadcastsTask` (every minute, `BATCH` per tick within the run's time — `Core\Scheduling\Budget` — for cron on
shared hosting; a run that breaks is logged and the next goes on)
finishes the rest and sends the admin the summary (a message run that went to everyone; not one they stopped, not an
unpin run). The panel's page is `/broadcasts` («ارسال همگانی» under ربات; `pages/broadcasts.tsx`, two sections in the
sidebar — an agent has the first alone, its page titled by the page's own name): «پیام همگانی» at /broadcasts (`components/broadcasts/broadcasts-list` → `Admin\Api\BroadcastsController`, `Telegram\Broadcasts\
BroadcastDirectory`: `GET /broadcasts?page=`, `POST /broadcasts/{id}/{pause|resume|cancel|unpin}` — a refused state is a
422 on `status`, and the bot's progress message follows; an unpin from the panel — or from the website's admins —
(`BroadcastDirectory::unpin(broadcast, actor)`, the run's `reviewer` the actor's name) has no progress message and the
scheduler works it) — every run newest first, live through the change feed (the `broadcasts` area), with what it is
(`content`, `excerpt` — kept at capture), its mode, audience, pins still pinned, progress and the row's menu — a page
read in the same few queries however many runs it shows (`Broadcast::withUnpinState()`, sources and admins eager-loaded,
each group's or server's name looked up once); and
«هدیه همگانی» at /broadcasts/gifts (see Server grants). Messages are composed in the bot only, by decision: it is the admin's own Telegram
message that goes out. The admin's words are `Messages::BROADCAST_*` (not BotTexts — only bot admins see them).

**Report group** («گروه گزارش‌ها», `Telegram\Reports`): a Telegram supergroup with topics (a "forum") the admin makes and
hands the bot, where the shop reports to its admins, a topic per `Reports\Topic` — خریدها (a sale delivered: customer,
plan, «لوکیشن», service, amount and method, the referrer's commission), تمدیدها (a renewal and the new end), شارژ کیف پول
(a top-up and the balance), رسیدها (a copy of the customer's receipt picture with what it pays for, then each verdict —
approved, auto-approved, rejected, cancelled — as a reply to it), کاربران جدید (a newcomer and who brought them), خطاها (a
paid order whose delivery failed, with the reason and the retry — the reason as the group's reader may read it,
`ShopReports::failure()`: the main bot's group, the owner's, a panel's diagnosis, an agent's group its word, which the
group's popups about a delivery say too —; a panel that stopped answering, and answers again — on
the transition only, never a server's first check), نمایندگی (a request to become an agent with its buttons, then the
verdict — see Agency), تیکت‌ها (a ticket opened — the customer, the subject, the service, where it came from and its
first message, its picture as a photo —, then every later message of it, the customer's or support's from a panel, its
closing, opening again and rating, each a reply to the one before, «🔒 بستن تیکت» under the last; every shop's — see
Tickets), نظرات (a review written on the website — its number, the name it is signed with, the stars, its context, who
wrote it, its words —, no buttons: support decides it on the panel's «نظرات» page; every shop's — see Reviews).
**Handing the group over**: the screen's link is Telegram's own
`t.me/<bot>?startgroup=reports_<code>&admin=manage_topics` (adds the bot as an admin asking for the "manage topics"
right; the code is one-time, `ReportGroupState::CODE_MINUTES`, kept encrypted, and a new link replaces the old), then the
bot gets `/start@<bot> reports_<code>` in that group — or the admin types that command (the screen shows it). A group's
updates have routes of their own in routes/bot.php, each an `Update\GroupHandler` (customers are still served in
private chats only, a group has no user/session/gates): `$bot->groupCommand('start', Reports\ReportGroupConnect)`,
`groupCallback(prefix, …)` for the buttons under its reports (ReceiptReview, DeliveryRetry, AgencyReview, TicketClose —
a tap no route takes is only acknowledged) and `group(Reports\ReportGroupUpdates)` for the rest — the bot's membership,
the group's title, a reply answering a ticket — or, in the tickets topic, a reply to a message of the bot's that names
no ticket, told where an answer goes (`Reports\TicketReplies`, asked first: a reply to a ticket's report always
reaches the ticket) —, a reply giving a rejection its reason (`ReceiptReview::reason()`); each reply's reader knows its
own by the message it answers as the bot recorded it, never by that message's words. `ReportGroupConnect` takes a good code, a supergroup with `is_forum`
and the bot an admin with `can_manage_topics` (`getChatMember`) → `ReportGroup::adopt()`: the group is kept
(`report_chats`, a row per bot: `chat_id`/`title`/`connected_at` — `Reports\ReportGroupState`, runtime state read from
the table on every call, never a process's copy), the code used up by a compare-and-swap on its row (two /starts connect once),
and every topic made by the bot itself (`createForumTopic`, the topic's colour — one of Telegram's six — and a custom
emoji icon when one of `Topic::icons()` is among `getForumTopicIconStickers`) with a first message saying what it is
for (`Topic::about()`, the switch's hint on the screen too — worded for the shop: an agent's «خطاها» promises its own
failed deliveries, not the servers, which the main bot's group alone hears of); anything else is refused with the
reason, in the group and on the screen (`attempt_*`), the code kept.
`report_topics` keeps the thread ids of the bot's connected group (`unique(bot_id, topic)`; another group adopted, or the
group disconnected, forgets them; `Reports\ReportTopics`); a null `thread_id` is a topic being made by whoever holds the
row (`Core\Database\Lease` on `lease_token`/`leased_until`, for longer than the making can take — `CALLS_TO_MAKE` (3)
calls, each `BotApi::longestCall()`: twice Telegram's timeout and `MAX_FLOOD_WAIT` —), so two processes never make one
twice and one whose maker went quiet is taken over; a topic made later (by its first report) asks Telegram for the icons itself. The bot's own membership (my_chat_member) and a `new_chat_title` keep the group's
state: removed / demoted / without the right is its `problem` (a `Reports\GroupProblem`, worded by its `message()`;
`GroupProblem::of()` classifies refusals the same way, off the one `Api\Refusal` reading; written only when it changes), given the right back clears it. **Sending**: `ShopReports` (no Bot API — OrderService,
PaymentService, ServerHealth, Customers, AgencyActions, Tickets and Reviews hold it) queues a `report_messages` row per event,
**in the transaction of the change it reports**, by decision — a savepoint of it (its own when there is none): a
receipt sent, rejected, cancelled or approved (the approval's inside the settlement), a delivery's completion (beside
what it delivered) or its failure, a ticket's every move, a review written, a panel down and up again (its server's row
moved by a conditional update), a newcomer with whoever brought them (`Customers::register()`; the referrer told once
it stands) — so a report stands or falls with what it reports: a report the database refuses rolls the change back and
the request fails, never a change that stood without its report (a website receipt whose save failed takes its picture
with it; tests/Feature/ShopReportsTest refuses each one's insert and finds the change undone — a delivery resumed
later, a panel's news noticed again at its next contact, a newcomer registered at their next arrival) —, only while a group is connected and the admin
wants the topic (`reports.topic.<key>`, the bot settings' `reports` group, all on by default), at its topic's place in
the queue (`priority`, `Topic::priority()`: receipts and errors — a customer waits on support for them — 0, reviews —
anyone writes one, nobody waits on it — 2, after everything else, the rest 1);
a report whose words cannot be composed is logged and the flow that reported goes on, but a database that fails it —
reading for its words, or writing its row — fails that flow too (inside a transaction, the transaction is the
database's to keep or lose, never carried on as if it stood). A ticket's message is folded into the ticket's last report
while that one still waits — no sender holds it, it has no picture and the same buttons, the two fit one message
(`ShopReports`' `folded()`, one conditional update) —: a burst of a ticket's messages is one report, not a queue of
them crowding the group's other reports out. `ReportSender::flush()` sends them lowest `priority` first, each in the
order it came, at most `PER_MINUTE` (18 — Telegram takes 20 a minute in a group) a rolling minute, each row leased first
(`Lease` on `lease_token`/`leased_until`, for longer than its send can take — `leaseSeconds()`: `CALLS_A_SEND` calls, each
`BotApi::longestCall()`, twice Telegram's timeout and `MAX_FLOOD_WAIT` —, the row read again once taken, and marked sent through the lease:
a sender that lost it writes nothing, and logs it; `attempts` counts the senders that went quiet holding it, given up on
at three):
a 429, an unreachable Telegram or the group's own trouble pauses everything (`ReportGroup::hold()`, `paused_until`; a longer
hold stands)
and loses nothing; a topic an admin deleted ("message thread not found") is made again and one closed is reopened; a
receipt sent in the bot is `copyMessage` of the customer's picture with the report as caption (text when it cannot be
copied), a picture the shop keeps — a receipt uploaded from the website, a ticket's picture uploaded from a website or a
panel — `sendPhoto` of its bytes (`report_messages.photo_path`: its reference, the folder and the name —
`CustomerPictures::reference()`, read back through `readReference()`) with the report as caption — text with a word when
Telegram will not take it as a photo or its file is gone; the group's, the topic's or Telegram's own trouble goes up as
for any report —; markup
Telegram cannot parse goes as plain text; a report refused for good is dropped and the next goes. It runs after every
update (`bot:poll`'s round, the webhook request) — so with nothing waiting it costs two reads, the group's row
(`ReportGroupState::sendingTo()`) and the queue; the minute's room is counted only once a report waits — and in
`Tasks\SendReportsTask` (every minute, last, which also prunes: sent rows kept 7 days for the verdict replies, unsent ones
given up after a day — `ReportSender::prune()`, every report but a ticket's: two ranges of the bot's pruning index
`(bot_id, ticket_id, sent_at, failed_at)` under a null `ticket_id`, so the reports kept for the tickets still open are never
read again; a ticket's are `pruneTickets()`', hourly with the tickets' housekeeping — Support\Tasks\PruneTicketsTask —:
a week old and their ticket closed, or gone). Screen: the «گروه گزارش‌ها» section of the bot settings —
`components/bot/report-group-card` (what the group gets — the agency's requests in the main bot's shop alone, read off
the session's shop —; its read and operations in `use-report-group`, a refusal the panel's toast like any mutation's;
`connect-steps`: steps + link + the command, polled while a link is out — a link a bot without its @username cannot
make worded for the shop on screen (`useMainShop()`; `POST …/link`'s 422 on `link`: the main bot's `ReportGroup::
NO_USERNAME`, the owner's «بررسی توکن» in «تنظیمات پنل»; an agent's bot not handed over yet `NO_AGENT_BOT`, its token
sent in the main bot's «نمایندگی») —; `connected-group`: the group, problem, queue,
topics, «پیام تست» / «بررسی دوباره» / «اتصال گروه دیگر» / «قطع اتصال») → `Admin\Api\
ReportGroupController` (`GET /bot/report-group`, `POST …/link|check|test`, `DELETE` — the bot stays in the group) — and
the «بخش‌های گزارش» switches. The words are the admin's reading, fixed in ShopReports, not BotTexts. **Reviewing from
the group** (`Reports\ReceiptReview`): a receipt's copy carries «✅ تایید» / «❌ رد» (`report_messages.keyboard`, callback
`rv:<action>:<payment id>`), for the bot's admins only — `users.role` admin, authorised by who pressed, never by the group
or the button's data — and they do what the payments screen does: `PaymentActions::approve()/reject()` (its rules, and
it tells the customer), decided by `Auth\Actor::groupAdmin(admin)` — its name `Reviewers::forAdmin()`'s, `@username`,
else `tg:<id>`; an admin's own receipt is another admin's to approve (`ActorRefusedException::ownPayment()`'s words in
the popup, the buttons staying for them); an approval whose delivery
failed says so in its popup (≤ 200 characters, the retry first). «رد» opens «رد بدون توضیح» / «رد با نوشتن دلیل» /
«بازگشت»; the second posts a ForceReply prompt under the receipt, recorded as it is posted (`ReportMessage::posted()`: a
`report_messages` row of `ref` `prompt:<payment id>`, the group and the message's id), and a bot admin's reply in words
to that very message, in the receipts topic (`ReportTopics::holds()`), becomes the rejection's note — never a reply
judged by what the replied message's words say: customers' words are in the group's reports (a ticket's, their names, an
agency note) and would name another customer's receipt (the note limit applies; the prompt is deleted once used or moot;
an anonymous admin is refused — no account to authorise). A decided receipt loses its buttons at once and through its verdict's
report (`clears_buttons`), whoever decided — here, on the screen or by the timer; a stale press is refused by the rules
and takes them off. **Retrying from the group** (`Reports\DeliveryRetry`): a failed delivery's report carries «🔁 تلاش
دوباره برای تحویل» (`rt:<order id>`), for the bot's admins only, and does the orders screen's retry —
`OrderActions::retry()`, which tells the customer only when it worked; failed again, the popup says why
and the button stays for another try. A failure report's `ref` and `reply_ref` are both `delivery:<order id>` with
`clears_buttons`, so a failure after an earlier one replies to it and takes its button over, and a delivery after a
failure (`ShopReports::orderDelivered()`, whoever retried) answers it «✅ سفارش #n با تلاش دوباره تحویل شد.» and takes the
button off. **Answering tickets from the group**: every report of a ticket is `ref` `ticket:<id>` (`ShopReports::TICKET_REF`)
and names its ticket (`report_messages.ticket_id`), each later one a reply to the last (`reply_ref`) that takes the buttons
off it (`clears_buttons`) and carries «🔒 بستن تیکت» itself while the ticket is open (`Reports\TicketClose`, callback
`tk:<ticket id>`: the bot's admins only, `Tickets::close()` as a panel closes it — the customer told —, one closed or gone
meanwhile loses the button with a word). A bot admin's reply to **any** of a ticket's reports is support's answer
(`Reports\TicketReplies`: the report replied to found by the group and its message — `report_messages`' `(chat_id,
message_id)` index —, its `ticket_id`, the words a text or a photo's caption, the photo kept as Telegram's file id;
`Tickets::answer()` by `Actor::groupAdmin()` — channel `group`, the reviewer `@username`/`tg:<id>`; not reported to the
group again), and
the bot answers the admin under their reply: «✅ پاسخ برای مشتری فرستاده شد.», or why not — not a bot admin, written
anonymously (`GroupButtons::ANONYMOUS`), no words (a picture without a caption, a file, a voice), the form's refusal. A
ticket's reports are kept while it is not closed, however old (`ReportSender::pruneTickets()`), so a reply a week on
still answers it; a reply in the tickets topic (`ReportTopics::thread()`, the connected group's) to a message of the bot's
that names no ticket — its own word under an earlier answer, the topic's introduction, a report of a ticket closed and
pruned — gets «این پیام به تیکتی وصل نیست و پاسخی فرستاده نشد؛ روی گزارش همان تیکت ریپلای کنید، یا از صفحه «پشتیبانی» در
پنل پاسخ دهید.» (a message written in the topic replies to its root, the topic's own first message: none of its business).
A reply to anything else is none of its business. The ticket's reports never name support's reviewer, and the new
ticket's says the panel's page by its menu's name, «پشتیبانی». The four kinds of button (the agency's review and a ticket's
close too) are `Update\GroupHandler`s routed by their prefix (`Dispatcher::groupCallback()`), and share
`Reports\GroupButtons`: the one rule for who may press (`admin()` — a bot admin, not banned; who decides is that account,
`Actor::groupAdmin()`), changing or taking off a message's buttons, a word under an admin's message in its topic (`say()`, `threadOf()` — the
receipts' rejection prompt and the tickets' answers); a press is answered with `BotApi::answerCallbackQuery()`, which
cuts the popup to Telegram's 200 (UTF-16 units, `Limits::cut()`) and never throws.

The Telegram bot
maps commands / keyboard labels / callback prefixes / conversation states to `Handler` classes in `routes/bot.php`
(`Telegram\Update\Dispatcher`, in that order; `text()` takes a closure, resolved through the container per update,
for labels the admin edits). Customers are served in private chats only, and a private chat is its sender's own: an
update whose chat is not its sender's is no update of Telegram's (an agent's webhook can be posted anything) and is left
alone. **Each update once**: `Update\ReceivedUpdates::claim(update)` records it in
`telegram_updates` (unique per bot) before anything is served — webhook and poller alike, group updates too — so one
Telegram sends again (a webhook answered past its patience, a poller that died before confirming its offset) is left
alone (logged at info), at most once by decision; `Tasks\PruneUpdatesTask` (hourly) forgets rows older than
`KEEP_HOURS` (48 — Telegram holds an update a day), and a bot's newest row is `BotState::lastUpdateAt()`. **One chat
cannot hold the bot up**: each record names the chat it counts against (`chat_id`; one Telegram held back while the bot
was away — its message older than a minute as it arrives — counts against none), and a chat with more than
`FLOOD_UPDATES` (20) in `FLOOD_SECONDS` (10) — taps or messages, faster than a person — is left unanswered while it keeps
on (`ReceivedUpdates::lately()`, read off its index; the dispatcher logs it once, as it begins): every answer is a
Telegram call whose limits would slow the bot down for every chat (tests/Feature/Security/BotFloodTest). A route says
what it is: commands, labels and the contact card are **navigation** (the flow in progress is left), a callback prefix
when routes/bot.php says so (`navigation: true` — the menu's `menu:*`), the fallback too (it shows the menu: an unknown
command or text, and a tap no prefix takes — a button of a screen long gone, never an answer to the step the chat is
at), and `adminOnly: true` (/broadcast, /emoji) gives anyone but a bot admin no answer at all (a step of such a flow the
chat is still at, its customer no admin any more, goes to the fallback, which leaves it). **A tap is answered once** (`Context::answer()` sends the first answer
only, `answered()`): a handler answers when it has something to say (a toast, a popup), the dispatcher acknowledges a
tap nobody answered once the handler is done (so the spinner runs while the work does), and a handler that throws is
reported — `BotText::Error` as a popup on the button while the tap is unanswered, as a plain message (`sendText`) once
it was or when Telegram no longer takes the answer. Callback data has one format, `Update\CallbackData`: `build(prefix,
...args)` writes it (a prefix and `:`-separated arguments, ≤ 64 bytes) and `$ctx->update->callbackArgs(prefix)` reads
the arguments back (null for another prefix) — handlers never explode or substr it. Prefixes are constants with builders
on their handlers — `PurchaseHandler::PLAN/CHECKOUT` + `planCallback()/serverCallback()/reopenCallback()`,
`MenuHandler::CATEGORY` + `categoryCallback()`, `subscriptionsCallback(page)` /
`subscriptionsCallbackFor(subscription)` (the list page a service sits on), `SubscriptionHandler::PREFIX` +
`serviceCallback(id, ...action)`, `ReceiptHandler::PREFIX` + `stateFor()`, `TopUpHandler::CALLBACK/STATE/START` +
`checkoutCallback(amount)`, `AgencyHandler::CALLBACK/STATE/TRAFFIC` + `checkoutCallback(gb)`, `BroadcastHandler::*`,
`TicketHandler::PREFIX/START` + `listCallback(page)/screenCallback(id, from, ...action)/viewFromNotice()/
replyFromNotice()/rateFromNotice()` (see Tickets: the bot), `JoinPrompt::CHECK`, `MainMenu::PREFIX`, and in the report
group `ReceiptReview::PREFIX`, `DeliveryRetry::PREFIX`, `AgencyReview::PREFIX`, `TicketClose::PREFIX` — never a literal `'plan:'` in code. A
state is routed by its longest registered prefix (`agency.token` → `AgencyHandler::STATE`, `topup.amount` →
`TopUpHandler::STATE`, `ticket.reply.12` → `TicketMessageHandler::STATE`). `Session\ChatSession` is the conversation: `enter(state, scratch)` goes to a step (the previous
step's scratch left behind), `clear()` leaves the flow, and a last step that must run once leaves it with
`claim(state)` (a compare-and-swap on the stored state: «تغییر لینک»'s confirmation `sub.rotate.{id}`, /broadcast's
send); keys starting with `_` in its data are the chat's own facts and survive every flow — whether a reply keyboard
is on the client's screen (`showsReplyKeyboard()` / `markReplyKeyboard()`, kept by `MainMenu::show()` and the gates),
`_joined` (ChannelMembership's). Its row is made the first time the bot serves the chat (`createOrFirst`, so two first
updates at once share it) and written only when changed (a fact put again as it was changes nothing). `Update::contentKind()` says what a message carries ("photo", "document",
"voice"… by Telegram's field name; `hasMedia()` on top of it). `BotState` holds the bot's runtime state (webhook URL, poll
heartbeat, and the last update from `ReceivedUpdates` — the dashboard's system card reads it); `Services\BotLifecycle` is
what `bot:poll`, `BotWebhooks` (`bot:webhook:set/delete`, the owner's panel) and `bot:info` share (enable/disable the
webhook, remember the bot's identity), with the public webhook URL built by `Core\Http\Urls`.

**Bot settings** (`Telegram\BotSettings`, settings-table keys `bot.*`, every bot its own — the same page in both panels,
screen `/bot-settings/:section` — one section per subject, picked from the settings' sidebar like the owner's config
settings (`BOT_SETTINGS_SECTIONS` in components/shell/nav; `SectionPicker` on a phone): عمومی / کانال‌ها / کیف پول /
تمدید سرویس / یادآوری / زیرمجموعه‌گیری / گروه گزارش‌ها / کد QR — no owner-only section; the agency's rules are the shop's,
on the agents page
→ `Admin\Api\BotSettingsController` `GET /bot/settings`, `PUT /bot/settings/{general|channels|wallet|renewal|auto_renew|reminders|referral|reports|qr}`
(a group the screen does not have: 404, `Settings\Exceptions\UnknownGroupException`)
— a card per group (the renewal section has two), each with its own save; a group's PUT answers with `settings` only and the page merges it into its cached
data through `lib/use-settings-group` — each section a file of `components/bot/settings/` on `useBotSettingsGroup`, as
the config settings' cards are on `useConfigGroup` —; the «ربات خاموش است» notice sits above the
sections, since it matters on every one). The screen is `Settings\Services\BotSettingsScreen`: the groups the modules
declare on the field engine (`Core\Forms`, see Conventions), registered in bootstrap/container.php, `settings` one flat map
of every group's fields (a name declared twice is a LogicException); tests save a group with `Fixtures::botSettings(group,
values)`. `BotSettings` declares its own three: `general` — the master switch and the phone rule plus `support_contact`,
the handle or link «پشتیبانی» shows (`supportContact()`; blank = `BotText::SupportUnavailable`), above its tickets' buttons
(see Tickets: the bot) —, `channels` — the
channel rule (`join_required`), on the channels section above the list it applies — and `qr`; `wallet` (`topup_min`,
`topup_presets` none below it, `TOPUP_MAX`: what the bot's top-up, the website's and `OrderService::topUpAllowed()` go
by) is `Users\Services\WalletSettings`', registered on the screen after them like every other module's (the container's
`BotSettingsScreen` factory: `BotSettings`, `WalletSettings`, `RenewalSettings`, `ReminderSettings`, `ReferralSettings`,
`ReportSettings`, in that order — the order of `GET /bot/settings`' fields). The `renewal` and `auto_renew` groups are `Subscriptions\Services\
RenewalSettings`' (see Renewal and Automatic renewal), `reminders` `ReminderSettings`' (see Reminders), `referral`
`ReferralSettings`' (see Referrals), `reports` `Telegram\Reports\ReportSettings`' (a switch per topic; the agency's only in
the main bot's shop, whose group alone hears of agency requests — `ReportSettings::topics()`). The `qr` group
is one switch (`qrEnabled()`, default on: a delivered service comes as a **QR card** — `Telegram\Qr\QrCard::render(url)`
draws the link with chillerlan/php-qrcode's matrix on the background through GD: a white rounded plate, 57% of the
picture's shorter side, centred, the code inside a four-module quiet zone, out as a JPEG; without GD, or with a
background GD cannot read, it returns null and the text goes alone — the customer never loses the link over a
picture). `Telegram\Notifications\ServiceCard::send(chatId, subscription, text, options)` is the one place a service is
sent: `BotApi::sendPhoto()` (multipart, bytes from memory) with the service text as the **caption**, or the text when the
card is off/unavailable or the caption outgrows Telegram's limit — `sendSettled()` is that call with `BotText::PaySuccess`
(a top-up, a renewal, an agent's traffic go as text), the checkout (after `Context::delete()` of it),
`CustomerNotifier::paymentSettled()` (as a reply to the receipt), a moved service and «تغییر لینک» (with
`BotText::LinkRotated`) all go through it; `show()` is the same card-or-text in place of the tapped message
(«لینک اشتراک», with `BotText::LinkRequested`); `values()`/`text()` fill every service text. Tests: `TestCase::withoutQr()` puts a flow on
the text path; `BotQrDeliveryTest`/`QrCardTest` cover the card and skip without GD; `FakeTelegram` parses multipart
(`params()` has the fields, `files()` the bytes). The picture the code is drawn on is `Telegram\Qr\QrBackground`: the shop ships
`resources/assets/qr-background.jpg` (Amir's picture: a dark nebula with a white rounded square for the code), the
admin may upload their own from the «پس‌زمینه کد QR» card
(`POST /bot/qr-background`, multipart `file`, typed by content — PNG/JPG/WebP ≤ `QrBackground::MAX_BYTES` (5 MB), the
card saying so up front (`max_bytes` on the background's description), a picture's pixels written in Latin digits
without separators («1024×1024») — stored as
`background.<ext>` (an agent's `background-<bot id>.<ext>`) in the container's `qr.backgrounds` folder
(storage/uploads/qr), written whole (`Core\Support\Files::writeAtomically()`) before it is remembered under
`bot.qr_background` and the one it replaces goes; `DELETE` goes back to the shipped one;
`GET` streams the one in use for the preview, cache-busted by its mtime) — actions apply at once, no save button.
The panel sends it as `api.post('/bot/qr-background', { file })` — a body holding a file goes as multipart, a part a
field (lib/api); tests send one with `HttpTestCase::upload()`, and `qr.backgrounds` is the
test run's own folder (`TestCase::FILES`). The `general` switches are enforced by **gates** — `Telegram\Gates\Gate::pass(Context)` — that
`routes/bot.php` registers (`$bot->gate()`) and the dispatcher runs after the ban check and before any handler; a gate
that returns false has answered the update itself (a tap it left unanswered is acknowledged), and a bot admin passes
them all. `MaintenanceGate` (`bot.enabled`, default on): off, every customer
message gets `BotText::BotOff` with the reply keyboard removed, callbacks a popup; a bot admin (`Context::isAdmin()`) passes.
`PhoneGate` (`bot.phone_required`, default off): a customer without `users.phone` (stored only verified) is asked for their number
with a `ReplyKeyboard::contactButton()` (Telegram's `request_contact`) whatever they send; only their own contact card
counts (`Update::isOwnContact()` — the card's `user_id` is the sender), stored E.164 by `User::verifyPhone()`; the
card then continues through the gates after it and, if they let it, reaches the `contact` route (`StartHandler`, the
menu) — a gate never opens the menu itself, or it would skip the gates behind it; verified once = never asked again. `ChannelGate` (`bot.join_required`, default off,
runs after the phone gate): the customer must be a member of every row of `bot_channels` — the list the admin keeps is
`Telegram\Channels\RequiredChannels` (screen: the "کانال‌های اجباری" card of `/bot-settings/channels` →
`Admin\Api\BotChannelsController`: `GET/POST /bot/channels`, `POST /bot/channels/reorder`, `POST /bot/channels/{id}/check`,
`DELETE`), whether a customer is in them `Channels\ChannelMembership::missing(ctx, fresh)` (the one place that knows the
rule holds — switch on, not a bot admin). The admin adds a
channel by what they paste (`ChannelReference::parse`: `t.me/name`, `@name`, or the numeric `-100…` id of a private one —
a private invite link cannot be looked up by the Bot API and is refused with that explanation); the bot must already be
an admin there (`getChatMember` with its own id from `BotApi::botId()`), or the row is refused — it needs the rights to
see the member list. Telegram out of reach for a moment (`Refusal::isTransient()`) is neither: add and check answer a 502
(`Telegram\Api\TelegramUnreachableException` — its `MESSAGE` the one word for Telegram not answering, a token's check,
`BotToken::UNREACHABLE`, says the same) and change nothing. `BotChannel::link()` is `t.me/<username>`, or for a private chat the `invite_link` the bot exports
(a check refreshes it, and keeps the last one while the bot cannot make one). Membership is
`getChatMember` per channel per update, cached in the session (`_joined`, `RECHECK_AFTER` seconds, keyed on the list);
a check Telegram cannot answer counts as joined, is logged and — when the channel is the reason, not a moment of
Telegram — flags the row (`bot_is_admin` false → warning on the
screen, "بررسی دوباره" re-checks). Not a member → `BotText::JoinPrompt` with one URL button per missing channel and
`JoinPrompt::CHECK` ("عضو شدم"), which the gate lets through to `Handlers\JoinHandler` (re-check without cache: still
missing → alert + trimmed screen; all in → `BotText::JoinDone` + the menu). A new rule = a gate class + a switch in
`BotSettings` + a `SwitchRow` on the page. The owner's system card shows the master switch (`GET /api/admin/system`,
`system.bot.enabled`).

**The dashboard** (`/dashboard` in both panels → `Admin\Api\DashboardController`, `Admin\Services\DashboardStats`): the
range's figures against the period before (`range` 7/30/90, else 30) — revenue is money that came in
(`Payments\Models\Payment::moneyIn()`: paid payments not made from the wallet, and refunded ones but a wallet
top-up's), orders, new customers, each with its previous period in one query (conditional aggregates) —, the active
services and the customers in all, a point per shop's day for the chart (see Production: time), the queues that need a
human (`attention`: `Admin\Services\Queues::counts()` — the receipts to review, the stuck orders (`Order::stuck()`),
the support tickets waiting on an answer (`open_tickets`: status open, «تیکت‌های در انتظار پاسخ», linking
`/tickets?status=open` — see Tickets) and the reviews waiting on support (`pending_reviews`, «نظرات در انتظار بررسی» →
`/reviews?status=pending` — see Reviews) —, then unpaid orders, servers whose panel failed — 0 in an agent's shop —,
services ending soon — the card's link opens them soonest first, `?status=expiring&sort=expires&dir=asc` —; each row in
its list's vocabulary, «سفارش‌های نیازمند رسیدگی», `TICKET_STATUS` of lib/statuses for the tickets) and the latest orders and customers (an order's number opens it on the orders screen, `searchLink()`; a
customer's name, their page). On screen (`pages/dashboard.tsx`), another range keeps the last one's figures — the cards
and the chart — dimmed (`isPlaceholderData`, as `ListView` dims a page) and worded as theirs («نسبت به ۳۰ روز قبل از
آن», the description's days: the answer's `range.days`) until its own arrive; a read that failed with
nothing to show is the page's `ErrorState` over every card at «—» — the figures, the chart, the queues (never «همه‌چیز
مرتب است» of counts that never came — and that said of what the shop on screen has: an agent's, no servers), the latest
arrivals. `GET /queues` (both panels, `Admin\Api\QueuesController`, the session
released first) answers the same counts alone — the sidebar's (see the shell's nav badges). «Ending soon» is one window everywhere:
`SubscriptionDirectory::EXPIRING_DAYS`, sent as `expiring_days` (the subscriptions list's meta has it too, a row its
`expiring_soon`) — the panel words its labels from it. Whether the machinery behind the shop is alive — the version,
PHP's, the open shop's bot by its id (`bot`: token, master switch, webhook / polling / offline by the poller's heartbeat,
the last update; the card's line «ربات اصلی», else «ربات این فروشگاه» and its @username), the main bot's beside it
(`main_bot`: how every bot of the installation gets its updates — what the telegram settings and the webhook card read)
and the scheduler — is the owner's alone: `GET /api/admin/system` (`Admin\Api\SystemController`,
`Admin\Services\SystemStatus`, read once as `lib/queries`' `systemQuery`, its modes worded by `lib/statuses`' `BOT_MODE`;
components/dashboard/system-card: each part its name and state, and under them what it is doing — a sentence, which
wraps, so a link in it, «راه‌اندازی Cron», is never cut on a phone); an agent is not told about the installation. A bot that gets no updates at all (`offline`) points the owner at the
telegram settings, whose «دریافت پیام‌ها» card (`apps/admin/settings/webhook-card`: the mode, «ثبت Webhook» / «ثبت دوباره
Webhook» / «برداشتن Webhook» behind a `ConfirmModal`, each bot's outcome; an http APP_URL says so and offers nothing) puts
every bot on webhooks or takes them off (`BotWebhooks`, see Agent bots); the telegram card says when a saved token
reaches the bot by that mode (a webhook's next request; a poller's restart, which `--watch` does).
**An agent's bot whose traffic sells nothing** — too little for its smallest plan on sale, or none left —
(`ServerSelector::shortage()`, on `covers()`: `{balance, smallest_plan}` or null, always null in the main bot) is the
dashboard's `traffic_shortage` and the account's (`GET /api/agent/account`): `AttentionCard` says it first
(`components/agency/traffic-shortage`, `TrafficShortageNotice`) with what to do the panel's own way —
`DashboardPage({trafficHelp})`: an agent buys in the main bot (`apps/agent/buy-traffic`), the owner viewing that shop
sets it on the agents page —, and the agent's account page says it too.

**Bot keyboards are the admin's** (`Keyboard\KeyboardLayouts`, settings key `bot.keyboard.<name>`, built-in default per
keyboard, editor at `/keyboards` → `Admin\Api\KeyboardsController`: `GET /keyboards`, `PUT /keyboards/{name}`,
`POST /keyboards/{name}/reset`). A `KeyboardLayout` is `type` (`reply` = persistent keyboard under the text field,
`inline` = buttons under the message) + rows of `{action, label, style, icon}`; rows are stored in **reading order** (first
button = rightmost) and reversed by `markup()` because Telegram lays rows out left→right. `style` is Telegram's own
button colour (`primary` blue / `success` green / `danger` red, Bot API `style` field; null = client default). `icon` is
a premium emoji's id (`KeyboardLayout::ICON_PATTERN`; null = none) sent as Telegram's `icon_custom_emoji_id` (Bot API
9.4: drawn before the label, only while the bot may use premium emoji — see Premium emoji; a reply button's tap still
sends the label alone, so routing is untouched; `InlineKeyboard::callback()` / `ReplyKeyboard::button()` take it as
their last argument). A label is plain text to Telegram, so one carrying a `<tg-emoji>` tag (pasted from /emoji's
template, perhaps cut short by `LABEL_MAX`) is refused with a 422 pointing at the icon; the button dialog offers
«انتقال به آیکون دکمه» while the label holds one (the tag's id becomes the icon, the tag leaves the label). A stored layout
is read back through the same checks `save()` makes (`KeyboardLayouts::get()`): a button of an action the bot no longer
has, or this shop's menu never has (`MainMenu::absent()`), is left out of it — the rest of the layout stands, as the
admin saved it (one with nothing left is the built-in menu) —, while saving one is refused; a layout that would not pass
the checks otherwise (a row changed by hand) is not the menu — the built-in one stands in, with a warning in the log.
Actions live in `Keyboard\MainMenu::ACTIONS` (key → title, default label `Messages::MENU_*`, `menu:*` screen: buy,
services, renew, wallet, affiliates, agency, tutorial — «آموزش», `BotText::Tutorial`, a message of the «عمومی» group the
admin writes, its default pointing to «پشتیبانی» —, support) — the default layout (`MainMenu::defaultLayout()`, a reply
keyboard) is [ خرید اشتراک ] / [ سرویس‌های من | تمدید سرویس ] / [ آموزش | کیف پول + شارژ ] / [ پشتیبانی |
زیرمجموعه‌گیری ] / [ نمایندگی ]; a shop's
menu has them all but `MainMenu::absent()` (an agent's bot: no «نمایندگی»), which `actions()` does not offer, a save
refuses («این دکمه در ربات وجود ندارد.») and a layout kept from before, and the default, read without; a
keyboard uses each action at most once and labels must be unique (a reply-keyboard tap arrives as its label —
`MainMenu::screenFor()`). `MainMenu` is a service and the one place that knows which form the menu takes:
`show(ctx, text)` sends a text with the menu (a reply keyboard, remembered as on the client's screen; or inline buttons —
then a reply keyboard still showing is taken away first by the text, the menu following under `BotText::MenuPrompt`;
/start, the fallback, the join check), `screen(ctx, text, ?keyboard, ?backStyle)` puts a screen the menu opens in place
of the tapped message — with an inline menu every such screen gets «بازگشت» to it (`menu:home`) —, `markup(user)`,
`labels()`, `isInline()`. The buttons more than one screen carries are `Keyboard\Buttons` (worded by BotTexts, one look
everywhere): `back(screen, ?style)`, `backOnly(screen)` (a keyboard that is just that — a dead end's), `pages(page, pages,
at)` (the «بعدی» / «قبلی» row under a list of pages — «سرویس‌های من», the renewals, «تیکت‌های من» — next on the left), `topUp()`
(«➕ افزایش موجودی», green), `buyTraffic()`; `InlineKeyboard` is the generic builder (`row()`, `grid()`, `callback()`,
`url()`). With a reply menu first-level screens carry no inline back, nested ones go back to their parent. «سرویس‌های من»
(`MenuHandler::subscriptions`, `menu:subs[:page]`) is the customer's active
services **paged** (five a page, newest first): one button per service named as the panel names it
(`remote_name`, «✨ amir_2 ✨», `SubscriptionHandler::serviceCallback()`), a «بعدی» / «قبلی» row
under them when there is more than a page (next on the left: forward is leftward for an RTL reader), «صفحه x از y» in
the text, and its inline back in Telegram's red (`screen(..., backStyle: 'danger')`) — no search button, by decision.
`Messages::fill()` fills `%name%` variables in one pass (`strtr`): a filled-in value is never read again for
variables. **One service**
(`Handlers\SubscriptionHandler`, prefix `sub:`, only the customer's own — anyone else's id is «این سرویس پیدا نشد»):
`sub:{id}` is the screen `BotText::ServiceDetails` — status, the client's name on the panel in a `<code>` span,
plan, «لوکیشن», quota / used / remaining (with the percent), `expiry()`, last connection and
whether it is online now — filled from `ProvisioningService::look()` (`inspect()` with presence, the row synced on the way, so an exhausted
service turns «⚠️ حجم تمام شده» here and leaves the list); when the panel
cannot be read as the screen opens (`ServiceNotReadException`: out of reach, or backing off), the row's last numbers are shown with `BotText::ServiceStale` under them, never an error. `Messages::bytes()`
words an amount of traffic («۱٫۵ گیگابایت», «۵۱۲ مگابایت»; `traffic(bytes)` is the quota form, 0 = «نامحدود»). Buttons,
in Telegram's left-to-right rows: «🔄 به‌روزرسانی اطلاعات» (`:refresh` — from the panel only, by Amir's call: a
toast when it answered; out of reach, a `BotText::ServiceRefreshFailed` popup and the screen stays as it was, never redrawn
from the row's copy); «🔗 لینک اشتراک» |
«♻️ تمدید سرویس»; the «🔁 تمدید خودکار: روشن/خاموش» switch (`:autorenew`, green while on; only while
`AutoRenewal::offeredFor()`) — a tap flips `auto_renew` and redraws **only the buttons** (`Context::editKeyboard()` →
`BotApi::editMessageReplyMarkup()`; no panel round-trip), with a popup saying when and what the wallet pays when it goes
on (`BotText::AutoRenewEnabled`) and a toast when it goes off; «⚙️ تغییر لینک» | «⚠️ ارسال گزارش اختلال»; «⬅️ بازگشت به لیست
سرویس‌ها» in red, to the list *page* the service sits on (`MenuHandler::subscriptionsCallbackFor()`, newest first). **«لینک اشتراک»** (`:link`) shows the kept link **in place of the
screen**, with «⬅️ بازگشت» to it (`ServiceCard::show()` with `BotText::LinkRequested` — which service it is, the
link in a `<code>` span and the tap-to-copy hint): the text is edited into the screen; the QR card is edited in too
(`BotApi::editMessageMedia()` — a text message can take media since Bot API 10.3) or, where Telegram refuses, replaces
the screen (`Context::editPhoto()`); "back" on the card replaces the card with the screen, since a photo cannot be
edited into text (`Context::edit()` does that for any button on a media message, `Update::hasMedia()`). By Amir's call:
edit in place when Telegram allows, otherwise delete and send — one message per screen, never a pile. No panel
round-trip, so the customer gets their link while the panel is down. «♻️ تمدید سرویس» (`:renew`) opens the renewal's
checkout (see Renewal from the bot). «⚠️ ارسال گزارش اختلال» (`:report`) opens a new support ticket about that service
(`TicketHandler::report()`: its first message awaited as a new ticket's is, asked in `BotText::TicketReportAsk`'s words of
the «پشتیبانی» group, «بازگشت» to the screen — see Tickets: the bot); showing the service screen leaves whatever step the
chat was at (`ChatSession::clear()`): what the customer types next is no outage report, and no old confirmation stands.
**«تغییر لینک»** (`:rotate`; the button, and the `BotText::ServiceRotateHint` line under the screen, only when the server's
driver has `linkRotation` — `ProvisioningService::rotates()`) asks first (`BotText::ServiceRotateConfirm` — every device on the old link drops; «✅ بله، تغییر بده» in
red on the right, «❌ انصراف» back to the screen; the conversation waits on `sub.rotate.{id}`) and then `:rotate:yes`
claims that confirmation — a second tap, or one after the customer went elsewhere, finds it spent and gets
`BotText::ServiceRotateExpired`, the link just delivered stands — → `ProvisioningService::rotateLink()` →
`rotateClientCredentials()`; the row learns the new link, the confirm message is deleted and the new link goes out
like a delivery (`ServiceCard::send()` with `BotText::LinkRotated`) and **nothing else** — no screen re-sent under it, by Amir's
call; a send that fails after the panel already changed gets `BotText::ServiceRotatedUnsent` with the «لینک اشتراک» button
under it. An inactive service cannot
rotate (`BotText::ServiceInactive` popup); a panel that fails — or a refusal of `rotateLink()`'s own, a service busy
or ended in the same moment — leaves the link as it was (`BotText::ServiceRotateFailed` + back). Every action of the
menu and button of the service screen opens a screen of its own — no «به‌زودی» placeholder ships. Frontend:
`pages/keyboards.tsx` + `components/keyboards/` (`keyboard-editor` — the rows (`keyboard-row`: a button's chip with its
icon and colour, row ▲▼ — the moved row keeping the focus on its arrow —, add/remove; «افزودن دکمه» held once every
action is on the keyboard, a line saying why), its draft `use-keyboard-draft` (`useForm` following the server's copy,
each row with an id of the draft's own — a moved row is the same row, its element and focus; never sent, nor counted as
a change (`reads`) —, each edit
within the API's `limits`; the save's 422 under the row it names) with a `SaveFooter`, native drag-and-drop of buttons
anywhere (`use-row-drag`) — while one is dragged, a drop zone under the last row makes it a row of its own;
`button-modal` — a new button's dialog `Creating`, no revert —; action Select (the label follows the action while it is the action's own),
label (only an empty one is refused here: the rest are the save's; a premium emoji's tag pasted into it is offered
«انتقال به آیکون دکمه», its plain emoji kept to stand in for the icon — `notePlainEmoji()`), the icon («آیکون دکمه»: the
chosen premium emoji, «انتخاب»/«تغییر» opening `PremiumEmojiPicker`, «برداشتن»), the colour as a radio group of
Telegram's colours (`styles.ts`: the API's `styles`, swatches from the `--tg-*` tokens) and, for a button on the
keyboard, its place — «به راست»/«به چپ» along its row, «ردیف بالا»/«ردیف پایین» (from the last row, onto a new one when
it leaves a button behind) — the keyboard's way to move one, the dialog staying on it;
`keyboard-preview` — Telegram-dark mock under the admin's own greeting (`BotText::Welcome` with its samples, read from
the bot texts), the icon before the label, palette in `.tg-preview` in index.css). Purchase flow (`Handlers\PurchaseHandler`): `plan:{id}` details (`BotText::PlanDetails` + either
`BotText::PlanPickServer` or `BotText::PlanNoServer`) + the plan's servers by name → `plan:{id}:srv:{server}` resolves the pick through
`ServerSelector::resolve()` and shows **the checkout** — `Handlers\CheckoutScreen`, the one every purchase in the bot
goes through (a plan, a service's renewal — `RenewalHandler` —, a top-up — `TopUpHandler` —, an agent's traffic —
`AgencyHandler`), the bot's rendering of the shop's one checkout (`Payments\Services\Checkout`, the website's too — see
Payment methods): what is being bought, a
button per enabled method by label (`PaymentMethods::forOrder()`), «بازگشت» to the step before. **Nothing is ordered
until a way to pay is picked, by decision**: `plan:{id}:srv:{server}:{method id}` makes the order — or finds the open one
of the same plan, server and price (`OrderService::openPurchase()`; `Order::payable()`: pending, no receipt in review;
picked again, its expiry counts from then) — and pays it (`CheckoutScreen::buy()` → `Checkout::pay()`, the screen
rendering its `CheckoutResult`): a wallet that cannot cover it says
so first (`BotText::PayInsufficient`, top-up + back) and nothing is ordered; the wallet settles at once and the outcome
*replaces* the checkout — `Context::delete()` takes the button's message off, then `ServiceCard::sendSettled()` sends the
service as a fresh message (QR card or text; `Context::replace()` = delete + reply is the same move for a plain text),
so the delivered service lands at the bottom of the chat like the notification it is; the wallet pays a checkout once —
the checkout's chat state (`checkout`) is claimed (`ChatSession::claim()`), so a second tap, or one on a checkout the
chat moved on from, gets `BotText::OrderNotPending` and nothing is charged; an order closed in the same moment unwinds
the debit (`OrderNotPayableException`); one paid whose delivery another process took in the same moment (`processing`)
says so as it stands — `BotText::PayProcessing`: paid, the order's number and what it brings
(`ReceiptHandler::outcome()`), which comes once delivered —, never «نامشخص». A card shows its card (`BotText::CardInstructions` from the gateway's
`CardTransfer`, «بازگشت» to the checkout) and sets the `ReceiptHandler::stateFor()` state that `ReceiptHandler` resolves
by storing the Telegram file id as the receipt for review; the same card again finds the same payment. There is no
«انصراف»: a checkout left alone orders nothing, and an order left unpaid expires (`ExpireOrdersTask`). A payment
reminder's «پرداخت دوباره» (`checkout:{order}`, `PurchaseHandler::reopenCallback()`) opens the checkout of what that
order buys — a renewal's, its service's renewal — while the customer may still pay it (`BotText::OrderNotPending` once paid, cancelled or its receipt is with
support).

**Bot texts are the admin's** («متن‌های ربات», `Telegram\Texts`, screen `/bot-texts` → `Admin\Api\BotTextsController`:
`GET /bot/texts`, `PUT /bot/texts/{key}` `{value}`, `POST /bot/texts/{key}/reset`). Everything the bot says to a customer
is a case of the `BotText` enum — its value is the key, the admin's wording lives under `bot.text.<key>` — and
`TextCatalog` describes each: its group on the screen (`GROUPS`; the ones the current bot says, `groups()` — not the
agency's in an agent's shop, whose texts there are a 404, `says()`), its `TextKind` (a message, the QR card's caption, a
popup, a button, or a part — a piece another text takes in, `%status%`, `%note%`, a hint under a screen — which the admin
may empty to drop it), a Persian title and where the customer meets it, the shop's default wording, and the `%variables%`
the code fills: `VARIABLES` is the one dictionary (name → meaning + the preview's sample; a text may word a variable its
own way — a named argument of `TextCatalog::vars()`, `self::vars('plan', 'traffic', traffic: 'حجم پلن')`), and `required` ones cannot be removed (a delivery without `%subscription%`). Every service text offers the same
eight (`Telegram\Notifications\ServiceCard::values()`: client, plan, server, duration, expires, traffic, remaining, subscription)
plus its own. `Texts\BotTexts` is the one way to a text: `get()` (a text without variables), `render(text, values)`
(exactly the declared variables — a mismatch is a `LogicException`: the code's mistake, never the admin's), `part(text,
values)` (a `TextKind::Part`, as a `Texts\Html` — the only way to one: get()/render() refuse a part, part() anything
else), `message()`
(a popup's text sent as a message, escaped), `welcome()`, `paragraphs()` (texts one under another; an emptied part leaves
no gap, a part's own leading line breaks give way to the blank line), `save()` (a 422 on `value`: empty, over `TextKind::limit()`, a button on two lines, tags in a popup/button, a
variable the text does not have or a required one missing, and `TelegramHtml::problem()` — Telegram's own parser rules,
so a wording Telegram would refuse is never stored and a customer never gets nothing instead of their service; CRLF → LF,
trimmed — a part only on the right, its leading line breaks are its spacing —; the default's own wording clears the row so
a default improved later still arrives), `reset()`, `present()`/`groups()`. **Escaping is render()'s alone**: a value is
plain text — a plan's name, a customer's caption, a URL — escaped on the way into a text Telegram reads as HTML (message,
caption, part) and left as typed in a popup or a button; a part the admin worded goes into another text as the HTML it
is, as the `Html` part() made of it (an `Html` in a popup or a button is a `LogicException`). No call site escapes a
value by hand. A caption longer than Telegram's 1024 characters (a long wording with a long link) goes as a plain
message instead of the QR card, never lost. `bot:poll`'s `Settings::refresh()` carries an edit to the bot within seconds. Adding a
text = a `BotText` case + its spec in `TextCatalog` (`TextCatalogTest` checks every default is a savable wording and still
Telegram HTML with the samples filled in) + the call site through `BotTexts`; tests expect the default through
`TestCase::text(BotText, values)`. Screen: `pages/bot-texts.tsx` (a card per group, search, a «همه / تغییر یافته»
`PageTabs` filter with the counts of what the search finds, row menu with the reset behind a `ConfirmModal`; each row's
plain-text preview parsed once per wording) + `components/bot-texts/`: `text-modal`
(the textarea — a toolbar that wraps the selection in Telegram's tags, variable chips that insert at the caret, per-line direction
(`unicode-bidi: plaintext`) so a line that starts with a tag reads left to right, «متن پیش‌فرض» puts the default in the
editor, a counter like the server's — UTF-16 units, as Telegram counts (an emoji beyond the basic plane two: a string's
own `length`), and for an HTML kind only what the customer sees, `visibleLength()`
—, and under it, as it is typed, what the save would refuse: `wording-problem` (`wordingProblem()`,
`telegramHtmlProblem()`, on `keptWording()` — the wording as `BotTexts::save()` keeps it, which the editor's unsaved
measure reads too) mirrors `BotTexts::problem()` and `TelegramHtml::problem()` in the server's words — its test
runs the PHP test's own cases —, a warning only: the server stays the judge; that line and the save's own 422 under the
field go through `isolateMarkup()`, every tag they name read as typed),
`text-preview` (Telegram-dark bubble, QR caption, popup or button, with the samples filled in), `telegram-html` (the
wording parsed with `DOMParser` and rebuilt as React elements from Telegram's tag list — never injected; a link is not
followed), `tokens` (`WithTokens`: `%variables%` set apart LTR inside Persian).

**Premium emoji** (Telegram's custom emoji; since Bot API 9.4 a bot may send them in private chats and groups **only while
its owner — the account that made it in @BotFather — has Telegram Premium**). A text carries one as Telegram HTML,
`<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>`: the id, and the plain emoji shown wherever the premium one cannot
be. `TelegramHtml` accepts the tag in a message, a caption or a part (an id of digits, the content one emoji, never inside
a link), and the limits of those kinds count what the customer sees as Telegram counts it (`TelegramHtml::visibleLength()`
— tags cost nothing, so a 60-character premium tag is its emoji's two UTF-16 units; `Telegram\Api\Limits::length()` is
that count of a plain text, a popup's or a button's, `Limits::cut()` a plain text cut to its room, never half an emoji —
what ticket words cut to a message's room take too). Popups and button labels cannot show them; a keyboard button may carry
one as its icon (`icon_custom_emoji_id`, see Bot keyboards). **Getting the ids**: a bot
admin sends `/emoji` (`Handlers\CustomEmojiHandler`, state `emoji`; anyone else gets no answer, like /broadcast) and then a
message composed in Telegram the way the customer should see it — premium emoji, formatting, `%variables%` typed in.
`Texts\EntityHtml::of()` writes the message's entities as Telegram HTML (UTF-16 offsets, as Telegram counts; what
Telegram found by itself — a @handle, a typed link — stays text), `premiumEmoji()` lists its premium emoji, and
`Emoji\CustomEmojis::remember()` keeps them (`custom_emojis`: the id, the sticker's own plain emoji, a still picture's
file id and how Telegram draws it — `format` static/animated/video (`is_animated` = a Lottie .tgs, `is_video` = WebM; null
until Telegram was asked: a row kept while it could not be learns it when its picture is asked for), `animation_file_id`,
`repaint` (`needs_repainting`: Telegram paints it in the text's colour; such stickers come drawn black) — from
`getCustomEmojiStickers`, 200 ids a call; the latest `MAX` = 200, one seen again moves to the front). The bot
then sends the message back **written by itself** — whether the sent message's entities still carry `custom_emoji` is
whether the bot may use them, recorded for that bot as `bot.premium_emoji` `{ok, at}` (`Emoji\PremiumEmojiStatus`; the last
finding is forgotten first, so the bot asks afresh and a Premium bought meanwhile is seen at once) — and answers with a summary and the
template in a `<pre>` (a tap copies it) to paste into a text. **The editors' picker**: «ایموجی پرمیوم» in the text modal's
toolbar and «آیکون دکمه» in the keyboards editor's button modal (`components/bot-texts/premium-emoji-picker` —
`PremiumEmojiPicker`: `onPick(emoji)`, and `selected` when it is a choice, as for a button's icon; the list is one query,
`components/bot-texts/custom-emojis` — `useCustomEmojis()`, `useCustomEmoji(id)`, `usePlainEmoji()` for the plain emoji
behind an id that travels without its tag) list them (`GET /bot/custom-emojis` → `{emojis: [{id, emoji, format, repaint,
…}], status}`; `DELETE /bot/custom-emojis/{id}` takes one off the list, a text or a button that uses it keeps it), a tap
puts the tag at the caret or makes it the icon, and it warns while the last `/emoji` found Telegram taking them off.
Pictures: `GET /bot/custom-emojis/{id}/image` streams a still one fetched from Telegram as the panel asks (the sticker's
thumbnail, or the sticker itself when it is still; nothing kept for good, like a receipt — the bytes kept a few minutes
for the reads that follow, `Core\Support\FileCache`; any id a text carries, kept or not;
404 when Telegram has none), and `GET /bot/custom-emojis/{id}/animation` how one moves (`CustomEmojis::animation()`: the
.tgs as Telegram has it — gzipped Lottie JSON — or the WebM, told apart by their first bytes;
404 for a still one, without asking Telegram when the row knows). Both go through `ApiController::bytes()`: a picture or
a WebM the panel shows goes as itself, anything else — the .tgs, a thumbnail Telegram hands over in another shape — as
`application/octet-stream`, an attachment a browser never renders (the panel fetches the .tgs through lib/api, `api.blob()`,
and unzips it itself; the picture's address is `mediaUrl()`'s, the shop in its query). `PremiumEmoji` (`components/bot-texts/premium-emoji`)
shows one everywhere — the picker, the text preview, a button's chip and icon, the keyboard preview — as Telegram draws
it, by its kept row: an animated one plays as Lottie (lottie-web's light build, SVG, fetched as its own chunk with the
first one; the .tgs unzipped in the browser by `DecompressionStream`, each animation fetched once and shared), a video one
as a looping muted `<video>` (`preload="none"`, its still picture the poster: nothing fetched until it first comes on
screen), each only while on screen (an IntersectionObserver plays and pauses it) and not under prefers-reduced-motion;
a repainted one in the text's colour (the still through a CSS mask filled with `currentColor`, the animation through
`.emoji-repaint` in index.css; a repainted video keeps its painted still); the still picture until the animation is
ready, and the plain emoji when there is no picture. One the list does not keep shows its still picture.
**A message is never lost to an emoji**: `BotApi::call()` sends a message whose premium emoji Telegram turned down (a
`Refusal::CustomEmoji` while the text, caption or an edited picture's caption carries a `<tg-emoji>`, or a button of its
`reply_markup` an `icon_custom_emoji_id`) again with the plain emoji and the buttons without icons (labels and colours
kept), and remembers the "no" (`PremiumEmojiStatus::record(false)` — each bot its own, in the settings table, so the poller,
the cron and the webhook all heed it): for `PremiumEmojiStatus::HOLD_SECONDS` (600) the next ones go that way from the
start instead of paying a refused call each, then premium emoji are tried again.

## Store API (the website: app/Modules/Store, app/Modules/Accounts)

The shop's own website — the main bot's and every agent's — talks to the shop through one public JSON API under
`{APP_URL}/api/store/v1/{store}` (the integrators' guide is docs/Store-API.md and the Store-API-* pages beside it — see
Documentation —; built in slices: slice 1 — the website, its door, CORS,
customers' sessions, the sign-in challenges, the OIDC verifier, Telegram sign-in, `GET /`, `GET /me`, the sessions and
signing out —, slice 2A — customers without Telegram (see Users), the shop's email (see Mail), signing up, in and back
in with an email and a password, Google sign-in, the captcha —, slice 2B — two-factor sign-in, the customer's own
names and password, ways in added and taken away, two accounts merged into one, the website account on the customer's
page —, slice 3A — what the shop sells and how its servers stand, a signed-in customer's services (read from the
panel, «تمدید خودکار», a new link), orders and payments, wallet and ledger, referrals —, slice 3B — the checkout,
the bot's own (purchases, renewals and their preview, top-ups, the ways to pay; every ordering request with its
Idempotency-Key), and card receipts uploaded from the site —, slice 4 — every notice the shop sends a customer kept
for their website, read and marked read there, and emailed to a customer without Telegram — slice 5A — support
tickets, the backend: the website's side of a conversation, the panels' API and the report group's —, slice 5B — the
bot's screens for them —, slice 5C — the panels' screens, see Tickets —, the review round, slice 6, and its security
round — the email budgets, the emailed codes' budget, the website's own PKCE verifier, JSON bodies, the captcha a
verifier of its own, see Captcha). Store
is the website and the API's HTTP layer; Accounts is customer sign-in and the customer's own account; Notifications is
what the shop told a customer; Support is the tickets. **Store leans on Accounts, never the other way** (dependency
inversion, by decision): what a sign-in needs of the website is Accounts' own `Accounts\Contracts\SignInSite` — `shop()`,
`telegramClientId()`, `telegramClientSecret()` and `hasTelegramSecret()` (the redirect flow set up), `googleClientId()`,
`allowsEmailSignUp()`, `allowsOrigin()`, and the captcha it asks — it extends `Core\Captcha\CaptchaSite`:
`captchaDriver()`, `captchaConfig()`, `captchaHosts()` —, which `Store\Models\Website` implements; every Accounts
service takes the interface (a controller hands it `StoreMiddleware::website()`), and no Accounts file names a Store
class (tests/Unit/Core/LayersTest). **Its contract holds, by decision**: v1 (the address's version)
only gains fields — none is removed, renamed or given another type, and a request takes what it took —; a change that
would break a website comes as v2, beside v1 (said in the description's `info`). Its refusals are the one error shape,
`message` in the customer's words (the panels': the admin's).
**The website** (`Store\Models\Website`, `websites`, one per bot, BelongsToBot): `key` — the store key, 24 lower-case hex
characters that name the shop in the address, no secret —, `enabled`, `url` (the site; its origin may call the API),
`origins` (others: a site being built on http://localhost:3000), `telegram_login`, `telegram_client_id` — the Client ID
@BotFather shows for the site (Bot Settings › Login Widget), **not the bot's id**, by Telegram's word —,
`telegram_client_secret` (Encrypted; only the redirect flow needs it), `email_signup` (signing up with an email — it takes
the shop's email), `google_client_id` (the site's OAuth client id in Google Cloud: Google sign-in is on while it is set,
`Website::googleClientId()`), `captcha_driver` (the captcha its forms ask, a captcha driver's key; null: none) and
`captcha_config` (that driver's form's values by key, JSON encrypted at rest — `EncryptedArray`; see Captcha),
`reviews_enabled` (it shows its customers' reviews and takes new ones — off by default; see Reviews), and how
the shop's admins work it (see The shop's admins on its website): `staff_enabled` (let in — off by default),
`staff_strong_sign_in` (a strong sign-in asked of them — on by default), `staff_grants` (JSON, what they may do beyond
the shop's daily work — `Store\Enums\StaffGrant` values, none by default; `Website::staffGrants()`). Both panels set their own shop's: `GET|PATCH
/api/{panel}/website`, `POST …/website/key` (`Admin\Api\WebsiteController` → `Store\Services\Websites`): `current()` makes
the row switched off with a key the first time it is asked (`createOrFirst` on its unique `bot_id`); `update()` is one
partial write, by decision — each of the screen's cards sends its own fields — on the field engine (`Form::checkSent()`,
see Drivers): a field sent is changed, one left out stays as it is kept (an empty body changes nothing — not even a
write — and answers the website), a secret sent blank keeps the one kept and `clear_<secret>` empties it; what is sent
is checked as it is typed (strict switches; `url` http(s), a host, no credentials, ? or # — the kernel's `Url`, named
«آدرس وب‌سایت» in its refusals;
`origins` a list or typed text, `ORIGINS_MAX`, each `scheme://host[:port]` and nothing after it, kept in
`Core\Http\Origin`'s normal form — `Store\Forms\Origins`; the Client ID digits; a new secret printable ASCII — the
Client Secret goes as HTTP Basic; Google's client id in Google's own form, `GOOGLE_CLIENT_ID`:
`<digits>-<name>.apps.googleusercontent.com`) and what the website needs is asked of it as it would stand after the
change (`Store\Forms\Ruled`, a field with a rule of the form's own): on, an address — the one sent or the one kept
(`URL_NEEDED`); Telegram sign-in on, a Client ID (`CLIENT_ID_NEEDED`); email sign-up switched on (not kept on), the
shop's email going out (`Websites::MAIL_OFF`, which sends the owner to «تنظیمات پنل › ایمیل»). The captcha is sent
whole as `captcha` (`WebsiteCaptchaRequest`): `none` (`Websites::NO_CAPTCHA`), or a driver's key and its form, checked
by that driver's form — a secret left blank keeps the one kept while the driver and the field it is bound to stay —,
refused under `captcha.<field>`; the admins' two switches strictly, their grants the whole list sent (`Store\Forms\Grants`:
nothing but grants, kept without repeats in the grants' own order); every refusal at once, under its field, before anything is kept; a secret is written
only when it changes (encrypted afresh, the one kept would be rewritten for nothing). `rotateKey()` ends the old
address at once, `present()` never a secret (`telegram.has_secret`; a captcha secret as its SecretState), the base address
(`Urls::store()`), `telegram.bot_id` — the shop's bot's own id, `Bots::telegramId()`, a hint beside the Client ID —,
`email {enabled, mail_ready}` (`mail_ready`: the installation's email goes out, `Mailer::ready()`), `google {client_id}`,
`captcha {driver, drivers, values}` (the driver asked or `none`, every captcha described, each one's fields as the
website keeps them), `reviews {enabled}`, `staff {enabled, strong_sign_in, grants}`. No change-feed area: only the panel writes the row. **The panels' screen**
(«تنظیمات وب‌سایت», both panels' settings column after the bot's, `WEBSITE_SETTINGS` in components/shell/nav;
`pages/website-settings`, its parts in components/website/; a section each, `WEBSITE_SETTINGS_SECTIONS`): «اتصال» —
`ConnectionSection` (the switch, the site's address, the other origins typed one a line and sent as a list) and
`ApiAddressCard` (the base address to copy, the key, «ساخت کلید جدید» behind a `ConfirmModal`) —, «ورود با تلگرام» —
`TelegramLoginSection` (the switch, the Client ID with the bot's own id beside it, the Client Secret kept as any secret
is — blank keeps it, `clear_telegram_client_secret` empties it —, BotFather's steps with the saved address) —, «ورود با
گوگل» — `GoogleLoginSection` (the Client ID, blank for off, under Google Cloud's steps: an OAuth client for a web
application, the website's origin — its saved address without a path — among its Authorized JavaScript origins, or a
pointer to «اتصال» while there is none) — and «ورود با ایمیل» — `EmailSection`: «ثبت‌نام با ایمیل» (the switch, held
while no email goes out and it is kept off — a warning `Callout` says so, the way to the shop's email the panel's own:
`WebsiteSettingsPage({mailSettings})`, the owner's app handing it /settings/mail, an agent's told the owner sets it up —;
the accounts made already keep signing in and resetting their password whatever it says) and the captcha card
(`CaptchaCard`: «خاموش», «Cloudflare Turnstile» or «ALTCHA», the chosen one's form drawn by `DriverFields` — see Captcha)
—, «نظرات» — `ReviewsSection` (the switch, see Reviews) — and «مدیران سایت» — `StaffSection` (components/website/staff-section: the let-in switch, the strong sign-in's, a switch
a grant in plain words — the group one field of the form —, and the way to who the admins are, the users list narrowed
to the role, `/users?role=admin`; see The shop's admins on its website). One read
(`websiteQuery`, `queryKeys.website`); a card's save is `useWebsiteGroup()`: a PATCH of the card's own fields alone (a
secret only when typed, `keptSecret()` its field's word) — its unsaved changes measured by that very PATCH (`reads`), so
a driver picked and picked back, or a secret's field left blank, is no change —, the answer — the website whole — put in place of the read, where
the other cards find it, and the card started again from it (a secret typed gone with the save); another card's save
leaves a draft as it is. **The door**:
routes/api.php's group on `Urls::STORE` (`/api/store/v1/{store:[0-9a-f]{24}}` — `Urls::PLACEHOLDER` is the one reading of
a route's placeholder, a quantifier's braces and all: Urls, Redact and the route-walking tests use it) behind
`InstalledMiddleware`, `Store\Http\StoreMiddleware` and `JsonBodiesMiddleware` (a body is JSON, a multipart form only on
the upload routes — see Production: HTTP): `Websites::open(key)`, read across shops — switched on, its
shop's bot running (an agent whose agency ended has their website closed with their panel) — else the error shape's 404
`StoreMiddleware::CLOSED` whatever the address under it; the request is worked in the website's shop (`CurrentBot::run`)
with the website on it (`StoreMiddleware::website()`). No PHP session, no CSRF header: a customer's own routes sit behind
`Accounts\Http\CustomerAuthMiddleware`. **CORS** is `Core\Http\Middleware\CorsMiddleware` (HttpKernel: outside the
routing, so a preflight is answered before it, and around the error handler, so an error answer carries it too) over the
`Core\Http\CorsPolicy` the container binds to `Store\Http\WebsiteCorsPolicy`: only a request carrying `Origin`, only a
Store API path (a string check after `http.base_path`, decoded as the router reads it), only an origin the open website
has (`Website::allowsOrigin()`: its `url`'s origin or one of `origins`), a shop not installed or a database that does not
answer allowing none. Allowed: an OPTIONS is a 204 (`Allow-Methods` GET, POST, PUT, PATCH, DELETE; `Allow-Headers`
Authorization, Content-Type, Idempotency-Key; `Max-Age` 600; `Vary: Origin`), any other answer gets `Allow-Origin`,
`Vary` and `Expose-Headers: X-Request-Id, Retry-After, WWW-Authenticate` (what a 401 or a 403 asks of the token); never credentials (a bearer token, no cookie). Anything else gets
nothing — the panels' API never (JsonCsrfMiddleware's protection rests on that); Slim answers an OPTIONS no route takes
with its own 200, which a browser refuses without the headers.
**Customers' sessions** (`Accounts\Services\CustomerSessions`, `customer_sessions`): `open(user, request, method)` → `DTO\SignedIn`
— a 64-hex bearer token shown once, its sha256 kept, the device in words (`Accounts\Support\DeviceName`: «Chrome در
Windows», «مرورگر در Android», «دستگاه ناشناس») and `RequestOrigin::clientIp()`, `authenticated_at` now (the sign-in),
and how it was signed in (`method`, an `Accounts\Enums\SignInMethod`: `telegram`, `google`, `password` — a sign-in's, a
sign-up's, a reset's —, `password_2fa` — the second step's —; null on a session made before the column, which counts as
none); `SignInMethod::strong()` is every way but a password alone, `isStrong(session)` whether the session was signed in
so — or a strong way proven on it since: `reauthenticated(session, method)` stands it signed in the stronger way, a
password alone proven again taking nothing away —, what the shop's admins are asked (see The shop's admins on its website);
a customer keeps `MAX_SESSIONS` (20), the newest: one more ends their oldest; `find(token)` in the current shop (BelongsToBot:
another shop's token is a 401), the customer eager-loaded, ended ones none — `IDLE_DAYS` (30) unused (`last_used_at`, else
`created_at`) or `LIFETIME_DAYS` (180) after the sign-in —; `touch()` every 5 minutes at most; `close()`, `end(user, id)`
(another's is a 404), `endAll(user)` (a new password signs them out everywhere), `endOthers(user, keep)` (a password
changed from a session: that one stays), `signOutEverywhere(user, actor)` (support's, from a panel or the website's
admins — logged with the actor), `count(user)`
(the devices signed in), `list(user)` newest first,
`present(session, current)`. `CustomerAuthMiddleware`: `Authorization: Bearer …` → the session, else 401 `SIGNED_OUT`
with `WWW-Authenticate: Bearer`; a banned customer 403 (`SignInRefusedException::BANNED`); the request carries
`Accounts\Http\Customer` (`Customer::of()`: session + user), the use touched, the presence written quietly
(`Customers::seen()`). **A recent sign-in** ("sudo mode", by decision: a bearer token alone — one that leaked — takes no
account over): what changes how the account is signed in to — every `/me/identities` write (the email code's send and
verify too), `PUT /me/password`, `/me/2fa/setup|enable|disable`, `POST /me/merge`: routes/api.php's sub-group inside the
customer's — sits behind `Accounts\Http\RecentSignInMiddleware`: the session's `authenticated_at` within
`CustomerSessions::RECENT_SECONDS` (900; `isRecent()` — `provenUntil(session, seconds)`, until when a way in proven on
the session counts for that long, which the website's admins' 12 hours read too), else a 403 «برای این کار دوباره وارد شوید.» (`SIGN_IN_AGAIN`)
with `WWW-Authenticate: Bearer error="insufficient_user_authentication", max_age=900` (RFC 9470's; `CHALLENGE`) — never
the 401 that says the session ended. `POST /me/reauthenticate` (`MeController::reauthenticate()` →
`Accounts\Services\Reauthentication::prove()`) proves one way into this very account and renews the session's
`authenticated_at` — and its `method` when the way proven is a stronger one (`reauthenticated()`) → `{expires_in: 900}`: `{method: password, password, code?}` — `EmailSignIn::
confirm()`, and while two-factor sign-in is on `TwoFactor::confirm()` (its app's code or a recovery code, as a sign-in
takes them, against the same second-step budget); both missing fields said at once; an account without a password
`NO_PASSWORD` —, `{method: telegram, id_token, nonce}` or `{method: telegram, code, state, code_verifier}` (the state the customer's own,
`POST /me/telegram/authorize`), `{method: google, id_token, nonce}` — the account proven must be this one, else a 422 on
the proof's field (`NOT_THIS_ACCOUNT`; `TelegramSignIn::proofField()`), counted as a failed sign-in; a method none of
these is a 422 on `method`. Throttled as sign-ins are. **Challenges** (`Accounts\Services\AuthChallenges`, `auth_challenges`, `Enums\ChallengePurpose` —
a nonce, an OIDC state, a sign-up's, a reset's and an email link's code, a two-factor sign-in's second step, an
authenticator app's secret waiting for its first code, a merge ticket): `issue(purpose, subject, user, payload, seconds)`
hands out 64 hex characters and keeps their sha256 with the payload (encrypted JSON); `spend(purpose, secret, holder?)` is
one conditional DELETE — of two spends at once one gets the payload, an expired one is gone with nothing; with `holder`,
only one issued to that customer (another's ticket is left as it is), without one only one issued to nobody (a
sign-in's: no signed-in customer's request spends it, and a customer's no sign-in does) —; `nonce()` (`NONCE_SECONDS`, 30 minutes). One tried
with codes counts its tries on its row, one way — `attempt(challenge, right)`: the try counted first by one conditional
UPDATE (`attempts` below `CODE_ATTEMPTS`, 5: two at once count twice), then `right(payload)` judges it, then one conditional
DELETE spends it — answers the payload, else null (wrong, expired, spent, tried out) —: a code emailed to an address
(`SignUp`, `PasswordReset`, `LinkEmail`: `issueCode(purpose, address, user, payload, seconds)` — six digits, good for
`CODE_SECONDS` (15 minutes), one a purpose, an address and a holder at a time (a new one replaces that holder's last —
never another customer's who asked for the same address), kept as a keyed hash — `Core\Security\Encrypter::mac()`,
HMAC-SHA256 under a key derived from APP_KEY for it (HKDF, label `amobot-mac`: never the encryption key itself) — bound to its purpose and address, since six digits are no
secret to a bare hash —; `checkCode(purpose, address, code, holder?)`, the hash compared in constant time, with `holder`
only the code sent for that account), a two-factor sign-in's (`find(purpose, secret)`: the live one — not expired, its
tries not used up — by its secret) and an app's secret (`hold(purpose, user, payload, seconds)`: one a purpose and a
customer, a new one replaces it, found by its customer — `held()` —, never by a secret); `[bot_id, purpose, subject]` is
its index. What anyone may ask for — a nonce, a redirect's state (`ChallengePurpose::anyonesToAsk()`) — a shop issues
`AuthChallenges::LIVE_MAX` (5000) of in their lifetime at most, whoever asks from however many addresses: past it a 503
(`Accounts\Exceptions\SignInsBusyException`, the log told once) until there is room again — a stranger fills no table.
`Accounts\Tasks\PruneAccountsTask` (hourly, across shops) forgets ended sessions and expired challenges —
and the notices half a year old (`Notices::prune()`, see Notifications below).
**OIDC** (`Accounts\Oidc`, firebase/php-jwt 7): an `OidcProvider` is its `issuers` (the `iss` its tokens may carry, the
first its name in the log), its JWK set and its endpoints — `telegram()` (https://oauth.telegram.org,
`/.well-known/jwks.json`, `/auth`, `/token`) and `google()` (`https://accounts.google.com` or `accounts.google.com`, as
Google signs; its keys at https://www.googleapis.com/oauth2/v3/certs); `Jwks` reads a provider's key set through the
shop's outgoing client and keeps it in the container's `jwks.path` (a file per provider, written whole) an hour, reads it
again for a `kid` it lacks at most once a minute, lets a kept set stand in while the provider is out of reach (logged),
and takes only asymmetric signing keys (no `oct`, no `use: enc`, an algorithm by type when a key names none);
`IdTokenVerifier::verify(provider, jwt, audience)` — the header's `alg`/`kid` text, the key its `kid` names (or the set's
only one), `JWT::decode` on the shop's clock (`JWT::$timestamp = now()`, put back after) with 60 s leeway, `exp` and
`iat` required, `iss` one of the provider's, `aud` alone or in a list (a numeric one read as text) — refuses with
`SignInRefusedException` 401 (`TOKEN_INVALID`, `TOKEN_EXPIRED`, `TOKEN_ELSEWHERE`), never repeating the token;
`CodeExchange::idToken()` POSTs the code (HTTP Basic client id:secret, the form — grant_type, code, redirect_uri,
client_id, code_verifier —, no redirects followed): a 4xx is null (the provider's `error` logged, never the secret),
anything else not an id_token `ProviderUnreachableException`. **An id_token handed to the page** — Telegram's popup,
Google's — signs anyone in one way, `Accounts\Services\IdTokens::nonced(provider, audience, input)`: it came for a nonce
the site got from `POST /auth/nonce` (none sent: a 422 on `nonce`); the token verified first (a provider out of reach
leaves the nonce for a retry), then the nonce spent and the token's own (`IdTokens::carries()`, which the redirect's kept
nonce is held to as well), else a 401 (`SIGN_IN_SPENT`).
**Telegram sign-in** (`Accounts\Services\TelegramSignIn`, `Store\Api\AuthController`): under the website's
`telegram_client_id` (`Website::telegramClientId()`: null while off or unset → 422 `TELEGRAM_OFF`). Popup — Telegram's
`https://oauth.telegram.org/js/telegram-login.js` runs the code + PKCE flow itself and hands the page a ready id_token
for the nonce the site got from `POST /auth/nonce`; the site posts `{id_token, nonce}` (`IdTokens::nonced()`). Redirect —
authorization code and PKCE, **the verifier the browser's own**, by decision: the website makes it, keeps it where the
page that began the sign-in can read it (its sessionStorage) and sends the shop only its challenge — `POST
/auth/telegram/authorize {redirect_uri, code_challenge}` (422 `REDIRECT_OFF` without a client secret; the address http(s)
on the website's origins, no #, else a 422 on `redirect_uri`; the challenge S256's shape, else a 422 on `code_challenge`)
keeps an `OidcState` challenge (10 minutes: the code challenge, the address, a nonce) and answers Telegram's `/auth` with
client_id, redirect_uri, response_type=code, scope `openid profile telegram:bot_access` (`SCOPE`), state, code_challenge
(S256) and nonce; the site posts `{code, state, code_verifier}`: the state spent, the verifier the one whose challenge the
state keeps — else the sign-in began in another browser and opens nothing —, the code exchanged with the client secret
and that verifier (`CodeExchange`), the token's nonce the kept one. The shop keeps no verifier: a code and state another
browser brings back (a crafted link) come without it. **The state is whoever asked for it**, by decision (a code and state
another browser brings back — login CSRF, an account-linking one — opens nothing): `/auth/telegram/authorize`'s is a
sign-in's (issued to nobody), spent by `/auth/telegram` alone; a signed-in customer's own is `POST /me/telegram/authorize
{redirect_uri, code_challenge}` (`authorize(…, holder)`, the holder an `Accounts\Http\Customer` — the customer and the session that asks:
the `OidcState` issued to them, its subject that session, `session:<id>`), spent by `/me/identities/telegram` and `/me/
reauthenticate` from that very session alone — `AuthChallenges::spend(purpose, secret, holder, subject)` takes each only
from whoever it was issued to (another device of theirs neither spends nor wastes it). The token's `id` claim is the numeric Telegram id the bot keys its
customers by (required: `NO_TELEGRAM_ID` — the site must ask for the profile); `sub` (Telegram's own OIDC id) is not
used. Either flow is `TelegramSignIn::account(website, request, input, holder?)` → `DTO\TelegramAccount {id, profile}` — the one
"verified Telegram account of this input", the throttle's count and Telegram's silence with it, which linking a Telegram
account to a signed-in customer's and proving it again take too (`holder`: then a proof that does not hold is a 422 on its
field, `proofField()` — `id_token`, or the redirect's `code` —, never the 401 that ends a session; see Ways in). Then `Customers::byTelegram(id, profile — preferred_username, given_name (else name), family_name —,
referral_code)`: the bot's customer, profile refreshed, or a newcomer registered exactly as the bot registers one; banned
→ 403; a session opened → `{token, customer}` (`Accounts\Services\AccountPresenter`, `GET /me`'s shape). Telegram out of
reach is `TelegramUnreachableException`'s 502 (its constructor takes any cause), logged, counted for nothing.
**Google sign-in** (`Accounts\Services\GoogleSignIn`, `POST /auth/google {id_token, nonce, referral_code?}`): under the
website's `google_client_id` (none: 422 `GOOGLE_OFF`) — Google Identity Services hands the page its id_token (the
`credential`), asked for with a nonce from `POST /auth/nonce` —, through `IdTokens::nonced(OidcProvider::google(),
client id, …)`; `sub` is required (the Google account; else `TOKEN_INVALID`), and **the address Google speaks for** is
judged in one place, `GoogleSignIn::trustedEmail()`, by Google's own rule for when an address proves its owner now:
`email` while `email_verified` is true (JSON's, or its text) **and** Google is the address's authority — an @gmail.com
address, or a Google Workspace account's (an `hd` claim); any other address (verified once, its domain's owner today
maybe another) is null — it finds, signs in to, takes on and offers a merge with no account, and no newcomer keeps it —
`GoogleSignIn::account(…, holder?)` → `DTO\GoogleAccount {sub, email, profile}`, the sign-in's, linking's and proving
again's one proof (a holder's proof that does not hold a 422 on `id_token`). Then `Customers::byGoogle(sub, trusted
email, profile — given_name (else name), family_name —, referral_code)`: the account that Google account signs in
already; else the one with that address, which takes the Google account on (a conditional UPDATE while `google_sub` is
null: of two at once one does it) and is told (`CustomerNotifier::wayInAdded()`, from `GoogleSignIn::signIn()`) — but
never one with two-factor sign-in on (the address alone is all a password reset asks, no way past the second step) nor
one another Google account signs in to: a **409** `GOOGLE_ACCOUNT_EXISTS` («حسابی با این ایمیل هست؛ …»: signed in its
own way, the customer takes this Google account on from their account's settings; 403 stays a banned customer's);
else a newcomer registered as every newcomer is, with the address when Google speaks for it (`createOrFirst` on `google_sub`; an address that took an account
in the same moment is found on a second look). Google's names fill a newcomer's alone: an account's are not changed by a
sign-in. Banned → 403; Google out of reach → 502 (`GOOGLE_UNREACHABLE`), logged, counted for nothing.
**An email and a password** (`Accounts\Services\EmailSignIn`): every stored address proven, which addresses have an
account told to nobody. `App\Support\Email` is the one reading of an address (trimmed, lower case, 191 characters at most,
PHP's filter, and none of what reads as more than an address — a quoted local part, any of `<>",;`, whitespace —:
`problem()` in the form's words); `App\Support\Password` is the one password rule, the panel's login's too
(`problem()`: `MIN_LENGTH` 8 characters, `MAX_BYTES` 72 — what bcrypt reads —, no NUL byte — where bcrypt stops reading, `NUL`; `hash()` with PASSWORD_DEFAULT). `POST
/auth/register {first_name, last_name?, email, password, referral_code?, captcha?}` — while the website's `email_signup`
is on (else 422 `EMAIL_OFF`) and the shop's email goes out (else 503 `MAIL_OFF`); every field refused at once (the names
by `Accounts\Services\AccountNames`' one rule: a first name 1–64 characters, a last name 64 at most), then the
website's captcha (`Accounts\Services\Captcha`, `CaptchaAction::SignUp` — see Captcha); an address with no account gets a code (`Accounts\Mail\SignUpCode` — nothing in it the requester typed, their
names neither —, the account it makes kept with it: the names,
the password's hash, the invite code) and one with an account an email saying so (`AlreadyRegistered`) — the same 202
`{expires_in}` either way, the password hashed either way so the time tells nothing; a second sign-up sends a new code in
the last one's place. `POST /auth/register/verify {email, code}` takes it back and registers the account as every
newcomer is (`Customers::byEmail()`: reported, the invite code's owner its referrer and told), signed in; an address that
took an account meanwhile is a 409 (`EMAIL_TAKEN`). `POST /auth/login {email, password, captcha?}` — a wrong address and a
wrong password are one 401 (`WRONG_EMAIL_OR_PASSWORD`; an address without an account is checked against a hash of
nothing at the running PHP's default cost — `NOBODY`, 10 before PHP 8.4, 12 since —, so it takes as long), counted
against the address and the account; banned 403; the hash made again
when PHP's default moved on (`password_needs_rehash`); an account with two-factor sign-in on answers its second step's
challenge instead, a 202 `{two_factor: {challenge, expires_in}}` (`DTO\TwoFactorChallenge`, see Two-factor sign-in). `POST /auth/password/forgot {email, captcha?}` — a code
(`PasswordResetCode`) to an address that has an account, and to one without an email saying it has none (`NoAccount`,
once a day an address: `SignInThrottle::noAccountEmail()` — a later ask that day sends nothing, every count and the
answer the same): an email either way, so neither the answer nor its time tells which (the same throttles), the same 202 — and `POST
/auth/password/reset {email, code, password}`: the new password kept, every session of the account ended
(`CustomerSessions::endAll()`), this device signed in, the customer told (`passwordChanged()`) — or, two-factor sign-in on
(a reset leaves it on, by decision: the email alone would be a way around it), the same 202 challenge and **nothing
changed yet**: the new hash waits in the challenge's payload (`TwoFactor::NEW_PASSWORD`), and only the second step sets
it and ends the other sessions (`TwoFactor::signIn()`). A code is six digits (Persian ones too); one that is no six
digits takes no try off the real one, a wrong one is a 422 on `code` (`EmailSignIn::WRONG_CODE` — wrong, expired, spent
and tried out alike) that counts against the address and the account — and against the emailed codes' own budget
(`SignInThrottle::emailedCode()`: `CODE_FAILURES_HOURLY` (10) wrong ones an hour and `CODE_FAILURES_DAILY` (20) a day for
the address, in its shop, and for the account adding it, counted before each is judged; spent, a 429 whatever the code,
no new code goes to the address until it has room again, and the address's account is told once a day —
`CustomerNotifier::emailCodesFailed()`, `BotText::EmailCodesFailed` of the «حساب وب‌سایت» group, `NoticeType`
`email_codes_failed`; a code that opened gives its count back). Signing in and resetting a password do not ask
`email_signup`: an account made while it was on keeps its way in. An email that does not go is `MailFailedException`'s
502 (see Mail).
**The captcha** (`Accounts\Services\Captcha`, over `Core\Captcha\Verifier` — see Captcha): while the website asks one,
`POST /auth/register`, `/auth/login` and `/auth/password/forgot` take the widget's token as `captcha`, judged for the
form's action (`CaptchaAction`, each operation's `x-captcha`) after the form's own fields: none, or not passed, a 422 on
`captcha` (`Verifier::REFUSED`); one that could not be judged a 503 (`Core\Captcha\CaptchaUnavailableException`).
**What signing in costs** (`Auth\Services\SignInThrottle`): every way the website has — Telegram, Google, a password, a
code, a second step's code, the password confirmed before a change, a way in proven again — fails into one count, the way `website` (`WEBSITE_ATTEMPTS`, 30, an address network in `DECAY_SECONDS` — the panels' ways keep `MAX_ATTEMPTS`, 10 —, and for a password or a
code the account too, `MAX_ACCOUNT_ATTEMPTS`, keyed in its shop — the same address in an agent's shop is another account; a success clears nobody's count), then a 429; and what a sign-in costs the
shop before anything is tried — a row kept or an email sent: a nonce, a redirect's state, a sign-up's or a reset's code,
a captcha's token judged (a passing one given back, `issuedNothing()`) — is `issuing()`: `ISSUE_MAX` (120) an address
network in `ISSUE_SECONDS` (10 minutes), then a 429 — the address limits loose
by decision: Iran's mobile networks put many customers behind one address (carrier NAT), and the per-account counts are
the targeted protection —. **An email costs more, so it has budgets of its own** (`emailing(request, address,
askedBy?)`, each counted before the email goes — requests that come while the mail server talks send none of their
own —, whether or not the address has an account, so a refusal gives none away; `emailNotSent()` gives them back when
none went — refused on the way, or a mail server that did not take it: `EmailSignIn::emailCode()`): the address's turn
once in `CODE_EVERY_SECONDS` (60) and its day, `EMAIL_RECIPIENT_DAILY` (10) from every shop together; the asking
network's hour, `EMAIL_NETWORK_HOURLY` (20); a signed-in customer adding an email, `EMAIL_LINK_DAILY` (5) — each a 429
with the wait —; the shop's `EMAIL_SHOP_HOURLY` (60) and `EMAIL_SHOP_DAILY` (300), and the installation's — every shop
together — `EMAIL_INSTALLATION_HOURLY` (150) and `EMAIL_INSTALLATION_DAILY` (1000), past which no code goes out at all,
the host's mail quota spared for the shop's other mail: a 503 in the customer's words (`MAIL_OFF`), the log told once a
window; and an address whose emailed codes were failed takes no new one while that budget is spent (`emailedCode()`,
above). **The second step has a budget of its own**
(`secondStep(account, spent)`, `secondStepOpened()`): every code tried for one account — `/auth/login/2fa`'s, a password
proven again's — counted before it is judged (`RateLimiter::attempt()`, each window under its lock: codes sent at once never pass it),
`SECOND_STEP_HOURLY` (10) an hour and `SECOND_STEP_DAILY` (20) a day from every address together; spent, a 429 whatever
the code (the try not counted — `attempt()` gives it back), and the first refusal of a day tells the
customer (`CustomerNotifier::secondStepLocked()`: someone has their password); a code that opened gives its count back,
the budget being the wrong codes'. The windows run on the shop's clock (`now()`, which a
test moves). **`GET /`** (`Store\Services\Storefront`): the shop's name (`Bots\Services\Bots::name(bot)`: APP_NAME; an
agent's bot's title, else its @username — what its emails are signed with too: the naming is the Bots module's, so
Accounts and Store do not lean on each other's services), its bot `{username, url}`, support as a link
(`BotSettings::supportUrl()`: an @handle's t.me, a web address, a bare t.me one with https, a phone as tel:, else null —
made of what the admin typed: a website treats it as any link from data),
whether it takes orders now (`shop.taking_orders`: the bot's master switch — off, the website's checkout refuses every
order, see the checkout below), `sign_in` — `telegram {client_id, redirect}` while on with a Client ID, `google
{client_id}` while set, `email` (true while email sign-up is on and the shop's email goes out) —, `captcha {driver,
site_key, challenge_url}` (null while none is asked — see Captcha), the referral terms.
`Store\Api\MeController`: `GET /me` (`AccountPresenter`: id, names, `telegram {id, username}` — null for a customer who
signed up on the website —, `email`, `google` (a Google account signs them in), `has_password`, `two_factor` (the
password sign-in asks a second step), phone, balance, created_at; what a sign-in answers too — and, beside it,
`unread_notifications`, see Notifications, and `staff` — what the website's admin API lets them do: `{grants,
strong_sign_in, signed_in_strongly, signed_in_until, recent_until}` (`Store\Presenters\StaffPresenter::present()`), null for a customer
who is no admin of the shop and while the website lets none in; see The shop's admins on its website), `PATCH /me {first_name?,
last_name?}` (`AccountNames::rename()`: the fields sent, the rest as they are — an empty body changes nothing —, by the
names' one rule; an account with Telegram is refused under each field sent, `FROM_TELEGRAM`: its names are Telegram's,
which the bot reads afresh), `PUT /me/password {current_password?, password}` (`EmailSignIn::changePassword()`: an email
on the account — else 422 `AccountRefusedException::NEEDS_EMAIL` —, the current password required and right while the
account has one — `confirm()`, a wrong one a 422 on its field counted as a failed sign-in against the address and the
account —, the one password rule; every other session ends, `endOthers()`; the customer told), `GET /me/sessions`, `DELETE
/me/sessions/{id}`, `POST /auth/logout`. `PATCH /me` answers as `GET /me` does (`StoreAccountResponse`: the customer,
`unread_notifications` and `staff`). A refusal of the customer's own account is `Accounts\Exceptions\
AccountRefusedException` (422, its words a constant each). **Two-factor sign-in** (`Accounts\Services\TwoFactor`; RFC
6238 — `Core\Security\Totp`, the app's one TOTP: SHA-1, 30-second steps, six digits, `verify()` a code of the step now or
one either side of it and later than the last one taken, `secret()` 160 bits in base32, `uri()` the otpauth:// address;
the 3x-ui driver's login code is its `code()`): `users.totp_secret` (Encrypted), `totp_recovery_codes` (Encrypted JSON of
keyed hashes — `Encrypter::mac()`, as the emailed codes are), `totp_enabled_at` (on since; `User::hasTwoFactor()`),
`totp_last_step` (no code taken twice). `POST /me/2fa/setup` → `{secret, uri}` (an account with an email and a password
only, else 422 `TWO_FACTOR_NEEDS_PASSWORD`; on already, `TWO_FACTOR_ON`): the secret held as the customer's `TotpSetup`
challenge, `SETUP_SECONDS` (15 minutes), a new one replacing it (`AuthChallenges::hold()`); the URI's issuer the shop's
name (`Bots::name()`), its account the email. `POST /me/2fa/enable {code}` → `{recovery_codes}` — the app's first
code (Persian digits, spaces) tried on that challenge (`attempt()`, five tries; none or expired `SETUP_GONE`, wrong
`WRONG_CODE`, a 422 on `code`), then on: `RECOVERY_CODES` codes of `RECOVERY_LENGTH` (10) characters of
`Core\Security\ReadableCode` (the alphabet without look-alikes the host keys use), shown this once, their hashes kept,
and the step taken; the customer told (`twoFactorEnabled()`). `POST /me/2fa/disable {password}` → the customer (off, the account's password confirmed —
`EmailSignIn::confirm()`; not on, `TWO_FACTOR_OFF`; told, `twoFactorTurnedOff()`). A password sign-in of such an account (and a reset) answers a
`TwoFactor` challenge (`CHALLENGE_SECONDS`, 5 minutes, the account's, by its secret; a reset's keeps the new password's
hash, `NEW_PASSWORD`); `POST /auth/login/2fa {challenge,
code}` (`TwoFactor::signIn()`) → `{token, customer}`: the challenge found while live (`find()` — else 401 `SIGN_IN_SPENT`,
counted: expired, tried out, two-factor turned off since), the account's throttle checked, the code tried on it
(`tryCode()`: counted first against the second-step budget — `SignInThrottle::secondStep()`, see What signing in costs —,
then `attempt()`: six digits — the step taken by one conditional UPDATE on `totp_last_step`, so of two sign-ins with one code
one gets it —, or a recovery code as typed in any case with dashes and spaces — spent by one conditional UPDATE on the
encrypted list as it was read); wrong is a 422 on `code` (`WRONG_SIGN_IN_CODE`) counted against the address and the
account, a code of neither shape no try off the challenge nor the budget; banned 403; a reset's new password set now,
every session of the account ended and the customer told. Both writes are quiet (no panel shows them).
`TwoFactor::confirm()` is the same code asked of a signed-in customer proving their password again (Reauthentication).
Google's and Telegram's sign-ins are not asked: the provider signed them in. Support turns it off for a customer who lost
the phone it was on — `POST /api/{panel}/users/{id}/two-factor/disable` (both panels — and the website's admins with
the `account_security` grant, never on an admin's or an agent's account —, the shop's own customer; 422 when it is off) → `{account}`:
`UserActions::disableTwoFactor()` → `TwoFactor::turnOff(user, actor)`, logged with the actor, the customer told
(`CustomerNotifier::twoFactorDisabled()`, `BotText::TwoFactorDisabled` of the bot texts' «حساب وب‌سایت» group — in
Telegram and by email both, kept for the website either way). **Every change of how an account is signed in to is
told** (`CustomerNotifier::tellAccount()`, plain messages of the «حساب وب‌سایت» group, `Notices::deliver(…, everyDoor:
true)`: their Telegram chat **and** their email both, beside the feed — whoever made the change may hold one door):
`wayInAdded(user, way)` / `wayInRemoved()` (`BotText::WayInAdded`/`WayInRemoved`, `%way%` the kind's word, `WayIn::label()`),
`passwordChanged()` (set, changed, reset, or set by a merge), `twoFactorEnabled()` / `twoFactorTurnedOff()` / support's
`twoFactorDisabled()`, `accountMerged()`, `secondStepLocked()`, `emailCodesFailed()` — `NoticeType` `way_in_added`,
`way_in_removed`, `password_changed`, `two_factor_enabled`, `two_factor_disabled`, `account_merged`, `second_step_locked`,
`email_codes_failed`. **Ways in** (`Accounts\Services\Identities`, `Store\Api\IdentitiesController`; their kinds the one list
`Accounts\Enums\WayIn` — telegram, google, email: `column()`, `label()`, `columns()` —, what Identities, AccountMerger and
MergeOffers read), each proven the way it signs in before anything is said of it, a proof that does not hold a 422 on its
field: `POST /me/identities/telegram` (`{id_token, nonce}` or `{code, state, code_verifier}` — a state of `POST /me/telegram/authorize`:
`TelegramSignIn::account(…, holder)`) and `POST /me/identities/google {id_token, nonce}` (`GoogleSignIn::account(…, holder)`) — the
account's own already: 200, nothing done; the account has another of the kind: 422 `OTHER_TELEGRAM`/`OTHER_GOOGLE`;
nobody's: the account's — a Telegram account with its profile (handle and names, which are Telegram's from then on) and
`bot_blocked` false, a Google account with the address Google speaks for when the account has none and nobody else has
it —, the customer told; another account's of the shop (Google's: by the Google account, else by the address Google speaks for): a merge
offered (202). `POST /me/identities/email {email, password}` → 202 `{expires_in}` (`EmailSignIn::sendLinkCode()`: an
account with an email is refused, `HAS_EMAIL`; the one password rule; a `LinkEmail` code to the address — the account its
holder, the password's hash in its payload, `Accounts\Mail\LinkEmailCode` —, sent whether or not another account has the
address: it is proven first; the code throttles as every code does; another customer's code for the address stands) and `POST /me/identities/email/verify {email, code}`
(`proveLink()`: the code checked as every code is, and only one sent for this account — `checkCode(…, holder)`) → the
address and the password the account's (200), or, another account's, a merge offered carrying both — never to an account
reached by its address alone (this emailed code, or a Google account's address Google speaks for) with two-factor
sign-in on, by decision (`TWO_FACTOR_ACCOUNT`: the address is all a password reset asks, and a reset stops at the second
step; signed in to that account, the customer adds this one's way in from there), judged again as the ticket is taken
(`MergeOffers`' `byAddress`). A slot is filled by
one conditional UPDATE while it is empty; the way in taken by another account in the same moment (a unique index) is
looked at again — a merge offered. `DELETE /me/identities/{telegram|google|email}` → the customer: never the last way in
(`LAST_WAY_IN`; one it does not have `NOT_LINKED`) — one conditional UPDATE that holds another way in, so two at once never
leave none —; an email goes with its password and two-factor sign-in (`TwoFactor::COLUMNS`) and the address is mailed
that it is no way in any more (`Accounts\Mail\EmailRemoved` — the account's notices no longer reach it), a Telegram account with its
handle (the bot knows that Telegram account as a newcomer from its next message: it registers it afresh); the customer
told. **A merge
offered** (`Accounts\Services\MergeOffers::offer(user, other, holds, apply)` → `DTO\MergeOffer`, `present()`): the merge's
refusal said first, before any ticket (`AccountMerger::refusal()`); a `Merge` challenge of the asking account's,
`TICKET_SECONDS` (15 minutes), keeping the other account, the way in it holds that the customer proved theirs (`holds`)
and what the account that stays takes once merged (`apply`: the Google account or the address being added, the password
chosen with an email); the answer `{merge: {token, expires_in, account: {name, created_at, services (running), orders,
balance, telegram, email, google}, keeps: this|other}}`. `POST /me/merge {token}` (`accept(user, session, token)`): the ticket spent —
this account's alone (another's is left as it is), once —, the other account still holding that way in, else 422
`OFFER_GONE`; then `AccountMerger::merge(…, BY_CUSTOMER)` and what the offer carried given to the account that stays (an
empty slot filled while nobody else has it; the password while its address is that account's — set, it ends every other
session of that account, `endOthers()`, as any new password does) → the account that stays,
which this session now signs in (a merged account's sessions are the survivor's), the customer told (`accountMerged()`
— and, a password set by it, `passwordChanged()` too, as every new password is). **Merging** (`Accounts\Services\
AccountMerger::merge(a, b, actor)`): the older account stays (`older()`: `created_at`, then the number), whichever
asked; refused before anything changes (`refusal()`, 422): the same account, either banned, a different way in of one
kind on each — «… (تلگرام، ایمیل)؛ اول یکی را جدا کنید.», naming the kinds —, two agents (a level or a bot of their own
each). One transaction, both rows locked in id order and judged again as they are then; every column that points at a
customer is carried as `REFERENCES` says — `move` (orders, and so their payments; services; agency requests; the
broadcasts it sent as a bot admin; sessions — its devices stay signed in; the merges it took in before; the notices the
shop told it; its support tickets, with their conversations; its reviews of the shop; its bot, `bots.user_id`; the
referral commissions credited to it, `referral_commissions.referrer_id`), `union` by a key (customer groups, a broadcast's pins, the website's request
keys — `request_keys`, by `key`: a row the survivor has for the key goes, the rest
move), `drop` (its challenges), `ledger` (`WalletService::takeOver()` → `Core\Database\Ledger::merge()`: both owners'
rows locked, its lines the survivor's, every `balance_after` counted again line by line in id order — one ledger, its
last line the two balances together), `referrer` (the customers it brought, never the survivor itself) —; its ways in and
profile fill the survivor's empty slots, each with what belongs to it (a Telegram account with its handle and
`bot_blocked`; an email with its password and two-factor columns; a Google account; the names together; the phone; the
invite code; who brought it — never the survivor, nor the account that is gone —; an agency level with its credit; the
bot's admin role; the later visit), taken off it first so no unique index sees one twice, and its row deleted; recorded in
`account_merges` (`Accounts\Models\AccountMerge`: the survivor, `merged_user_id`, `merged` — its Telegram id and handle,
email, whether Google signed it in, its names, since when —, `moved` — `REFERENCES`' counts by name —, `actor` —
`BY_CUSTOMER`: the customer, from their website, the one way two accounts are made one) and logged. A table that adds a column pointing at `users` adds it to `REFERENCES`:
tests/Unit/Accounts/AccountMergerReferencesTest reads database/schema.php's foreign keys (and `bots.user_id`) and fails
while one is missing. **On the customer's page** (both panels): `GET /users/{id}`'s `account` (`CustomerProfile::account()`:
`{email, google, has_password, two_factor, sessions — CustomerSessions::count() —, merges: [{merged_user_id, merged:
{telegram_id, username, email, google, name}, created_at}] newest first}` — every merge the customer's own doing); `POST /users/{id}/sessions/end`
→ 204 (`UserActions::endSessions()` → `CustomerSessions::signOutEverywhere()`, logged with the actor; the website's
admins' too with `account_security`, never an admin's nor an agent's); the card «ورود به وب‌سایت»
(`components/customer/website-account-card`, `WebsiteAccountCard`, the side column): Telegram, Google, the email with or
without a password, two-factor sign-in with «خاموش کردن ورود دو مرحله‌ای» and the devices with «خروج از همه دستگاه‌ها»
(each behind a `ConfirmModal`, its refusal said there; the answered account — or no device left — put into the page's read,
`setQueryData`, nothing read again; the second look at two-factor sign-in says the customer is told — in Telegram and by
email, whichever they have — and their website keeps the notice), and the accounts merged into theirs (`UserIdentity`, the
former number, «به درخواست خود مشتری», the day).
**What the shop sells, and the customer's own (slice 3A)** — the bot's own services and rules, never a second copy: where
the bot's handler held a rule the website needs, it moved into the service both call (`ProvisioningService::look()`,
`rotates()`/`rotatable()` and `rotateLink()`'s refusal, `AutoRenewal::set()`'s — see Plans and Automatic renewal), and a
rule judged per row has a bulk form for a list (`AutoRenewal::offeredForAll()`, `CustomerRenewal::plansFor()`,
`ServerSelector::coverage()`/`offers()`). Presenters are `Store\Presenters\*` — rows in, arrays out, loading the caller's,
for the next slice's checkout answers to reuse: `PlanPresenter` (and `category()`), `ServerPresenter::status()`,
`SubscriptionPresenter` (what the customer may do handed in as flags), `OrderPresenter` (`payment()`, `method()`; a
method's `kind` its driver's, `GatewayRegistry::kind()`), `WalletPresenter` (`balance()`, `transaction()`). **Public**
(`Store\Api\CatalogController` → `Store\Services\StoreCatalog`): `GET /plans` → `{groups: [{category: {id, name} | null,
plans}]}` — exactly the bot's «خرید اشتراک», `PlanCategoryService::catalogue()`: every active category in order, an empty
one too, then the plans of none or of one switched off as the group without a category; a plan `{id, name, description,
price, traffic_gb (0 unlimited), duration_days (0 never), devices (ip_limit), category_id (its group's: null for the
rest), locations: [{id, name}]}` (its choices, in the plan's order), in the same few queries however many plans and
servers; `GET /plans/{id}` → `{plan}`, found in that catalogue — switched off, on no server that can sell, an agent's
traffic short of it: a 404 (`ErrorHandler::NOT_FOUND`); `GET /status` → `{servers: [{id, name, available, checked_at}]}`
— the servers of the shop's plans switched on, once each, in the servers' order, `available` = `ServerReadiness` has
nothing against it (a panel whose last contact failed is still available, as the bot still sells on it), their room counted
with them; never an address, a connector or a panel's words; and the shop's reviews, while the website shows them —
`GET /reviews` the approved ones, `POST /reviews` a signed-in customer's theirs, a guest's behind the website's captcha
alone (see Reviews). **Signed in** (the `CustomerAuthMiddleware` group; the
customer's own by the query, `where('user_id', …)` — another's a 404 as one that never was): `GET /subscriptions?status=
&page=` (`Store\Api\SubscriptionsController` → `Store\Services\CustomerSubscriptions`: newest first, a `SubscriptionStatus`
or all, `Page::fetchTogether()` so a page is judged at once) and `GET /subscriptions/{id}` → `{id, name, status, plan,
server, link (null once deleted), traffic {limit_bytes, used_bytes, remaining_bytes — null unlimited}, term {duration_days,
starts_at, expires_at, awaits_first_use}, auto_renew {on, offered, days_before (`RenewalSettings::autoRenewDays()`, the
window AutoRenewal renews in)}, renewable (`plansFor()`), link_rotation
(`rotatable()`), next_period {ends_at, bytes} | null (`period_ends_at` + `next_period_bytes`), presence, synced_at,
created_at}` — the shop's copy, `presence` null but on a refresh; `POST /subscriptions/{id}/refresh` → `look()`: the
panel's numbers and `presence {online, last_online_at}` (null for a client gone: the row comes back `deleted`), a panel
out of reach or backing off the 502 `ServiceNotReadException` and the row untouched; `PATCH /subscriptions/{id}
{auto_renew}` — a strict switch (`ApiController::switch()`), then `AutoRenewal::set()` (422 on `auto_renew` while not
offered); `POST /subscriptions/{id}/rotate-link` → `rotateLink()`: a 422 on `status` (`ROTATE_INACTIVE`,
`ROTATE_UNSUPPORTED`), a 409 busy, a panel's failure the 502 `PanelFailedException`
`CustomerSubscriptions::NOT_ROTATED` + `ProviderErrorPresenter::summary()` (never the owner's diagnosis), logged — and
the bot says nothing of any of it. What asks a panel is held per customer through `Core\Security\RateLimiter` — every
request on their own service, a refused one too: `REFRESHES` (10) reads a minute, `ROTATIONS` (5) new links an hour,
then a 429 with `Retry-After` (`TooManyAttemptsException::wait()`). `GET /orders?status=&type=&page=`, `GET /orders/{id}` (`Store\Services\CustomerOrders`,
`RELATIONS` with the payments chaperoned): `{id, type, status, amount, created_at, fulfilled_at, plan, server (a
renewal's: its service's), subscription {id, name} | null (its service by its name on the panel — the one way the Store
API names a service, a ticket's too; never a bare id), payments: [{id, method {id, label, kind}, status, amount, created_at,
paid_at, receipt {sent_at} | null (`receipt_at`), note}]}` — a payment's `note` (a rejection's reason…) is the customer's
to read, the order's `notes` (support's diagnosis) never shown. `GET /wallet` (`Store\Services\CustomerWallet`: the
customer read again with `User::balanceColumn()`) → `{balance, credit, spendable, top_up {min, max, presets}}` (Toman strings;
`WalletSettings::topUpMin()`/`TOPUP_MAX`/`topUpPresets()`, what `OrderService::topUpAllowed()` and the bot's «افزایش موجودی»
go by — `POST /wallet/top-up` takes them as written, "50000.00": `Input::amountOf()` reads a fraction of zero),
`GET /wallet/transactions?page=` newest first; `GET /referral` (`Store\Api\ReferralController`) → `{enabled, rate,
first_only, code (`ReferralService::codeFor()` — made on the first ask, the program on or not), bot_link (`linkFor()`, null
without the bot's @username), invited, earned (`statsFor()`: the commissions credited to them, `referrer_id`)}`.
**The checkout (slice 3B)** — the shop's one (`Payments\Services\Checkout`, see Payment methods), under the bot's rules,
signed in (`Store\Api\CheckoutController` → `Store\Services\CustomerCheckout` and `CustomerReceipts`): `GET
/payment-methods?for=purchase|renewal|wallet_topup` → `{methods: [{id, label, kind}]}` — `PaymentMethods::forOrder()`
(the wallet never for a top-up), `OrderPresenter::method()`; `for` required, anything else a 422 on it. Every ordering
request carries `Idempotency-Key` (`Store\Http\IdempotencyKey::of()`: 1–64 of `[A-Za-z0-9_-]`, the description's
required header parameter — none or malformed a 422 on `idempotency_key`, `MISSING`/`MALFORMED`) and is answered by the
order its key came to, as it stands, before the rules are asked again (`Checkout::replayed()` with an `OrderKey` of the
key and the `method_id` sent — another than the key's first a 422 on `idempotency_key`; the request's own models are
found as named, any state — the plan, the server, the service's plan, the amount); a customer sends `CHECKOUTS` (30) of
them — purchases, renewals and top-ups together, one made again among them — in `CHECKOUT_WINDOW` (10 minutes), then a
429 with the wait (`CustomerCheckout::throttle()`, the throttle's `RateLimiter`), and is left `OrderService::UNPAID_MAX`
(5) unpaid orders at most (see Payment methods' checkout); while the shop's bot is switched off (`bot.enabled`, its
master switch) every ordering request — a purchase, a renewal, a top-up, a receipt uploaded, one made again with its
key too — is a 503 (`CustomerCheckout::takingOrders()`, `CheckoutRefusedException::PAUSED`), what `GET /` says as
`shop.taking_orders` (reading what it sells and signing in stay open; the bot's phone rule is the bot's alone — a
website customer cannot verify a phone); then: `POST /orders {plan_id,
server_id, method_id}` — the plan as the bot sells it (`is_active`, `ServerSelector::covers()` and a location that can
deliver it now — `choices()` not empty —, else 422 on `plan_id` `NOT_ON_SALE`; `server_id` required, then `resolve()`'s
own 422 on it: another of its locations sells, not that one), the way to pay `PaymentMethods::payable()` (422 on
`method_id`: none, one that may not pay it, the wallet for a top-up in words of its own) → `Checkout::pay()` with
`openPurchase()`; `GET /subscriptions/{id}/renewal` → `{renewal: {plan {id, name, price, traffic_gb, duration_days},
price, after {term, traffic, next_period}}}` (`CustomerCheckout::renewal()`: `CustomerRenewal::preview()` — `after` in
the very shapes a service has, `SubscriptionPresenter::term()`/`traffic()`/`nextPeriod()` — or 422 on `status`,
`NOT_RENEWABLE`, and `RenewalUnderWayException`'s while one is under way); `POST /subscriptions/{id}/renewal {method_id}`
→ the same, paid (`openRenewal()`); `POST /wallet/top-up {amount, method_id}` — `Input::amountOf()` (a typed amount, or
the API's own "50000.00") held to `topUpAllowed()` (422 on `amount`, `OrderService::topUpBounds()`) → `openTopUp()`. Answered `{checkout: {outcome:
settled|transfer|processing, order (OrderPresenter, the order read again with its relations — its `notes` never),
transfer: {payment_id, amount, card, holder, instructions (null for none)} | null, subscription (the purchase's or the
renewal's service once delivered — `fulfilled_at`, `CustomerSubscriptions::present()`) | null}}`
(`CustomerCheckout::answer()`); one that paid nothing is `Store\Exceptions\CheckoutRefusedException`: short — 422 on
`method_id`, «موجودی کیف پول کافی نیست؛ … کم است.» —, closed — 409, «… همین حالا …» —, cancelled — 409 of its own words,
`CANCELLED`: a request made again whose order was cancelled since, maybe days ago —, the wallet's refusal as it charged —
422 on `method_id`, its note. A delivery that failed is `settled` with the order `failed` — and so is a request made again whose
order was paid since and done with (by a card's approval too: delivered, failed or refunded). The website's wallet payment tells
the customer nothing in the bot (the site shows it); a card's approval does as ever (`paymentSettled()`, no receipt
message to reply to; nothing without Telegram). **Receipts uploaded from the site**: `POST /payments/{id}/receipt`,
multipart `file` + `note` → `{order}` (`CustomerReceipts::upload()`): the customer's own payment (else 404), while it
awaits its receipt (`PaymentService::awaitsReceipt()`, the bot's rule — else 422 on `status` in
`PaymentActions::refusal($payment, 'receipt')`'s words, the state support reads it in); the note
(`Input::note()`, `PaymentService::RECEIPT_NOTE_MAX`) and the picture (`Receipts::judge()` → the shop's one picture rule,
`CustomerPictures::judge()`: JPEG, PNG or WebP by its bytes, `CustomerPictures::MAX_BYTES` — the bot's limit, one
constant —, read no further; none while the host's disk has no room, a 503; PHP's own refusal in
`Validation::uploadFailure()`'s words) refused at once, before anything is kept; then the customer's one budget of
pictures, their tickets' counted in it too (`CustomerPictures::budget()`: `UPLOADS` (10) in `UPLOAD_WINDOW` (an hour)),
counted as one is kept — past it a 429 «تصویر زیادی فرستاده‌اید» with the wait, nothing kept —; kept as the shop keeps a
picture (`Receipts::keep()` → `CustomerPictures::keep(PictureFolder::Receipts, …)`, GD's writing of it — see Production:
uploads —: `{bot}-{payment}-{16 hex}.{ext}` under the container's `receipts.path`, storage/uploads/receipts; the device's
name only as `receipt_name`), then `PaymentService::submitUpload()` — the bot's compare-and-swap and report, but never the
method's review window: an upload always waits for support (`AutoApproveReceiptsTask` takes receipts sent in the bot
alone); one
that lost it (an album's other picture, a decision) is discarded (`Receipts::discard()` → `CustomerPictures::discard()`,
the one place an upload is deleted, after what made it unneeded: a lost race, an expired order) and refused in the
state's words — and one whose save failed (its report the database turned down, which took the receipt back with it)
is discarded the same way, no receipt either.
**Notifications (slice 4, app/Modules/Notifications)** — every notice the shop sends a customer reaches them on every
door they have, in one wording, the admin's bot text. `Notifications\Services\CustomerNotifier` composes each in its
recipient's shop (`tell(about, NoticeType, compose)`: `compose` answers the text — Telegram HTML as BotTexts renders
it, the caption when it goes as a QR card (`ServiceCard::send()`/`sendSettled()` take the words made already) — and how
Telegram is sent it, given the chat — the reply to the receipt, the keyboard, the card), then hands it to
`Notifications\Services\Notices::deliver(customer, type, text, subject, telegram)`: the notice kept first, whatever
comes of the rest (`notifications`, `Models\Notification` BelongsToBot — `type` a `Enums\NoticeType`, one per kind of
notice the notifier sends, `subject()` its email's subject line; `text`; `subject_type` + `subject_id`, `Enums\
NoticeSubject` order|subscription|ticket: what the website links it to — a payment's notice its order, an order's that
order, a service's that service, a ticket's that ticket, null for their account, a service deleted (`serviceDeleted()` tells the account), and someone
else's row — a referral's commission, an agent's customer's order —; `read_at`), then: Telegram through
`CustomerChats::send()` as ever (every Telegram call and its options unchanged) for a customer with a Telegram account;
without one — or with one that turned the bot away (`Delivery::TurnedAway`: `bot_blocked` known, or a 403 learned as it
was sent) —, with an email and the shop's email ready (`Mailer::ready()`) → `Mail\NoticeMail` (subject and heading the
type's line, the body `WebText::html()` in `MailBody::formatted()`, `WebText::plain()` beside it, from the shop's name,
`Bots::name()`) → `Delivery::Emailed`, a mail server that did not take it `Delivery::Unreachable` (the Mailer
logged why); neither → `NoTelegram`. A notice of how the account is signed in to (`everyDoor`) goes to the email too
beside a Telegram chat (see Ways in). `Telegram\Texts\WebText` (beside TelegramHtml) is the one reading of Telegram HTML
for the web: `plain()` (tags dropped, entities read, a premium emoji its plain emoji) and `html()` — b/strong, i/em,
u/ins, s/strike/del, code, pre, blockquote kept, a spoiler `<span class="tg-spoiler">`, `<a href>` only to an http(s)
address (a bare t.me one made https; not inside another link), `<br>` for a line break (not inside a pre), every other
tag its text, every attribute but href gone, every text escaped, every element closed in order — written by its own
tokenizer, never the source passed through (tests/Unit/Telegram/WebTextTest: scripts, handlers, javascript:/data:
links, crossed and unclosed tags). **The feed** (`Store\Api\NotificationsController` → `Store\Services\
CustomerNotifications`, `Store\Presenters\NotificationPresenter`): `GET /notifications?unread=&page=` → `{notifications:
[{id, type, text (plain — a website escapes it as it shows it), html (safe as it is), subject: {type, id} | null, read,
created_at}], meta: {…the page's, unread}}` newest first (`unread=true` — `Input::truthy()` — only those not read); `POST /notifications/read {ids?}` → `{unread}`:
one UPDATE of the customer's unread ones — those numbered (another's number marks nothing, no error; `READ_MAX` 100,
anything but a list of numbers a 422 on `ids`, a null too), every one without `ids` —, a notice read before keeping
when; `GET /me` answers `unread_notifications` beside the customer (`StoreAccountResponse`; the other answers with a
customer stay `StoreMeResponse`) — `CustomerNotifications::unread()`, counted off the `(user_id, read_at)` index alone,
the shop's scope left aside (the customer's number names their shop). Kept `Notices::KEEP_DAYS` (180): the hourly
`PruneAccountsTask` forgets older ones, every shop's (`notifications.created_at`'s index); a merge moves them
(`AccountMerger::REFERENCES`). Not a change-feed area (no panel shows them). Tests: `NoticesTest` (every kind kept with
its type, its subject and the very words Telegram got — a QR card's caption —, an agent's customer's in the agent's shop,
the email — subject, safe HTML, plain text, from the shop —, the reminder's `emailed`, neither door open, a Telegram
customer gets no email but one who turned the bot away does, a mail server's refusal, the housekeeping), `Store/StoreNotificationsTest` (the feed, its forms
and subjects, unread in the feed and `/me`, read marks their own alone, `ids` refused, an admin's wording with a link
and a premium emoji, NoticeType = the description's `StoreNoticeType`), `AccountMergerTest`, `ListQueriesTest`.
**The description**: the store paths carry the path-level `Store` parameter (its pattern) and the signed-in ones the `customer`
bearer scheme (`security` — its description says what ends a session) and its 401 (`components/responses/SignedOut`, its
`WWW-Authenticate: Bearer` required; a 401 there is never a refusal of what was asked), which league holds every test's request to — one without a token goes `unchecked()`;
ApiDescriptionTest checks both: every Store path but the shop, what it sells, its reviews and the ways in (its
`STORE_PUBLIC`, as RouteGuardsTest's) is the customer's, and what anyone does, a customer as themselves (`STORE_EITHER`:
`POST /reviews`), takes no token or theirs (`security: [{}, {customer: []}]`); a list's `status`/`type` is the enum (`SubscriptionStatus`, `OrderStatus`,
`OrderType`) — anything else is no filter, sent `unchecked()`; an ordering request's `IdempotencyKey` header parameter
(required, its pattern) — one sent without it, or malformed, goes `unchecked()`. **Tests** (tests/Feature/Store, tests/Unit/Accounts, tests/Unit/Http/OriginTest): `Fixtures::website()` (on,
https://shop.example + http://localhost:3000, Telegram sign-in under `WEBSITE_CLIENT_ID`, no secret; a test's overrides
switch on email sign-up, Google under `GOOGLE_CLIENT_ID`, the captcha — `captcha_driver`, `captcha_config`), `webCustomer()` (a customer of the website
alone: `WEB_EMAIL` and `WEB_PASSWORD`, no Telegram account), `customerSession(user, overrides)` → its token (opened in
the customer's own shop as a sign-in opens it, with a password — `method` `password` —, signed in now, so recent for a
quarter of an hour: `['authenticated_at' => …]` or a moved clock makes it a stale one, `['method' => …]` another way in),
`twoFactorOn(website, user)` (two-factor sign-in turned on as the website does — setup, then the app's first code — →
`{secret, recovery_codes}`: `Totp::code(secret)` makes the app's codes; the code of that step is taken, so a sign-in
moves the clock 30 s); `HttpTestCase::bearer(token)` (the requests that follow carry it), `storeApi(website, path)`; `TestCase::telegramLogin()`
→ `Tests\Support\FakeTelegramLogin` and `googleLogin()` → `FakeGoogleLogin` on the outgoing transport, each publishing
`Tests\Support\SigningKey` as its JWK set (an RSA key made once a process — an empty openssl.cnf where a Windows PHP has
none) and signing with it: `claims()`/`idToken()`, `down()`, `calls()`, Telegram's `answerCode()`/`refuseCode()` too;
`turnstile()` → `FakeTurnstile` and `AltchaWidget` (see Captcha); `mail()` (see Mail) and
`RecordingMailTransport::codeIn()` to read a code back. A preflight is no operation of the description: a test sends it
straight to the app. Slice 2B's: `TwoFactorTest`, `AccountSettingsTest`, `IdentitiesTest` (tests/Feature/Store),
tests/Feature/`AccountMergerTest` and `CustomerWebsiteAccountApiTest`, tests/Unit/Accounts/`AccountMergerReferencesTest`;
the review's (tests/Feature/Store): `RecentSignInTest` (a token alone changes no way in; a sign-in recent a quarter of an
hour; each way of proving one again, another account's refused on its field, throttled), `AccountNoticesTest` (every
change told on every door, the address taken off mailed, a merge's new password told as one), `SecondStepBudgetTest`
(ten an hour, twenty a day, counted before judged — tries in flight —, told once, a right code's room given back, a
password proven again's code counted), RouteGuardsTest's `STORE_CREDENTIALS` (the routes that ask a recent sign-in,
walked; none else does), tests/Unit/Core/`TotpTest`, `LedgerTest`'s merge; the card's `website-account-card.test.tsx`;
the second round's: tests/Unit/Core/`RateLimiterTest` (six processes racing one window: exactly its room passes; a
later window's refusal gives back what earlier ones counted), `EmailSignUpTest` (a sign-up that comes while the last
one's email still goes sends none), `IdentitiesTest` (a redirect's state its very session's), tests/Feature/Security/
`FormBodyTest` (a form's text that is not UTF-8, a POST PHP emptied for its size), tests/Unit/Telegram/`LimitsTest`
(UTF-16 units, a cut never half an emoji), `MailSettingsApiTest` (the encryption moved asks the password again); the
security round's (tests/Feature/Store): `EmailBudgetsTest` (a network's hour, an address's day from every shop, the «no
account» email once a day, a customer adding emails, the shop's and the installation's budgets — a 503, the log told
once —, what an email that did not go gives back), `EmailedCodesBudgetTest` (ten wrong an hour, twenty a day, the
address waiting and its account told once, a right code's room given back, an address without an account held all the
same), `TelegramRedirectSignInTest` (the challenge the site's to make; a code and state brought back to another browser,
or without the verifier, open nothing), `StoreBodiesTest` (JSON alone, a form only where a picture is taken),
`IssuanceThrottleTest` (a shop's live sign-ins begun), `CaptchaTest` and `WebsiteCaptchaTest` (see Captcha),
`StoreCheckoutTest`'s bot switched off. Slice 3A's
(tests/Feature/Store): `StoreCatalogTest` (the bot's own groups compared, locations, the 404s, an agent's traffic, the
status and what it never says), `StoreSubscriptionsTest` (`FakeProvider::put()`/`mirror()`, `$onCall` to see the panel not
asked while backing off or past the customer's reads, `Lease::take()` for busy, the renewal window `days_before`, the
reads' and the new links' 429s), `StoreOrdersTest`, `StoreWalletTest` (the top-up's bounds taken back as written),
`StoreReferralTest`; and
`ListQueriesTest` reads the store's every list and one-row read for the first customer, whose own rows grow with the rest.
Slice 3B's (tests/Feature/Store): `StoreCheckoutTest` (the ways to pay, a wallet purchase with its service, a card's
transfer, short, the plan — on no location that can deliver it: `plan_id` —, the server and the way to pay refused, an
agent's traffic and credit, a top-up's bounds, five unpaid orders at most, thirty ordering requests in ten minutes),
`StoreCheckoutIdempotencyTest` (the same key answered by its order whatever changed since — a price too, its own order at
its own amount —; a key that paid another key's open order answered by it made again, never charged twice; an open order
the bot made is the key's; another thing refused, a top-up of another amount too, and its key sent with another way to
pay; one whose order was cancelled since worded as such; none or malformed refused; two at once
— the twin served at the first's third look for its key, so its key meets the other's row, and at its order's commit, so
the payment's compare-and-swap decides —; two accounts merged; a key forgotten a week on), `StoreRenewalTest`
(the preview is what the payment makes, refusals, a renewal under way and one made again), `StoreReceiptsTest` (taken,
reported as a photo, approved on the panel, served by it, the review window leaving it to support, ten an hour, the
bytes and the limit, PHP's refusals, the state's words, an album's second picture, an expired order's file, the photo
refused or gone, a customer without Telegram); the request with its key is `send('POST', …, body, ['Idempotency-Key' => …])`, a receipt
`HttpTestCase::upload(path, 'file', name, bytes, fields)` — the form's other fields, and the bearer token when the
requests carry one —, the uploads in the run's own folder (`TestCase::FILES`' `receipts.path`, `tickets.path`).

## The shop's admins on its website (Store\Http\StaffMiddleware)

A shop's admins — its customers whose role is admin (`users.role`, the bot's admins, given by a panel: `PUT
/users/{id}/role`, see Users) — work the shop's daily work from its own website, signed in there with their own website
accounts, by decision: no third panel and no login of their own, and a website the shop's developer builds draws the
screens. **The admin API** is `/api/store/v1/{store}/admin/*`: routes/api.php's `$operations` — the panels' own
operations of the shop's daily work — mounted a third time, beside both panels, behind the Store API's door, then
`CustomerAuthMiddleware`, then `Store\Http\StaffMiddleware`; `$configuration` — the payment methods, the bot's settings,
texts, keyboards, channels, custom emoji and QR background, the report group, the website, who the admins are (`PUT
/users/{id}/role`), the panel's error reports — is the panels' alone, and so are the owner's sections (servers, grants,
mass gifts, the agency, config.php, the system) and an agent's account: none is there, a 404. Every request is worked in
the website's shop (the store key names it) and answered in the panels' own shapes — the same presenters, the same
operation of the API description (see API contract: `x-staff`).
**The door** (`StaffMiddleware::process()`), each refusal a 403 in the error shape, asked in this order once
`CustomerAuthMiddleware` found a session of this shop's (else its 401) and its customer not banned:
1. the customer is an admin of the shop (`User::isAdmin()`) — anyone else is told only that, `NOT_STAFF` «این بخش فقط
   برای پشتیبانی فروشگاه است.» (a customer's words: never «مدیر»), whatever the website says;
2. the website lets its admins in (`staff_enabled`, off by default — `STAFF_OFF`);
3. while it asks them a strong sign-in (`staff_strong_sign_in`, on by default), the session is a strong one
   (`CustomerSessions::isStrong()`: signed in with Telegram, Google, or a password and its second step — `SignInMethod`,
   see Store API's sessions) — else `SIGN_IN_STRONGLY`, its `WWW-Authenticate` `STRONG_CHALLENGE` (RFC 9470's
   `insufficient_user_authentication` with `acr_values="telegram google password_2fa"`): proving a strong way on the
   session (`POST /me/reauthenticate`) makes it one, a password alone proven again never does;
4. a way in proven on the session within `SIGN_IN_HOURS` (12 — a working day: an admin's token that leaked works for
   hours, not for the months a customer's session lasts; `signedInUntil()` over `CustomerSessions::provenUntil()`, which
   `isRecent()` reads too) — else `RecentSignInMiddleware::SIGN_IN_AGAIN` with its `CHALLENGE`, whatever the operation;
5. an operation whose route names a grant (`StaffGrant::ARGUMENT`) must be granted (`Website::staffGrants()`) —
   `NOT_GRANTED`;
6. and such an operation asks a recent sign-in too (`CustomerSessions::isRecent()`, the customers' quarter of an hour) —
   `SIGN_IN_AGAIN` with its `CHALLENGE` —, as does one whose route asks it without a grant (`RECENT_SIGN_IN`, its
   description's `x-staff-recent: true`: approving a payment, which delivers a service by the admin's word alone).
Then the request carries a staff principal (`Auth\Principal`, `PrincipalKind::Staff`: its name `Reviewers::forAdmin()`'s,
the website's shop, the admin's account), read by them (`CurrentPrincipal::run`), and every change it asks — anything but
a GET or a HEAD — is logged once answered, refused or done, with its status («A shop admin on the website asked {method}
{path}: {status}», the line's `actor` naming them; a failure of the server's is the error handler's line, named too).
**Grants** (`Store\Enums\StaffGrant`, the website's `staff_grants` — a switch each on «مدیران سایت», none until the
shop's owner turns it on; `StaffGrant::among()` reads a kept or sent list): `catalog` (the plans and their categories:
made, edited, reordered, switched, duplicated, deleted), `wallet` (a customer's wallet credited or debited by hand),
`refunds` (a payment given back), `extend` (days and traffic given to a service), `delete` (a service deleted for good),
`account_security` (a customer's two-factor sign-in turned off, every device of theirs signed out). An operation names
its grant on its route (`->setArgument(StaffGrant::ARGUMENT, …)`, seventeen of them) and in the description (`x-staff:
<grant>`); the panels' principals hold every one — the panels ask none. The rest is the daily work, granted to every
admin let in: every read (the dashboard, the queues, the change feed, the lists and their rows, a receipt's picture),
receipts approved, rejected, cancelled, reminded and retried, orders retried or cancelled, tickets answered, closed and
opened again, reviews approved, rejected or deleted, a service read again, switched off or on, moved, customer groups
kept and assigned, a customer banned or let back in, broadcasts paused, resumed, cancelled or unpinned.
**Who decided** is an `Auth\Actor` (`ActorKind`: owner, agent, staff, group_admin, system), the second argument of every
operation that records a decision — `PaymentActions` (approve, reject, cancel, refund), `OrderActions::cancel()`,
`SubscriptionActions`, `UserActions`, `Tickets::answer()`/`close()`/`reopen()`, `Reviews::approve()`/`reject()`/
`delete()`, `BroadcastDirectory::unpin()`,
`AgencyActions` (approve, reject, `adjustTraffic()`), `Grants::start()`, `TrafficPool::adjust()`/`extending()`,
`TwoFactor::turnOff()`, `CustomerSessions::signOutEverywhere()`: `Actor::of(principal)` (`Principal::actor()` — the
panels' and the website's admins'), `Actor::groupAdmin(user)` (the report group's buttons and a ticket's answer there),
`Actor::system()` (a timer: the review window's approval). Its `reviewer` is the name the decision keeps (`name()` where
only a person decides — the shop itself throws), its `userId` the acting customer's own account (an admin's — the
website's or the report group's; null for the panels and the shop itself), `isStaff()` the website's admins, `is(customer)`
a decision about themselves, `isOwner()` the owner (the reader a panel's failure is diagnosed for), `label()` the log's
actor (see Production). A ticket's message takes its channel from it
(`TicketChannel::of()`: `panel`, `staff`, `group`). An admin's name is `Reviewers::forAdmin()`: `@handle`, else
`tg:<telegram id>`, else — an account of the website alone — `user#<id>`.
**What no admin decides** (`Auth\Exceptions\ActorRefusedException`, 403; the owner and an agent are refused none of it,
and the report group's admins are held to the same self-guards): about themselves — a shop's admin is its customer too
—: their own payment approved or given back (`ownPayment()`), their own wallet credited or debited (`ownWallet()`),
their own service extended, switched off or on, moved or deleted (`ownService()` — reading its panel again decides
nothing), their own request to become an agent approved (`ownRequest()`: the report group's buttons stay for another
admin), the review they wrote approved, rejected or deleted (`ownReview()`); and, from the website alone (`isStaff()`): an admin's account — banned or let back in, its two-factor sign-in
turned off, signed out, their own included (`adminAccount()`: the panels' to change) —, an agent's alike — the owner's
partners (`agentAccount()`, `UserActions::mayTouch()`) —, a payment approved without a receipt in review
(`receiptFirst()`: a service given away). From the website the agent's rules hold in every shop, the main bot's
included: a client is left on its panel only while that panel is out of reach (`SubscriptionActions::mayLeave()`), the
subscriptions screen's panel work is held to `SubscriptionActions::PANEL_WORK`, and a panel's failure is told as its
summary (`ProviderErrorPresenter::summary()` — a move's too, `MoveException::worded()`; a failed order's `notes`, its
`diagnosis` the owner's alone, `OrderDirectory::notes()`).
**Read by the viewer**: who decided something is shown by who reads it (`Auth\Services\Reviewers::present()`, asking
`CurrentPrincipal`): the owner reads every reviewer as kept; an agent and the website's admins read the owner's login as
«پشتیبانی» — a login's name would let anyone lock the owner out — and the shop's own people as they are kept
(`@handle`, `tg:<id>`, `user#<id>`, `agent#<id>`). On the website a reviewer is drawn by the site; on the panels by
`Reviewer` (components/reviewer).
**What the site is told**: `GET /me` (and `PATCH /me`) answer `staff` (`Store\Presenters\StaffPresenter::present()`):
`{grants, strong_sign_in, signed_in_strongly, signed_in_until, recent_until}` — what the website grants, whether it
asks a strong sign-in and whether this session is one, until when its sign-in lets it work the admin API at all
(`SIGN_IN_HOURS`) and until when it counts as recent (null: prove a way in again first, `POST /me/reauthenticate`) —,
null for a customer who is no admin of the shop and while the website lets none in; the site offers what it says, and
asks a way in again before the door's 403 would. The description's info says the same (see API contract), each refusal
of the door `responses/StaffRefused`.
**The panels' side**: «مدیران سایت» (`/website-settings/staff`, `StaffSection` — see Store API: the website's screen):
the let-in switch, the strong sign-in's, a switch a grant in plain words, what the door asks (a sign-in good for 12
hours, a payment's approval one of the last 15 minutes), a warning — the guards stop an admin deciding about their own
account only, two admins may do it for each other: give the role only to people trusted —, and the way to who the
admins are — the users list narrowed to «مدیران ربات» (`/users?role=admin`, its «نقش» filter), whose menu gives or takes the role; the wallet's
ledger and the ticket's conversation name the admin who wrote (`Reviewer`; a ticket's message from the website «مدیریت
وب‌سایت», `TICKET_CHANNEL`).
**Tests**: tests/Feature/Store/StaffApiTest (the door's every refusal in its order, a password sign-in made strong by its
second step proven again, the working day's sign-in, a grant then a recent sign-in, an approval's recent sign-in, every
read in the panels' shapes, the admin's name on what they decide, each self-guard, a receipt first — and a receipt
rejected while an admin approves it from the website stays rejected —, the leave rule, a panel's failure as its
summary, the pace, the log's line, the ticket's channel, another shop's admins, rows and tokens); `RouteGuardsTest` walks every route of the admin API
(`staffRoutes()`) refused at each step of the door in its order — a customer, an admin while the website lets none in,
a password alone, a sign-in past its 12 hours (every route), a grant not given (seventeen routes), a recent sign-in
missing (eighteen: the granted ones and an approval) —, finds every route of
the agents' panel the admin API does not have a 404 under `/admin` (the shop's configuration, the agent's account), and
another shop's row a 404 there too;
`ApiDescriptionTest` holds the marked operations to the mounted ones with their grants and their recent sign-in
(`x-staff-recent`, only on an operation marked `x-staff: true`); tests/Unit/Core/ActingTest (the
log's actor, its request's alone); `CustomerSessionsTest` (each way in keeps how its session was signed in);
OwnerLoginHiddenFromAgentsTest (the owner's login «پشتیبانی» to the website's admins too); ReceiptReviewTest and
AgencyReviewTest (an admin's own receipt, their own request, in the report group); `AdminUsersApiTest` (the role's own address, the role filter, the wallet line's
reviewer); the panels' website-settings, nav and wallet-modal tests. Helpers: `HttpTestCase::loginAsStaff($website,
$user)`, `Fixtures::panelActor()` and `groupAdminActor()` (see Conventions).

## Tickets (app/Modules/Support)

Support conversations («تیکت», the Store API's slice 5A — the backend; the bot's screens are slice 5B's, the panels'
screens 5C's, below): a customer opens one from the website or the bot, support
answers it from either panel, the website's admin side or the report group, and the customer is told on every door they
have. **The rows**:
`tickets` (BelongsToBot — `user_id` cascade, `subject` (120), `status` — `Enums\TicketStatus`: open (waiting on support),
answered (support wrote last, waiting on the customer), closed —, `subscription_id` (the customer's own service it is
about, nullOnDelete), `last_message_at` (every list's order), `customer_unread` (support wrote since the customer last
read it), `rating` 1–5 + `rating_note` (500), `closed_at`; indexes for the customer's list, the panels' tabs and the
queue's count) and `ticket_messages` (`author` customer|support — `TicketAuthor` —, `reviewer` — who of support, the
answering `Auth\Actor`'s name (a panel's principal, one of the shop's admins as `Reviewers::forAdmin()` keeps them); null
for the customer —, `body` (text),
at most one picture — `attachment_file_id` (a photo sent in Telegram, kept by its file id as a receipt is) or
`attachment_path` (an upload, in the tickets' folder; 100 characters, indexed — the uploads kept still, which the
housekeeping reads —; null again once it went) — with its `attachment_name` (left when the file goes:
`TicketMessage::hasPicture()` it came with one, `keepsPicture()` it is there to show), `channel` web|bot|panel|staff|group
— `TicketChannel`, where it was written: the customer's website or bot, or support's panel, website (`staff`: the shop's
admins there) or report group — support's by the actor, `TicketChannel::of(actor)`; `source()` its words, «از مدیریت
وب‌سایت» the staff's —, `created_at` alone). The customer's own tickets are one
scope, `Ticket::of(customer)`, the website's and the bot's alike.
**One service, every door** (`Support\Services\Tickets`), by decision — the website, the bot, the panels and the group
call the same methods: `open(customer, input, picture, channel)` — `subject` 3–120 (`SUBJECT_MIN`/`SUBJECT_MAX`), the
first message's `body` 1–4000 (`BODY_MAX`; CRLF to LF, its line breaks kept), `subscription_id` one of the customer's own
(else a 422 on it), a picture (an upload judged — `TicketAttachments::judge()`, a 422 on `file` — or a Telegram photo,
`DTO\Attachment::telegram()`): every refusal at once, then the throttle, then one transaction (the ticket, its first
message, the picture kept — `writing()`: a picture kept for a message that was then not written is discarded) and the
report; `write(ticket, input, picture, channel)` — the customer's: a closed ticket opens again (closed → open, `REOPENED`),
an answered one is open again, an open one takes the latest moment; `answer(ticket, actor, input, picture)` — support's,
its channel the actor's: open or closed → answered with `customer_unread` (a closed one opens again so, `REOPENED`),
logged, reported unless written in the group, the customer told (`CustomerNotifier::ticketAnswered()`). **A message and the change it
makes are one transaction under the ticket's row lock** (`lock()`: `lockForUpdate()`, the row read again onto the model),
so a close, an answer or another message coming in the same moment comes before it or after it, never between — support
closing as the customer writes leaves no message unseen on a closed ticket —, and the status the lock read decides the
one write it takes (a message on an open ticket: its latest moment alone, not two moves that fail first). A ticket holds
`MESSAGES_MAX` (200) messages, the customer's and support's together: past it a 422 on `status`, `FULL` «این تیکت به
سقف پیام‌ها رسیده؛ تیکت تازه‌ای باز کنید.» — asked before the throttle (a refused message costs the customer nothing) and
again under the lock —, and `PICTURES_MAX` (20) pictures the customer uploaded (each a file on the host, kept while the
ticket is not closed; a photo sent in the bot stays Telegram's and is not counted): past it a 422 on `file`,
`PICTURES_FULL`. `close(ticket, ?actor)` — either side's: open|answered → closed with `closed_at`, a 422 on
`status` (`CLOSED`) once closed; reported; support's (an actor) logged and told (`ticketClosed()`), the customer's own
(none) tells nobody but the group; `reopen(ticket, actor)` — support's: closed → open (`REOPENED`), a 422 `NOT_CLOSED`
otherwise; reported, not told; `rate(ticket, input)` — `rating` 1–5 (`Input::integerOf()`, Persian digits too) and `note`
(`Input::note()`, `RATING_NOTE_MAX` 500), refused at once; then a message of the customer's window (the throttle); then,
under the lock, while it is closed — else a 422 on `status` (`RATE_WHEN_CLOSED`) — a rating given before replaced, and
**only a first rating or one that changed is written and reported** (the same again changes and tells nothing);
`markRead(ticket)` — the customer read it (the bot's screen as it shows it, the website's `POST /tickets/{id}/read`):
`customer_unread` off, quietly (`ChangeFeed::quietly()`: no panel shows it), and the notices about the ticket in their
website's feed read with it (`notifications` of `subject_type` ticket). Every move of `status` is one compare-and-swap
(`Transitions::move()`), so of two at once one decides and only the one that did tells; a ticket opened again — by either
side — leaves its end and its rating behind (`REOPENED`), by decision: a rating is the customer's word on a conversation
that was over. A message needs words with a picture too, by decision (a picture alone answers nothing; the group's photo
goes as its caption).
**What a customer may send** is held per customer through `Core\Security\RateLimiter` (the throttle's folder): `OPENS`
(10) tickets in `OPEN_WINDOW` (an hour) and `MESSAGES` (30) messages — a ticket's first among them, and every rating — in
`MESSAGE_WINDOW` (10 minutes), and the pictures they upload in the shop's one budget of them, their receipts' too
(`CustomerPictures::budget()`, 10 an hour, «تصویر زیادی فرستاده‌اید» — asked first, so its own words say it), all counted in
one `RateLimiter::attempt(windows)` only once a request passed its checks (each window counted under its lock, what a
full one refused given back — none counted then), then a 429 with `Retry-After` (`TooManyAttemptsException::wait(what,
seconds)`, its words in minutes); support is never held.
**Pictures** (`Support\Services\TicketAttachments`, on the shop's one picture rule — `Users\Services\CustomerPictures`,
see Production: uploads): `judge()` (an upload: JPEG, PNG or WebP by its bytes, `CustomerPictures::MAX_BYTES`; none is
none; none while the host's disk has no room, or the shop's uploads took their day, the 503 — support's own too), `keep()` (a Telegram photo by its file id; an upload in
`PictureFolder::Tickets` — the container's `tickets.path`, storage/uploads/tickets — as GD writes it again,
`{bot}-{ticket}-{16 hex}.{ext}`, the device's name a label), `discard()`, `fetch()` (the bytes — Telegram asked on demand,
kept a few minutes for the reads that follow (`FileCache`), nothing copied for good — by their own type, `CustomerPictures::served()`; a 404 `Exceptions\AttachmentUnavailableException` —
none, gone from Telegram, removed: deleted, or no longer kept —, a 502 while Telegram is out of reach), answered through
`ApiController::bytes()` (`private, max-age=300`); `send(chat, message, options, fetch)` — the picture as a photo of its
own in a customer's chat (support's answer, the bot's screen): Telegram's by its file id (`BotApi::sendPhotoById()`), an
upload by its bytes; with `fetch` a file id Telegram will not send as a photo (a picture the customer sent as a file)
fetched and sent by its bytes; false when it cannot (none kept, its file gone, Telegram turning the picture itself down),
the chat's or Telegram's own trouble going up as any message's; `prune()` — **a closed ticket's uploads go `KEEP_DAYS`
(30) after it closed** (hourly, `Support\Tasks\PruneTicketsTask`, each shop's — with `ReportSender::pruneTickets()`): the
message let go of only while its ticket is closed still — one compare-and-swap on its `attachment_path` —, the file then
discarded, the message keeping its words and its `attachment_name` — it says it had a picture (`attachment.kept` false in
both APIs, the attachment address the existing «فایل این تصویر دیگر روی سرور نیست.» 404) —; one opened again keeps its own,
and Telegram's stay Telegram's. What both APIs say of a ticket and a message is said once, `Support\Presenters\
TicketFields` (`ticket()`, `message()` — `attachment {name, kept}`), each API adding its own.
**The website** (signed in; the customer's own only — another's a 404 as one that never was; `Store\Api\
TicketsController` → `Store\Services\CustomerTickets` + `Tickets`, `Store\Presenters\TicketPresenter`; channel `web`):
`GET /tickets?status=&page=` → `{tickets: [{id, subject, status, subscription {id, name} | null, last_message_at, unread,
rating, created_at, closed_at (null unless it is closed)}], meta: {…the page's, unread}}`, the latest activity first —
`meta.unread` how many of all of theirs hold support's words they have not read, whatever the list shows (a site's
badge) —; `POST /tickets` (`{subject, body, subscription_id?}` — null, blank or left out: about none —, or a form, its
`file` optional) → 201 `{ticket}`; `GET /tickets/{id}` → `{ticket: {…, rating_note, messages: [{id, author, body,
attachment {name, kept} | null, created_at}]}}`, the first first — support's never saying who of support wrote it —, as
it stands: **reading it changes nothing, by decision; `POST /tickets/{id}/read` says the customer read it**
(`markRead()`: support's words and the ticket's notices), as the bot's screen does as it shows it; `POST
/tickets/{id}/messages` (`{body}` or a form, `file` optional), `GET /tickets/{id}/messages/{message}/attachment` (each a
picture Telegram may be asked for: `CustomerTickets::picture()`, `PICTURES` (120) in `PICTURE_WINDOW` (an hour) a
customer, a refused ask counted too, then a 429 «تصویر زیادی خواسته‌اید»), `POST /tickets/{id}/close`, `POST /tickets/{id}/rating {rating,
note?}` — each write answered by the ticket as it stands, read again.
**The bot** (slice 5B; `Telegram\Handlers\TicketHandler` the screens, `TicketMessageHandler` what the customer writes —
the bot renders, `Tickets` decides; channel `bot`, the report group hearing it as from the website): «پشتیبانی»
(`MainMenu::SUPPORT` → `TicketHandler::home()`) says what it ever did — `SupportContact` with the admin's contact, or
`SupportUnavailable` (its default: support is reached by the tickets under it) — with «📨 تیکت جدید»
(`TicketHandler::START`, `ticket:new`) and «🗂️ تیکت‌های من» (`listCallback(page)`); a service's screen opens a ticket
about that service with «⚠️ ارسال گزارش اختلال» (`TicketHandler::report()`: the new ticket's message awaited at once,
`BotText::TicketReportAsk`, «بازگشت» to the service).
**A new ticket**: which of the customer's running services it is about (`Subscription::active()`, the newest five as
`ServiceButton`s, and «بدون سرویس مشخص» — `ticket:new:{service}`, 0 none; none to pick: straight on), then the message
awaited (state `ticket.new`, the service in its scratch — `TicketMessageHandler::awaitNew()`): words — a text, or a
picture's caption — and at most one picture, a Telegram photo (its largest size) or a picture sent as a file judged by its
bytes (`CustomerPictures::isPicture()`, the receipts' rule; `Attachment::telegram(fileId, name)` keeps the file's name); a
picture without words is kept in the scratch and its words asked for (`TicketPictureNeedsWords`: the next text goes with
it; the same album's next picture is not asked about again), anything else is `TicketTextOnly`, the message still
awaited. **The subject, by decision**: the message's first line, cut to `Tickets::SUBJECT_MAX` — at a space in its second
half when there is one — with «…» (`App\Support\Text::fit(…, atWord)`, the one cut by characters — a button's label too —;
a text fitted to Telegram's own room is `Telegram\Api\Limits::fit()`, counted as Telegram counts it); a first line under
`SUBJECT_MIN` takes the next words with it (the message on one line), and a message shorter than that whole is asked for
more (`TicketTooShort`); the body is the whole message as written. Opened (`Tickets::open()`; a service deleted since it
was picked: about none, the words kept) → `TicketOpened` with «🗂️ مشاهده تیکت», and **the chat stays on the ticket, by
decision** (state `ticket.reply.{id}`, `awaitReply()`): what the customer sends next is `Tickets::write()` into it
(`TicketMessageSent`), as one types in a chat, until they go elsewhere — a command, the menu, any `ticket:` button (every
`ticket:` tap is navigation) —, **for `REPLY_SECONDS` (15 minutes) after their last message** (the scratch's `at`, set as
the state is entered and as each message comes): a message after that is not the ticket's — the state left, the fallback
answers it —; and **a notice of another of their tickets ends it at once** (`TicketMessageHandler::leaveOtherTicket(chat,
ticket)`, one conditional update of the chat's session, from both ticket notices: what they type next answers no ticket
by mistake) — the notice's «✍️ پاسخ», like the screen's, is the way back in. (A new ticket's prompt, `ticket.new`, the
customer's own tap asked for, has no such end.) A refusal — the words past `BODY_MAX`, the ticket full, the throttle's 429
— is `TicketRefused` with the service's own words (`%reason%`), the message still awaited; **a picture that came with words
refused — or too short to open a ticket with (`TicketTooShort`) — is kept for the next message, as one without words is**,
which the refusal says (its `%kept%`, `TicketPictureKept`).
**«🗂️ تیکت‌های من»**: the latest activity first, five a page — «بعدی»/«قبلی» (`Buttons::pages()`, the pager «سرویس‌های
من» and the renewals share) and «صفحه x از y» (`SubscriptionsPage`) —, a button per ticket marked by where it stands
(`TicketButtonOpen` 🟡, `TicketButtonAnswered` 🟢, `TicketButtonClosed` 🔒: «#12 · subject», the subject cut to 40);
none yet, `TicketsEmpty` with «📨 تیکت جدید»; «بازگشت» to «پشتیبانی». **A ticket's screen** (`screenCallback(id, from,
...action)`, `ticket:{id}:{from}` — `from` the list's page «بازگشت» goes to, 0 its first): `TicketScreen` — number, subject,
where it stands (`TicketStatus*` parts), its service, its rating, and the last five messages, the earliest first
(`TicketFromCustomer` «شما» / `TicketFromSupport` «پشتیبانی», each with its time — `Persian::date(…, withTime)` —, its
words cut to 600 as Telegram counts them (`Limits::fit()`), a picture there to send numbered — `TicketMessagePicture`
«همراه تصویر ۱» — and one no longer kept said so, `TicketPictureRemoved`; `TicketEarlier` counts those before; the
earliest dropped while the text outgrows a message) —, read by now (`Tickets::markRead()`, its notices too): «🖼️ تصویر n»
for each picture shown, 1 on the right, over the rest (`TicketPicture`; `:picture:{message}` →
`TicketAttachments::send(…, fetch: true)`: the picture as a photo of its own — the screen stays —, `TicketPictureCaption`
its caption while it fits one; gone — Telegram no longer hands it over, its file deleted —, a `TicketPictureGone` popup),
«✍️ پاسخ» (`:reply` → state `ticket.reply.{id}`, `TicketReplyAsk`, `TicketReplyReopens` on a closed one; read too), «🔒 بستن
تیکت» while open or answered (`:close` → `Tickets::close(ticket, null)`, a toast, the screen redrawn; closed meanwhile,
`TicketAlreadyClosed`), «⭐ امتیاز» once closed and unrated (`:rate` → five stars, 1 on the right; `:rate:{n}` →
`Tickets::rate()`, the screen with its rating — each star a message of the customer's window: past it the wait in a popup;
opened again meanwhile, `TicketRateUnavailable`). Another customer's ticket is `TicketNotFound` with the way back.
**Under a notice** (`replyFromNotice()`, `rateFromNotice()`, `viewFromNotice()`: `from` «n») what a button opens comes as
a message of its own — the notice, support's words, stays —, whose own buttons then edit it in place as a screen's do.
Every word is a `BotText` of the «پشتیبانی» group.
**The panels** (both — the shop's daily work, `$operations`, so the website's admins' too, channel `staff`;
`Admin\Api\TicketsController` → `Support\Services\TicketDirectory` +
`Tickets`): `GET /tickets?status=&user=&search=&page=` — the latest activity first (no column sorts it, by decision: the
conversation that moved last is the one to look at), a status tab, one customer's (`PageRequest::id('user')`, their
page's link), searched by the customer (`User::idsMatching()`), the subject or a number («#12» the ticket alone,
`PageRequest::search()`); a row `{id, subject, status, customer (presentRef), subscription, last_message_at,
messages_count (withCount), rating, created_at, closed_at}`, `meta.open` the queue whatever the list shows —; `GET
/tickets/{id}` (the conversation, each message with its `reviewer` as `Reviewers::present()` words a decision's — the owner's login
«پشتیبانی» to anyone but the owner — and its `channel`, its picture `{name, kept}`), `POST /tickets/{id}/messages` (support's
answer, JSON or a form with `file` — optional —, the principal's actor its reviewer and channel — `panel`, `staff`; a 422 on `status`
once the ticket is full, a 503 for a picture the host's disk has no room for), `POST /tickets/{id}/close`, `POST
/tickets/{id}/reopen`, `GET /tickets/{id}/messages/{message}/attachment` — each write answered by the ticket read again
with its conversation.
**Told**: `CustomerNotifier::ticketAnswered(ticket, answer)` (`NoticeType::TicketAnswered`, its subject the ticket —
`NoticeSubject::Ticket` —; `BotText::TicketAnswered` of the bot texts' «پشتیبانی» group: the ticket's number, its
subject, the answer cut with «…» to what a message holds as Telegram counts it (`Limits::fit()`) — the whole is on the
website — and `BotText::TicketAnswerPicture`, a part, when it carries a picture; «✍️ پاسخ» and «🗂️ مشاهده تیکت» under it) —
**support's picture goes with it** (`TicketAttachments::send()`: a photo by its Telegram file id or an upload's bytes, the
notice its caption) while the notice fits a caption, else — or when Telegram turns the picture down — the words alone
(the screen's «🖼️ تصویر» sends it) — and `ticketClosed(ticket)` (`BotText::TicketClosed`: a message to it opens it again;
«⭐ امتیاز» while it is unrated and «🗂️ مشاهده تیکت» — `ticketButtons()`, see The bot) — in Telegram, else by email, kept
for the website either way (see Notifications). Both, as they go to the chat, end a stay of it on another ticket of the
customer's (`ticketNotice()` → `TicketMessageHandler::leaveOtherTicket()`).
**The report group's «تیکت‌ها»** (`Topic::Tickets`, every shop's, `reports.topic.tickets` on by default): `ShopReports::
ticketOpened()` (the customer, the subject, the service, where it came from, the first message — an upload as the photo
it captions, a Telegram one said in a line —, how to answer: by a reply, or on the panel's «پشتیبانی» page — its menu's
name), `ticketMessage()` (the customer's, «🔓 تیکت دوباره باز شد.» when it opened the ticket again, or support's answer
from a panel or the website's admin side — where it came from, `TicketChannel::source()`), `ticketClosed()` (by whom), `ticketReopened()`, `ticketRated()` (a first rating or a changed one: the same
again is not reported) — each `ref` `ticket:<id>` and `ticket_id`, a reply to the one before it that takes its «🔒 بستن
تیکت» over (`reply_ref`, `clears_buttons`), a message cut to the room a caption or a message has, as Telegram counts it
(`Limits::fit()`, `ShopReports::ROOM` a margin — a review's report's too), support's reviewer never named, an answer written in the group not reported
again; a message is folded into the ticket's last report while that one still waits in the queue — a burst of a
ticket's messages is one report, not a queue crowding the group's other reports out (see Report group: sending). A bot
admin's reply to any of them answers the ticket — kept, however old, while the ticket is not closed —, the
button closes it, and a reply in the topic to a message of the bot's that names no ticket is told where an answer goes
(see Report group: `TicketReplies`, `TicketClose`).
**The queue**: `Admin\Services\Queues::counts()`' `open_tickets` (status open) — `GET /queues` (the «پشتیبانی» entry's
badge, `QUEUES.open_tickets` in components/shell/queue-badge) and the dashboard's attention row «تیکت‌های در انتظار
پاسخ» → `/tickets?status=open`, the list's open tab (`TICKET_STATUS` of lib/statuses: «در انتظار پاسخ», «پاسخ‌داده‌شده»,
«بسته‌شده»; `TICKET_CHANNEL` the messages' doors: وب‌سایت، ربات، پنل، مدیریت وب‌سایت، گروه گزارش‌ها). Live: the `tickets` area (`tickets`,
`ticket_messages`) reads the tickets lists (`queryKeys.tickets` — a customer's card too), a ticket's page
(`everyTicket`), the queues and the dashboards again (`AREA_QUERIES`). A merge moves a customer's tickets
(`AccountMerger::REFERENCES`, `tickets.user_id`).
**The screens** (both panels; «پشتیبانی», `SUPPORT` of components/shell/nav, a top-level entry right after the dashboard
with the queue's count): `/tickets` (`pages/tickets.tsx` — the status tabs, «در انتظار پاسخ» counted from `meta.open`, the
search, `CustomerFilter`; no column sorts it; a row's cells in components/tickets/ticket-cells) and `/tickets/:id`
(`pages/ticket.tsx`, a subject's page as a customer's is — keyed on the ticket, a 404 «تیکت پیدا نشد.» under `BackLink`
«تیکت‌ها»: the subject and its state, under them «تیکت #12 · باز شده در …» and, while it is closed, «· بسته شده در …»
(`closed_at`), one button that closes it — a `ConfirmModal`, the customer told — or opens it again — at once, or, when
the customer rated it, after a `ConfirmModal` that says opening it again clears the rating (the backend's rule; asked
first, its refusal said in the dialog, `meta.quiet` while it is open; at a press, a toast) —,
the facts — the customer, the service → `/subscriptions?search=#id`, the rating —, the conversation in
components/tickets/conversation — the customer's at the start, support's at the end on a quiet fill, each with its door and
support's writer (`Reviewer`); a picture an `ApiPicture`, as a receipt is — one no longer kept (`attachment.kept` false)
a word in its place, never asked for — and the answer, components/tickets/reply-form —
`useForm` + `FormActions`, the words held to the server's 4000, a picture refused at once past its 10 MB, a form when it
holds one, JSON otherwise; on a closed ticket it says the answer opens it again). Each write answers the ticket, put in
place, and names the lists, the queues and the dashboards; a refusal because the ticket moved on reads it again.
**Tests**: `Fixtures::ticket(user, subject, body, overrides)` (a ticket with its first message, from the website) and
`ticketMessages(ticket, count)` (a long conversation, as rows), `TestCase::imageOf(bytes)` (a picture's width, height and
type: what an upload the shop kept is, GD's writing of it or not), tests/Feature/Store/`StoreTicketsTest` (opened about a
service, a picture by a form — kept as GD writes it, served, the group's photo —, the answer unread until the website says
it was read — `GET` changes nothing, `POST …/read` reads it and its notices —, `meta.unread`, writing reopens, a message one
write under the ticket's hold — and read where the ticket stands, closed a moment before —, closing and rating, every
refusal at once, another's, the throttle's 429; the review's: pictures one budget with the receipts, a closed ticket's
pictures gone a month on — said so on the website and the panels —, ratings a message of the window and only a first or
a changed one reported, two hundred messages, a hundred and twenty pictures read an hour, twenty uploaded a ticket, a
disk with no room left — `swap()` of a
`CustomerPictures` keeping every byte free — a 503 and the owner's warning), `CustomerPicturesTest` (GD's: no larger than
its side, a phone's photo upright — EXIF either byte order —, nothing appended kept, one GD cannot read kept as it came),
`AdminTicketsApiTest` (the list — order, tabs, a customer's, the search, the queue —, the conversation with its
reviewers and channels, the answer told — its picture with it — and reported, close and reopen once — `closed_at` set,
then left behind —, an agent's shop, a Telegram picture),
`TicketReportsTest` (the report with its photo and button, the cut — emoji counted as Telegram counts them —, the thread,
the group's answers — a text, a photo with its caption, who may, anything else —, the close button; a ticket not closed
answered a week on, a closed one's reports gone; a reply to the bot's own word told where an answer goes; a burst of
messages folded into the report that still waits, and only into one that can take it),
`NoticesTest`' ticket notices (kept, emailed, cut), `AdminQueuesApiTest`, `AccountMergerTest`, `ListQueriesTest` (both
lists and conversations), `RateLimiterTest`'s `attempt()`, `ShopScheduleTest` (the housekeeping, hourly, each shop's),
tests/Unit/Support/`TextTest`, tests/Unit/Telegram/`LimitsTest`' `fit()`; the bot's, `BotTicketsTest` (on «پشتیبانی»;
opened with words, a photo, a picture sent as a file — judged by its bytes —, about a running service or none, a picture
without words — or with words refused — kept for the next words, what is neither refused; the subject's rule; the limits
and the throttle in the shop's words; what follows joining the ticket until the customer goes elsewhere, a quarter of an
hour passes, or another ticket's notice comes — the review's; the list's pages and order, none yet; the screen — the last
five, read by now with their notices, a picture numbered and sent by its button, one no longer kept said so —, another's; a
reply, a closed one opened again; closing; the stars — a message of the window each, only a change reported —; support's
picture reaching the chat with its answer, or the words alone; the notices' buttons each a message of its own; the report
group's «از ربات»; an agent's shop; a fixed few queries however many rows), and `BotMenuTest`'s «پشتیبانی» with its two
buttons; and the panels' `pages/tickets.test.tsx` and `pages/ticket.test.tsx` (since when a closed one is closed; opening
one again at a press, or — rated — after the dialog, its cancel sending nothing and its refusal said in it; a picture no
longer kept said so, not asked for).

## Reviews (app/Modules/Reviews)

Customers' reviews of the shop («نظرات»): written on its website — by a guest, or by a customer signed in there —,
decided by support from either panel or the website's admin side, shown on the website once approved — while the
website shows them at all: its `reviews_enabled` (off, as a website starts; set by `PATCH /website`, read as `reviews:
{enabled}`), off, both `GET` and `POST /reviews` answer a 404 (`Store\Exceptions\ReviewsOffException`), as a closed
store's address does. **The row**:
`reviews` (BelongsToBot — `user_id` the signed-in writer, null for a guest (`nullOnDelete`; moved by a merge,
`AccountMerger::REFERENCES`), `name` the name it is signed with (2–64 characters, one line), `rating` 1–5, `body` the
writer's words (10–600, their line breaks kept), `context` where they use the service from (100 at most, one line —
«ایرانسل · اندروید · Happ»), `avatar` a key of the website's own set (`[a-z0-9_-]{1,32}`, never an address a page would
load a picture from), `status` — `Enums\ReviewStatus`: pending (waiting on support), approved (shown), rejected (not) —,
`reviewer` and `decided_at`: who of support decided it last, and when). One index, `(bot_id, status, id, rating)`, serves
the website's page, the tabs, every count and the average off the index alone; one on `user_id` (a merge, an account
gone).
**One service** (`Reviews\Services\Reviews`), whichever door a decision comes through: `submit(site, request, writer,
input)` — a guest's only while the website asks a captcha, by decision (without one nothing would stand between a bot
and the queue: `Exceptions\SignInToReviewException`, a 403 «برای نوشتن نظر، اول وارد حساب خود شوید.», its signed-in
customers' alone); every refusal of its fields at once (`NAME_MIN`/`NAME_MAX`, `BODY_MIN`/`BODY_MAX`, `CONTEXT_MAX`, the rating
by `Input::integerOf()`, Persian digits too); then the shop's room: `PENDING_MAX` (200) waiting on support at most, else
`Exceptions\ReviewsFullException`, a 503 in the customer's words until support decides some — whoever writes them by the
hundred, from address after address, fills neither the queue nor the report group without end; then the tries counted
(`RateLimiter::attempt()`: `NETWORK_REVIEWS` (20) in `NETWORK_WINDOW` (an hour) from an address network — an IPv6 one
by its /48, `NETWORK_IPV6_PREFIX`, as much as one host is often handed (`RequestOrigin::clientNetwork(request, bits)`) —,
and `CUSTOMER_REVIEWS` (3) in `CUSTOMER_WINDOW` (a day) from a signed-in customer, or — a guest — every guest together
`GUEST_REVIEWS` (20) in `GUEST_WINDOW` (an hour), a 429 «نظر زیادی ثبت شده است» with its
wait); then the website's captcha judged for `CAPTCHA_ACTION` `review` (`Verifier::check()`, see Captcha) — a refused
token keeps its network's count, so a bot's guesses cost its network its reviews and its provider is asked no more than
that, but never the guests' count (given back: a bot's guesses would spend it for every guest); one that could not be
judged gives the counts back (its 503) —, the reviews' own windows and never the sign-ins'
(`SignInThrottle::issuing()`), so a review bot behind a carrier's shared address keeps nobody behind it from signing in,
by decision; then kept, pending, and the report group told, in one transaction. `approve(review, actor)` — from pending
or rejected — and `reject(review, actor)` — from pending or approved: hidden again — are each one compare-and-swap
(`Transitions::move()`) writing `reviewer` (`Actor::name()`) and `decided_at`, a refusal a 422 on `status` in the words
of the state it found (`APPROVED` «این نظر تایید شده است.», `REJECTED`); `delete(review, actor)` whatever it stood at,
logged; `allowed(review)` a row's `actions` (`{approve, reject}`; a delete always). One of the shop's admins never
approves, rejects nor deletes the review they wrote themselves, signed in (`notTheirOwn()` →
`ActorRefusedException::ownReview()`, 403: another admin's, or a panel's, to decide). Nobody is told of a decision.
**The website** (`Store\Api\ReviewsController`, public): `GET /reviews?page=` → `{reviews: [{id, name,
rating, body, context, avatar, created_at}], meta: {…the page's, summary: {count, average}}}` — the approved ones, the
newest first, the writers' own words as plain text a website escapes and nothing of who wrote it nor of support's
(`ReviewDirectory::published()`); `summary` every approved one (`ReviewDirectory::summary()`: how many, and their mean
rating to two decimals, null while there is none); `POST /reviews {name, rating, body, context?, avatar?, captcha?}` →
201 `{review: {id, status}}`, anyone's (`security: [{}, {customer: []}]`, `x-captcha: review`): a bearer token with it
makes it the customer's — `Accounts\Http\OptionalCustomerMiddleware` hands such a request to the customer's own door
whole (`CustomerAuthMiddleware`): a token that opens no session is its 401, never a guest's review in its place, and a
banned customer its 403.
**The panels and the website's admins** (`Admin\Api\ReviewsController` → `Reviews\Services\ReviewDirectory` +
`Reviews`; the shop's daily work, `$operations`, `x-staff: true` — moderating reviews is support's work, no grant):
`GET /reviews?status=&search=&page=` — every review, the newest first, a status tab, searched by the customer who wrote
it (`User::idsMatching()`), the name it is signed with, its words or a number («#12» the review alone,
`PageRequest::search()`); a row `{id, name, rating, body, context, status, customer (presentRef; null for a guest),
reviewer (Reviewers::present(): by who reads it), decided_at, created_at, actions}`, `meta.pending` the queue whatever
the list shows —; `POST /reviews/{id}/approve` and `POST /reviews/{id}/reject` (no body) → `{review}`, the row as it
now stands; `DELETE /reviews/{id}` → 204.
**The queue**: `Admin\Services\Queues::counts()`' `pending_reviews` — `GET /queues` (the «نظرات» entry's badge: `REVIEWS`
of components/shell/nav, a top-level entry after «پشتیبانی», `QUEUES.pending_reviews` in components/shell/queue-badge)
and the dashboard's attention row «نظرات در انتظار بررسی» → `/reviews?status=pending`.
**The report group's «نظرات»** (`Topic::Reviews`, every shop's, `reports.topic.reviews` on by default; `priority()` 2,
after everything else — anyone writes one, nobody waits on it, so however many come none holds the shop's own news up):
`ShopReports::reviewWritten()` — its number, the name it is signed with, the stars, the context, who wrote it (the
customer signed in, `ShopReports::customer()`, or «مهمان، بدون ورود به حساب»), «از وب‌سایت» and its words, cut to a
message's room as Telegram counts them (`Limits::fit()`, `ROOM` a margin) —, no buttons: support decides it on the
panel's «نظرات» page, which the report says.
**Live**: the `reviews` area (the `reviews` table) reads the list, the queues and the dashboards again (`AREA_QUERIES`).
**The screen** (both panels; `pages/reviews.tsx`, `ReviewsPage`, `queryKeys.reviews`): the status tabs (`REVIEW_STATUS` of
lib/statuses: «در انتظار بررسی», «تاییدشده», «ردشده» — the pending one counted from `meta.pending`; the dashboard's link
opens it), the search, and rows — the number, the name with its writer (the customer's `UserLabel` leading to their
page, or «مهمان»), the stars, the words whole with the context under them, the state with who decided it and when, when
it was written — and the row's menu: «تایید» / «رد» as `actions` allow (one another decided first: the toast, and the
list read again), «حذف» behind a `ConfirmModal`, which says that rejecting is enough to keep it off the website. A
decision names the queues and the dashboards (`meta.invalidates`). The website's switch is «تنظیمات وب‌سایت» › «نظرات»
(`/website-settings/reviews`, `ReviewsSection` of components/website: the switch, the way to the «نظرات» page, and
whether guests can write by the captcha the website asks now — none, its signed-in customers alone, with the way to
«ورود با ایمیل»'s captcha).
**Tests**: tests/Feature/Store/StoreReviewsTest (the switch's 404 both ways; the approved ones, the newest first, and
their summary; a guest's waiting on support, the report group hearing of it last, and a guest's refused while no
captcha is asked; every refusal of its fields at once; a signed-in customer's theirs, a token that opens nothing no
guest; Turnstile for the review's action — a refused token counted, one unjudged not —, and ALTCHA's challenge of the
shop's own; an address network's — an IPv6 one by its /48 —, a customer's and the guests' windows; the shop's room),
tests/Feature/AdminReviewsApiTest (the list, its tabs and the pending count; approved, rejected and deleted each once in
the words of the state it found, and a decision lost in the same moment; an agent's shop, the owner's login «پشتیبانی»
there; the website's admins moderating; the queue of the sidebar and the dashboard), `ListQueriesTest` (both lists),
`AccountMergerTest`, ApiDescriptionTest and RouteGuardsTest (`STORE_PUBLIC`, `STORE_EITHER`, the row kind
`review`); the panels' `pages/reviews.test.tsx`. Helpers: `Fixtures::review(writer, name, rating, body, overrides)` (a
review written on the website, waiting on support unless the overrides say otherwise), `reviewRow()` of src/test/rows.

## Documentation (docs/)

**docs/ is the project's one tree of documentation, by decision**: the repository's own view on GitHub, the project's
GitHub wiki and GitHub Pages read the same files as they are, and so can any other Markdown system (MkDocs, VitePress,
Wiki.js, a Gitea or Forgejo wiki — docs/Contributing.md's «Documentation» is the contributor's guide to all of it; this
is the engineer's). Who reads what: README.md is the short front door (what AmoBot is, its features, the requirements, a
quick start, then the tree's pages), CONTRIBUTING.md and SECURITY.md are the files GitHub looks for at the root (the
checks and the rules a change keeps; a weakness reported privately, through GitHub's private vulnerability reporting —
the policy itself is docs/Security.md), CHANGELOG.md is what each release brought (Keep a Changelog: a change a shop's
owner, customers or a website's developer would notice is a line under `## Unreleased` at its top, the section a
release renames to its version and publishes as its notes), .github/ holds the workflows, the issue forms and the pull
request's template (the checks to run), CLAUDE.md stays the engineers' handbook — every subsystem's rules and the
decisions behind them —, and the tree holds the guides: the operator's (installation, production, configuration and
its reference, upgrades, backups, the console), the
owner's (a page a part of the panels), the bot's, the Store API's (a page a part of it, for a website's developer), the
developers' (architecture, drivers, extending, development, testing, contributing, releasing) and the project's
(security, the roadmap); `_Sidebar.md` is the list. A change of behaviour a guide describes changes the guide with it.
- **The pages**: English, GitHub-flavoured Markdown, flat (the wiki has one namespace, Docsify a route a page), one
  subject a page, named `Title-Case-With-Dashes.md` — `Home.md` the front page, `_Sidebar.md` the navigation every page
  is in, `_Footer.md` the footer. A page links to another as `[words](Page-Name.md)` or `(Page-Name.md#anchor)` (the
  repository's view and Docsify follow it as it is, the wiki's export rewrites it), and to anything else — a file of the
  repository, another site — by its full `https://` address: the wiki and Pages have nothing but the tree. A heading
  another page links to keeps to what GitHub and Docsify anchor alike (no dash or slash between spaces, no leading
  digit, no «»). The product's own words — a screen's, a button's — are quoted in Persian, in «»; the rest is English.
- **Generated pages** (`php scripts/docs.php`): `Config-Reference.md` — every setting config.php may hold, by
  `ConfigKeys::SECTIONS` in the file's order (its default and the words the file says about it), and after the setting
  that names a driver (`DB_CONNECTION`, `MAIL_TRANSPORT`) each registered driver's own settings as its form describes
  them (`database.drivers`, `mail.drivers`) — and `Bot-Texts-Reference.md` — every `BotText` by `TextCatalog`'s groups:
  its title, its `TextKind` (a table of what each holds and its limit), where the customer meets it, its variables
  (`VARIABLES` with the preview's samples; the required ones marked) and whether only the main bot says it. The script
  boots the app on a config.php of its own, made and deleted at once (an in-memory SQLite database, a fresh key — never
  this machine's config.php nor its database), writes only a page that changed, and a page it writes says so in its
  first line (`generatedNotice()`): **never edit one by hand** — a change to `ConfigKeys`, a database or mail driver's
  form, or `TextCatalog` is followed by the script, and what it wrote is kept with the change. Its generators are one
  list (`generatedPages()`); another (the API's reference, from resources/api/openapi.yaml) joins it.
- **The check** (`php scripts/docs.php --check`, CI's step after `composer check`; `stalePages()` + `linkProblems()`),
  a line for each problem and the run fails: a generated page that is not as the code makes it now; a page whose name is
  not of the tree's form, or another page's in another case, or that `_Sidebar.md` does not list; a heading GitHub and
  Docsify would give two anchors; a link between pages to no page or no heading (a heading's anchor numbered as both
  number a repeated one), or one that leaves the tree; and the links of README.md, CONTRIBUTING.md, SECURITY.md and
  CHANGELOG.md — to
  the repository's files, and to the tree's pages and headings as `docs/Page-Name.md#anchor`. A link inside code — a
  fenced block, a code span — is no link (`mapLinks()`).
- **Published**: GitHub Pages serves docs/ as it is (Settings › Pages, deployed from the default branch's `/docs`) —
  `docs/index.html` is Docsify, its script, theme and search pinned by version from jsDelivr, `Home.md` its front page
  and `_Sidebar.md` its sidebar, and `docs/.nojekyll` keeps Jekyll from hiding the `_` files: nothing to build or
  install. The wiki is `.github/workflows/wiki.yml`: on a push to the default branch that changes docs/, the script or
  the workflow (or by hand), where the repository has a wiki, it checks out `<repo>.wiki`, runs `php scripts/docs.php
  --wiki <dir>` (`exportWiki()`: every page with its links to other pages written without `.md`, a page the tree no
  longer has removed; a folder that is no checkout of a wiki, or is this repository or docs/, refused) and commits and
  pushes it with the workflow's token, one run at a time. GitHub makes a wiki's repository only once its first page
  exists: the wiki is switched on and one page made by hand, which the first run replaces — until then a run finds no
  wiki's repository (`git ls-remote` says it is not found), says so in a warning with that way out and publishes nothing, any
  other failure to reach it failing the run.

## Conventions

- `declare(strict_types=1)`, `final` classes by default, constructor promotion, readonly where possible.
- Loosely typed request input is read through `App\Support\Input` (`text()`, `integer()`, `decimal()` accept
  Persian digits; `truthy()` for the booleans browsers and JSON send, `isBoolean()` to validate one; `note()` for an
  admin's free text with the one «حداکثر … کاراکتر» rule, `active()` for an `is_active` switch with its default;
  `integerOf()`/`decimalOf()` for a scalar that is not a request field, `amountOf()` for a whole amount a customer types
  in the bot or on the website — «۱۵۰٬۰۰۰ تومان», «۲۰ گیگ»: separators and a unit word allowed, a fraction or two
  numbers not, but the API's own form of a whole amount, "50000.00", a fraction of zero —, `amount()` for money the
  admin types — a plan's price, a level's price per GB, a wallet set right by hand, the settings' `Amount` —: whole
  Toman by the same reading, a fraction refused in the field's words; `wholeOf()` a whole number an admin types into a
  form («10,000», «۱۰٬۰۰۰» — the settings' `Number`), `numbersOf(typed)` a list of them as typed, apart by spaces, «،» or
  a comma — but a comma between groups of three digits, a number's own («50,000, 100,000» is two numbers; the settings'
  `Numbers`); a thousands separator — a comma, a space, «٬» — is one only between groups of three digits, in
  `amountOf()`, `decimalOf()` and `wholeOf()` alike: «2,5» is no 25); text read through it —
  `text()`, `password()`, `note()`, `secret()` — is UTF-8 or a 422 on its field (`Input::NOT_UTF8`), the same for a
  form's field and a query's value as for JSON (which cannot be anything else): bytes a database would refuse with a 500,
  or an encoder throw on, never pass it — services never re-implement these; the one wording for a too-long field is
  `App\Support\Validation::tooLong()`, for an upload PHP did not take whole (past php.ini's limit, cut short)
  `Validation::uploadFailure()` (a QR background, a receipt).
  Admin-ordered rows (`sort` column) are renumbered by `Core\Database\Sorting::reorder()` (`MAX_IDS`, 1000, at most — a
  422 on `ids` before anything is read) / placed with `Sorting::next()`; a reorder endpoint reads its ids with
  `ApiController::reorderIds()` (a `ValidationException` when there are none).
- Two processes never act twice on one row: every "did I win" is one conditional UPDATE and its matched-row count
  (MySQL is told to report matched rows, `PDO::MYSQL_ATTR_FOUND_ROWS` in `MySqlDriver::connection()`, as SQLite does —
  a write of what is there already still wins). State changes go through `Core\Database\Transitions`: `move(model,
  column, from[], to, attributes)` (see Payments), `reclaim()` for a claim whose owner died, `claim(model, column,
  ?before)` for a one-time mark — a moment set while empty (or older than `before`, a new window), so of two processes
  about to tell a customer the same thing one does. A row worked through over time by one process at a time — a
  broadcast's run, a grant's part, a report being sent, a report topic being made — is a `Core\Database\Lease`'s: `Lease::take(model, seconds, while)` (null while
  someone holds it and its time has not run out; `while` the state it must be in, a status) → `write(values)` (a cursor
  onto the next item before the item is touched), `renew()` (only the hold, before a call that may take long),
  `increment(column, values)`, `finish(values)` (the end and the lease cleared) — each false once a pause, a cancel or a
  takeover left the row, and each holding it `seconds` longer — then `move(column, from, to)` (an item put back) and
  `release()` (in a `finally`), which need the token alone; a stop from outside writes its state with `Lease::FREE`, and
  a write that must not land on a held row narrows its query with `Lease::whereFree()`. A leased table carries `lease_token` (string 32) and
  `leased_until` (nullable timestamp); the model takes what a successful write wrote.
- Telegram's answers are read one way: `Telegram\Api\TelegramApiException::refusal()` / `is(Refusal::…)` —
  `Api\Refusal` is the only place Telegram's error codes and descriptions are matched (a flood limit, a token Telegram
  refuses, the customer gone, a topic gone, an unparsable text…); `Reports\GroupProblem::of()` and the callers read it,
  never the words. A bot's token, wherever it is handed over (the settings screen, the installer, an agent in the main
  bot), is `Api\BotToken`'s: its shape, then `identify()` (getMe as that token) — one rule, one set of words. Whatever the
  bot writes to a customer on its own initiative (a notice, a broadcast) goes through `Notifications\Services\CustomerChats`: a
  customer without a Telegram account is sent nothing (`no_telegram`), a customer who turned the bot away (`users.bot_blocked`) is skipped, a refusal that says so is learned, and only
  Telegram's answers are caught there — a mistake of the code goes up; what came of it is a `Notifications\Enums\Delivery`
  (told, emailed, turned_away, unreachable, refused, no_telegram), which a screen that tells for telling's sake says (a
  reminder). A notice (CustomerNotifier's, never a broadcast) goes through `Notifications\Services\Notices::deliver()`
  first: kept for the customer's website, then CustomerChats — or, a customer Telegram cannot reach (no Telegram account,
  or one that turned the bot away), their email (`emailed`).
- Frontend data plumbing lives in a few hooks, typed by the generated API types (never a hand-typed copy of an answer or
  of a request — a form's draft may hold its numbers as typed, as long as the request is the draft whole, `Exact`, or one
  typed function turns it into the request: `termsBody()`, `databaseRequest()`, `driverPayload()`; a draft's type built of the request's,
  `Required<ServerGrantRequest> & {days: string; …}`, and a settings card's `satisfies BotWalletSettingsRequest`, where
  the operation's own body is a `oneOf` of every group's):
  `lib/use-form` (`useForm(initial, {follow?, reads?})`: values, `set`/`patch` (clearing those fields' errors — and every word of
  a submit's refusal once the values are back at the baseline, which is not what was refused: it was about the values
  sent), `revert()` back to
  the baseline, field errors from a 422 and a form error for anything else, `busy`, **`dirty` by one rule — what the
  server would read of the values against what it would read of the baseline**, by decision (every false «discard?» is
  a form whose draft says more than its request): `reads(values)` is that reading, by default `trimmed` (every text
  trimmed, as `Input::text` reads it, in lists and records too), and a form whose request reads its values otherwise
  says so — `asSet(items)` a list the server keeps as a set (a customer's groups, the amounts offered), a driver form's
  `driverPayload()` (the fields shown alone, so a driver picked and picked back leaves nothing behind), the website
  cards' PATCH (`useWebsiteGroup`: `trimmed(request(values))`), a bot text's `keptWording()` (as `BotTexts::save()` keeps
  it: its line breaks a newline each, trimmed — a part only at its end), the keyboard editor without the draft's own row
  ids, a current password typed out of it (the login card, the Telegram card), a password not trimmed; then the two
  readings are the same when an empty text, `null`, `undefined` and a missing key all say nothing, and a number is its
  typed text in Persian or Latin digits (`30` = `"30"` = «۳۰»; leading zeros count: «0912» is no «912»), `submit(request,
  {onInvalid, invalidates})` — a quiet mutation of the panels' cache (`runMutation()`), which re-baselines on success and
  has what it `invalidates` read again —, `handleSubmit(fn)`; `follow` starts the form again whenever the
  server's copy changes by value) for every form, `lib/use-rows` (`useRows({query, list, reorder, remove, patch?,
  invalidates})` — the list's writes as the screen makes them, `reorder: (ids) => api.post('/plans/reorder', {ids})`, a
  switch's `patch` answering the row —: the
  read + the cache writes of an admin-ordered list — `upsert`, `patch`, `move`/`reorder`, `remove` — each a change of what is cached now, so
  two quick ones never undo each other, and sent after the one before it — one mutation `scope` per list, its read's key —, so the server
  ends where the screen does; what else shows the rows read again after each, and handed back for its form) for every
  sortable list — a row's form saves with its own PUT or POST (`row ? api.put(`/plans/${row.id}`, values) : api.post('/plans', values)`) —,
  `lib/use-settings-group` for a settings card (`save`: the group's PUT as its card writes it, `reads` handed through to
  `useForm` — `useBotSettingsGroup(initial, save, {invalidates?, reads?})`, `useConfigGroup(initial, {save, saved,
  invalidates?, reads?})`, `useWebsiteGroup(website, draftOf, request)`),
  `lib/use-probe` for a "test connection" button (`useProbe(() => api.post('/servers/test', body))`, `run()`; an answer
  about input that changed since, or a probe since started, is dropped), `lib/use-paged-list` for a paged table,
  `lib/queries` for a read two
  screens make. Do not call setState from an effect to follow a prop — adjust state during render
  (`const [prev, setPrev] = useState(prop); if (prev !== prop) {...}`); oxlint enforces it, and there is no
  lint-disable comment in the tree.
- The panels' unit tests (Vitest, `pnpm test` — in `pnpm check`, so in CI) sit beside what they test as `*.test.ts[x]`
  and test behaviour through the way a screen uses it — a hook through `renderHook`, a screen through Testing Library,
  the API through `lib/api` — in a happy-dom page at the owner's panel (`http://localhost/admin/`: lib/config reads it;
  an agent's test moves the address before anything loads, `vi.hoisted`), in UTC and in an order of their own each run
  (vite.config.ts `test`). `src/test/setup` gives every test a fresh `src/test/server` in place of `fetch` —
  `server().on(method, path, answer)` (`json()`, `refusal()`, `html()`, `noContent`, `offline`, `silent` — headers of its
  own with `withHeaders(answer, headers)`: an `X-Request-Id`, a 429's `Retry-After` —, or a function
  of the request), `hold()` for an answer the test gives later, `sent()`/`requests` for what went out; a request no route
  answers fails the test, and so does one the API description does not take (`src/test/contract` — see API contract;
  `unchecked(method, path)` for the one a test sends malformed on purpose), so a test answers its screen at the addresses
  the API has, with the bodies the API takes — and leaves nothing behind (unmounted, spies undone, the address, storage, real timers).
  `src/test/render`: `providers({client, at})` (the query client as the app makes it, reads not retried, and a router in
  memory), `signedIn({session, client, at})` (the same behind `RequireAuth`, the panel's `/auth/me` answered with
  `session` — `OWNER`, or `AGENT_SHOP` for what depends on the shop shown), `until(assertion)` (waits on the event loop,
  never on a timer — one ticks every 15 ms on Windows), and
  react-query tells its readers on a microtask there (`notifyManager.setScheduler`); `src/test/rows` makes whole API rows,
  `src/test/browser` a browser that keeps nothing (`blockStorage()`). Time is faked (`vi.useFakeTimers()`,
  `advanceTimersByTimeAsync`) — nothing waits for real.
- Models: explicit `$fillable`, `$casts`, `@property` / `@property-read` docblocks (PHPStan relies on them).
  Use static query helpers (`Plan::active()`, `Order::sold()`, `Subscription::active()`) instead of `scopeX` magic;
  enums by value in `where()`, never a status string. Forwarded builder methods
  (`orderBy`, `whereIn`, `limit`, `count`, ...) are typed by our PHPStan extension in phpstan/src — extend its
  lists if PHPStan complains about a new one. Relations and helpers exist only with a caller (see the next point).
  **No query per row**: a list loads what its rows show with it (`with()`, `withCount()`, an `addSelect()` subquery —
  `User::balanceColumn()`), a presenter that reads a count of a row read on its own loads it first (`loadCount()`), a
  child that points back at its parent is given it (`chaperone()` — an order's payments), and work over many rows names
  its relations once (`OrderService::DELIVERY_RELATIONS`). `Model::preventLazyLoading()` is on everywhere: the test suite
  throws on a relation read row by row off a list and on reading an attribute the query did not select
  (`preventAccessingMissingAttributes()`, tests only); live, a lazy load is logged once a process per relation
  (`DatabaseManager::boot()`) and the rows still load — a customer never meets it. What judging a row asks of its shop
  (the wallet on, an agent's traffic) is asked once for a list: a bulk form beside the one-row rule (`offeredForAll()`,
  `plansFor()`, `coverage()`, `offers()`) and a page presented together (`Page::fetchTogether()`). `ListQueriesTest` reads every list
  and count of the owner's panel — and the Store API's reads, the catalogue and a signed-in customer's own — with a few rows and with many and fails when the second asks more; `BotQueryBudgetTest`
  is each common bot screen's budget in queries and Telegram calls (an update: its id claimed, the customer and the chat a
  query each, nothing written unless changed; the settings none — read once for every text, keyboard and rule) — a
  budget raised says what the new query buys.
- **Nothing without a caller, held by the analysis**: `composer analyse` runs shipmonk/dead-code-detector (phpstan.neon)
  over all the PHP — a method, constant, enum case or property nothing uses (or never reads, or never writes) fails it,
  and so does what only the tests use (its tests excluder: a test is no caller — the code goes, and its tests with it).
  What the framework calls by name the detector cannot see, so phpstan/src/DeadCode tells it: `ContainerUsageProvider`
  (a constructor PHP-DI calls — a class named, `X::class`: a definition, a route's, a task's, a handler's or a command's
  registration —, or injected into a constructor or a top-level closure), `SlimRouteUsageProvider` (a route's action,
  `[Controller::class, 'action']` or an invokable) and `ModelUsageProvider` (Eloquent's boot hooks — `boot<Trait>` —, and
  a relation read as a property or named in `with()`, `withCount()`, `whereHas()`… or in a list a model hands them —
  `Bot::statCounts()`; the detector's own Eloquent support is off, since it counts every relation as used). A false
  report is taught to a provider, never ignored: phpstan.neon's ignore list is the state the suite puts back between two
  tests (`CurrentBot::reset()`, `RequestId::reset()`, `ChangeFeed::countAsCommitted()`, `ProviderRegistry::flush()` /
  `unregister()`) and nothing else. Not caught: an optional parameter no caller passes — it goes with the call that
  stopped passing it.
- Secrets at rest (panel passwords, tokens, an agent's bot's webhook secret, the report group's connect code, a
  customer's two-factor secret) use the `Encrypted` cast on their model's column, and a driver's settings with a secret
  among them (a website's captcha) `Core\Database\Casts\EncryptedArray` (JSON encrypted, through `Encrypted`); the
  settings table holds no secret (a row that no longer decrypts — an `APP_KEY` change — reads as null with a logged
  warning, never a fatal). What is too short to keep as a bare hash — an emailed code, a two-factor recovery code — is
  kept as its keyed hash, `Core\Security\Encrypter::mac(text, purpose?)` (HMAC-SHA256 under a key derived from APP_KEY
  for its purpose, never the encryption key; another use, another purpose — ALTCHA's signed challenges), one way.
- **One field engine for every settings group, and every driver's form** (`app/Core/Forms` — see Drivers): a `Form`
  (its key — `PUT …/{group}` — and its fields) is a card of a screen, saved at once. A field (`Core\Forms\Fields\*`:
  `Toggle`, `Number` — a whole number between bounds, its unit after them, typed with its thousands set apart or not
  (`Input::wholeOf()`) —, `Amount` — whole Toman, Money —, `Numbers` — a short list typed as text (`Input::numbersOf()`:
  «50,000, 100,000» is two) or sent as a list, without repeats, smallest first, at most so many, `atLeast` another
  field —, `Text` — required or not, a line, a path or a few lines —, `EmailAddress` — App\Support\Email's rule, kept in
  lower case, required or not —, `Url`, `Choice`, `Secret`) has
  the name the screen uses, the key it is kept under and its default; it checks a form's value (`read()`, a
  `FieldRefused` in the one wording: «تعداد روز باید عددی بین 1 تا 30 باشد.», `Validation::NOT_A_SWITCH`,
  `Validation::tooLong()`) and reads a kept one back (`cast()`: one it would refuse — written by hand or under another
  rule — is its default). `Form::check()` refuses a form as a whole, every field's refusal at once, before anything is
  kept (a field that `when` hides neither read nor refused — a field the group's other values ask for is `required` with
  a `when`: an SMTP server while smtp is the way out); `present()` is the group as the screen shows it (a secret as
  `{set, hint}`); `only()` narrows a group for a step that sets part of it. Where values are kept is the caller's: **two kinds of settings, by decision**. The
  panel's own configuration (bot token/username, database, APP_*, the panel's login, the shop's email, log/session/http
  timeout/cron token) lives in **config.php** only — beside the app, as WordPress keeps wp-config.php; never in the database, never
  the environment (see Configuration) — edited from the owner's «تنظیمات پنل» screen through
  `Settings\Services\ConfigSettings` (`/settings/config…`; `update(group, input, request)`): the groups `app` (`APP_URL`
  the kernel's `Url`: no credentials, `?` or `#`), `telegram` (the main bot's token a `Secret` bound to the Bot API's
  address, by its origin — `TOKEN_MOVED`, which the panel's Telegram card says under the token before the save while the
  address moved and none is typed (`originOf()`) —; and an address moved to another origin, where every bot's token —
  agents' too — goes from then on, asks the owner's `current_password` with the form's other refusals
  (`movesBotApi()`, `AdminAccount::confirm()`: a wrong one a failed sign-in of the owner's), which the card shows,
  «رمز عبور فعلی پنل», and sends only while the origin moves; its test asks the saved address alone) and `advanced`
  declared there (a setting the file lacks shows what the app runs with: the field's default is config/*.php's value),
  `database` `DatabaseSettings`' (its fields the drivers', probed before anything is written — see Database drivers),
  `mail` `MailSettings`' (a mail driver's form and who the emails come from — see Mail); the cron
  trigger shows masked like its token (`meta.cron_url`), whole only from `GET /settings/config/cron-url` for the screen's
  copy action. Adding a setting = its entry in `Core\Config\ConfigKeys::SECTIONS` (section, default — its type the
  setting's —, the words the file says about it), its reading in config/*.php (`ConfigValues`), and, when the screen
  edits it, a field in its group in `ConfigSettings` and one in the panel's section. The shop's runtime settings
  (every bot its own) go to the `settings` table: `Settings` (`get/set/forget`, a bot's rows read once a process,
  `refresh()`; no fallback — a key nobody wrote is the reader's default, a database that fails surfaces) with
  `read(field)` (a field's value, typed: `@template` — `$this->settings->read($this->enabled)` is a bool), `present(group)`
  and `save(group, input)` (all of it or nothing, in one transaction). A module declares its own groups and typed
  getters (`DeclaresSettings`, a `list<Form>`: `BotSettings` general/channels/qr, `Users\Services\WalletSettings` wallet, `RenewalSettings` renewal/auto_renew, `ReminderSettings` reminders,
  `ReferralSettings` referral, `ReportSettings` reports) and registers them on the bot
  settings screen in bootstrap/container.php (`Services\BotSettingsScreen`, which knows no module); `AgencySettings` is
  the shop's, saved in the main bot's rows from its own endpoint. Runtime *state* is not a setting: the report group's
  lives in `report_chats` (`Telegram\Reports\ReportGroupState`, read fresh); `Telegram\BotState` (webhook URL, poll
  heartbeat) still uses the table.
- Money is Toman, the shop's only currency (no currency column, setting or code anywhere): `decimal(14,2)` strings,
  always through `App\Support\Money` (bcmath on normalised strings: normalize/add/subtract/compare and `format()` →
  "۱۲۰٬۰۰۰ تومان") on the PHP side and `formatMoney()` in the admin, never raw `number_format`/`bccomp`. Traffic is
  bytes (int): `App\Support\Traffic` is the one GB→bytes conversion (`Plan::trafficBytes()`, a grant's traffic),
  `Messages::bytes()`/`traffic()` the wording.
  Persian digits and Jalali dates on the PHP side come from `App\Support\Persian`.
- JSON responses always go through `App\Core\Http\Json` (controllers, middleware, error handler) — one encoder (a
  byte that is not UTF-8 becomes U+FFFD; anything else it cannot encode throws), one error shape: `{message, errors?,
  request_id}` for every failure (the request's id, `Http\RequestId`), plus a top-level `debug: {exception, detail, trace}`
  only for a failure of the server's, with `APP_DEBUG`, to a request straight from this machine (see Production), through
  `Redact`.
  `Http\ErrorHandler` is the one place a failure becomes that shape: a `Core\Exceptions\DomainRuleException` — the
  shop said no, because…: its message in the user's words, `status()` (422 refused in this state, the default; 409
  other rows hold on to it — PlanInUse, ServerInUse, LevelInUse, BuiltinMethod, MethodInUse; 413 too large to take —
  `TooLargeException`; 429 too many tries, its wait in `Retry-After` too — `TooManyAttemptsException`; 502 a panel failed —
  MoveException's previous/target), `field()` for the one request field it is about (OrderNotPayable on
  `status`, NoServerAvailable on `server_id`), `errors()` — is answered as it is, and
  `ValidationException` is one (its refusals per field, private and read through `errors()`; `ValidationException::on(field,
  message)` when one field is refused, its message the answer's too; `ifAny(errors)` throws only when there are some); Eloquent's `ModelNotFoundException` is a 404 (`ErrorHandler::NOT_FOUND`); anything
  else a 500, logged once with the exception in context (refusals and missing rows are answers, never logged). So an
  action is load → service → present and catches only what it words its own way: `ApiController::load(Model::class,
  $args, with)` is the row the route names in the current shop (BelongsToBot makes another shop's row a missing one),
  `switch($request, field)` an on/off read strictly (anything but a boolean is a 422 `Validation::NOT_A_SWITCH`, never
  "off"), `bytes($response, body, mime, cache, ?filename)` a file answered with its length, its caching and an RFC 5987
  name — shown as itself when it is a picture the panels show (or a sticker's video), handed over as
  `application/octet-stream` + `attachment` otherwise (the sandboxing policy is on every answer).
- Tests log to `storage/logs/tests.log` (`LOG_FILE` in `TestCase::CONFIG`); the dev log stays the app's own. The suite
  runs in random order, strict about deprecations/notices/output, on a pinned config.php of its own
  (`TestCase::CONFIG`, read at boot and gone at once — this machine's config.php is never read), UTC, with
  PHPStan level 6 over all the PHP there is (app, tests, bin/console, bootstrap, config, database, routes, scripts,
  public/index.php) — the code style's finder covers the same. phpunit.xml pins `COLUMNS` and `LINES` too, so a command's output is laid
  out the same on every terminal and the console is never asked its size; a command under test takes its answers from
  `CommandTester::setInputs()`, never the process's stdin.
  Fixtures: every Feature test builds its rows with the `Tests\Support\Fixtures` trait on `DatabaseTestCase`
  (`customer()`, `webCustomer()` (a customer of the website alone: WEB_EMAIL and WEB_PASSWORD, no Telegram, a cheap
  hash of old), `admin()`, `fakeServer()`, `sellingServer()` (a server the shop sells on today: the fake panel, links,
  an inbound), `panelServer()`, `inbound()`, `plan()`, `planEntry()`, `category()`,
  `cardMethod()`, `walletMethod()`, `purchaseOrder()`, `topUpOrder()`, `cardPayment()`, `receipt()`,
  `subscription()`, `channel()`, `inlineStartMenu()`, `buy()` (paid from the wallet), `renew(subscription, plan?)` (a
  wallet-paid renewal, delivered), `paidByCard()` (a receipt sent and
  approved, as the payments screen settles one), `reportGroup()` (the report group connected, every topic made — threads
  from `FIRST_THREAD`), `referralProgram(enabled, firstOnly, rate)`, `agencyProgram()` (the agency program on — or off —, its default credit, traffic offered as
  50/100 GB, 10 at least), `agencyLevel()` (3,000 Toman a GB), `agent()` (a customer of the main bot on a level — the first
  there is —, with a credit and their shop open; switches the program on when it is off), `agentBot(agent, traffic,
  overrides)` (an agent's bot handed over, `FakeTelegram::AGENT_TOKEN`, @agent_shop_bot, `traffic` GB),
  `agencyRequest()`, `customerGroup(name, members)`, `website()`, `customerSession(user)`, `twoFactorOn(website, user)`
  (see Store API), `ticket(user, subject, body)` (a support ticket with its first message — see Tickets),
  `ticketMessages(ticket, count)` (a long conversation) and `review(writer, name, rating, body)` (a review written on the
  website, a guest's for a null writer, waiting on support — see Reviews) — a row's maker takes `array $overrides`, merged last, where a test
  varies the row —, the two balances,
  which are ledgers: `wallet(user, balance)` (one line bringing the wallet there, a debt included) and `traffic(bot, gb)`,
  and who decides what a test calls straight on a service: `panelActor(name = 'root', kind = Owner)` (a panel's
  principal's `Auth\Actor` in the current shop — an agent's by their bot, `'@agent_shop_bot'`, `PrincipalKind::Agent`)
  and `groupAdminActor()` (the shop's admin @boss deciding in the report group, `Actor::groupAdmin()`, made the first time);
  a fixture builds in the
  current bot's shop (`CurrentBot::run($bot, fn() => $this->plan(...))` for an agent's); never
  `Model::create([...])` by hand in a test.
  Test doubles swap a leaf, never what was built around one — the container is one per process, and nothing is
  listed for rebuilding: the app boots once with every file it writes in a folder of the run's own (emptied after each
  test, gone with the process — never storage/, config/ or the real config.php; `TestCase::FILES`), its transports answering
  "no network" (a ConnectException, as an unreachable host) and `Core\Support\Sleeper` a `Tests\Fakes\NullSleeper`
  (nothing sleeps; `Tests\Fakes\RecordingSleeper` remembers the waits a test asserts). Every test leaves the app's event
  listeners as it found them — the models' own hooks (BelongsToBot's) are registered once a process, so a `forget()`
  by hand would take them from every later test; `DatabaseTestCase` fails a test that changed them. Another process's
  move at the moment it matters is heard through `whileListening(event, listener, work)` (a model's
  `'eloquent.retrieved: …'`, the connection's `QueryExecuted`; `whileTheOrderClosesOnceRead(work)` is the settlement's
  race), and a scheduler turn whose time is spent is `withTheTurnOver(work)`. `TestCase::telegram()` is the fake Telegram (`tests/Support/FakeTelegram`): the transport of the Bot
  API and of bot:poll's long polls for the test, and the main bot's token `FakeTelegram::TOKEN` (`BOT_ID` its id); it
  records every call (`calls()`, `params(i)`, `files(i)`, `history`, `tokenOf(i)` — which bot spoke —, `sentTo(chat)` —
  what a chat was told —, `replyTarget(i)`, `markup(i)` / `markupOf(params)` — its reply markup decoded), answers with a plain success unless the test queued something
  (`reply(...)`, `fail(code, description)`, `raw(Response)`; answers are positional; `on(method, closure)` answers one
  method from its parameters and the token that asked, ahead of the queue — a thread per createForumTopic, each bot its
  own updates —; `FakeTelegram::error(code, description, parameters)` is a refusal under its real HTTP status,
  `flood(seconds)` a 429); `api()` is a client of its own (BotApiTest). BotTestCase's updates carry ids from a
  per-process counter (the bot serves each id once). `TestCase::panelHttp()`
  is the fake 3x-ui panel (`tests/Support/FakePanel`: the outgoing client's transport, with `ok()`, `fail()`, `raw()`,
  `refuse()`, `calls()`, `request(i)`, `params(i)`, `options(i)` (how it was sent: TLS, timeouts, redirects),
  `FakePanel::success(obj)` and the named scenarios
  `healthyPanel()`/`inboundList()`; `client()` a client of its own) for the connector, servers-API and settings-probe
  tests; `fakePanel()` the fake connector for everything above one (`FakePanelDriver`, its client `FakeProvider`); `telegramLogin()` Telegram's
  sign-in service on the same transport (`tests/Support/FakeTelegramLogin`, see Store API), `googleLogin()` Google's
  (`FakeGoogleLogin` — both sign with `SigningKey`, an RSA key made once a process) and `turnstile()` Cloudflare's captcha
  (`FakeTurnstile`; ALTCHA's widget is `Tests\Support\AltchaWidget` — see Captcha); `mail()` the shop's email set up (see
  Mail). Besides: `config([...])`
  (configuration for the test, put back), `logs()` (a Monolog TestHandler on the app's logger), `configFile(settings)` (the
  config.php the app reads and writes, holding those settings alone), `probedDatabase(key, installable)` (a driver whose probe the test answers —
  `Tests\Fakes\ProbedDriver`), `scratchDir()` (a folder of the test's own), `imageOf(bytes)` (a picture's width, height
  and type — an upload as the shop kept it), `fileCacheExpired()` (what `FileCache` keeps, as it is once its time is
  over), and `swap($id, $instance, ...$rebuild)` for a
  one-off double whose holders the test lists itself. `Fixtures::TELEGRAM_ID` (= `BotTestCase::CHAT`) and
  `Fixtures::RECEIPT_MESSAGE` are the ids every fixture and bot test share. Bot flows extend `Tests\BotTestCase`: `send($this->message(...)|tap(...)|contact(...))`
  drives the container's dispatcher (routes from routes/bot.php) and forgets the previous step's calls first, so
  `calls()`/`params()` describe the last thing the customer did; `said()` is every text or caption the step wrote, in
  order — what the customer read, whichever call carried it —, `inlineKeyboard(i)`/`callbacks(i)`/`replyKeyboard(i)`/`markup(i)`
  read a call's buttons, `buttonsUnder(message)` what a message's buttons became, `popup()` the answer to a tap, and the report group has its updates (`groupTap()`, `groupText()`,
  `groupReply()`, `botMembership()`; `GROUP_ADMIN`/`GROUP_MEMBER`). HTTP tests use `HttpTestCase::json(method, path,
  body)` / `postJson` / `putJson` / `patchJson` / `deleteJson` (the CSRF header is theirs), `send()` only to prove a
  request without it is refused — or for a header of the request's own (a website's `Idempotency-Key`) —, `upload()` for
  multipart (the form's other fields too, and a website customer's bearer token) — and every request they send and every answer they get back
  is checked against resources/api/openapi.yaml (`apiDescription()`, read once a run — the website's admins' paths added,
  `Tests\Support\ApiDescription`; see API contract), so a test that
  sends a new field or reaches a new answer shape fails until the description has it; `unchecked()` lets one request no
  panel sends through, for a test of its refusal. The shop counts as installed (`installed(false)` plays a fresh
  one), and `loginAsAdmin()` opens the owner's session directly — signing in over HTTP is the auth tests' business —;
  `openShop($bot)` makes the requests that follow name an agent's shop (`X-Shop`), as `bearer($token)` makes them carry a
  website customer's token; `loginAsStaff($website, $user)` signs one of the shop's admins in on its website as its admin
  API takes them (the customer made an admin, the website letting its admins in, a session signed in with Telegram — a
  strong sign-in — a moment ago, its token on the requests that follow; what the website grants them is the test's).
- Admin lists that page read the request once, in the controller: `Core\Database\PageRequest::fromQuery($query)` —
  `page` (1 and up), `term` (the search box, `Page::term()`: trimmed, Latin digits, 64 at most), `number()` (the term as
  a row number, `12` or `#12`), `row()` (`#12` alone: the row numbered 12 and nothing else — every screen's link to a
  row, `searchLink()`), `search(query, applySearch, numbered?)` — the one rule every list that numbers its rows keeps
  (users, orders, payments, subscriptions, the referral commissions — numbered by their payments, `numbered`): «#12» the
  row alone, any other term the directory's own search, handed the term and `number()` to match among its fields (a
  bare 12 finds the row 12 and whatever else holds a 12) —, and each filter as what it may be: `enum(name,
  Enum::class)` (a string-backed case or no filter), `id(name)` (a row's id or none), `text(name)`, `dates()` (`from`/`to`,
  days of the shop's calendar, both included, either alone — a `Core\Database\DateRange` whose `apply(query, column)`
  bounds a moment column from the shop's midnight starting the first day to the one ending the last, in UTC, the
  dashboard's way; anything that is no date is no bound) — and the order, `sort(keys, default)`: `sort` one of the
  list's keys (else its own, `default`), `dir` asc|desc (else desc), each key mapped by the directory to what it orders
  by — a column, an alias the rows read (`orders_count`), SQL of its own (`User::balanceOrder()`: no ledger is 0; an end
  with "never" after every deadline) or a subquery (none is 0) —, never a name from the request. The directory answers
  one `Page` — `Page::fetch(query, request, present, sort)`: the rows of the page asked for (clamped to the last) in the
  `Core\Database\Sort`'s order with the row's id after it in the same direction (ties keep one place from page to page,
  the other direction is the exact reverse) — `Page::fetchTogether()` hands `present` the page's rows at once, for a list
  whose rows are judged together (a website customer's services) —, `meta()` = `{page, per_page, total, last_page}`, a sorted list's
  `{sort, dir}`, then the list's own figures (`with(['stuck' => n])`; `Page::tally(query, column)` is a list's takings —
  how many rows and the money they add up to, `[n, amount]`), `Page::empty()` for none — and the controller
  names its rows: `->toArray('orders')`. Sorted (a column's header): users, orders, payments, subscriptions, the three
  referral lists, the agents and the agency requests; a ledger (wallet, traffic) and the broadcast runs stay
  chronological, and an admin-ordered list keeps its ▲▼ (`Sorting`).
  `Page::whereContains()`/`orWhereContains()` is a LIKE on a search term — its `%`/`_` escaped with `!` and an explicit
  `ESCAPE '!'`, since a backslash escapes nothing in SQLite nor in MySQL under NO_BACKSLASH_ESCAPES, and client names
  are full of underscores. **What a search costs**: a LIKE '%…%' reads every row of the searched table in the shop (no
  index helps a leading %), so a list searches another table once — `Page::matches(keys)` reads what the term finds up
  to `MATCHES_LISTED` (200) and hands the list their ids, which its own index takes (`User::idsMatching()`: the
  customers; orders also by plans and services), or the subquery itself for a broader term — never once per row or per
  query of the page, its count and its figures. A list of a few hundred rows (the agency's requests) takes the subquery
  alone (`idsMatching($term, listed: false)`). The lists are consumed by `lib/use-paged-list` (`usePagedList({queryKey, read, list,
  statuses, params, choices, sorts, address})`: one plain view object — the search once typing pauses (300 ms; `typed` is the
  box's own text), the status tab, the other filters (`choices`: the values one may take where they are a closed set, an
  enum's — the orders' `type`), the order (`sorts`: the API's keys, typed by its generated enum,
  the list's own first, read descending; the server is told nothing of the own order), the page — another tab, filter or
  order, or a settled search, goes back to page 1 —, `keepPreviousData`, `refetch()`, `filtered` (a search, a tab or a
  filter narrows it) and `narrowed` (a search or a filter, the tab aside: an empty tab is no search that found nothing). **The view is in the list's
  address** while the address bar shows `address` (only the list of the section on screen, then): the query string holds
  what differs from the list's own — `search`, `status` (empty for «همه» when the list starts on another tab), `params`,
  `sort` + `dir`, `page` —, a tab, a filter, an order or a page a history entry each and the settled search in place of
  its last, so a reload, back and forward and a shared link keep the view; an address that changes under the list — back,
  forward, a link carrying `?status=`, `?search=#id`, one of `params` or `?sort=`(`&dir=`) — is the whole view, what it
  names that the list does not have (a status it has no tab for, a value outside `choices`, an order it does not sort by,
  `?page=1`) left out of the view and of the address, put right in place (no history entry of its own), and a
  section shown again at its bare address keeps the view it had, which the address takes in place. A hidden section's
  list reads with `subscribed: false` (`useSectionShown`). An operation's answer goes into the cached pages alone —
  `replace(row)`; `remove(id)` takes the row and one from the count — and the live updates read the list again; a later
  page whose last rows were removed steps back a page), drawn by `ListView` with `components/search-box` and the filter
  pills above it and `SortableHead` on the columns it sorts by.
- **Upgrades from the first release on** (before it there were no migrations: no installation existed to upgrade).
  Every change of database/schema.php ships the same change as database/upgrades/<version>.php (see Updates: database
  upgrades). The schema is database/schema.php:
  every table as a Blueprint closure, in the order they are made (a foreign key only points at a table above it —
  `SchemaTest` checks, since MySQL refuses one to a table not made yet and SQLite would not notice), plus the rows a new
  shop starts with (the wallet). `Core\Database\Schema` applies it: `create()` makes what a database lacks (the web
  installer, the tests' `DatabaseTestCase`), `rebuild()` (`php bin/console db:rebuild`) follows an edit —
  every table moved aside as `<table>__old` (its driver's `releaseNames()` first), made again, its rows copied back by column name (a new column takes its
  default, so a NOT NULL one needs one; a dropped one goes with its values), the asides dropped; a row the new shape will
  not take stops it with the database's words and the old rows kept, and the next run, the schema fixed, finishes it.
  A schema change = edit the file, `db:rebuild` the local database (stop the bot first: its requests fail meanwhile), and
  write its upgrade for the shops installed. Keep both to Laravel's schema builder, portable across the drivers (the
  tests run it on SQLite). Speculative columns and indexes are not kept "for later" (the `meta`/`links` JSON columns, the referral columns,
  the gateway-reference index went that way) — add a column with the feature that reads it. **An index answers a query
  that was measured** (EXPLAIN on a shop of 50,000 customers and 150,000 orders and payments): the comment above it names
  the query; the shop's own column first where the shop scope reads (`bot_id`, or after the column a list narrows by); a
  tally's columns at its end so it is counted from the index alone (`orders`: status, type, amount; `payments`' takings:
  the method, the amount, the order); a MySQL index name is 64 characters at most — a longer column list names its own
  (`payments_takings_index`); and a foreign key needs no index of its own once one starts with its column.
- Runtime requirements are one list, `App\Support\Requirements::check()` (the PDO extension of the database driver the
  shop runs on — pdo_mysql —, curl, mbstring, openssl, bcmath, fileinfo, `memory_limit`, and storage/ and config.php (or
  the folder it goes in) writable; gd is optional — the QR card —, and so are sodium and zip — the panel's own update,
  listed `optional`), each with its name and its Persian label (the driver's extensions worded with the driver's name),
  shown by the web installer; composer.json declares the same extensions (the optional ones as `suggest`).
- Route group closures must NOT be `static` (Slim binds them to the container).
- **Persian only, no i18n layer.** Everything a user sees (bot, panel, installer, API error messages) is Persian;
  code, comments, CLI output and docs stay English. Everything the bot says to a customer is a `Telegram\Texts\BotText`
  the admin may reword (see Bot texts; HTML parse mode — escape user input); `Telegram\Messages` keeps only the words
  the formatters build values from, the main menu's default labels and /broadcast's (admins only); the domain
  contributes only its ledger/note constants. Admin strings are inline in the React components; PHP error texts in
  `ErrorHandler::MESSAGES`.
  Write Persian plainly, without Arabic diacritics: no tanwin/harakat/shadda (`لطفا`, `مثلا`, `بعدا`, not `لطفاً`), no hamza-ezafe
  (`نسخه ۳`, `شناسه تلگرام`, not `نسخهٔ`) and `تایید` rather than `تأیید`. ZWNJ (‌) in compounds like `می‌شود`, `به‌روزرسانی` stays.
  Emoji: one form per glyph across the text catalog and `Messages` (the VS16 form where one exists — ⛔️ 🛍️ 🗜️ ⚠️ ⚙️), `⬅️` for every
  back button, `🗜️ حجم سرویس` for every quota line.
  Write it the way a Persian developer talks, not the way a dictionary translates: technical terms keep their Latin
  form (`حالت Debug`, `Webhook`, `Session`, `Cron`, `Scheduler`, `CPU`, `RAM`, `build`) or the transliteration everyone
  uses (`دیتابیس`, `توکن`, `لاگ`, `کوکی`, `کانفیگ`, `اینباند`, `تایم‌اوت`, `ریست`, `تست`, `لیست`, `سایدبار`, `تم`); never
  the formal calques (`اشکال‌زدایی`, `پایگاه داده`, `وب‌هوک`, `نشست`, `نویسه`, `پوسته`, `نوار کناری`, `فهرست`, `آزمایش`,
  `پیکربندی`, `بازنشانی`).
- Numbers/dates via `lib/format.ts` (fa-IR digits, Jalali via Intl in the shop's zone — `setShopTimeZone()`, from
  `/api/app`; `shownTimeZone()` names the zone in use, the browser's until then —, an amount of traffic in its largest
  unit, `formatBytes()` — none is «۰ گیگابایت», the unit traffic is counted in —, «۳ روز قبل» and «۵ ساعت» in their
  largest unit, `digitsOnly()` for a typed whole number — a card's digits, a page, minutes —, and for a
  typed amount, Toman or GB, `decimalOf()` — Persian or Arabic digits, «٬» «,» or spaces between thousands (only between
  groups of three, as Input::decimalOf() reads them: «2,5» is no 25), «٫» «.» or «/» for the point, as Input::decimal
  takes it, «۱٫۵» → "1.5", null for what is no such number (`BalanceAdjust` refuses
  it in its words) — and `decimalInput()`, what a request carries of one (else as typed, for the API to refuse); a whole
  number or a list of them an admin types into a setting, `wholeOf()` / `wholesOf()` — as Input::wholeOf() and
  numbersOf() read them (a settings card's hints say what its save will take); never
  `digitsOnly()` on an amount, which reads «1.5» as 15).
  Persian digits are for quantities, money and dates only. Identifiers keep Latin digits — ports, HTTP status codes,
  ids (`#12`, Telegram ids), version numbers, IPs/hosts/URLs, charsets, and the guidance for numeric inputs
  (placeholders, "بین 5 تا 120") — rendered as-is (in `dir="ltr"` when mixed with Persian text), never through
  `formatNumber`/`Persian::digits`.
  Give such Latin-only content `dir="ltr"` (inputs, badges, inline spans/bdi, a line of code): index.css nudges it
  down a pixel because Vazirmatn's Latin glyphs sit ~0.1em higher than its Persian ones, so metric centring alone
  leaves Latin text looking high (`<bdi dir="ltr">` for a username, a port, a reviewer; a `<code dir="ltr">` sits
  inside the box that draws its border, which the nudge would move; a key cap, `Kbd`, lowers its letters by its own
  padding). Latin labels inside components (a PageTabs option, a badge) are wrapped in `<span dir="ltr">` for the
  same reason.
  A plain string has no markup to isolate a run in, and a Persian sentence hands a leading «@» to a Latin run's far side
  («agent_bot@») — a toast, a dialog's title or description, a confirm's sentence, an `aria-label`, the tab's name: such a
  run goes through `lib/direction`'s `isolate()` (Unicode's isolates, U+2068…U+2069 — what `<bdi>` is in markup):
  `handleLabel(username)` «@amir», `idLabel(id)` «#12», `userLabel()`/`distinctUserLabel()` — never a bare `` `@${…}` ``
  or `` `#${…}` `` in a string shown to the admin (a URL's `?search=#12` is data, not text); a sentence that names markup
  — a bot text's problem, `<b>`, `&lt;`, `href="…"` — through `isolateMarkup()`, each run apart. In markup, `<bdi>` — an
  account's name (an agent signs in as their «@bot»: the account row, the greeting), `<bdi dir="ltr">` for a handle or a
  number.
  LTR fields (`input[dir="ltr"]`, textareas, the input inside `SecretInput`) are also **right-aligned** by index.css:
  the field stays LTR so the caret and selection behave, but the value sits under its label instead of hugging the
  far edge of the box. Do not add `placeholder:text-start`/`text-left` to them; selects keep the page direction.
  **One typeface, by decision: Vazirmatn** — every word of both panels and the installer, Persian, Latin and identifiers
  alike (tokens, URLs, ports, card numbers, the voiced titles, the Telegram preview's code). Self-hosted under
  resources/panel/src/fonts/vazirmatn (the Google Fonts woff2 subsets + its .css with the @font-face rules, vendored as
  downloaded — Prettier leaves them alone; no runtime request to Google — must work from Iran); src/root.tsx imports
  the CSS and Vite bundles the files. No other family: index.css resets Tailwind's (`--font-*: initial`, so
  `font-mono`/`font-serif` draw nothing) and gives `code`, `kbd`, `samp`, `pre` the page's face, which Tailwind's
  preflight would hand a system monospace. RTL specifics are under Gotchas.
- Only the panels' API opens a session (`SessionMiddleware` on the `/api/admin` and `/api/agent` groups); every
  state-changing call to `/api/admin`, `/api/agent` and `/api/install` needs the `X-Requested-With` header
  (`JsonCsrfMiddleware` on those groups — a browser cannot send it cross-site: CORS opens the websites' API alone). PHP
  serves no page, so there is no form-based CSRF to guard. The Store API takes neither: a customer's bearer token, which
  no other site's page can send.

## Gotchas

- Windows: `dirname('/x')` returns `\` — normalise slashes (see `Http\BasePath`). PHP's built-in
  server reports the *request path* as SCRIPT_NAME, so base-path auto-detection only trusts paths ending in /index.php.
- RTL: `<html dir="rtl">`, Radix `Direction.Provider`, logical Tailwind classes in our components, `rtl:rotate-180`
  on directional icons, charts wrapped in `dir="ltr"`.
- `now()` helper is ours (`app/Support/helpers.php`); illuminate/support alone does not ship it.
- Linux is not Windows for pictures: Linux's PHP packages (Debian's, Ubuntu's, the builds CI runs) link the system's
  libgd, where Windows' PHP bundles its own, and `finfo`'s answers move with the libmagic a PHP carries (5.40, 5.43 and
  5.45 in 8.2, 8.3 and 8.4). GD's `imagescale()` is never called — libgd 2.3.3's bicubic scaler reads a row that is not there and ends the process (a
  picture 2050 by 2 is enough); `Core\Support\Picture::fitted()` draws with `imagecopyresampled()` — and a test's
  damaged picture starts as every libmagic reads its type (a JPEG's bare start is text to some). A crash only Linux CI
  shows is found on CI itself: valgrind with `USE_ZEND_ALLOC=0` (and `-d pcre.jit=0`, or PCRE's JIT floods the report),
  gdb's backtrace, and `--log-events-text` for the test it was in.
- The Bash tool truncates very long heredoc commands (~8KB) and collapses `\\` — use the Write tool for big files.
- `bot:poll` is a long-lived process: its `Poller` calls `Settings::refresh()` every `Poller::SETTINGS_SECONDS` (2 s —
  not every batch, which would read every shop's settings again for each) so admin changes to the settings table
  (keyboard layout, support contact, gateway rows are their own table) reach the bot without a restart.
  Any other long-lived reader of `Settings` must do the same. config.php is read once per process: a change to it (the
  main bot's token) restarts the `--watch` worker, and a poller run without `--watch` needs a restart.
- Stopping a background Bash task on Windows kills only the shell; the php.exe child keeps running with OLD code
  (stale pollers caused 409 Conflicts and English replies). Find and kill them with PowerShell:
  `Get-CimInstance Win32_Process | ? { $_.CommandLine -like "*bot:poll*" }` then `Stop-Process -Id`. Always run the
  bot via `bot:poll --watch` in development; it holds storage/cache/bot-poll.lock (the container's `poller.lock`) so a
  second poller refuses to start, and its supervisor reloads the worker on any change of the watched files —
  `BotPollCommand::fingerprint()`: every file's name, size and modification time, so an edit, a file added or deleted
  and a rollback to an older copy each count.
  A background Bash task is also stopped after its time limit, taking a long-lived process with it — start MySQL and
  the poller that must outlive a session with PowerShell's `Start-Process -WindowStyle Hidden` (output redirected to
  files) instead.
