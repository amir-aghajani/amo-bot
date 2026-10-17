[فارسی](fa/Installation.md)

# Installation

AmoBot is installed from a release zip on shared hosting, such as cPanel or DirectAdmin; this is the only supported way
to install it. A web installer sets it up in the browser: the host needs no shell, no Composer and no Node. Once it is installed,
[Running in production](Running-In-Production.md) keeps it running and the [Owner's guide](Owners-Guide.md) sets the
shop up to sell.

## What you need

- **A domain with HTTPS.** Telegram sends webhooks only to `https://` addresses, and the panels' session cookie is
  marked secure over HTTPS.
- **PHP 8.2, 8.3 or 8.4** with the extensions `pdo_mysql`, `curl`, `mbstring`, `openssl`, `bcmath` and `fileinfo`, and
  a `memory_limit` of 64M or more. AmoBot does not start on an older PHP.
- **Three optional extensions.** The installer lists them as «اختیاری», and they hold nothing up:
  - `gd` — the QR card a delivered service comes with, and pictures written again without their metadata. Without it
    the bot sends a service's link as text, and uploaded pictures are kept as they came.
  - `sodium` and `zip` — installing a new release from the panel ([Upgrading](Upgrading.md)). Without them you upgrade
    by hand.
- **Write access** to `storage/` and to the shop's folder, where the installer writes `config.php`.
- **PHP that runs as your account** is best — PHP-FPM, suEXEC or LiteSpeed's LSAPI, as most hosts have it. The files
  the shop writes are then yours alone. Under Apache's `mod_php` the other accounts on the server can read them
  ([file ownership](Running-In-Production.md#file-ownership)).
- **Uploads of 10 MB.** Customers send receipts and ticket pictures of up to 10 MB, and a QR background may be 5 MB.
  PHP's defaults take less: set `upload_max_filesize` to `10M` and `post_max_size` to `12M` (cPanel › *MultiPHP INI
  Editor*, or the host's `php.ini`). With smaller limits a phone's photo is refused with «فایل بزرگ‌تر از حد مجاز سرور
  است.»
- **MySQL 5.7.8+ or MariaDB 10.3+**: an empty database, and a user with every privilege on it. The installer checks the
  version. SQLite is for the test suite and for developers, never for a live shop.
- **A cron job every minute** (cPanel › *Cron Jobs*) for the scheduler — or, on a host without cron, an outside service
  that calls an address every minute ([the scheduler](Running-In-Production.md#the-scheduler)).
- **A Telegram bot's token**, from @BotFather. The installer takes it, or you add it in the panel later.

The installer's first step checks this list, all but PHP's version.

## The release zip

Install from a **release zip**: a GitHub release's, or one you build ([Releasing](Releasing.md)). It carries the PHP
packages (`vendor/`) and the built panels (`public/admin/`, `public/agent/`, `public/assets/`), and nothing a running
shop does not need. Never upload a clone of the repository.

## Shared hosting

On cPanel (DirectAdmin and the others have the same parts under other names):

1. **Make the database.** In cPanel › *MySQL Databases*, create a database and a user, then, under *Add User To
   Database*, add the user to the database with *ALL PRIVILEGES*. cPanel puts your account's name before the database's
   and the user's names (`USER_amobot`): type these full names into the installer.
2. **Pick PHP.** In cPanel › *MultiPHP Manager* or *Select PHP Version*, choose PHP 8.2 or newer for the domain, with
   the extensions above.
3. **Upload the files.** Upload the zip in cPanel › *File Manager* and unpack it there (*Extract*). The zip holds one folder,
   `amobot-<version>/`, and its contents are the shop: rename the folder (`amobot`, as below) or move its contents
   where they belong.
   - Best: keep the files **outside** `public_html` and point the domain's document root (*Document Root*) at the
     release's `public/` folder (cPanel › *Domains*).
   - If you cannot change the document root, move the release's contents **into** `public_html`. The root `.htaccess`
     then forwards every request into `public/` and refuses the rest. Every other folder has a deny-all `.htaccess` of
     its own, and `config.php` (with any copy an editor left beside it) is refused even without mod_rewrite.
4. **Run the installer.** Open `https://your-domain/` and follow it ([the web installer](#the-web-installer)).
5. **Point Telegram at the shop.** In the panel, open «تنظیمات پنل › ربات تلگرام» and press «ثبت Webhook» on the
   «دریافت پیام‌ها» card. It registers the main bot and every agent's bot. Telegram needs the site's address on
   `https://`.
6. **Add the scheduler.** In the panel, open «تنظیمات پنل › پیشرفته». At «توکن Cron», press «تولید توکن تازه» (the
   button beside the field) and save. Two lines appear under the field: the cron address, and a cron line made for
   cPanel. Press «کپی خط Cron». Then, in cPanel › *Cron Jobs*, choose *Once Per Minute* from *Common Settings*, paste
   the line into the *Command* field and press *Add New Cron Job*. The line looks like this:

   ```
   curl -fsS https://your-domain/cron/<CRON_TOKEN> > /dev/null
   ```

   A minute or two later, the dashboard's «وضعیت سیستم» card shows «کارهای زمان‌بندی‌شده» as «فعال», with its last
   run. On a host without cron, press «کپی آدرس Cron» instead and give the address to an outside cron or uptime service
   that calls it every minute.

### The scheduler as a PHP command

Where the web server runs PHP as your account (PHP-FPM, suEXEC, LSAPI — not Apache's `mod_php`), the cron job may run
the scheduler's command instead, and no cron token is needed: a cron job runs as your account too, so the command and
the web server write files of the same owner. Use the PHP of the version you picked: on cPanel it is
`/opt/cpanel/ea-php82/root/usr/bin/php` (`ea-php83` for 8.3 …), not always the plain `php`. Put your account's home
folder in place of `/home/USER` (cPanel shows it as *Home Directory* under *General Information*), and paste this into
the *Command* field, with *Once Per Minute*:

```
/opt/cpanel/ea-php82/root/usr/bin/php /home/USER/amobot/bin/console schedule:run --quiet >> /home/USER/amobot/storage/logs/cron.log 2>&1
```

The dashboard's card says whether it runs, as above; `storage/logs/cron.log` stays empty while all is well and holds
whatever went wrong otherwise. Under `mod_php` keep to the panel's line: the command would run as another user than the
web server's PHP ([file ownership](Running-In-Production.md#file-ownership)).

## The web installer

Until the shop is installed, its address opens the owner's panel on its installer (`/admin/install`), and every other
address of the panel leads there. Meanwhile the shop's own routes — the panels' API, the webhooks, the cron address —
answer `503`.

1. **«کلید نصب»** — the install key. A freshly uploaded shop would let whoever reaches it first install it, so the
   installer asks for a key first. The first visit writes it to `storage/install-key.txt`: open that file in cPanel ›
   *File Manager*, copy its text and paste it into the form. Only someone with the host's files can read it, and it is
   deleted when the installation ends. If it cannot be written, the page asks you to give the web server write access
   to `storage/`. The browser tab keeps the key until the end, through a reload too; a browser that refuses to keep
   site data still installs, but asks for the key again after a reload.
2. **«پیش‌نیازها»** — the list above, each item marked as there or missing (an optional one with «اختیاری»). Ask the
   host for what is missing, then press «بررسی دوباره». The installer goes on once every required item is there.
3. **«دیتابیس»** — «نوع دیتابیس» (MySQL / MariaDB) and its settings: «هاست» (`localhost` on shared hosting), «پورت»,
   «نام دیتابیس», «نام کاربری» and «رمز عبور», and under «تنظیمات پیشرفته» «سوکت» and «پیشوند جدول‌ها». «اتصال و ساخت
   جدول‌ها» makes `config.php` beside the app, with an `APP_KEY` of its own; tries the database; writes the database's
   settings into `config.php` only once it answers to them; and makes the tables.
4. **«ورود پنل»** — the username and password you sign in to the panel with. The username is 2 to 64 characters (Latin
   letters, digits, `. _ @ -`); the password at least 8 characters and at most 72 bytes. `config.php` keeps only the
   password's bcrypt hash.
5. **«سایت و ربات»** — «نام فروشگاه»; «آدرس سایت», the address the shop is reached at (`APP_URL`: `https://`, its
   sub-folder included, no `/` at the end), which the bots' webhooks, the cron address and the agents' sign-in links are
   built on; and, if you have it now, «توکن ربات», which is checked with Telegram before it is saved.
6. **«پایان»** — what the installation did, and the two things to do after you sign in: register the webhook and set up
   the cron (steps 5 and 6 of [shared hosting](#shared-hosting)). «پایان نصب و ورود به پنل» is refused while the
   requirements, the tables or the panel's login are missing. Then `storage/installed.lock` is written, the install key
   is deleted, and the panel opens on its sign-in.

Every step can be taken again until the end. Once the shop is installed, `/admin/install` leads to the sign-in and the
installer's API answers `404`.

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
