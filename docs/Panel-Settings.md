[فارسی](fa/Panel-Settings.md)

# Panel settings

«تنظیمات» at the foot of the sidebar › «تنظیمات پنل», the owner's: the installation's own settings — what `config.php`
keeps ([Configuration](Configuration.md)) —, your login and AmoBot's own update. Each section is a page of its own, and
each card on it is saved on its own. An agent's «تنظیمات پنل» has «ظاهر» alone.

**A secret** — a token, a password, a key — is never shown back: its field shows a hint of it (a token's last four
characters — the bot's token with its bot's id —, or dots alone for a password or a secret shorter than 12) and says «خالی بماند، همان مقدار ذخیره‌شده می‌ماند.». Left blank, it stays. «پاک کردن
مقدار ذخیره‌شده» clears one that may be empty; a required one has no such button: type the new one. A secret kept for
an address must be typed again when the address moves, so it is never sent somewhere new.

**While the web server may not write `config.php`**, the page says so in red and saves nothing: give the file (and the
app's folder) write permission in the host's File Manager — or edit the file by hand meanwhile.

## The Telegram bot

«ربات تلگرام», card «ربات اصلی» — the main bot, whichever shop is open:

- **«توکن ربات اصلی»** — from @BotFather (`/newbot`, or `/token` for an existing bot). A save checks only its shape;
  **«بررسی توکن»** asks Telegram who it is and fills **«نام کاربری ربات اصلی»** (without @) — press it, then save: the
  report group's link and the referral links need the @username.
- Under «تنظیمات پیشرفته»: «آدرس API تلگرام» (`https://api.telegram.org`; another for a local Bot API server or a
  mirror — no username or password in it, no `?` or `#`), «تایم‌اوت long polling (ثانیه)» (1 to 60, polling only), and
  «رمز Webhook» (the secret part of the webhook's address; «تولید رمز تازه»).
- **Moving «آدرس API تلگرام» elsewhere** — another scheme, host or port — sends every bot's token there from then on,
  the agents' bots' too. So the save asks two things, which the card says before you press it: the main bot's token
  typed again (the one kept never goes to a new address by itself), and **«رمز عبور فعلی پنل»**, your panel login's
  password — a wrong one counts as a failed sign-in to the panel, as on «ورود به پنل». A save that keeps its scheme,
  host and port asks neither.

A new token reaches a bot on webhooks with its next update. **Another bot's token, or a new webhook secret, needs the
webhooks set again** on the card below; the card's description says so after a save.

Card **«دریافت پیام‌ها»** — how every bot, the main one and the agents', gets its updates
([Running in production](Running-In-Production.md#how-the-bot-receives-updates)): «Webhook», «پولینگ» (a poller
answered in the last two minutes) or «مشکل دارد» (neither: the bots get no messages).

- **«ثبت Webhook»** («ثبت دوباره Webhook») — asks first, then puts every bot on webhooks at `APP_URL`: Telegram sends
  updates straight to the shop, nothing has to run on the host — the way a shop runs. A poller running stops.
  Telegram takes webhooks only on **https**: with an `APP_URL` on http the button is held.
- **«برداشتن Webhook»** — asks first, then takes every bot off webhooks: «Webhook برداشته شد؛ ربات تا Webhook دوباره
  ثبت نشود پیامی روی هاست نمی‌گیرد: در همین کارت «ثبت Webhook» را بزنید.» A shop on a host gets nothing until
  «ثبت Webhook» is pressed again (only a developer's machine, running the poller, receives updates without one —
  [Console commands](Console-Commands.md)).

Each bot's outcome is said on its own line — the main bot's token refused, an agent's (they send a new one from the main
bot's «ربات من»), another program polling the same bot, Telegram out of reach.

## Database

«دیتابیس» — MySQL or MariaDB: «هاست» (`localhost` on shared hosting), «پورت» (3306), «نام دیتابیس», «نام کاربری», «رمز
عبور», and under «تنظیمات پیشرفته» «سوکت» (instead of host and port; empty on shared hosting) and «پیشوند جدول‌ها»
(only for a database shared with other apps — never change it after installing). «تست اتصال» tries them; **a save tries
them first and changes nothing if the database does not answer**, saying why — the user or password refused, no access,
no such database, the server out of reach, a server older than MySQL 5.7.8 / MariaDB 10.3. The password must be typed
again when the host, port or socket changes.

Moving to another database copies nothing: the new one must hold the shop's data already ([Backups](Backups.md)).

## The app

«برنامه»:

- **«نام برنامه»** — the shop's name, 64 characters at most: in the panels' page titles, the bot's messages and the
  main shop's emails.
- **«آدرس سایت»** — `APP_URL`, where the shop is reached (with its sub-folder, no `/` at its end; no username or
  password in it, no `?` or `#`): the webhooks, the cron address, an agent's login link and the websites' API address are
  built on it. **Changing it, set the webhooks again**, and copy the cron line again («پیشرفته»).
- **«منطقه زمانی»** — the zone every date and time is shown in: the panels, the bot, the dashboard's days, the log.
  Changing it moves nothing kept.
- **«حالت Debug»** (off) — the details of a server error, but only to a request from the server itself; your browser
  never sees them. For finding a fault, then off.

## Email

«ایمیل» — how the shop's email goes out: website sign-up codes and password resets (every shop's website), and the
notices of customers Telegram cannot reach. Card «ارسال ایمیل», **«روش ارسال»**:

| Choice | What it needs |
|---|---|
| «خاموش» (default) | Nothing — no email sign-up on any website, no reset code, no notice by email |
| «SMTP» | An email account on a mail server (a cPanel *Email Account*): «سرور SMTP» (`mail.example.com`), «پورت» (587 STARTTLS, 465 SSL, 25 none), «رمزنگاری اتصال», «نام کاربری» (usually the whole address) and «رمز عبور» — typed again when the server, port, encryption or username change |
| «ایمیل خود هاست» | The host's own mail (PHP's `sendmail_path`) — the host must have set it up |
| «Resend» | resend.com: its «کلید API» (`re_…`), and the sender's domain verified there |

Then **«ایمیل فرستنده»** — an account of that SMTP server, an address of the host's domain, or of a domain verified in
Resend — and «نام فرستنده» (empty: each shop's name — the app's for the main shop, the bot's for an agent's). Card
**«ارسال ایمیل تست»** sends a short email with the saved settings to an address you type: check the inbox, and the
spam folder.

## The panel's login

«ورود به پنل» — your username (2 to 64 characters: Latin letters, digits, `. _ @ -`) and password (8 characters at least,
72 bytes at most — 36 Persian letters), with **«رمز عبور فعلی»** asked first; a new password left empty keeps the old
one. Saving signs every other browser out; this one stays. A wrong current password counts as a failed sign-in. Lost
it: the sign-in page's «رمز را فراموش کرده‌اید؟» ([the panel's login](Running-In-Production.md#the-panels-login)).

## Appearance

«ظاهر» — «تیره» (the default) or «روشن», kept in this browser alone; the switch in the top bar of every screen changes
it too.

## Update

«به‌روزرسانی» — the version the shop runs and the newest release of AmoBot, with its notes, read from GitHub once a day
(the dashboard's «وضعیت سیستم» card says when one is out) and at once on «بررسی دوباره». «به‌روزرسانی به نسخه …» installs it
in a few steps, the shop answering «در حال به‌روزرسانی» for a moment; only a release signed with the key built into the
shop is installed, and the previous version is kept to put back. Back the database up first. What each step does, what
the host needs for it, and the update by hand: [Upgrading](Upgrading.md).

## Advanced

«پیشرفته»:

- **«سطح لاگ»** — what the log keeps: `debug`, `info` (the default), `notice`, `warning` or `error`; `debug` while
  finding a fault.
- **«طول Session پنل‌ها (دقیقه)»** — how long an unused panel session lasts, yours and the agents': 5 to 43200, 120 by
  default.
- **«تایم‌اوت درخواست‌های خروجی (ثانیه)»** — how long a call out may take — Telegram, the payment gateways, Google, the
  captcha, Resend: 5 to 120, 30 by default. A server's panel has its own, on the server.
- **«کوکی Session همیشه امن»** (off) — only behind a proxy (Cloudflare) that hides https from PHP; on a real http address
  it makes signing in impossible.
- **«توکن Cron»** — turns on the cron address, which runs the scheduled work without a shell
  ([the scheduler](Running-In-Production.md#the-scheduler)). Press «تولید توکن تازه» (the button beside the field) and
  save. Two lines then appear under the field, each with its copy button: the cron address («کپی آدرس Cron»), and a
  cron line for cPanel («کپی خط Cron»), `curl -fsS <the address> > /dev/null`. Paste the line into cPanel › *Cron
  Jobs*, its *Command* field, with *Once Per Minute* — or give the address to an outside cron service that calls it
  every minute ([Installation](Installation.md#shared-hosting), step 6). The screen shows the address with its token
  hidden; a copy holds it whole. A new token stops the old address: copy the line again.

What the screen does not show — `APP_KEY`, `TRUSTED_PROXIES`, the log file, the session's cookie and folder — is
`config.php`'s alone ([Configuration](Configuration.md)).

## Watch out for

- **After changing `APP_URL`, the bot's token for another bot or the webhook's secret, press «ثبت دوباره Webhook»**: the
  address Telegram calls does not follow by itself.
- **After changing `APP_URL` or «توکن Cron», copy the cron line again** into cPanel's *Cron Jobs*: the line is built on
  both, and an old token no longer runs the scheduler.
- **Email off** leaves customers without Telegram unreachable, and every website without email sign-up.
- **A database password, an SMTP password or a bot token left blank after its address changed** is refused: type it
  again. Moving the Bot API's address asks your panel password too.
