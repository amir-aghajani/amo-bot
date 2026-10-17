[فارسی](fa/Internals-Panels.md)

# Internals: panels

The owner's panel (`/admin/`) and an agent's panel (`/agent/`) are one React project in `resources/panel`: Vite,
TypeScript, Tailwind 4, shadcn/ui on Radix, react-query and react-router. They are built to static files and talk to
PHP only through the JSON API. This page is the map of the shared foundation — the build, the data layer, failures,
the shell and the component kit — then of the screens built on it ([The screens](#the-screens)). What the panels look
like to an owner is in [The panels](Panels.md); the API they call is described in [API contract](API-Contract.md).

## The source tree

```
resources/panel/
  admin/index.html, agent/index.html   each panel's page (entry: src/apps/<panel>/main.tsx)
  src/
    root.tsx        mount(routes): providers, router, error net — what both panels stand on
    apps/admin/     the owner's routes (app.tsx), menu (nav.ts), shell config, sign-in, owner-only pages
    apps/agent/     an agent's routes, menu, sign-in and account page
    pages/          the shop's pages both panels have, each its own chunk (pages/lazy.ts)
    components/     the generic kit at the top level; domain folders (plans/, orders/…) beside it
    components/shell/  sidebar, rail, drawer, topbar, the nav tree
    components/ui/  shadcn primitives, restyled
    lib/            the data layer, failures, formatting, session, small hooks
    test/           the test server, the API-contract checker, render helpers, row fixtures
    index.css       design tokens and the few global rules
```

Open first: `root.tsx`, `apps/admin/app.tsx`, `lib/api.ts`, `lib/query-client.ts`, `lib/use-form.ts`,
`components/list-view.tsx` and `components/shell/nav.ts`.

## Two panels, one build

The two panels are two apps rather than one app hiding things by role — a deliberate choice: each app owns its route
table (`ADMIN_ROUTES`, `AGENT_ROUTES`), its menu (`nav.ts`) and its shell config (`PanelShell`: the menu, the account
row's role word, the owner's shop picker). Shared code never checks a role; where the panels differ, the app passes the
difference in as props (`SubscriptionsPage({servers, serverPage})`, `BroadcastsPage({more})`). The server is what keeps
the panels apart: an agent's page cannot call the owner's API.

One `vite build` (`vite.config.ts`) has both pages as inputs and writes `public/admin/index.html`,
`public/agent/index.html` and the hashed files they share in `public/assets/`. The chunks are split by how often they
change — `react` and `vendor` (only a dependency changes them, so browsers keep them across upgrades), `app` (the
shared first-load code), `common` (what three or more pages share) and one per page — and an agent's page never
references the owner's own code. The build also writes each page's Content-Security-Policy (the inline script is
allowed by its hash), a brotli copy beside every script and stylesheet, and `assets/.htaccess`, which serves those
copies as they are with long caching and the right `Content-Type` (LiteSpeed would otherwise send `.js.br` as
`application/octet-stream`). Built paths are relative, and each page's inline script sets `<base>` to its own folder,
so one build works from any sub-folder and any deep link.

`lib/config` reads everything else off the page once: which panel it is, its API base, the router basename and the
**shop the tab shows**. The owner's tab keeps the shop in its address (`/admin/…` is the main shop, `/admin/s/7/…` agent
bot 7's) and sends it on every request (`X-Shop`, or the `shop` query parameter for an `<img>` — `mediaUrl()`), so a
reload or a new tab opens the shop its address names. Opening another shop (`useOpenShop()` in `apps/admin/owner.ts`)
is a full page load at that shop's address: nothing of the last shop survives in memory.

Every page is a chunk of its own (`lib/lazy-page`), loaded ahead by `lib/prefetch` — the first page as the panel starts,
a link's page when the pointer rests on it or a press starts. A tab opened before an upgrade asks for chunks that no
longer exist; `lib/stale-build` reloads it once for the new build, and a second failure soon after is shown as
incomplete files on the host instead of a reload loop. It never reloads while the browser is offline — the file is
missing because the network is, and a reload would leave only the browser's own offline page. Where the tab can keep
nothing (storage refused), a page that is itself a reload of a moment ago counts as that one reload, so there is no
loop either. A page loaded ahead of need (`lib/prefetch`) that fails reloads nothing; the
load that is needed reports and reloads.

## Talking to the server

`lib/api` is the only way to PHP. Writes are typed by their path: `scripts/api-types.mjs` (`pnpm api:types`) generates
`lib/api-types.ts` from `resources/api/openapi.yaml`, including one union of every write per API (`PanelWrite`), so
`api.post('/plans', values)` picks the body type and the answer type from the path — a field the server does not read
is a compile error. Never hand-type an answer or a request; reads name their answer (`api.get<PlansResponse>(…)`). A
body holding a `Blob` goes as multipart. Every failure becomes an `ApiError` (status 0 when no answer came; the API's
message and field errors; the request id, `Retry-After`, the endpoint). A 401 ends the session unless the request was a
sign-in. Requests time out after 90 seconds.

## Reads, writes and live updates

The query client (`lib/query-client`): a read is fresh for 10 seconds and kept 30 minutes once unused, so a page opened
again draws at once; only reads that got no answer at all are retried; nothing refetches on window focus. **Every write
is a mutation of the cache**, and the two things a write may need are named once, on it: `meta.quiet` (the screen shows
the failure itself, otherwise it is a toast) and `meta.invalidates` (other screens' reads it changes, refetched once it
succeeds). No screen calls `invalidateQueries` after a success by hand, and none toasts its own errors.

Query keys live in `lib/query-keys`, flat: no key is a prefix of another, except a one-row key under its `every…`
family key, which the live updates invalidate. Reads two screens share are defined once in `lib/queries`.

The hooks a screen is built from:

| Hook | For |
|---|---|
| `useForm` | Any form talking to the API: values, field errors from a 422, a form error, `submit()` (a quiet mutation), `dirty`, and — for a form that follows the server's copy — `elsewhere` |
| `useSettingsGroup` | A settings card: a `useForm` that follows the server's copy — a dirty draft kept, `elsewhere` when that copy changed under it — and folds its save into the screen's cache |
| `useRows` | An admin-ordered list (plans, categories…): optimistic patch, reorder and delete, one request at a time per list |
| `usePagedList` | A server-paged list: search, status tab, filters, sort and page, kept in the list's address |
| `useSelection` | Rows ticked across a paged list; `selection.follow()` keeps the ticked copies in step with the list |
| `useOpenRow`, `useNextRow` | A list's detail dialog that follows its row as it changes, and «بعدی» through a queue |
| `useReadFailure` | A failed read's «تلاش دوباره»: the failure and the focus kept while it retries, its button spinning |
| `usePressHold` | A confirm button held from its press until the writes it started settle, so a double press sends once |
| `useRequestKey` | The `Idempotency-Key` of a change support makes by hand (a wallet's credit or debit, an agent's traffic, a ticket's answer, an order): the same key while the same change is sent again — a retry after no answer or a failure is answered by what the first one did —, a new one once it went through (`done()`) or the form was edited |
| `useProbe` | A "test the connection" button beside a form |

**`dirty` means "the server would read something else"**: `useForm` compares the values as the request reads them
(`reads`; by default every text trimmed), treats every kind of empty as equal and a number as equal to the text that
writes it in any digits. A form whose request reads its values differently passes its own `reads` (a driver form's
payload, a set; `asAmount()` and `asWhole()` read a typed amount or count as `Input::amountOf()` and `wholeOf()` do).
This keeps false "discard changes?" questions away.

**What is typed is never replaced.** A form that follows the server's copy (`follow`: a settings card, the keyboard
editor, a server's connection form) starts again from a new copy only while nothing is typed. Under a dirty draft the new copy is held as `newer`
and `elsewhere` is true: the card shows `ChangedElsewhere` (`components/form-footer`) — the draft stays as typed and,
saved, takes the new copy's place; «دیدن نسخه تازه» puts the draft aside and shows the new copy (`revert()`). A save
makes what was sent the new starting point, and a newer copy held meanwhile is gone under it.

**A server-paged list's address** (`usePagedList`) holds only what the API takes: a filter that names a row by its id
(`ids`: `server`, `user`, `referrer`, `group`…) is no filter when the address's value is no whole number, and a filter with a
closed set of values (`choices`) ignores any other. A page past the list's end — an old link, a list that shrank, its
last rows deleted — sends the view and its address to the list's last page, in one step that replaces the history
entry.

**Live updates** (`lib/use-live-updates`, mounted by the shell): every 4 seconds while the tab is visible the panel
asks `/changes` which areas of the shop moved — by anyone, the bot, a scheduled task, another tab — and invalidates the
reads `AREA_QUERIES` maps each area to; only what is on screen refetches. A new table a screen shows needs its area
here. A section kept mounted but hidden reads with `subscribed: false` and refreshes when shown.

**Offline**, reads and the live poll wait for the connection, as react-query's do; but **every write fails at once**
(`mutations: { networkMode: 'always' }` in `lib/query-client`, and `runMutation()` for a form's save): a form's save and
a row's operation alike go out then and there and fail with the offline words, rather than wait and go out minutes
later, once the admin has left the screen that asked for them. `ConnectionBanner` (a `PageStrip` under the topbar) says
so — «اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها ذخیره نمی‌شوند.» — and says when the poll
gets no answer from the server too.

PHP serves one session's requests one at a time, so the shell's own reads (the menu's queue counts, the owner's shops)
wait until the page has asked first (`useShellReads()`).

## Failures

Every failure is read one way and drawn one way. `lib/failure` turns any error into a `Failure`: one of fifteen kinds
(offline, unreachable, timeout, not-found, invalid, rate-limited, server, stale-build, crash…), the words — the
server's message where the kind's refusal is meant for the admin, else the kind's own Persian description — whether
retrying may help, and the technical facts support needs. `ErrorState` draws it in four variants: `screen` (outside
the shell), `page` (in the shell), `card` (in place of a failed read, the default) and `inline` (a form's line,
`FormError`). Its «جزئیات فنی» fold lists the status, the tracking code, the request, the moment and its time zone,
the address and the build, with a copy button. A 429's wait counts down on the retry button and on a form's line.

`ErrorBoundary` wraps the app and, in `AppShell`, each page (keyed on the page, reset by the address), so a page that
throws leaves the sidebar working. Its «تلاش دوباره» asks again for what failed (`onRetry`: the page's file, which did
not load offline — `preloadPage()`) and draws the page again; drawn, the page's main column (`#main`) takes the focus
the pressed button had. Outside the shell (the sign-in, the installer), the router's error screen (`RouteError`)
offers «تلاش دوباره» where retrying can help: it loads the page's file again and follows the same history entry afresh,
what it carries too (where a sign-in leads back to). The panel's own bugs — a screen that failed to draw, an error
nothing handled — are reported to the shop's log (`lib/error-reporting`, `POST /client-errors`), throttled, with the
address stripped of query values and the fragment.

A read that failed keeps its failure on screen while «تلاش دوباره» asks again (`useReadFailure` in `components/page`,
which `ListView` and the page-level reads use): otherwise the read would go back to "loading", taking the failure, its
button and the focus on it away until the answer. The button spins meanwhile, so a second press asks nothing more.

## Sessions and unsaved changes

`lib/auth` holds the session both panels share (`GET /auth/me`, asked in the shop the tab names). Signing in goes
through `enter()`, which drops everything cached under the last session; `RequireAuth` guards the shell and sends a
visitor to `/login` with where they were (`LoginArrival`), so a sign-in returns there. An unreachable server is a
failure screen with a retry, never "signed out".

`lib/use-unsaved-guard` makes sure a draft is never lost silently. `FormActions` and `SaveFooter` register while
dirty; then closing the tab gets the browser's prompt, a navigation to another page waits for the panel's own dialog
(the router's blocker, mounted only while a draft is unsaved), and a modal asks before its ✕, Escape or backdrop drops
a draft of its own. Signing out and opening another shop ask first (`confirmLeave()`).

## The shell

`AppShell` is the two-column layout: the sidebar and the page, which owns the scroll, under a topbar stuck to the top
at every width (the page's name and `HeaderActions`, the theme switch; `--topbar-height`, which the connection banner
and the browser's scroll padding clear). Below 48rem (`PHONE`) the sidebar becomes a drawer the topbar's menu button
opens; between 48 and 64rem it starts as an icon rail; the admin's choice is remembered and Ctrl/⌘+B toggles it. The
shell is hand-written, not shadcn's sidebar. `PublicScreen` (the sign-ins, the installer) has a bare topbar with
`HeaderActions` alone.

The navigation is data (`components/shell/nav.ts`): top-level items, groups that fold open (the group of the page on
screen always open), and the settings at the foot. **A page made of sections gets a column of its own** instead of
tabs: on its pages the sidebar lists the page's sections (`areaFor()`), with «منوی اصلی» to get back to the menu. Each
section has its own address; `SectionedPage` keeps every section mounted and hidden but the current one, so searches
and drafts survive switching. A menu entry may carry a queue's count (`QueueBadge`, from `GET /queues`); where the
count is out of sight (a folded group, the rail) a dot shows the most pressing one. One tree feeds the sidebar, the
topbar's title and the document's title (`lib/document-title`).

Dialogs are native `<dialog>` elements (`Modal`, the drawer) driven by `lib/use-native-dialog`, so the browser gives
the focus trap, Escape and the top layer. While a modal is open the body is inert, so Radix's selects, menus and
tooltips — and the toasts — portal into the topmost dialog (`components/portal-container`). The focus:

- **on opening** goes to a `[data-autofocus]` control, else the first field. On a phone, where a field's focus brings
  up the keyboard over half the sheet, it goes to the dialog itself instead — read out by its name, the whole sheet in
  sight — unless typing is the dialog's whole point (`Modal`'s `typing`: the bot text editor);
- **on closing** goes back to what had it when the dialog opened (for a menu item, the menu's trigger) — or to the
  page's main column (`#main`) when that control is gone, disabled or hidden by then, so it never falls to the
  document.

**On a phone** (below `md`) a `Modal` is a sheet rising from the bottom edge, and the head that stays is its title and ✕
alone: the description scrolls with the content (`Modal` draws it in the body there), rather than keep a third of a
short screen from it. A row that must stay in reach sticks to the sheet's foot, edge to edge, over what scrolls under it
— `SHEET_FOOT` (`components/form-footer`), pulled down into the body's bottom padding so nothing scrolls by beneath it:
`FormActions` always, and `OperationsSection` given `foot` — the dialog's very business, the receipt review's decisions
(`ReviewModal`) — while no operation's strip is open (an open strip scrolls with the rest). The ✕ is a finger's 44px
there; so is the press of every small control, its look unchanged (`touch-target` in `index.css`: `Button`, the filter
pills, `Switch`, `Disclosure`, `InfoTip`), and inputs and selects are 44px tall.

## The component kit

A screen is built from the kit, never beside it:

| Need | Components |
|---|---|
| A page | `Page` (its column width), `PageHeader`, `SectionedPage` (with a section picker on phones) |
| A list | `ListView` (one state at a time), `SortableHead`, `Pagination`, `RowMenu`, `RowTitleButton`/`RowTitleLink`, `PhoneFacts` (under a row's title on a phone), `Dash` |
| Filters | `SearchBox`, `FilterSelect`, `DateFilter` + `DateRangePicker` (Jalali), `CustomerFilter`, `ListTotal` |
| An admin-ordered list | `OrderHead`/`OrderCell`, `EditMenu`, `RemoveConfirm` (components/sortable-list), `useEditor` + `EditorModal` |
| Dialogs | `Modal`, `ConfirmModal`, `DetailModal` + `DetailFooter`, `PickerModal` (pick a driver, then its form) |
| A row's operations | `useOperations` + `OperationsSection` (components/operations) |
| Forms | `Field`, `FieldGroup` (several controls that are one field), `FormPart`, `CheckList`, `HourSelect`, `FormActions`, `SaveFooter`, `SectionCard`, `Disclosure`, `SwitchRow`, `SecretField`, `NoteInput`, `DriverFields`/`DriverPicker` |
| Feedback | `ErrorState`, `Callout`, `EmptyState`, `PageStrip` (the strip under the topbar: the connection, an agent's shop that sells nothing), `StatCard`/`StatGrid`, `StatusBadge`/`StatusDot`/`CountBadge`, `InfoTip`/`InfoBadge`, `ProgressBar`, `Rating` (a rating's five stars, reviews and tickets alike) |
| People and links | `UserIdentity`, `CustomerFacts`, `TelegramChatLink`, `TextLink`, `BackLink`, `Reviewer`, `ApiPicture` |
| Switching | `PageTabs` — the one tab/choice control (deliberately no segmented control) |

A few rules the kit enforces:

- **Held, not disabled.** A control that cannot act right now but may have the focus (a submit while nothing changed,
  every operation while one runs, a pagination arrow with nowhere to go) is `aria-disabled`, so a press keeps the
  keyboard's focus instead of dropping it on the document. `Button` swallows the press when held.
- **A press that writes sends once.** `ConfirmModal` and `ConfirmActions` hold their confirm button from its press
  until the writes it started settle, or the dialog closes (`lib/use-press-hold`): react-query says a mutation runs
  only a tick later, so without it a double click sends the write twice. A screen needs no guard of its own.
- **A field waits while its program is off** — a bot-settings card's fields are held while the program's switch is
  off (`useHeldWhile()`, `components/bot/settings/use-bot-settings-group`) — every reward program's card alike, the
  lucky wheel's wait and cost too —, except one changed since the last save: the
  save carries every field, and one the server refuses must be there to fix. A changed field stays open even when it
  is typed back to the kept value, so it is never held under the typist's focus — until the program is switched on, or
  the card is saved or reverted (the form's `touched`) and the focus has left the text field (a save sent with Enter
  keeps it there).
- **A table fits a phone** (`components/ui/table`). Below `md` it keeps its title column, its amount where it lists
  money, and its controls; every other column is `hidden md:table-cell`. What a phone still needs of those columns —
  the status first — goes under the row's title in `PhoneFacts`, which draws nothing from `md` up, so nothing is said
  twice. A tablet (`md` to `xl`) has little more room — the rail or the sidebar takes its share, about 650px are left
  for a list —, so the columns a list can do without come back at `xl` (`hidden xl:table-cell`: the customers',
  payments', orders', subscriptions', tickets' and plans' lists), or at `lg` where a list has room to spare. The last
  column — the row's controls, its header «عملیات» for screen readers alone — sticks to the end when
  the table scrolls sideways, its edge shadowed while rows pass under it (`data-under`).
- **The focus never falls to the document**: a refused form focuses its first invalid field, opening a closed
  `Disclosure` for it; operations move the focus to the next sensible control; a closed dialog returns it to its opener.
- **Drivers are drawn from their description**: `components/driver-form` draws any driver's form from the API's
  `DriverDescription` and sends it back as the server reads it — a new driver needs nothing in the panels
  ([Drivers](Drivers.md)). A kept secret whose bound field has changed (a server's address, proxy or TLS check) is
  drawn as one to type again (`SecretField`'s `stays` false), with the server's own words for why, before anything is
  sent.
- `components/ui` holds shadcn primitives restyled to the design; reinstall one with `pnpm dlx shadcn@latest add -o`
  (components.json has `"rtl": true`), then re-apply the `PortalContainerContext` container prop on portalled ones.

Vocabularies of states (an order's, a payment's) live in `lib/statuses`, keyed by the API's values, so a new state is a
type error until it is worded.

## Design tokens and right to left

The look follows the design language of Anthropic's Claude Console (code comments say "as the Console's" where a
component copies one of its patterns). `index.css` defines every colour once per theme on `:root` and `.dark`, mapped
to Tailwind names in `@theme inline`: four near-neutral surfaces, hairlines and fills as alpha, three text inks (each at
least 4.5:1), one inverted primary, blue only for links, focus and what is selected, and green, amber and red status
families — the red edge (`--danger-line`) at least 3:1 on the surfaces, which `src/index.css.test.ts` checks in both
themes. The type scale has its own
names (`text-caption` … `text-display`); `lib/utils`' `cn()` teaches tailwind-merge that they are sizes. Dark is the
default theme and there is no "system" theme; each index.html applies the saved theme before the first paint.

The panels are Persian and right to left: logical classes (`ps-`, `ms-`, `start-`), Radix in RTL, icons flipped with
`rtl:`/`ltr:`. One typeface, Vazirmatn, self-hosted, for everything. Latin content (`dir="ltr"`) is nudged down a pixel
because Vazirmatn's Latin glyphs sit higher than its Persian ones. In a plain string a Latin run is wrapped in Unicode
isolates (`lib/direction`'s `isolate()`, `handleLabel()`, `idLabel()`) so «@amir» does not become «amir@»; the server's
own words carry a row's number bare (a ledger line's note «شارژ کیف پول (سفارش #4)», a refusal), so `isolateIds()`
isolates every «#12» in them, and the wallet's and an agent's traffic histories and `lib/failure` pass them through it.
The browser's tab and history often draw an isolate as a box or a gap: `lib/document-title` opens each isolated run
with the mark of its own direction instead (U+200E LRM, or U+200F RLM for a right-to-left run) and drops the isolates,
so «تیکت #12» still reads «#12» in the tab.

Numbers and dates go through `lib/format` (Persian digits, Jalali dates in the shop's time zone; `lib/jalali`'s `shopClock()` is a
moment's day and hour on the shop's clock, whatever the browser's); identifiers keep Latin digits, and so do a form
hint's bounds and a value as the owner types it («بین 5 تا 120», «0»,
[Conventions](Conventions.md#money-time-and-numbers)).
A typed price is read as the server reads money: `tomanOf()` takes «هزار», «میلیون» and Toman's words exactly as
`Input::amountOf()` does for `Unit::Toman` — never another unit's word, so «۲۰ گیگ» is no price.

## Frontend conventions

- Follow a prop by adjusting state during render (`if (prev !== prop) { setPrev(prop); … }`), never `setState` in an
  effect — oxlint enforces it, and there is no lint-disable comment in the tree.
- A `Button` is `type="button"` unless it says `type="submit"`.
- Browser storage is for conveniences only, through `lib/storage` (it may refuse).
- `pnpm check` must be green: the API types current, `tsc`, oxlint, knip (nothing unused), Prettier, the tests.

## Tests

Tests sit beside what they test as `*.test.ts[x]` (Vitest, happy-dom, shuffled, in UTC). `src/test/setup` gives every
test a fresh fake server in place of `fetch` (`src/test/server`): a test answers routes with `server().on(method, path,
answer)` (`json()`, `refusal()`, `offline`, `silent`…), holds requests with `hold()`, and reads what was sent. A request
no route answers fails the test, and so does one the API description does not take (`src/test/contract`, the frontend
twin of the PHP tests' check); `unchecked()` lets one malformed request through on purpose. `src/test/render` has
`providers()` and `signedIn()` wrappers and `until()`, which waits on the event loop rather than timers; `src/test/rows`
builds whole API rows. Time is faked, never waited for. See [Testing](Testing.md#the-panels).

## The screens

The shop's pages that both panels have live in `src/pages` (each its own chunk, `pages/lazy.ts`); a page only one panel
has lives in `apps/<panel>/pages`, and the parts of a page in a domain folder of `src/components` (`plans/`,
`subscriptions/`, `discounts/`…). Every screen is the same few shapes from the kit:

- **A server-paged list** — `usePagedList` + `ListView`, status tabs (`statusTabs()`, the tab that waits on a human first,
  counted from the list's `meta`), a `SearchBox` and filter pills. Its view lives in the address, so other screens link
  to a filtered list (`searchLink()` for `?search=#12`, `customerList()` for `?user=`).
- **An admin-ordered list** — `useRows` + `ListView`, ▲▼ (`OrderCell`, each move said politely to assistive tech:
  «… به ردیف ۲ رفت»), an inline `Switch`, `useEditor` + `EditorModal`
  for its form and `RemoveConfirm` for a delete; `kept` names the rows the server would refuse to delete.
- **A row's dialog** — `useOpenRow` follows the row while the dialog is open; `DetailModal` + `useOperations` draw the
  operations the row's `actions` allow. Each dialog names the reads its operations change elsewhere in a `CHANGES`
  list (`invalidates`), and a refusal because the row moved on reads it again (`onStale`).
- **A settings card** — `SectionCard` on a settings-group hook: `useBotSettingsGroup` (one bot-settings group per PUT),
  `useConfigGroup` (config.php), `useWebsiteGroup` (a PATCH of the card's own fields). A bot-settings card drawn on a
  page of its own (cashback, discount codes, gift codes, the draw, the wheel, the test accounts) waits for the settings
  in `WithBotSettings`.

Where the panels differ the app passes the difference in, never a role check: `PlansPage({addServers})`,
`TestsPage({serverPage})`, `SubscriptionsPage({servers, serverPage, traffic})`, `CustomerPage({…, agentPage})`,
`DashboardPage({aside, trafficHelp})`, `BroadcastsPage({more})`, `WebsiteSettingsPage({mailSettings})`. What depends
on the shop shown (an agent's shop has no agency, needs traffic on every plan) asks `useMainShop()`.

### Sales and support queues

Orders, payments, subscriptions, support tickets and reviews are server-paged lists whose first tab is the queue: stuck
orders, receipts to review, services ending soon, tickets waiting on an answer, reviews waiting on support. The
payments list has a «نگه داشته شده» tab beside the receipts: online payments held for support (`status=held`, counted
in the list's `meta.held`). The menu's badges read `GET /queues`. The dashboard's attention card reads
`GET /dashboard`'s `attention`: its «… مورد منتظر اقدام شماست» counts only what waits on the admin — receipts, held
payments, failed deliveries, tickets and reviews waiting, failing servers (the main shop's), and an agent's selling
notice and traffic shortage —, each linking to its filtered list; «در انتظار مشتری» (unpaid orders, services ending
soon) is listed under them uncounted. With nothing to count the header's line is left out, and the card's empty state
says it once («همه‌چیز مرتب است»). Failing servers are switched-on ones with a `last_error` (`DashboardStats`): a
server switched off is no one's to see to.

- The payments dialog (`ReviewModal`) words each operation by what the payment paid for, and «بعدی» (`useNextRow`) walks
  the list so a queue of receipts is reviewed in a row.
- The subscriptions screen ticks rows across pages and moves them in a batch (`useMoveBatch`): one request per service,
  so each one's outcome shows; an old panel that does not answer pauses the batch on `SkipServerPrompt`.
- A ticket has a page of its own (`pages/ticket.tsx`): `Conversation`, which names support's writer by their name
  (`reviewer_name`) beside how they are kept, and `ReplyForm`, which refuses a picture over the server's limit before
  sending it and sends each answer under its `useRequestKey()`.

### The customer page

`/users/:id` is where support answers a customer from, keyed on the customer so nothing of one is left on another. Its
cards of latest services, orders, payments and tickets (`components/customer`, `useLatestRows` + `LatestCard`) read the
list screens' own endpoints narrowed by `user=`, under the lists' own query keys, and open the list screens' own
dialogs — so an operation on a card refreshes the full list too, and the other way round. The side cards hold the
wallet, the referral facts, the groups, the personal discount, the loyalty tier, the website account and an agent's
agency.

### Catalogue and payment methods

Plans, categories, payment methods and customer groups are admin-ordered lists. `PlanForm` keeps its four tabs in one
grid cell, the hidden ones `inert` and invisible, so the dialog never changes height under the pointer, and a refused
save jumps to the first tab with an error. Its `reads` compares prices by their digits and the term in hours, so `dirty`
is only true when the server would store something else. The bulk price change (`BulkPricesModal`) must be previewed,
and applies exactly the request it previewed. A payment method is added through a `PickerModal` of the drivers'
descriptions; its form (`MethodForm`) is drawn with `DriverFields`.

The free test accounts' page (`pages/tests.tsx`, `components/tests`) is a sectioned page: the tests (a server-paged
list), every server's terms (`testServersQuery`, which the page's notices read too) and the rules' bot-settings card.
The owner's app passes `serverPage`, so a server leads to its own «اکانت تست» card
(`components/servers/test-account-card`, saved on its own at `/servers/{id}/test-account`, which scrolls itself into
view at `#test-account` once it has loaded); an agent's lists them read-only. A free test's «تست» badge in the
services lists is `ServicePlan`.

### Discounts and rewards

Discount codes, sales, loyalty tiers, cashback rules, gift codes, the lucky wheel and the nightly draw are small lists
beside a bot-settings card for the program's switch and rules. A program that is off says so above its lists
(`ProgramOff`); one whose menu button is missing from the saved keyboard says so too (`MenuButtonNotice`). Shared parts:

- `components/discounts/window` — a code's or sale's window. Each end is typed as a Jalali day and an hour on the shop's
  clock, sent as `YYYY-MM-DDTHH:00` (the server applies the shop's time zone) and read back in that zone, whatever the
  browser's. `WindowField` draws one end; `OrderKindsField` and `PlansField` are the "applies to" tick lists, where
  «همه» means every one, now and to come (`null`).
- `components/rewards/prize-fields` — `PlanSelect` and `PrizeAmountField`, used by the wheel's slices, the draw's prize,
  gift codes and the referral reward. Any plan may be given, one off sale too.

### Bot configuration

- **Bot settings** (`pages/bot-settings.tsx`) is one sectioned page, a card per settings group. A card whose values are
  typed numbers or lists passes `reads`, so «۵۰٬۰۰۰» and "50000" are no change.
- **Bot texts**: the editor previews a wording as Telegram shows it by parsing it into React elements
  (`telegram-html.tsx`, never `innerHTML`), and `wording-problem.ts` mirrors the server's checks while typing; its test
  runs the PHP tests' own cases so the two cannot drift. The server stays the judge.
- **Keyboards**: `useKeyboardDraft` holds the rows with local ids so a moved row keeps its element and focus; buttons
  move by dragging (`useRowDrag`) or from their dialog, the keyboard way. Rows are kept in reading order; the preview's
  RTL row puts the first button on the right, where Telegram shows it once the server reverses the row.
- **Premium emoji** (`PremiumEmoji`) are drawn as Telegram draws them: Lottie or video loaded on first sight and played
  only while on screen.
- **Broadcasts** are composed in the bot; the panel lists the runs live and controls them. The report group card
  (`useReportGroup`) polls while a connect link is out.

### Servers, grants and the agency

The owner's servers (`apps/admin/pages/servers.tsx` and `server-detail.tsx`, `components/servers`) are added through a
`PickerModal` of the connectors; `ServerForm` draws the connector's form from its description and tests it with
`useProbe`. On the server's page it follows the server's copy (`follow`) as the settings cards do: a save elsewhere
meanwhile (another tab, a live refresh) is said over the draft by `ChangedElsewhere`, whose «دیدن نسخه تازه» takes the
newer copy. A server row's `last_error` is the diagnosis and `last_error_detail` the technical detail
(`ProviderErrorPresenter::split()` takes apart the one line the server keeps); `LastError`
(`components/servers/server-status`) draws the detail on a line of its own, left to right — in the list's ⓘ and on the
status card, as `ProbeResult` draws a probe's.

A grant (a server's «افزودن زمان و حجم») and the mass gift share `components/grants`, and a server's «انتقال سرویس‌ها» is `components/servers/migrations-card`: while a grant or a move is under way and its card is on screen,
`useJobRunner` (`lib/use-job-runner`, through `useGrants` and `useMigrations`) works it a request at a time so it
finishes without waiting for the scheduler, which carries on otherwise — the next a second after, half a minute while it
waits for a panel, ten seconds after a request that got no answer or a server error (a 5xx). The run ends once a turn
no longer works the job, when a request is refused (a 4xx: the job gone, the session ended), or when the panel's own
code fails — that one with a toast and a report, the scheduler carrying on.

The owner's agency page (`/agents`) has requests, agents, levels and rules; «باز کردن فروشگاه» is a real link
(`useOpenShop().link()`), so it opens the agent's shop in a new tab like any link. An agent's own account page
(`apps/agent/pages/account.tsx`) reads `accountQuery`. The agent's services screen reads it too (`useBotTraffic()` in
`apps/agent/app.tsx`), so «افزایش زمان و حجم» on a service knows how much traffic the agent's bot has left to give. A
bot whose traffic covers no plan shows `TrafficShortageNotice` on the dashboard and the account.

### Website and panel settings

Each website-settings card saves only its own fields with a PATCH (`useWebsiteGroup`), and `dirty` is whatever that PATCH
would change; the answer (the whole website) replaces the page's single read, so another card's draft is left alone.
The panel settings (`apps/admin/settings`) edit config.php's groups through `useConfigGroup`; the update section drives
an update one step per request until the page reloads on the new build. The web installer (`apps/admin/install`) talks
to `/api/install` with the install key the owner read off the host (`X-Install-Key`, kept for the tab), and opens on the
first step still needed (`steps.ts`).

### The dashboard

`pages/dashboard.tsx` reads `/dashboard?range=`; switching the range keeps the last figures on screen, dimmed, until
the new ones arrive. A figure that could not be read shows «—», never a zero, and the attention card only says "all
clear" of counts that arrived. The chart (`components/dashboard/area-chart.tsx`) is plain SVG — no chart library: five
rounded value ticks from zero, as many day labels as fit, and a monotone curve that never overshoots a day's figure.
