# Database

The shop keeps everything in one database: MySQL or MariaDB on a live shop, SQLite for the test suite and a
developer's machine. This page covers the schema, how an installed shop's database follows a new release, the rules
the tables keep, and the database drivers. How a row is moved safely between states is on
[Conventions](Conventions.md#only-once).

## How it is wired

Eloquent (`illuminate/database`) runs outside Laravel. `Core\Database\DatabaseManager::boot()` hands it the
connection of the driver `DB_CONNECTION` names (`Core\Database\Drivers`), lazily: nothing talks to the database until
the first query. The connection speaks UTC whatever the server's zone, so a stored moment means the same everywhere;
the shop's zone (`APP_TIMEZONE`) is applied only where a time is shown.

A relation lazy-loaded row by row off a list is a bug: the test suite throws on it, a live shop logs it once per
relation and still loads the rows (`Model::preventLazyLoading()`). `tests/Feature/ListQueriesTest` reads every list
with a few rows and with many and fails when the second asks more queries.

## The schema

`database/schema.php` is the database: every table as a `Blueprint` closure, in the order they are made (a foreign key
only points at a table above it), and the rows a new shop starts with (the main bot, the wallet payment method).
`Core\Database\Schema` applies it:

- `create()` makes the tables a database lacks — the web installer's tables step and the test suite.
- `rebuild()` — `php bin/console db:rebuild`, a developer's tool — makes every table again after an edit of the schema,
  copying the rows back by column name. A new column takes its default (so a `NOT NULL` one needs one); a dropped
  column goes with its values. A row the new shape will not take stops the rebuild with the database's error, the old
  rows waiting in `<table>__old`; the next rebuild, once the schema is fixed, finishes it. Stop the bot first.

The comments in `schema.php` say what each table is for; read them before adding a column.

## Upgrades

An installed shop never runs `db:rebuild`. From the first release (0.1.0) on, **every change of `database/schema.php`
ships the same change as an upgrade** for the databases of the release before: `database/upgrades/<version>.php`, named
by the version it brings the database to.

An upgrade file:

- returns `static function (Builder $schema, Connection $db): void` — Laravel's schema builder and the shop's
  connection, and nothing of the app's code, which changes from release to release while an upgrade must keep working
  on all the later ones;
- does what the release's edit of `schema.php` did: a column added (with a default, or nullable — the rows there have
  none), a table, an index, a foreign key, and any data the change needs moved (a setting carried over, days turned into
  hours);
- **can be run again after it stopped half-way.** MySQL changes a table outside any transaction, so a run that died
  (a time limit, a killed request) or failed on one statement leaves part of the work done. Every step therefore asks
  first and adds one thing: `hasTable()`, `hasColumn()`, `hasIndex()`, and `getForeignKeys()` for a foreign key — and
  changes a column (`->change()`) only while `getColumns()` shows it as it was, as `0.4.0.php` makes `given_hours`
  signed on MySQL and MariaDB (SQLite keeps no sign: nothing to change there). Make a table with its columns alone,
  then add its indexes, then its foreign keys, each guarded on its own — Laravel issues them as separate statements on
  MySQL, so guarding the table alone would leave an index missing forever. Indexes come
  before foreign keys because MySQL gives a foreign key without a usable index an index of its own. A data step must be
  safe to repeat: copy only what is not there yet, compute from a column that is still there.
  `database/upgrades/0.2.0.php` is the model: four small guarded helpers and a section per feature.

`Core\Database\Upgrades` runs, in version order, the upgrades newer than the version the database is at and not newer
than the code's. The database's version lives in the installation lock (`storage/installed.lock`, `Core\Installation`):
the installer writes the code's version, and the version moves after each upgrade, so a failed upgrade is where the
next try starts. The owner's panel runs them as it installs a release (see [Upgrading](Upgrading.md)); files put in
place by hand are followed by an upgrading run of their own. A fresh install makes `schema.php`'s tables as they stand
and runs none.

`tests/Unit/Database/UpgradesTest` holds the rule. It installs the first release's database
(`tests/Fixtures/schema-0.1.0.php`, kept exactly as it shipped — never edit it), runs every upgrade on it twice, and
again after a simulated half-way stop, and requires the result to equal a fresh install of `schema.php` table by table:
columns, indexes and foreign keys. A schema change without its upgrade, or an upgrade that cannot run twice, fails it.

To change a table:

1. Edit `database/schema.php` and run `php bin/console db:rebuild --force` on your own database.
2. Add the same change to `database/upgrades/<the next version>.php` (create it if the release has none yet).
3. A column that points at `users` joins `AccountMerger::REFERENCES`, so a merge of two accounts carries it
   (`AccountMergerReferencesTest` fails until it does).
4. Run `vendor/bin/phpunit tests/Unit/Database`.

