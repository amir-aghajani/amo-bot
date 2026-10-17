[فارسی](fa/Configuration.md)

# Configuration

An installation's own configuration — the database, the main bot's token, the site's address, the key that encrypts
its secrets, the owner's login, the email — is one file, `config.php`. Everything else a shop is set up with (its plans,
its bot's texts and rules, its website) lives in the database, per shop, and is set in the panel
([Owner's guide](Owners-Guide.md)).

## config.php

`config.php` sits in the app's folder and returns a flat list of settings: upper-case names with PHP values (text in
quotes, whole numbers, `true` or `false`), as WordPress keeps its own in `wp-config.php`. Shared hosting has no shell
and no environment to set, and its File Manager edits a file. Nothing of the configuration is read from the
environment; there is no `.env`.

- The web installer makes it, with an `APP_KEY` of its own. It is in no release, and the root `.htaccess` refuses it to
  the web — with any copy an editor left beside it, such as `config.php.bak` — even without mod_rewrite.
- Every save renders the whole file anew, section by section, each setting under a comment that says what it is for. A
  setting the file does not set is written commented out at its default — `// 'APP_TIMEZONE' => 'UTC',` — for you to
  see and set. The database's and the mail driver's own settings (`DB_HOST`, `MAIL_HOST` …) end their section; a
  setting no section knows goes under `Other settings` at the end.
- The file is written whole, never half, and under a lock, so two saves at once take turns. The installer makes it
  readable by the shop's own user alone (`0600`, or `0644` where PHP runs as another user:
  [file ownership](Running-In-Production.md#file-ownership)), and a save keeps the permissions it has.
- Every setting, with its default and its description: the [config.php reference](Config-Reference.md), generated from
  the code.

## Editing it by hand

Open it in the host's File Manager and change a value between its quotes. A line that starts with `//` is a setting
left at the value it shows: delete the `//` to set another. Keep a copy before you edit — a line written wrongly keeps
the whole shop from starting: every request then answers a 500, and the error, with its line, is in
`storage/logs/php-errors.log`. Never share the file: it holds the database's password, the bot's token and the key that
reads what the shop keeps encrypted.

Every request reads the file afresh, so a change counts from the next request. (A developer's poller reads it once:
`bot:poll --watch` restarts its worker when the file changes; without `--watch` it needs a restart.)

## What the panel edits

The owner's panel edits most of it, in «تنظیمات» at the foot of the sidebar › «تنظیمات پنل»
([Panel settings](Panel-Settings.md)):

| Section | Settings |
|---|---|
| «ربات تلگرام» | `TELEGRAM_BOT_TOKEN` (the main bot's token), `TELEGRAM_BOT_USERNAME`, `TELEGRAM_API_URL`, `TELEGRAM_POLL_TIMEOUT` and `TELEGRAM_WEBHOOK_SECRET` — and the «دریافت پیام‌ها» card, which puts every bot on webhooks or takes them off |
| «دیتابیس» | `DB_CONNECTION` and its driver's settings, written only once the database answers to them |
| «برنامه» | `APP_NAME`, `APP_URL`, `APP_DEBUG`, `APP_TIMEZONE` |
| «ایمیل» | `MAIL_TRANSPORT` and its driver's settings, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` — with a test send |
| «ورود به پنل» | `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` ([the panel's login](Running-In-Production.md#the-panels-login)) |
| «پیشرفته» | `LOG_LEVEL`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE`, `OUTGOING_HTTP_TIMEOUT`, `CRON_TOKEN` |

The rest is set by hand: `APP_KEY`, `TRUSTED_PROXIES`, `APP_BASE_PATH`, `LOG_FILE`, `LOG_MAX_FILES`, `SESSION_NAME`,
`SESSION_DOMAIN` and `SESSION_SAVE_PATH`. While the web server may not write `config.php` (or the folder it is in), the
panel says what to fix and saves nothing.

## Secrets

- **`APP_KEY`** encrypts what the shop keeps secret in its database: its servers' panel credentials and their SOCKS5
  proxies' passwords, the agents' bots' tokens and webhook secrets, the payment methods' tokens and keys, the websites'
  Telegram client secrets and captcha keys, the customers' two-factor secrets, the report groups' connect codes and the
  sign-ins under way. **Never change it**: what it encrypted could not be read again. Every server's credentials and
  every payment method's keys would have to be typed again, and every agent's webhook would be refused until «ثبت
  Webhook» is pressed again. Keep a copy of `config.php` with your backups ([Backups](Backups.md)).
- The panel never shows a secret back — a bot token or a key shows as its last characters, a password as dots —, and a
  secret's field left blank keeps the one kept.
- A secret is kept with the address it was given for: the database's password with its host, port and socket, the
  mail server's password with its server, port, encryption and username, the main bot's token with the Bot API's
  address. When the address moves, the secret must be typed again: it is never sent somewhere it was not given for.

## Time

Every time the shop keeps is UTC — PHP's clock and the database connection alike —, whatever the host's zone or its
daylight saving. `APP_TIMEZONE` only sets how times read: in the bot's Jalali dates, the dashboard's days and every
date the panels show. It may change at any time.

## Behind a proxy, or under a folder

- **`TRUSTED_PROXIES`** — the reverse proxies in front of PHP (Cloudflare's published ranges, a load balancer), as
  addresses or CIDR ranges, apart by commas or spaces:

  ```php
  'TRUSTED_PROXIES' => '173.245.48.0/20, 103.21.244.0/22, 2400:cb00::/32',
  ```

  (three of Cloudflare's ranges: behind Cloudflare, list every range it publishes at `cloudflare.com/ips`). A request
  from one of them is read for the visitor's address in its `X-Forwarded-For`; that address is what the
  sign-in limits and the shop's other per-visitor limits count, and what the log and the websites' sessions record.
  Empty, the connection's own address is the visitor's. An entry PHP cannot read — a mistyped address, a range without
  its length (`10.0.0.1/`) — is trusted for nothing, never for everyone.
- **`SESSION_SECURE_COOKIE`** — forces the panels' cookie secure behind a proxy that hides the https and says nothing of
  it (no `X-Forwarded-Proto`, no Cloudflare `CF-Visitor`). Over https the cookie is secure without it.
- **`APP_URL`** carries the sub-folder a shop is installed under (`https://example.com/shop`); `APP_BASE_PATH` sets the
  folder requests reach PHP under, only for a web server that does not say it.
