[فارسی](fa/Conventions.md)

# Conventions

How the code is written, so a change reads like the code around it. The checks enforce much of it
([Development](Development.md#the-checks)); this page is the rest, and the reasons.

## Code style

- **PHP**: `declare(strict_types=1)`, PER-CS 2 (`composer fix`), ordered imports. Classes are `final` unless built to be
  extended; constructor promotion and `readonly` where they fit. PHPStan runs at level 6 over all the PHP — the app,
  the tests, the scripts — with a dead-code detector: a method, constant, enum case or property nothing uses fails it,
  and so does one only the tests use. Delete code the moment nothing calls it.
- **Models**: explicit `$fillable` and `$casts`, `@property` docblocks (PHPStan reads them), static query helpers
  (`Plan::active()`, `Order::sold()`) rather than `scopeX` magic, enum cases rather than status strings.
- **TypeScript**: two spaces, no semicolons, Prettier's import and Tailwind class order (`pnpm format`); oxlint with
  warnings as errors; knip finds unused files, exports and dependencies. See [Internals: panels](Internals-Panels.md).
- **Simple over clever.** Plain code beats a layer of indirection; one rule lives in one place, and every way in — the
  bot, the panels, the website — calls it; a helper used once that adds nothing is inlined.

## Comments

The code's comments and this wiki are the only documentation. Every class, interface, trait and enum gets a short
docblock (one to five lines): what it is for, and its key rule if it has one. A public method — and a private one that
is not obvious — gets one too: what it does, what it returns, and only what the code does not show: a race it prevents,
why its steps come in this order, what it refuses. Keep the annotations PHPStan needs (`list<…>`, array shapes,
generics, a meaningful `@throws`); drop `@param` and `@return` lines that repeat the native types.

Inside a long function, a short comment above each step that is not obvious; never a comment per line, never one that
restates the code. Write plain, short English, keep the *why* in one sentence ("Indexes come before foreign keys:
MySQL gives a foreign key without a usable index an index of its own"), and leave out history — what the code did
before belongs to git. Tests get a docblock saying what the class covers, and names that say what they check.

## Reading input

Request input is loosely typed: a JSON number or its text, Persian or Arabic digits, a thousands separator. It is
read in one place, `App\Support\Input`, never by hand:

- `text()` (trimmed; text that is not UTF-8 is a 422 on its field, never a 500 from the database), `password()` (not
  trimmed), `secret()` (a blank one keeps the stored secret, `clear_<field>` empties it), `note()` (with the one "too
  long" wording, `Validation::tooLong()`);
- `integer()` / `integerOf()` (a JSON `true` is no number: never id 1), `telegramIdOf()`, `wholeOf()` (a whole number
  an admin types: «10,000», «۱۰٬۰۰۰», digit groups split by a comma or any space, a pasted no-break one too),
  `numbersOf()` (a typed list — a comma between groups of three digits is a thousands separator, any other separates
  numbers), `decimal()` (two decimals, GB of traffic);
- `amountOf($value, Unit)` — a whole amount of the unit the caller names (`App\Support\Unit`: `Toman`, `Gigabyte`,
  `Day`, `Device`; `amount()` reads a field as Toman). After the number a customer may write that unit's own words —
  Toman («تومان», «تومن», «ت»), GB («گیگ», «گیگابایت»), days («روز»), devices («کاربر», «دستگاه», «نفر») and their
  «…ی» / «…ه» forms («۲۰ گیگی», «۲ نفره») — and, before them, a scale word of that unit (`Unit::scales()`): «هزار»
  for money and GB («۵۰ هزار تومان» is 50,000, «۵ هزار گیگ» 5,000 GB), «میلیون» for money alone; days and devices take
  none. Any other word — «ریال», «ساعت», «50k», another unit's word («۲۰ روز» where GB are read) — is refused, so a
  number is never read in the wrong unit. The bot's steps that await an amount read it through
  `Telegram\Handlers\TypedAmounts`, each in its own unit (`typedUnit()` of `CustomQuantity` and `ExtraKind`).
  `optionalAmountOf()` reads money an admin may leave blank (a bound, a cap): null for none, false for the caller to
  refuse;
- a moment the panels send («2026-03-20T18:00» on the shop's clock): `LocalTime::moment()`, in UTC; one past
  `LocalTime::LAST_DAY` is refused ([Database](Database.md#time-and-the-2038-bound));
- `isBoolean()` and `truthy()` for switches. A switch that is missing or not a boolean is refused
  (`ApiController::switch()`, the `Toggle` field), never read as "off";
- a list of ids to reorder: `ApiController::reorderIds()`, which refuses it (422 on `ids`) when it is empty, then when
  it is longer than a reorder takes (`Sorting::MAX_IDS`, 1,000 — before any id is read), then when one is not an id;
- a rating of 1 to 5 stars (a review, a closed ticket): `Validation::rating()`, its bounds `Validation::RATING_MIN` and
  `RATING_MAX`;
- text that must show something (a name, a bot text): `Support\Text::blank()` tells apart text that draws nothing —
  spaces of any kind, zero-width and filler characters alone —, so a Telegram name made to look empty counts as none.

A form of several fields is checked as a whole by the field engine (below), so every refusal comes back at once, each
under its field.

## Money, time and numbers

- **Money** is Toman, the shop's one currency, in `decimal(14,2)` strings through `App\Support\Money` (bcmath:
  `add()`, `compare()`, `percentOf()` rounding down, `format()` → «۱۲۰٬۰۰۰ تومان»); the panels use `formatMoney()`.
  Never floats, never `number_format` by hand.
- **Traffic** is bytes (int); admins type gigabytes (`Support\Traffic::bytesOfGb()`).
- **Time** is stored in UTC; `Support\LocalTime` applies the shop's zone only for display, and `Support\Persian::date()`
  writes the bot's Jalali dates. A query gets `->utc()` moments; "today" on the shop's clock (a cap a day) starts at
  `LocalTime::midnight()`, a Jalali month is `Support\JalaliMonth`.
- **Digits**: Persian digits for quantities, money and dates (`Persian::digits()`, `Persian::number()`); Latin digits
  for identifiers — ids (`#12`), ports, versions, addresses — and, in a form's hint, for its bounds («بین 5 تا 120»,
  «حداکثر 100 درصد») and for a value as the owner types it («0», «3.1»).

## Errors

A refusal the user can act on is a `Core\Exceptions\DomainRuleException` whose message is already written for them:
`status()` (422 by default: refused in this state; 409 another row holds on to it; 413 too large; 429 try later, with
`Retry-After`; 502 a panel or service failed) and `field()` for the request field it concerns. `ValidationException`
carries several fields (`ValidationException::on()` for one, `ifAny()` to throw only when there are errors). A
controller never catches these to word them again: `Core\Http\ErrorHandler` answers every failure in the one JSON shape
(`Core\Http\Json::error()`), a missing row (`findOrFail()`, `ApiController::load()`) is a 404, and anything else is a
logged 500. Never hand a raw exception message to a user; a VPN panel's failure is worded by
`ProviderErrorPresenter`, in full for the owner and in one word for anyone else.

A limit on tries (a customer's tickets, a sign-in's failures) is `Core\Security\RateLimiter`: `attempt()` counts a try
in every window before it is judged, so requests sent at once never pass a limit together, and `enforce()` is that with
the usual 429 («…؛ ۵ دقیقه دیگر دوباره امتحان کنید.»).

## Only once

Two processes — a panel and the bot, a tap and the timer, two tabs — must never act twice on one row. The rules:

- **A state change is a compare-and-swap.** `Core\Database\Transitions::move($model, 'status', [from…], $to)` is one
  `UPDATE … WHERE id = ? AND status IN (…)`; it returns whether *this* call moved the row, and only the call that moved
  it tells anyone. `reclaim()` takes over a claim whose owner went quiet; `claim($model, 'notified_at')` marks something
  done once (a customer told), optionally again once a window has passed.
- **A row worked through over time is leased.** A broadcast's run, a grant's part, a report being sent: whoever holds
  `Core\Database\Lease` (`lease_token` until `leased_until`) moves it on, step by step, each step a conditional write
  that also extends the hold; `renew()` before a long call; a holder that goes quiet loses it when the time runs out.
  A cancel from outside writes its state with `Lease::FREE`; a pause (or a server's move switched to a rescue) writes
  its state alone, so the holder can still put its cursor back on an item it must come back to. Either way the holder
  stops at its next step: the lease holds while the row is in the state it was taken in. A run past a cursor, one item
  at a time (a grant's part, a server's move), is `Lease::work()`'s loop: next item, its step, the end, the lease let go
  however it ended.
- **A ledger line** is written under a lock on its owner's row, after a locking read of the last line
  (`Core\Database\Ledger`).
- **A money write is tried again on a deadlock.** The ledger's locking read also locks the index gap after the owner's
  last line, so two owners' lines at once can deadlock on MySQL, which aborts one transaction whole. The outermost
  transaction of a money write passes `Ledger::ATTEMPTS` (`$db->transaction($callback, Ledger::ATTEMPTS)`: Laravel tries
  again only on a concurrency error, and only the outermost transaction — a nested one's deadlock goes up to it). Its
  callback does database work only — a message, a panel's call, a file deleted come after the commit; a report group's
  line is a row, so it stays inside — and reads what it moves inside the callback, or moves it with `Transitions`, which
  writes its whole move whatever the model in memory still holds from the try the rollback took back.
  [Internals-Commerce](Internals-Commerce.md#ledgers) lists the transactions that are.
- **A request sent again is answered, never done twice.** A request that moves money carries an `Idempotency-Key`
  (`Store\Http\IdempotencyKey`), a key its sender makes per change: the same request again — a retry after no answer —
  is answered by what the key did. A checkout, a balance transfer and an order support makes by hand need one;
  support's wallet credit or debit, an agent's traffic adjustment and a ticket's answer take one when it is sent, and
  the panels send it (`useRequestKey`). The key is kept on the row it wrote, under a unique index
  ([Database](Database.md#kinds-of-data)); the same key with another request is refused (`KeyReusedException`, a 422
  on `idempotency_key`).
- **A counter** that only goes up is `Core\Database\Sequence` (a locked read-and-increment; gaps are normal).
- **A unique name** is the unique index's to decide; `Core\Database\UniqueName` turns its error into the form's.
- **Every database the shop runs on reports the rows an `UPDATE` matched**, not only those it changed (MySQL is told
  to), so writing what is already there still counts as winning.

When a protection looks complex, simplify how it is written, never what it guarantees.

## Lists and queries

A paged list reads its request once (`Core\Database\PageRequest`: the page, the search, each filter as what it may be,
the order from a fixed list of keys — a controller's `pageRequest()`) and answers one `Core\Database\Page` (`fetch()` with a `Sort`, `with()` for a
tab's count, `tally()` for its takings). `#12` in a search box is row 12 alone — how one screen links to another's row.
A list loads what its rows show with it (`with()`, `withCount()`, a subquery), never a query per row; a rule judged per
row has a bulk form for a page. `ListQueriesTest` and `BotQueryBudgetTest` keep it so.

## Settings and the field engine

The installation's own configuration lives in `config.php` (see [Configuration](Configuration.md)); what a shop changes
at runtime lives in the `settings` table, each bot its own (`Settings\Services\Settings`).

Both are edited through one field engine, `Core\Forms`. A `Form` is a named set of fields saved together — a settings
card, a driver's form. A field (`Toggle`, `Number`, `Amount`, `Numbers`, `Text`, `EmailAddress`, `Url`, `Choice`,
`Secret`, `PricedRows` — amounts each at a price —, and a module's own; `Ruled` wraps one with a rule against the
form's other fields) has the name a screen uses, the key it is kept under, a default, a check (`read()`, which
refuses with a message for the admin) and a reading of a kept value (`cast()`: a kept value the field would refuse reads as its
default). `Form::check()` refuses a save as a whole before anything is kept; `checkSent()` checks a partial save (a
PATCH). A `FieldSpec` lets a field describe itself, so a generic form in the panels can draw it, and `when` shows a
field only while another holds a value.

A module declares its settings groups (`Settings\DeclaresSettings`) and registers them on the bot settings screen in
`bootstrap/container.php`; it reads them through typed getters of its own, never by key elsewhere. A `Secret` is never
sent back to a screen, and one bound to other fields (`boundTo`: a server's address, its proxy, its TLS check) is asked
for again when one of them changes — a kept password never goes to a server, or by a way, it was not given for.

## Persian text

Everything a user reads — the bot, the panels, the installer, API error messages — is Persian; code, comments, logs
and the CLI are English. There is no i18n layer.

- Everything the bot says to a customer is a `BotText` the admin may reword ([Bot texts](Bot-Texts-And-Keyboards.md));
  the panels' strings sit in their components; PHP error texts are constants beside the code that refuses.
- No Arabic diacritics: «لطفا», «مثلا», «تایید», «نسخه ۳» — not «لطفاً», «تأیید», «نسخهٔ». Keep the ZWNJ in compounds
  («می‌شود»).
- Customers read «پشتیبانی», never «مدیر».
- Technical words keep their Latin form or the usual transliteration («Webhook», «دیتابیس», «توکن», «لاگ»), never a
  formal calque.
- Latin content inside Persian (a handle, an id, a URL) is set apart left to right — in the panels, `<bdi dir="ltr">`
  in markup and `isolate()` (`lib/direction.ts`) in a plain string such as a toast. A PHP message that lists Latin
  words with Persian between them puts each in Unicode's isolates (U+2068 … U+2069), as `Core\Support\Picture::FORMATS`
  does, so «تصویر باید JPG، PNG یا WebP باشد.» reads in its order.
