# Installation

AmoBot installs from a release zip on shared hosting — cPanel, DirectAdmin and the like —, the one way it is supported.
A web installer sets it up from the browser: no shell, no Composer, no Node on the host. Once it is installed,
[Running in production](Running-In-Production.md) keeps it running and the [Owner's guide](Owners-Guide.md) sets the
shop up.

## What you need

- A domain with **HTTPS**: Telegram sends webhooks only to `https://` addresses, and the panels' session cookie is
  marked secure over it.
- **PHP 8.2, 8.3 or 8.4** with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `bcmath` and `fileinfo` — and `gd` for the QR
  card a delivered service comes with and for pictures written again without their metadata (without it the bot sends
  the link as text), and `zip` and `sodium` for installing a new release from the panel ([Upgrading](Upgrading.md)) —,
  a `memory_limit` of 64M or more, and write access to `storage/` and to the shop's folder (where the installer writes
  `config.php`). The web installer checks the same list, and an older PHP is stopped before it
  runs. Best is PHP that runs as your account — PHP-FPM, suEXEC, LiteSpeed's LSAPI, as most hosts do —: the files the
  shop writes are then yours alone; under Apache's `mod_php` other accounts on the server can read them
  ([file ownership](Running-In-Production.md#file-ownership)).
- **Uploads of 10 MB**: customers send receipts and tickets' pictures up to 10 MB, and a QR background may be 5 MB. PHP's
  defaults take less — set `upload_max_filesize` to `10M` and `post_max_size` to `12M` (cPanel › *MultiPHP INI Editor*,
  or the host's `php.ini`). Below them a phone's photo is refused with «فایل بزرگ‌تر از حد مجاز سرور است.»
- **MySQL 5.7.8+ or MariaDB 10.3+**: an empty database and a user with every privilege on it (the installer checks the
  version). SQLite is the test suite's and a developer's database, not a live shop's.
- **A cron job every minute** (cPanel › *Cron Jobs*) for the scheduler — or, on a host without cron, an outside service
  that calls an address every minute ([the scheduler](Running-In-Production.md#the-scheduler)).
- A Telegram bot's token, from @BotFather — the installer takes it, or the panel later.

## The release zip

Install from a **release zip** — a GitHub release's, or one you build ([Releasing](Releasing.md)). It carries the PHP
packages (`vendor/`) and the built panels (`public/admin/`, `public/agent/`, `public/assets/`), and nothing a running
shop does not read. Never upload a clone of the repository.

## Shared hosting

On cPanel (DirectAdmin and the rest have the same parts under other names):

1. Create a database and a user with all privileges on it (cPanel › *MySQL Databases*).
2. Pick PHP 8.2 or newer for the domain (cPanel › *MultiPHP Manager* or *Select PHP Version*) with the extensions above.
3. Upload the zip and extract it. The zip holds one folder, `amobot-<version>/`: its contents are the shop — rename the
   folder (`amobot`, as below) or move its contents where they belong. Best: put the files **outside** `public_html` and
   point the domain's document root at the release's `public/` folder (cPanel › *Domains*). If you cannot change it,
   move the release's contents **into** `public_html`: the root `.htaccess` forwards every request into `public/` and
   refuses the rest — every other folder carries a deny-all `.htaccess` of its own, and `config.php` (and any copy an
   editor left beside it) is refused even without mod_rewrite.
4. Open `https://your-domain/` and follow the web installer ([the web installer](#the-web-installer)).
5. Point Telegram at the shop: in the panel, «تنظیمات پنل › ربات تلگرام», the «دریافت پیام‌ها» card, «ثبت Webhook»
   (Telegram needs the site's address on `https://`). It does it for the main bot and every agent's bot. Where the host
   has a terminal (cPanel › *Terminal*), `php bin/console bot:webhook:set` does the same, and `php bin/console bot:info`
   shows what Telegram says of every bot.
6. Add the scheduler (cPanel › *Cron Jobs*, every minute). Use the PHP of the version you picked — on cPanel it is
   `/opt/cpanel/ea-php82/root/usr/bin/php` (`ea-php83` for 8.3 …), not always the plain `php`:

   ```
   * * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/amobot/bin/console schedule:run --quiet >> /home/USER/amobot/storage/logs/cron.log 2>&1
   ```

   Run it once by hand first: it must print nothing (or the tasks it ran). Where the host's cron cannot run PHP's
   command line, or where PHP runs as a server-wide user rather than your account (Apache's `mod_php`,
   [file ownership](Running-In-Production.md#file-ownership)), have the cron call the cron address instead: set its
   token in the panel, «تنظیمات پنل › پیشرفته», copy the address with «کپی آدرس Cron», and make the line
   `curl -fsS https://your-domain/cron/<CRON_TOKEN> > /dev/null`. A host without cron at all: have an outside cron or
   uptime service call that address every minute.

## The web installer

Until the shop is installed, its address opens the owner's panel on its installer (`/admin/install`), and every other
address of the panel goes there; the shop's own routes — the panels' API, the webhooks, the cron address — answer `503`.

1. **The install key** («کلید نصب»). A fresh upload would answer its installer to whoever reaches it first, so the
   installer first asks for a key: the first visit writes it to `storage/install-key.txt`. Open that file in the host's
   File Manager (cPanel › *File Manager*) and paste its text. Only someone with the host's files can read it; it is
   deleted when the installation ends.
2. **«پیش‌نیازها»** — the list above, each with whether it is there; ask the host for what is missing, then check again.
3. **«دیتابیس»** — the driver (MySQL / MariaDB) and its settings: the host (`localhost` on shared hosting), the port,
   the database's name, the user and the password, and under «تنظیمات پیشرفته» a socket and a table prefix. «اتصال و
   ساخت جدول‌ها» makes `config.php` beside the app (with an `APP_KEY` of its own), tries the database, writes its
   settings into `config.php` only once it answers to them, and makes the tables.
4. **«ورود پنل»** — the owner's username and password (8 characters at least, 72 bytes at most), kept in `config.php` as
   the password's bcrypt hash.
5. **«سایت و ربات»** — the shop's name and the address it is reached at (`APP_URL`: `https://`, its sub-folder included,
   no `/` at the end) — the bot's webhooks, the cron address and the agents' sign-in links are built on it —, and, now
   or later, the bot's token, checked with Telegram.
6. **«پایان نصب»** — refused while the requirements, the tables or the panel's login are missing; then
   `storage/installed.lock` is written, the install key deleted, and the panel opens on its sign-in.

Every step can be taken again until the end. Once installed, `/admin/install` goes to the sign-in, and the installer's
API answers `404`. Then point Telegram at the shop and add the scheduler, if not done yet (steps 5 and 6 of
[shared hosting](#shared-hosting)).

## The panel stays on its loading screen

The page arrives but its scripts never run: the browser refuses a script its server sends as some other type. The
browser's console (F12) says so — *Expected a JavaScript-or-Wasm module script but the server responded with a MIME
type of "application/octet-stream"*.

- **0.1.0 on a LiteSpeed server** (most Iranian shared hosts; LiteSpeed's error pages read *Access to this resource on
  the server is denied!*): that release's `public/assets/.htaccess` left the compressed scripts without a type LiteSpeed
  reads. Take that file from 0.1.1 or later, or add inside its `<IfModule mod_mime.c>` block a `<FilesMatch>` each
  with `ForceType text/javascript` for `"\.js\.br$"`, `ForceType text/css` for `"\.css\.br$"` and `ForceType
  image/svg+xml` for `"\.svg\.br$"`. Then load the page with **Ctrl+Shift+R**: a browser keeps those files for a year,
  and a plain reload would use the broken copies.
- **After an upload or an upgrade by hand**: everything under `public/assets/` must be the release's own, whole — upload
  that folder again.

## Next

- [Running in production](Running-In-Production.md): the bot's updates, the scheduler, the checklist, the logs.
- [Configuration](Configuration.md): what `config.php` holds and how to change it.
- The [Owner's guide](Owners-Guide.md): a server, a plan and a way to pay are what the bot needs to sell.
