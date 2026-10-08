# Upgrading

A new release replaces the app's files; the shop's own — `config.php` and `storage/` — stay, and the database is brought
along by the upgrades the release ships, with every row kept. The owner's panel does all of it with one press; a host
where it cannot is upgraded by hand, the panel still running the database's part.

## From the panel

«تنظیمات پنل › به‌روزرسانی» says the version the shop runs and the newest release on GitHub, with its notes — read once
a day by the scheduler, so the dashboard's system card says when one is out, and again on «بررسی دوباره». «به‌روزرسانی
به نسخه …» asks first — back the database up before you confirm ([Backups](Backups.md)) — and then takes the update a
step a request, the page open meanwhile:

1. **Download** — the release's `release.json` (its manifest: the version, the zip's size and sha256, the PHP and the
   extensions it needs) and its signature, checked against the key built into the shop (`resources/release-key.pub`):
   the shop installs nothing its maker did not sign, so neither GitHub nor anyone between it and the host can hand it
   code of their own. Then the zip, checked against the manifest. Files come from github.com and GitHub's own storage
   alone.
2. **Extract** — the zip unpacked into `storage/updates/<version>/`, a slice a request; an entry that would land
   anywhere but the app's own folders refuses the whole of it.
3. **Preflight** — the host's PHP and extensions against what the release needs, and whether PHP may swap the app's
   folders: it runs as the account that owns them, may write them, and `storage/` is on the same disk.
4. **Install**, in one request — every other request is told the shop is updating (a 503 with `Retry-After`, a few
   seconds; the bots' webhooks and the cron address come again by themselves): each folder of the app the release
   replaces (`app/`, `vendor/`, `public/`, …) moved aside into `storage/updates/previous/` and the release's moved into
   its place, the root files replaced, the database's upgrades run, the compiled code and the router's table forgotten,
   the new version recorded in `storage/installed.lock`. The page reloads on the new panel.

`config.php` and everything under `storage/` are never touched. The `.htaccess` files are the release's, with what
cPanel's MultiPHP Manager and INI Editor wrote into them (the shop's PHP version and settings) carried over; anything
else you put inside a folder or file the release replaces yourself (`public/`, say) is in `storage/updates/previous/`
afterwards.

Anything that fails after the swap takes it back by itself — the previous version's folders return — and says why; the
update can be tried again or given up («لغو به‌روزرسانی», until its install began: what it fetched is deleted). An
install a crash cut short is finished by the next «ادامه به‌روزرسانی». An installed update is taken back with
«بازگرداندن نسخه قبلی» while the version it replaced is kept (until the next update) — unless it changed the database:
then going back is your backup's.

What the panel needs, and says it needs where it is missing:

- PHP's **sodium** and **zip** extensions — the host's PHP settings (cPanel › *Select PHP Version* › *Extensions*);
- PHP running as your account (PHP-FPM, LSAPI, suEXEC — the usual on shared hosting), so it may change the app's files;
- a release with its signed files, and a copy of AmoBot that has the key — every release does.

## By hand

1. **Back up** — the database, `config.php` and `storage/` ([Backups](Backups.md)).
2. **Replace the files**: in the host's File Manager, delete every folder and file of the old release but `config.php`
   and `storage/`, then upload and extract the new release's zip in its place (its own `storage/` folder may be left
   out). Extracting over the old files would leave behind what the new release no longer has — a file of `config/` that
   every request still reads, the old panels' scripts. The new `.htaccess` lacks what cPanel wrote into the old one (the
   PHP version): choose the version again in cPanel › *MultiPHP Manager*, or copy those lines over.
3. **Bring the database along**: open the panel's «تنظیمات پنل › به‌روزرسانی». The new files with a database of the
   version before show as an update under way — «ادامه به‌روزرسانی» runs the release's database upgrades and records
   the version, nothing else.

Open tabs of the panels pick up the new build by themselves: a tab opened before the upgrade asks for the old build's
files, finds them gone and reloads once.

## When something went wrong

- **The shop answers «در حال به‌روزرسانی»** — an install is under way; the answer stops after two minutes at most, even
  when the install died, and the update screen says how it stands.
- **The shop answers nothing but errors after an update** — put the previous version back by hand: move each folder and
  file in `storage/updates/previous/` back to where it was (the new ones aside first), then restore the database from
  your backup if the update had changed it.

## The database's upgrades

Before the first release (0.1.0) there were no migrations: the schema was one file, `database/schema.php`, which
`php bin/console db:rebuild` follows on a developer's database ([Architecture](Architecture.md#data)). From the first
release on, every change of the schema ships as an upgrade — `database/upgrades/<version>.php`, the rule in that
folder's README —, run in version order from the version `storage/installed.lock` records (a lock that records none is
0.1.0's) to the code's. The version moves after each, so an upgrade that fails is where the next try starts.
