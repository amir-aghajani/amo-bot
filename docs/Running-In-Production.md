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
dashboard's «وضعیت سیستم» card says when the scheduler last ran. Every task, and how often it runs — yours to set:
[the task schedule](#the-task-schedule).

### The task schedule

«تنظیمات پنل › زمان‌بندی کارها», the owner's: every scheduled task in the order a run takes them — what it does, whether
it runs in each shop, when it last ran and when it is next due — with how often it runs and, for a few, the times it
decides by. One for the whole installation, kept with the main shop: one scheduler runs every shop's work, an agent's
too. The page is saved as one form — every value checked, and all kept or none.

How often a task runs is 1 to 10080 minutes (a week). The cron starts the scheduler once a minute, so 1 is as often as
any task can run; its next run counts from its last. While the database cannot be read, every task runs at its default.

| Task | What it does | Runs by default every |
|---|---|---|
| «تایید خودکار رسیدها» | Card-to-card receipts nobody reviewed within their method's window, approved ([Payment methods](Payment-Methods.md#approving-receipts-by-themselves)) | minute |
| «بررسی پرداخت‌های آنلاین» | Online payments not paid yet, asked about at their gateway — a few each run, in case a gateway's callback never came ([Payment methods](Payment-Methods.md#every-online-gateway)) | minute |
| «ادامه تحویل‌های نیمه‌کاره» | Paid orders that a process left undelivered when it died, delivered (a failed delivery waits for support) | minute |
| «کنار گذاشتن سفارش‌های رهاشده» | Unpaid orders nobody touched for a while, cancelled — one made with a discount code sooner, once the code's hold is over; never one whose money may be with support ([Orders and payments](Orders-And-Payments.md#what-runs-by-itself)) | 10 minutes |
| «ارسال پیام‌های همگانی» | Broadcasts, a batch at a time | minute |
| «تمدید خودکار» | The services whose «تمدید خودکار» is on and whose end is near, renewed from the wallet ([Services](Services.md#what-runs-by-itself)) | 30 minutes |
| «شروع دوره تمدیدشده» | A renewed service's next period, begun where the paid one ended | 10 minutes |
| «افزودن زمان و حجم و هدیه همگانی» | «افزودن زمان و حجم» and «هدیه همگانی» that no open screen is working on, a batch at a time | minute |
| «انتقال سرویس‌ها» | «انتقال سرویس‌ها» that no open page is working on, a batch at a time; then the clients a rescue left on a panel that answers again, removed ([Servers](Servers.md#moving-its-services)) | minute |
| «همگام‌سازی سرویس‌ها» | Every running service read from its panel — usage, end, link —, one call per server | 15 minutes |
| «یادآوری‌ها» | «یادآوری» for the services near their end or low on traffic ([Bot settings](Bot-Settings.md#reminders)) | 15 minutes |
| «حذف سرویس‌های تمام‌شده» | The services that are done with, removed from their panels by each shop's rules: ended services the set hours after their end, where the shop removes them ([Bot settings](Bot-Settings.md#ended-services)), and free tests that ended or never connected ([Test accounts](Test-Accounts.md#the-clean-up)) — a stopped agent's shop too | 15 minutes |
| «شرایط نمایندگی» | The agents' terms: an agency's end and a month's minimum purchase warned of ahead, then the agent's selling suspended — or allowed again once the month's minimum is met ([Agency](Agency.md)) | hour (60 minutes) |
| «پاک‌سازی سابقه پیام‌های ربات» | The updates the bots took, forgotten once Telegram can no longer send them again | hour |
| «پاک‌سازی حساب‌های وب‌سایت» | The websites' ended sessions, expired sign-in codes and old notices, removed | hour |
| «پاک‌سازی تیکت‌ها» | The pictures of closed tickets and the report groups' old reports of closed tickets, removed | hour |
| «قرعه‌کشی شبانه» | Once the shop's clock has passed the hour of the draw, the day's draw — once a day, so the interval only sets how soon after the hour it comes ([Loyalty, cashback and draws](Loyalty-Cashback-And-Draws.md#the-nightly-draw)) | 10 minutes |
| «ارسال پشتیبان به گروه» | Each shop's backup, sent to its report group's «پشتیبان‌گیری» topic: the main shop's group gets the whole installation's, an agent's group its own shop's — in every shop whose group is connected and whose topic is on ([Backups](Backups.md#in-the-report-group)) | day (1440 minutes) |
| «بررسی نسخه تازه» | AmoBot's newest release, read from GitHub, so the dashboard says when one is out ([Upgrading](Upgrading.md)) | day (1440 minutes) |
| «ارسال گزارش‌ها» | The report groups' queued messages, at Telegram's pace ([Report group](Telegram-Group.md#pace)) | minute |

A task's interval is also how late its work may come. «تمدید خودکار» by «ریست زمان + انتقال حجم» waits for the last run
before a service's end — its interval and 5 minutes ahead at most —, and the term it starts afresh throws away no more
than that: a longer interval renews such a service earlier ([Bot settings](Bot-Settings.md#renewal)).

The times the tasks decide by, each under its task:

| Task | Setting | Default | From – to |
|---|---|---|---|
| «کنار گذاشتن سفارش‌های رهاشده» | «مهلت سفارش پرداخت‌نشده» — an unpaid order nobody touched for this long is cancelled; never one whose money is with support | 48 hours | 1 – 720 hours |
| «بررسی پرداخت‌های آنلاین» | «فاصله پرسیدن، صفحه پرداخت باز» — while a gateway's page is open, its payment is asked about this often | 3 minutes | 1 – 60 minutes |
| «بررسی پرداخت‌های آنلاین» | «پرسیدن پس از بسته شدن صفحه» — still asked about this long after its page closed: a gateway may take the money late | 2 hours | 0 – 72 hours |
| «بررسی پرداخت‌های آنلاین» | «فاصله پرسیدن، صفحه بسته» — after its page closed, asked about this often | 15 minutes | 1 – 1440 minutes |
| «بررسی پرداخت‌های آنلاین» | «مهلت پول تسویه‌نشده» — one on which the gateway has money it has not settled is asked about until this long after its page closed, then held for support | 48 hours | 1 – 720 hours, never less than «پرسیدن پس از بسته شدن صفحه» |
| «پاک‌سازی حساب‌های وب‌سایت» | «نگه‌داری اعلان‌های وب‌سایت» — a customer's notices on the website kept this long | 180 days | 1 – 3650 days |
| «پاک‌سازی تیکت‌ها» | «نگه‌داری تصویرهای تیکت بسته» — the pictures uploaded to a ticket kept this long after it closed | 30 days | 1 – 3650 days |
| «ارسال گزارش‌ها» | «نگه‌داری گزارش‌های فرستاده‌شده» — the report group's messages kept this long, so a reply to one is still known; an open ticket's always | 7 days | 1 – 365 days |
| «ارسال گزارش‌ها» | «مهلت ارسال گزارش» — a report still queued after this long is given up | 24 hours | 1 – 168 hours |

**A value equal to its default is kept as none**: the field shows the default, and when a later version of AmoBot
changes a default, the shop follows it. A value you set stays until you set another.

**What stays fixed**, since what keeps the shop from doing a thing twice rests on it:

- an update the bots took is remembered 48 hours, so one Telegram sends again is served once (Telegram keeps an update a
  day);
- a checkout's request key answers again for a week, so a retry never buys twice
  ([the Idempotency-Key](Store-API-Buying.md#the-idempotency-key));
- a delivery left half-way counts as stuck after 10 minutes paid and not started, or 18 minutes with no sign of life, so
  one still running is never delivered again;
- a test a process left half-made is given back to the quota 30 minutes after it was claimed, so one still being made
  is never given back ([Test accounts](Test-Accounts.md#the-clean-up));
- how much of its work a task takes in one run (its batch), so a run keeps to its 45 seconds.

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
- **PHP is current**: the newest release of its branch. While the host runs one with known security holes, a strip over
  every page of the owner's panel says so ([what you need](Installation.md#what-you-need)).
- **PHP takes the pictures customers send**: `upload_max_filesize` `10M` and `post_max_size` `12M`
  ([what you need](Installation.md#what-you-need)) — more to restore a backup larger than that.
- **The shop is backed up**: the report group's «پشتیبان‌گیری» topic gets a backup every day, and `config.php` and
  `storage/uploads/` are kept beside them ([Backups](Backups.md)).

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
- Files put back by hand from a backup must belong to that user too.

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

**Backups** take room on the same disk, in `storage/backups/`: a backup made for a download (deleted after an hour), a
file uploaded to restore (30 minutes), and the last three states kept from before a restore of each kind — the whole
installation's, and each shop's, each about as large as a backup of it ([Backups](Backups.md#limits)).

Pictures sent in the bot stay on Telegram: the shop keeps only their reference. A closed ticket's uploaded pictures are
removed 30 days after it closed by default ([the task schedule](#the-task-schedule)). The shop's sign-in emails have budgets of their own
too — a shop's hour and day, and the whole installation's ([limits](Store-API-Sign-In.md#limits-and-lifetimes)).

## Health and logs

- **`GET /health`** answers `200` with `{"status": "ok", "version": …, "installed": …, "database": "ok"}` while the
  database answers, and `503` with `"status": "degraded"` and `"database": "unavailable"` while it does not — point an
  uptime monitor at it. It names nothing about the server; why the database did not answer is in the log.
- **A database the shop cannot reach** — stopped, out of reach, refusing the user, password or database `config.php`
  names, or out of connections (the host's limit on them reached) — makes every page of the panels say «دیتابیس فروشگاه
  در دسترس نیست…» (a `503`), not a bare server error. Check in the host's panel that the database is running and that
  `config.php`'s `DB_*` settings are its own; a limit on connections the host sets is theirs to raise. Each such request
  writes one line to the log, with the database's own words.
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