## What the schema must keep

`tests/Unit/Database/SchemaTest` holds `schema.php` to what every supported database takes, on SQLite in the suite but
measured on MySQL and MariaDB:

- a foreign key points only at a table made before it;
- a key name fits MySQL's 64 characters after the longest table prefix (`MySqlDriver::PREFIX_MAX`, 16) — a long one gets
  a name of its own, like `payments_takings_index`;
- an indexed string is at most 191 characters (767 bytes of utf8mb4 — MySQL 5.7 makes COMPACT tables);
- no cascading foreign key on a column a stored generated column is computed from (MySQL refuses it; MariaDB does not);
- every timestamp that may not be null has a default.

## Kinds of data

- **Time** is UTC in every column. `Support\LocalTime` turns it into the shop's zone for display; a query never gets a
  Carbon in another zone (bindings are written in the value's own zone): `->utc()` first.
- **Money** is Toman in `decimal(14,2)` strings, handled through `Support\Money` (bcmath), never floats.
- **Traffic** is bytes in an integer column; admins type gigabytes (`Support\Traffic`).
- **Balances** are ledgers: a wallet (`wallet_transactions`) and an agent's traffic (`traffic_transactions`) keep a line
  per change with `balance_after`, and the last line is the balance — nothing else stores it (`Core\Database\Ledger`).
  A money write is tried again when MySQL aborts it for a deadlock ([Internals-Commerce](Internals-Commerce.md#ledgers)).
- **States** (`orders.status`, `payments.status` …) are one column each, moved by compare-and-swap, and rows worked
  through over time carry a lease (`lease_token`, `leased_until`) — see [Conventions](Conventions.md#only-once).
- **Shops**: every row of a shop carries `bot_id` and its model the `BelongsToBot` scope; servers, grants and the
  agency's tables belong to the installation as a whole — see [Architecture](Architecture.md#many-bots-one-database).
- **Secrets** (panel passwords, bot tokens, TOTP secrets, a website's captcha settings) are encrypted at rest by the
  `Encrypted` and `EncryptedArray` casts under `APP_KEY`; a value that no longer decrypts reads as empty and is logged.
- **Settings** a panel changes live in the `settings` table, a JSON value per key and bot (`Settings\Services\Settings`);
  the installation's own configuration is `config.php`, never the database ([Configuration](Configuration.md)).

## Indexes

An index answers a query that was measured — with `EXPLAIN` on a shop of 50,000 customers and 150,000 orders — and the
comment above it in `schema.php` names that query. The shop's column comes first where the shop scope reads; a tally's
columns go at the end so it is counted from the index alone (`orders`: status, type, amount); a foreign key needs no
index of its own once another index starts with its column. Searches are `LIKE '%…%'`, which no index helps, so a list
runs one search on the table searched and narrows itself by the ids it found (`Core\Database\Page::matches()`).

## Database drivers

A database is a driver of the [driver kernel](Drivers.md): `Core\Database\Drivers\DatabaseDriver` is everything the app
asks of one — its form of connection settings, its PHP extensions, its connection, a probe (connect briefly, check the
version, count the tables), the SQL of a UTC column's local day for the dashboard, and the names a rebuild must drop.
No code outside a driver names a database.

- **MySQL / MariaDB** (`MySqlDriver`, offered by the installer): MySQL 5.7.8 or MariaDB 10.3 at least. The connection is
  utf8mb4, strict, UTC, and asks MySQL to report the rows an `UPDATE` *matched* rather than changed, so a
  compare-and-swap that writes what is already there still knows it won.
- **SQLite** (`SqliteDriver`, not offered): the test suite runs on it in memory, and a developer may use a file. It is
  not offered for a shop: PHP before 8.4 cannot begin the `IMMEDIATE` transactions that keep the bot, the panel and the
  cron from failing each other's writes with `SQLITE_BUSY`, its locking is unsafe on the network filesystems some hosts
  use, and one slow transaction holds every writer.

The installer's database step and «تنظیمات پنل › دیتابیس» (`Settings\Services\DatabaseSettings`) write the settings to
`config.php` only once the driver's probe answers, and a kept password is never sent to another host — not even for a
test. [Drivers](Drivers.md#a-database-driver) says how to add one; PostgreSQL would also need its sequences set after a
rebuild's copy and a case-insensitive search.

## Where to start

- `database/schema.php`, `database/upgrades/`
- `app/Core/Database/Schema.php`, `Upgrades.php`, `DatabaseManager.php`, `Drivers/`
- `app/Core/Database/Transitions.php`, `Lease.php`, `Ledger.php` — the patterns every module uses
- `app/Core/Database/Page.php`, `PageRequest.php`, `Sort.php` — paged lists
- `tests/Unit/Database/` — `SchemaTest`, `UpgradesTest` and the drivers' tests
