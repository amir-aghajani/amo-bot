# Running in production

What keeps an installed shop alive on its host: how its bots receive their updates, the scheduler, the settings to
check, the files' owner, the logs, and the owner's way back into a panel they are locked out of.
[Installation](Installation.md) comes first; [Backups](Backups.md) and [Upgrading](Upgrading.md) come with it.

## How the bot receives updates

The main bot and every agent's bot get their updates through **webhooks**: Telegram calls the shop's address for every
update, and nothing has to keep running on the host. In the panel, «تنظیمات پنل › ربات تلگرام», the «دریافت پیام‌ها»
card, «ثبت Webhook» — or, where the host has a terminal, `php bin/console bot:webhook:set`. It needs `APP_URL` on
`https://`; the panel says so, and offers nothing, while it is not. Each bot gets an address with a secret of its own;
an agent's bot's webhook takes at most 600 updates a minute and 300 from customers it has never seen in an hour (what
arrives past them is acknowledged and dropped, the log told once), and Telegram makes at most a few calls to it at once.
A new token of the same bot, saved in the panel, reaches it by its next webhook request; another bot's token — or a
changed webhook secret — needs «ثبت Webhook» again (the panel says so).

`bot:info` prints every bot's identity and webhook state, as Telegram reports them. An agent's bot that Telegram refuses
(its token revoked) or that another program polls keeps the reason on its row, which its agent and the owner see.

Long polling — `php bin/console bot:poll`, one process that polls every bot and runs the scheduler too — is a
developer's way to run the bots on their own machine ([Development](Development.md)); a shop on a host stays on
webhooks. A poller still running when the webhooks are set is told Telegram now sends to a webhook and stops — but
`--watch` starts it again, and it takes the webhooks down as it starts: stop it for good first.

## The scheduler

The shop's timed work — card receipts nobody reviewed, deliveries a dying process left, unpaid orders that expire,
broadcasts, automatic renewals, the sync of every service with its panel, reminders, the report groups' queue and the
housekeeping — runs in the scheduler, which the host's cron starts every minute in one of two ways:

