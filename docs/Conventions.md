# Conventions

How the code is written, so a change reads like the code around it. The checks enforce much of it
([Development](Development.md#the-checks)); this page is the rest, and the reasons.

## Code style

- **PHP**: `declare(strict_types=1)`, PER-CS 2 (`composer fix`), ordered imports. Classes are `final` unless built to be
  extended; constructor promotion and `readonly` where they fit. PHPStan runs at level 6 over all the PHP — the app,
  the tests, the scripts — with a dead-code detector: a method, constant or property nothing uses fails it, and so does
  one only the tests use. Delete code the moment nothing calls it.
- **Models**: explicit `$fillable` and `$casts`, `@property` docblocks (PHPStan reads them), static query helpers
  (`Plan::active()`, `Order::sold()`) rather than `scopeX` magic, enum cases rather than status strings.
- **TypeScript**: two spaces, no semicolons, Prettier's import and Tailwind class order (`pnpm format`); oxlint with
  warnings as errors; knip finds unused files, exports and dependencies. See [Internals: panels](Internals-Panels.md).
- **Simple over clever.** Plain code beats a layer of indirection; one rule lives in one place and both doors call it;
  a helper used once that adds nothing is inlined.

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
- `integer()` / `integerOf()`, `wholeOf()` (a whole number an admin types: «10,000», «۱۰٬۰۰۰»), `numbersOf()` (a typed
  list — a comma between groups of three digits is a thousands separator, any other separates numbers), `amount()` /
  `amountOf()` (whole Toman; a customer may add a unit word), `decimal()` (two decimals, GB of traffic);
- `isBoolean()` and `truthy()` for switches. A switch that is missing or not a boolean is refused
  (`ApiController::switch()`, the `Toggle` field), never read as "off".

A form of several fields is checked as a whole by the field engine (below), so every refusal comes back at once, each
under its field.

## Money, time and numbers

- **Money** is Toman, the shop's one currency, in `decimal(14,2)` strings through `App\Support\Money` (bcmath:
  `add()`, `compare()`, `percentOf()` rounding down, `format()` → «۱۲۰٬۰۰۰ تومان»); the panels use `formatMoney()`.
  Never floats, never `number_format` by hand.
- **Traffic** is bytes (int); admins type gigabytes (`Support\Traffic::bytesOfGb()`).
- **Time** is stored in UTC; `Support\LocalTime` applies the shop's zone only for display, and `Support\Persian::date()`
  writes the bot's Jalali dates. A query gets `->utc()` moments.
- **Digits**: Persian digits for quantities, money and dates (`Persian::digits()`, `Persian::number()`); Latin digits
  for identifiers — ids (`#12`), ports, versions, addresses — and for the bounds in a form's hint («بین 5 تا 120»).

## Errors

A refusal the user can act on is a `Core\Exceptions\DomainRuleException` whose message is already written for them:
`status()` (422 by default: refused in this state; 409 another row holds on to it; 413 too large; 429 try later, with
`Retry-After`; 502 a panel or service failed) and `field()` for the request field it concerns. `ValidationException`
carries several fields (`ValidationException::on()` for one, `ifAny()` to throw only when there are errors). A
controller never catches these to word them again: `Core\Http\ErrorHandler` answers every failure in the one JSON shape
(`Core\Http\Json::error()`), a missing row (`findOrFail()`, `ApiController::load()`) is a 404, and anything else is a
logged 500. Never hand a raw exception message to a user; a panel's failure is worded by `ProviderErrorPresenter`, in
full for the owner and in one word for anyone else.

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
  A pause or cancel from outside writes its state with `Lease::FREE`, and the holder stops at its next step.
- **A ledger line** is written under a lock on its owner's row, after a locking read of the last line
  (`Core\Database\Ledger`).
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
`Secret`, and a module's own) has the name a screen uses, the key it is kept under, a default, a check (`read()`, which
refuses with a message for the admin) and a reading of a kept value (`cast()`: a kept value the field would refuse reads as its
default). `Form::check()` refuses a save as a whole before anything is kept; `checkSent()` checks a partial save (a
PATCH). A `FieldSpec` lets a field describe itself, so a generic form in the panels can draw it, and `when` shows a
field only while another holds a value.

A module declares its settings groups (`Settings\DeclaresSettings`) and registers them on the bot settings screen in
`bootstrap/container.php`; it reads them through typed getters of its own, never by key elsewhere. A `Secret` is never
sent back to a screen, and one bound to an address (`boundTo`) is asked for again when the address moves — a kept
password never goes to a server it was not given for.

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
- Latin content inside Persian (a handle, an id, a URL) is set apart left to right — `<bdi dir="ltr">` in the panels,
  `isolate()` in a plain string.
