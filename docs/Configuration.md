# Configuration

An installation's own configuration — the database, the main bot's token, the site's address, the key that encrypts
its secrets, the owner's login, the email — is one file, `config.php`. Everything else a shop is set up with (its plans,
its bot's texts and rules, its website) lives in the database, per shop, and is set from the panel
([Owner's guide](Owners-Guide.md)).

## config.php

`config.php` sits beside the app and returns a flat array of settings — upper-case names, PHP values (text, whole
numbers, `true`/`false`) — as WordPress keeps its own in `wp-config.php`: shared hosting has no shell and no environment
to set, and its File Manager edits a file. Nothing of the configuration is read from the environment; there is no
`.env`.

- The web installer makes it, with an `APP_KEY` of its own. It is git-ignored and in no release, and the root
  `.htaccess` refuses it to the web — and any copy of it an editor left beside it, `config.php.bak` — even without
  mod_rewrite.
- Every write renders the whole file anew, section by section, each setting under the words that say what it is for. A
  setting the file does not set is written commented out at its default — `// 'APP_TIMEZONE' => 'UTC',` — for you to
  see and set. A database's or a mail driver's own settings (`DB_HOST`, `MAIL_HOST` …) follow the setting that names the
  driver, as the panel saved them; any other setting goes with the section its prefix names (`APP_`, `DB_`, `TELEGRAM_` …),
  else under «Other settings». The file is written whole
  (never half) and under a lock, so two writers change it one after the other; the installer makes it readable by the
  shop's own user alone (`0600` — `0644` where PHP runs as another user, [file ownership](Running-In-Production.md#file-ownership)),
  and a rewrite keeps the permissions it has.
- Every setting, its default and its words: the [config.php reference](Config-Reference.md), generated from the code.

## Editing it by hand

Open it in the host's File Manager and change a value between its quotes; a line that starts with `//` is a setting
left at the value it shows — delete the `//` to set another. Keep a copy before you edit: a line written wrongly keeps
the whole shop from starting — every request answers a JSON 500, and the error, with its line, is in
`storage/logs/php-errors.log`. Never share the file: it holds the database's password, the bot's token and the key that
reads what the shop keeps encrypted.

Every request reads the file afresh. A developer's poller reads it once: `bot:poll --watch` restarts its worker when the
file changes; a poller run without `--watch` needs a restart.

## What the panel edits

The owner's panel edits most of it — «تنظیمات» at the foot of the sidebar › «تنظیمات پنل»:

| Section | Settings |
|---|---|
| «ربات تلگرام» | the main bot's token (`TELEGRAM_BOT_TOKEN`: its shape checked as it is saved; «بررسی توکن» asks Telegram whose it is and fills in its @username), `TELEGRAM_BOT_USERNAME`, `TELEGRAM_API_URL` (moved to another scheme, host or port, it asks the token again and the owner's current password: every bot's token goes there), `TELEGRAM_POLL_TIMEOUT`, `TELEGRAM_WEBHOOK_SECRET` — and the «دریافت پیام‌ها» card that puts every bot on webhooks or takes them off |
| «دیتابیس» | `DB_CONNECTION` and its driver's settings — written only once the database answers to them |
| «برنامه» | `APP_NAME`, `APP_URL`, `APP_DEBUG`, `APP_TIMEZONE` |
| «ایمیل» | `MAIL_TRANSPORT` and its driver's settings, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` — with a test send |
| «ورود به پنل» | `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` ([the panel's login](Running-In-Production.md#the-panels-login)) |
| «پیشرفته» | `LOG_LEVEL`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE`, `OUTGOING_HTTP_TIMEOUT`, `CRON_TOKEN` |

The rest is set by hand: `APP_KEY`, `TRUSTED_PROXIES`, `APP_BASE_PATH`, `LOG_FILE`, `LOG_MAX_FILES`, `SESSION_NAME`,
`SESSION_DOMAIN` and `SESSION_SAVE_PATH`. While the web server may not write `config.php` (or the folder it is in), the
panel says what to fix and changes nothing.

## Secrets

- **`APP_KEY`** encrypts what the shop keeps secret in its database: the panels' credentials of its servers, the agents'
  bots' tokens and webhook secrets, the websites' Telegram client secrets and captcha keys, the customers' two-factor
  secrets, the report groups' connect codes and the sign-ins under way. Never change it — what it encrypted could not be
  read again: every server's credentials would have to be typed again, and every agent's webhook would be refused until
  «ثبت Webhook» is pressed again. Keep a copy of `config.php` with your backups ([Backups](Backups.md)).
- The panel never shows a secret back — a bot token or a key shows as its last characters, a password as dots —, and a
  secret's field left blank keeps the one kept.
- A secret is kept with the address it was given for: the database's password with its host, port and socket, the
  mail server's password with its server, port, encryption and username, the main bot's token with the Bot API's
  address. When the address moves, the secret must be typed again — it is never sent somewhere it was not given for.

## Time

Every time the shop keeps is UTC — PHP's clock and the database connection alike —, whatever the host's zone or its
daylight saving. `APP_TIMEZONE` only sets how times read: in the bot's Jalali dates, the dashboard's days and every date
the panels show. It may change at any time.

## Behind a proxy, or under a folder

- `TRUSTED_PROXIES` — the reverse proxies in front of PHP (Cloudflare's published ranges, a load balancer): their
  `X-Forwarded-For` then says who the visitor is, for the sign-in throttles and the websites' sessions.
- `SESSION_SECURE_COOKIE` — forces the panels' cookie secure behind a proxy that hides the https and says nothing of it
  (no `X-Forwarded-Proto`, no Cloudflare `CF-Visitor`).
- `APP_URL` carries the sub-folder a shop is installed under (`https://example.com/shop`); `APP_BASE_PATH` sets the
  folder requests reach PHP under only for a web server that does not say it.
