# Database upgrades

Before the first release (0.1.0) there were no migrations: `database/schema.php` was the database, and
`php bin/console db:rebuild` followed it. From the first release on, **every change of `database/schema.php` ships an
upgrade here** — the same change, for a database of the release before.

- One file a release that changes the tables, named by the version it brings the database to: `0.2.0.php`.
- It returns `static function (Builder $schema, Connection $db): void` — Laravel's schema builder and the shop's
  connection (`Illuminate\Database\Schema\Builder`, `Illuminate\Database\Connection`) — and does what the release's
  edit of `schema.php` did: a column added (with a default, or nullable — the rows there have none), an index, a table.
- It uses the schema builder and the connection alone: nothing of the app's code, which changes from release to
  release while an upgrade must keep working on the next ones' too.
- It can be run again after it stopped half-way (MySQL changes a table outside any transaction): ask before you add
  (`$schema->hasColumn()`, `hasTable()`, `hasIndex()`).

A shop runs, in version order, the upgrades newer than the version its database is at — `storage/installed.lock` says
it — and not newer than its code's (`App\Core\Database\Upgrades`), as the owner's panel installs a release
(`App\Modules\Updates\Updater`), or once files put in place by hand are — the panel's «تنظیمات پنل › به‌روزرسانی».
The lock's version moves after each, so an upgrade that fails is where the next try starts. A fresh install makes
`schema.php`'s tables as they stand and records its code's version: it runs none.
