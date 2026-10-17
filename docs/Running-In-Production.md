[فارسی](fa/Running-In-Production.md)

# Running in production

What keeps an installed shop alive on its host: how its bots receive their updates, the scheduler, the settings to
check, who owns the files, uploads, the logs, and the way back into the panel when you are locked out.
[Installation](Installation.md) comes first; [Backups](Backups.md) and [Upgrading](Upgrading.md) go with this page.

## How the bot receives updates

The main bot and every agent's bot get their updates through **webhooks**: Telegram calls the shop's address for each
update, so nothing has to keep running on the host. Register them in the panel: «تنظیمات پنل › ربات تلگرام», the
«دریافت پیام‌ها» card, «ثبت Webhook» ([Panel settings](Panel-Settings.md#the-telegram-bot)). Telegram calls only
`https://` addresses: while `APP_URL` is not on https, the card says so and the button is held.

- Each bot gets an address with a secret of its own.
- An agent's bot takes at most 600 updates a minute, and 300 an hour from customers it has never seen. What arrives
  past that is acknowledged and dropped, and the log says so once. Telegram makes at most 4 calls to an agent's bot at
  a time.
- A new token of the same bot, saved in the panel, is used from the bot's next update. Another bot's token, or a new
  webhook secret, needs «ثبت دوباره Webhook»; the panel says so.
- An agent's bot that Telegram refuses (its token revoked), or that another program polls, keeps the reason on its row,
  where its agent and the owner see it.

Long polling (`bot:poll`) is a developer's way to run the bots on their own machine ([Development](Development.md)); a
shop on a host stays on webhooks. Where the host has a terminal, the webhooks can be set from it too:
[Console commands](Console-Commands.md).

## The scheduler

The shop's timed work runs in the scheduler, which the host's cron starts every minute in one of two ways:

- **the cron address**, `https://your-domain/cron/<CRON_TOKEN>`: the line the panel makes ready in «تنظیمات پنل ›
  پیشرفته» («کپی خط Cron»), which cPanel's *Cron Jobs* runs, or an outside cron or uptime service that calls the address
  ([Installation](Installation.md#shared-hosting), step 6);
- **PHP's command line**, `bin/console schedule:run`, where the host's cron runs PHP as your account
  ([the scheduler as a PHP command](Installation.md#the-scheduler-as-a-php-command)).

One run goes at a time (a lock), and its time — 45 seconds — is shared out over its tasks, so a run does not grow with
the number of agents' bots. A task that belongs to a shop runs in every shop in turn, each with its own rules. The
dashboard's «وضعیت سیستم» card says when the scheduler last ran. What runs, and how often:

| Every | What runs |
|---|---|
| minute | Card-to-card receipts nobody reviewed within their method's window, approved ([Payment methods](Payment-Methods.md#approving-receipts-by-themselves)) |
| minute | Online payments not paid yet, asked about at their gateway — a few each run, in case a gateway's callback never came ([Payment methods](Payment-Methods.md#every-online-gateway)) |
| minute | Paid orders that a process left undelivered when it died, delivered (a failed delivery waits for support) |
| minute | Broadcasts, a batch at a time |
| minute | «افزودن زمان و حجم» and «هدیه همگانی» that no open screen is working on, a batch at a time |
| minute | «انتقال سرویس‌ها» that no open page is working on, a batch at a time; then the clients a rescue left on a panel that answers again, removed ([Servers](Servers.md#moving-its-services)) |
| minute | The report groups' messages, at Telegram's pace |
| 10 minutes | Unpaid orders nobody touched for 48 hours, cancelled — one made with a discount code sooner, once the code's hold is over; never one whose money may be with support ([Orders and payments](Orders-And-Payments.md)) |
| 10 minutes | A renewed service's next period, begun where the paid one ended |
| 10 minutes | «قرعه‌کشی شبانه», once the shop's clock has passed the hour of the draw — once a day ([Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md)) |
| 15 minutes | Every running service read from its panel, then «یادآوری» for the services near their end or low on traffic |
| 15 minutes | The services that are done with, removed from their panels by each shop's rules: ended services the set hours after their end, where the shop removes them ([Bot settings](Bot-Settings.md#ended-services)), and free tests that ended or never connected ([Test accounts](Test-Accounts.md#the-clean-up)) — a stopped agent's shop too |
| 30 minutes | «تمدید خودکار» |
| hour | The agents' terms: an agency's end and a month's minimum purchase warned of ahead, then the agent's selling suspended — or allowed again once the month's minimum is met ([Agency](Agency.md)) |
| hour | Housekeeping: the updates the bots took (kept 48 hours, so an update Telegram sends again is served once), the websites' ended sessions and expired sign-in challenges, notices half a year old, the pictures of tickets closed a month ago, and the report groups' week-old reports of closed tickets |
| day | AmoBot's newest release, read from GitHub, so the dashboard says when one is out ([Upgrading](Upgrading.md)) |

## Checklist

- **`'APP_DEBUG' => false`** in `config.php`, as the installer leaves it. `true` adds a failure's details (its class,
  its message and the code it ran through) to the server's 500 answers — but only to a request made on the server
  itself, never through a proxy or a tunnel. Switch it on only to find a fault, and off again.
- **`APP_URL`** is the `https://` address the shop is reached at, its sub-folder included (`https://example.com/shop`).
- **The panel's login** is strong ([the panel's login](#the-panels-login)).
- **Behind Cloudflare or another reverse proxy**, list the proxies in `TRUSTED_PROXIES`, so the sign-in limits count
  each visitor rather than the proxy — unless the host's web server already gives PHP the visitor's address (Apache's
  mod_remoteip). How to write it: [Configuration](Configuration.md#behind-a-proxy-or-under-a-folder).
- **The document root** is `public/` wherever the host lets you set it.
- **The time zone**: times are kept in UTC; «منطقه زمانی» («تنظیمات پنل › برنامه») only sets how they read, and may
  change at any time.
- **The scheduler runs every minute and the bot gets its updates**: the dashboard's «وضعیت سیستم» card says both.
- **PHP takes the pictures customers send**: `upload_max_filesize` `10M` and `post_max_size` `12M`
  ([what you need](Installation.md#what-you-need)).

## File ownership

Every file the app writes — `config.php`, the install and recovery keys, the scheduler's state, the sign-in limits'
counts, the pictures it keeps, the log, the sessions' folder — is made for the user PHP runs as. How far it is closed
to everyone else depends on who owns the app's folder, which the app reads as it starts:

- **PHP runs as the account that owns the app** — PHP-FPM, suEXEC, suPHP, LiteSpeed's LSAPI, CloudLinux, a console
  command. This is the common case, and the one to want: every file is its owner's alone (`0600`, its folders `0700`).
  That matters on a shared host, which has other accounts, since `config.php` holds the database's password, the bot's
  token and the key to everything kept encrypted. A host without the functions to tell (Windows) counts as this case.
- **PHP runs as another, server-wide user** — Apache's `mod_php` (DSO). Files are made `0644` and folders `0755`, so you
  can still open `config.php` and the install and recovery keys in the host's File Manager. On such a host every site's
  PHP is that one user anyway — an owner-only mode would protect nothing and only lock you out —, so the other accounts
  on the server can read the shop's files, `config.php` included. Ask the host for PHP-FPM or suEXEC, or choose another
  host.

A file the app writes again keeps the permissions it has (a `config.php` you made readable to your group stays so). PHP's
own error log, `storage/logs/php-errors.log`, is made with PHP's own permissions. Either way, every file must belong to
the user PHP runs as:

- The host's cron and its terminal run as your account — the user PHP runs as in the first case. Under `mod_php` a
  command run there would leave files the web server's PHP cannot write (the scheduler's state, the day's log): have the
  cron call the cron address instead ([the scheduler](#the-scheduler)).
- Files restored from a backup must belong to that user too.

## Uploads and the host's disk

Customers send pictures — a card transfer's receipt or a ticket's picture from the shop's website — and support may add
one to a ticket's answer; the owner may upload a QR background. Each is judged by its bytes (JPEG, PNG or WebP) and may
be 10 MB at most (a QR background, 5 MB). Where PHP has `gd`, it is written again with nothing of the sender's file but
its pixels. The shop holds uploads to what a host can bear:

- **A customer** uploads 10 pictures an hour — receipts and tickets' pictures together.
- **A shop** takes 1 GB of pictures a day — every picture it keeps, support's too. Past that, every upload is refused
  with a `503` until the day is over, and the log says so once.
- **The disk**: no picture is taken while less than 200 MB is free on the disk `storage/` is on (a `503`, and a line in
  the log).

Pictures sent in the bot stay on Telegram: the shop keeps only their reference. A closed ticket's uploaded pictures are
removed a month after it closed ([the scheduler](#the-scheduler)). The shop's sign-in emails have budgets of their own
too — a shop's hour and day, and the whole installation's ([limits](Store-API-Sign-In.md#limits-and-lifetimes)).

## Health and logs

- **`GET /health`** answers `200` with `{"status": "ok", "version": …, "installed": …, "database": "ok"}` while the
  database answers, and `503` with `"status": "degraded"` and `"database": "unavailable"` while it does not — point an
  uptime monitor at it. It names nothing about the server; why the database did not answer is in the log.
- **The logs** are in `storage/logs/`: `app-YYYY-MM-DD.log`, the app's own (kept `LOG_MAX_FILES` days, at `LOG_LEVEL`),
  and `php-errors.log`, PHP's own warnings and errors, which never go into an answer. The app takes secrets out of every
  line it writes: bot tokens, the secret part of the webhooks', the cron's and the payment callbacks' addresses, a login
  written into an address (a proxy's), and a key carried in an address's query. `php-errors.log` is written by PHP
  itself and nothing is taken out of it: read it before you share it.
- **Every request has an id**: every answer says it (`X-Request-Id`), and every line the request writes carries it
  (`request_id`). A server failure shows it in the panel as «کد پیگیری» (under «جزئیات فنی», or after the error's words):
  search the logs for it to find what happened. A line written for someone signed in also says who (`actor`: your
  login, an agent, or one of the shop's admins on its website), and every change a website admin asks for gets a line of
  its own.
- **Failures of the panels' own pages** — in the owner's browser or an agent's — reach the log too («A panel page failed
  …»), held to a size and a rate.

## The panel's login

Change it in the panel: «تنظیمات» at the foot of the sidebar › «تنظیمات پنل» › «ورود به پنل»
([Panel settings](Panel-Settings.md#the-panels-login)). It asks for the current password — a wrong one counts as a
failed sign-in, as on the sign-in page — and a new username, a new password, or both (a new password left blank keeps
the one you have). Saving signs every other browser and device out of the panel; yours stays signed in.

Locked out, with the password or the username lost? No shell is needed (the screens, field by field:
[The panels](Panels.md#signing-in)):

1. On the sign-in page, press «رمز را فراموش کرده‌اید؟», then «ساختن کلید». The panel writes a one-time key to
   `storage/recovery-key.txt`.
2. Open that file in the host's File Manager and copy its text.
3. Paste it into the form with a new username and password. You are signed in under them, and every other browser is
   signed out.

Only someone with the host's files can read the key. It works for an hour — pressing «ساختن کلید» again meanwhile keeps
the same key — and it is deleted once used. Wrong keys have a count of their own: 10 from one IP address in 15 minutes
(for IPv6, from one /64 network), then that address waits.

Failed sign-ins to the owner's panel are counted too: 10 from one IP address (or IPv6 /64 network) in 15 minutes, and 50
for one username from every address together (a sign-in that works does not clear the second count); past either, the sign-in waits. An agent
signs in to their panel only with the one-time link the main bot gives them ([Agency](Agency.md)).

## What keeps it safe

The shop guards itself without any setting: what the code does — the answers PHP gives, the sign-in limits, the keys in
the host's files, the encrypted secrets, the payment gateways' callbacks — is on [Security](Security.md#how-a-shop-is-kept-safe),
and [Architecture](Architecture.md#failures-and-hardening) says how. What is yours to do is above: the
[checklist](#checklist), PHP running as your account ([file ownership](#file-ownership)), and [backups](Backups.md).
