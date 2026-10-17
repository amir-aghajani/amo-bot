[فارسی](fa/Upgrading.md)

# Upgrading

A new release replaces the app's files. The shop's own — `config.php` and `storage/` — stay, and the database is
brought along by the upgrades the release ships, with every row kept. The owner's panel does all of it with one press.
On a host where it cannot, you replace the files by hand and the panel still runs the database's part.

## From the panel

«تنظیمات پنل › به‌روزرسانی» shows the version the shop runs and the newest release on GitHub, with its notes. The
scheduler reads the newest release once a day, so the dashboard's «وضعیت سیستم» card says when one is out; «بررسی
دوباره» reads it at once.

**Back the database up first** ([Backups](Backups.md)). Then press «به‌روزرسانی به نسخه …» and confirm. The update
goes a step a request, so keep the page open until it is done; if it closes, open it again and «ادامه به‌روزرسانی» goes
on from the same step:

1. **«دانلود و بررسی امضا»** — the release's `release.json` (its manifest: the version, the zip's size and sha256, the
   PHP and the extensions it needs) and its signature, checked against the key built into the shop
   (`resources/release-key.pub`). The shop installs nothing its maker did not sign, so neither GitHub nor anyone between
   it and the host can hand it code of their own. Then the zip, checked against the manifest. Files come from
   github.com and GitHub's own storage alone.
2. **«باز کردن بسته»** — the zip unpacked into `storage/updates/<version>/`, a slice a request. An entry that would land
   anywhere but the app's own folders refuses the whole zip.
3. **«بررسی هاست»** — the host's PHP and extensions against what the release needs, and whether PHP may swap the app's
   folders: it runs as the account that owns them, may write them, and `storage/` is on the same disk.
4. **«نصب»**, in one request. It first waits up to a minute for a scheduler run under way to end. Meanwhile every other
   request is told the shop is updating — «فروشگاه در حال به‌روزرسانی است؛ چند لحظه دیگر دوباره امتحان کنید.», a 503
   with `Retry-After` of a few seconds; Telegram and the cron come again by themselves. Each folder of the app the
   release replaces (`app/`, `vendor/`, `public/` …) is moved aside into `storage/updates/previous/` and the release's
   is moved into its place, the root files are replaced, the database's upgrades run, and two caches of the old code are
   cleared — the router's saved table of addresses (`storage/cache/routes`) and PHP's OPcache, where the host lets a
   script clear it. Then the new version is recorded in `storage/installed.lock`. The page then reloads on the new
   panel.

`config.php` and everything under `storage/` are never touched. The `.htaccess` files are the release's, with what
cPanel's MultiPHP Manager and INI Editor wrote into them (the shop's PHP version and settings) carried over. Anything
else you put inside a folder or file the release replaces (`public/`, say) is in `storage/updates/previous/` afterwards.

When something fails:

- **Before the install**, the update stops and says why. Press «تلاش دوباره» once it is fixed, or «لغو به‌روزرسانی» to
  give up: what it fetched is deleted. Once the install has begun, it can no longer be cancelled.
- **After the swap**, the install takes itself back — the previous version's folders return — and says why; the update
  can be tried again.
- **An install a crash cut short** is finished by the next «ادامه به‌روزرسانی».

An installed update is taken back with «بازگرداندن نسخه قبلی» while the version it replaced is kept, until the next
update — unless the update changed the database: then only your backup takes it back.

What the panel's update needs, and says it needs where it is missing:

- PHP's **sodium** and **zip** extensions — the host's PHP settings (cPanel › *Select PHP Version* › *Extensions*);
- PHP running as your account (PHP-FPM, LSAPI, suEXEC — the usual on shared hosting), so it may change the app's files;
- free disk of about four times the release's zip;
- a shop installed from a release zip — never a clone of the repository — and a release with its signed files: every
  release has them.

## By hand

1. **Back up** the database, `config.php` and `storage/` ([Backups](Backups.md)).
2. **Replace the files.** In the host's File Manager, delete every folder and file of the old release except
   `config.php` and `storage/`, then upload and extract the new release's zip in their place (its own `storage/` folder
   may be left out). Extracting over the old files would leave behind what the new release no longer has — a file of
   `config/` that every request still reads, the old panels' scripts. The new `.htaccess` lacks what cPanel wrote into
   the old one (the PHP version): choose the version again in cPanel › *MultiPHP Manager*, or copy those lines over.
3. **Bring the database along.** Open «تنظیمات پنل › به‌روزرسانی». New files on a database of an older version show as
   an update under way: «ادامه به‌روزرسانی» runs the release's database upgrades and records the version, and nothing
   else.

Open tabs of the panels pick up the new build by themselves: a tab opened before the upgrade asks for the old build's
files, finds them gone and reloads once.

## When something went wrong

- **The shop answers «در حال به‌روزرسانی»** — an install is under way. The answer stops after two minutes at most, even
  when the install died, and the update screen says how it stands.
- **The shop answers nothing but errors after an update** — put the previous version back by hand: move the new
  folders and files aside, move each folder and file in `storage/updates/previous/` back to where it was, then restore
  the database from your backup if the update had changed it.

## The database's upgrades

Every release that changes the database ships an upgrade for it, `database/upgrades/<version>.php`. The upgrades newer
than the version `storage/installed.lock` records (a lock that records none is 0.1.0's) run in version order, up to the
release's own. The version moves after each one, so an upgrade that fails is where the next try starts. How an upgrade
is written, and the rules it keeps: [Database](Database.md#upgrades).

**0.7.0** changes the database: its upgrade gives the wallets' lines, the agents' traffic lines and the tickets'
messages a request key, so a wallet credit or debit, a traffic adjustment or a ticket answer that support sends again
after a lost answer is done once. The panel's update runs it by itself (after an upgrade by hand, «ادامه به‌روزرسانی»
does); nothing is asked of you, and the lines written before keep no key. Since it changes the database, going back to
0.6.0 afterwards takes your backup.