- **a cron job that runs `php bin/console schedule:run`** — cPanel › *Cron Jobs*, the line in
  [Installation](Installation.md#shared-hosting);
- **the cron address** — `https://your-domain/cron/<CRON_TOKEN>` (set the token in «تنظیمات پنل › پیشرفته»), called
  every minute by the host's cron (`curl`) where it cannot run PHP's command line or PHP runs as another user than the
  cron ([file ownership](#file-ownership)), or by an outside cron or uptime service on a host without cron.

One run goes at a time (a lock), and one run's time is shared over its tasks, so it does not grow with the number of
agents' bots. The dashboard's system card says when it last ran. What runs, and how often (`bootstrap/schedule.php`):

| Every | Task |
|---|---|
| minute | card receipts nobody reviewed inside their method's window, accepted |
| minute | paid orders a process that died left undelivered, delivered (a failed delivery waits for support) |
| minute | broadcasts, a batch at a time |
| minute | «افزودن زمان و حجم» and «هدیه همگانی» no open screen is working on |
| minute | the report groups' messages, at Telegram's pace |
| 10 minutes | a renewed service's next period, where the paid one ended |
| 15 minutes | every running service read from its panel, then «یادآوری» |
| 30 minutes | «تمدید خودکار» |
| hour | unpaid orders nobody touched for 48 hours, cancelled |
| hour | the updates the bots took (kept 48 hours, so one Telegram sends again is served once), forgotten |
| hour | the websites' ended sessions and expired sign-in challenges, and the notices half a year old, forgotten |
| hour | the pictures of tickets closed a month ago, and the group's week-old reports of closed tickets, removed |
| day | AmoBot's newest release read from GitHub, so the dashboard says when one is out ([Upgrading](Upgrading.md)) |

## Checklist

- `'APP_DEBUG' => false` in `config.php` (as the installer leaves it). `true` adds a failure's details (its class,
  message and the code it ran through) to the server's 500 answers — only to a request made on the server itself,
  never through a proxy or a tunnel — and to nothing else; still, switch it on only to find a fault, and off again.
- `APP_URL` is the `https://` address the shop is reached at, its sub-folder included (`https://example.com/shop`);
  the panel's login is strong ([the panel's login](#the-panels-login)).
- Behind Cloudflare or another reverse proxy, list them in `TRUSTED_PROXIES` (addresses or CIDR ranges, apart by
  commas — for Cloudflare its published ranges) so the sign-in throttle counts each visitor, not the proxy — unless the
  host's web server already restores the visitor's address (Apache's mod_remoteip). The panels' cookie is secure
  whenever the browser came over https — said by the request itself, or by a proxy's `X-Forwarded-Proto` or
  Cloudflare's `CF-Visitor`; behind a proxy that says neither, `SESSION_SECURE_COOKIE` forces it.
- The document root is `public/` wherever the host lets you set it.
- Times are kept in UTC; `APP_TIMEZONE` («تنظیمات پنل › برنامه») only sets how they read, and may change at any time.
- The scheduler runs every minute ([the scheduler](#the-scheduler)), and the bot gets its updates — the dashboard's
  system card says both (the owner's alone).
- PHP takes the pictures customers send: `upload_max_filesize` `10M` and `post_max_size` `12M`
  ([what you need](Installation.md#what-you-need)).

## File ownership

Every file the app writes — `config.php`, the install and recovery keys, the scheduler's state, the sign-in throttle's
counts, the pictures it keeps, the log, the sessions' folder — is made for the user PHP runs as. How far it is closed to
everyone else depends on who owns the app's folder, which the app reads as it starts:

- **PHP runs as the account that owns the app** — PHP-FPM, suEXEC, suPHP, LiteSpeed's LSAPI, CloudLinux, a console
  command: the common case, and the one to want. Every file is its owner's alone (`0600`, its folders `0700`): a shared
  host has other accounts, and `config.php` holds the database's password, the bot's token and the key to everything
  kept encrypted. A host without the POSIX functions to tell (Windows) counts as this one.
- **PHP runs as another, server-wide user** — Apache's `mod_php` (DSO). Files are made `0644`, folders `0755`, so the
  account's owner can still open `config.php` and the keys the installer and the recovery write in the host's File
  Manager. On such a host every site's PHP is that one user anyway — an owner-only mode would protect nothing and only
  lock the owner out —, so the other accounts on the server can read the shop's files, `config.php` included. Ask the
  host for PHP-FPM or suEXEC, or choose another host.

A file the app writes again keeps the permissions it has (a `config.php` you made readable to your group stays so). PHP's
own error log, `storage/logs/php-errors.log`, is made with PHP's own permissions. Either way the owner of every file must
be the user PHP runs as:

- The host's cron and its terminal run as your account — the user PHP runs as in the first case. Under `mod_php` a
  command run there would leave files the web server's PHP cannot write (the scheduler's state, the day's log): have the
  cron call the cron address instead ([the scheduler](#the-scheduler)).
- Files restored from a backup must belong to that user too.

## Uploads and the host's disk

Customers send pictures — a card transfer's receipt from the shop's website, a ticket's picture from the website — and
support may add one to a ticket's answer; the owner may upload a QR background. Each is judged by its bytes (JPEG, PNG or
WebP), 10 MB at most (a QR background, 5 MB), and written again by GD without anything of the sender's file but its
pixels. The shop holds them to what a host can bear:

- **A customer** uploads 10 pictures an hour — receipts and tickets' together.
- **A shop** takes 1 GB of pictures in a day — every picture it keeps, support's too; past it every upload is refused
  with a `503` until the day is over, and the log says so once.
- **The disk**: no picture is taken while less than 200 MB is free on the disk `storage/` is on (a `503`, the log told).

Pictures sent in the bot stay Telegram's: the shop keeps only their reference. A closed ticket's uploaded pictures are
removed a month after it closed ([the scheduler](#the-scheduler)). The shop's sign-in emails have budgets of their own
too — a shop's hour and day, and the whole installation's ([limits](Store-API-Sign-In.md#limits-and-lifetimes)).

## Health and logs

- `GET /health` answers `200` `{"status": "ok", "version": …, "installed": …, "database": "ok"}` while the database
  answers, and `503` with `"status": "degraded"` and `"database": "unavailable"` while it does not — point an uptime
  monitor at it. It names no server detail; why the database did not answer is the log's.
- Logs: `storage/logs/app-YYYY-MM-DD.log` (kept `LOG_MAX_FILES` days, at `LOG_LEVEL`) and `storage/logs/php-errors.log`
  (PHP's own warnings, which never go into an answer). Secrets — bot tokens, the webhooks' and the cron address's
  secrets, a mail server's password — are taken out of every line.
- Every request gets an id of its own: every answer says it (`X-Request-Id`), and every line the request writes carries
  it (`request_id`). A failure of the server's shows it in the panel as «کد پیگیری» (under «جزئیات فنی», or after the
  error's words): search the logs for it to find what happened. A line written for someone signed in says who, too
  (`actor`: your login, an agent, one of the shop's admins on its website) — and every change a website admin asks is
  a line of its own.
- Failures of the panels' own pages — in the owner's browser or an agent's — land in the log too («A panel page failed
  …»), held to a size and a rate.

## The panel's login

Change it in the panel: «تنظیمات» at the foot of the sidebar › «تنظیمات پنل» › «ورود به پنل». It asks for the current
password — a wrong one counts as a failed sign-in, as at the login page — and a new username, a new password, or both (a
new password left blank keeps the one you have). Saving signs every other browser and device out of the panel; yours
stays signed in.

Locked out — the password, or the username, lost? No shell needed: on the sign-in page press «رمز را فراموش کرده‌اید؟»,
then «ساختن کلید». The panel writes a one-time key to `storage/recovery-key.txt`; open that file in the host's File
Manager, paste its text into the form with a new username and password, and you are signed in under them (every other
browser is signed out). Only someone with the host's files can read the key; it works for an hour, the same one while it
does, and it is deleted once used. Wrong keys count as failed sign-ins.

Failed sign-ins to the owner's panel are counted: 10 from one address in 15 minutes, and 50 for one username from every
address together (a sign-in that works does not clear the second), then it waits. An agent signs in to their panel only
with the one-time link the main bot gives them ([Agency](Agency.md)).

## What keeps it safe

PHP answers JSON — and the pictures it keeps, as their bytes — and nothing else, each answer with a sandboxing
Content-Security-Policy, `nosniff`, no framing, and no caching but a picture's own few minutes; a failure's details are
never a visitor's; a JSON body is read to 1 MiB and a picture to its own limit; uploads are judged by their bytes and
written again; every sign-in is throttled and fails closed when the throttle cannot count; a fresh upload's installer
asks for a key only the host's files hold; every file the app writes is its owner's alone where PHP runs as the account
that owns the app ([file ownership](#file-ownership)); and secrets at rest (panel
passwords, agents' bot tokens and webhook secrets, the websites' secrets) are encrypted with `APP_KEY`.
[Architecture](Architecture.md#failures-and-hardening) says more, and [Security](Security.md) how to report a weakness.
