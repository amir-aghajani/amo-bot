[فارسی](fa/Backups.md)

# Backups

Back these up every day, together:

- **The database** — with the host's own backups (cPanel › *Backup*, or the host's backup service), or phpMyAdmin's
  *Export*.
- **`config.php`** — keep it with the dump. The secrets the database keeps — the servers' panel credentials, the agents'
  bots' tokens, the payment methods' keys, the websites' secrets — are encrypted with its `APP_KEY` and cannot be read
  without it ([secrets](Configuration.md#secrets)).
- **`storage/uploads/`** — the pictures the shop keeps on disk: the QR backgrounds (`qr/`), the receipts customers
  uploaded on a shop's website (`receipts/`) and the support tickets' pictures uploaded on a website or in a panel
  (`tickets/`). A receipt or a ticket's picture sent in the bot is not here: Telegram keeps it.
- **`storage/installed.lock`** — the mark that the shop is installed (a shop without it opens its installer), and the
  version the database is at, which tells an upgrade where to start. Keep the one taken with the dump.

The rest of `storage/` — logs, sessions, caches, the updater's files — needs no backup.

## Restoring

1. Put a release's files back: the one the shop ran, or a newer one.
2. Put back `config.php`, `storage/installed.lock` and `storage/uploads/`.
3. Import the dump into the database `config.php` names (phpMyAdmin's *Import*).
4. When the release is newer than the backup, open «تنظیمات پنل › به‌روزرسانی» and press «ادامه به‌روزرسانی»: it brings the database along,
   as after an upgrade by hand ([Upgrading](Upgrading.md#by-hand)).

The files you restore must belong to the user PHP runs as: the app makes its files for that user
([file ownership](Running-In-Production.md#file-ownership)).

## Moving to another host

Moving the shop is a restore on the new host. When its address changed, set `APP_URL` to the new one («تنظیمات پنل ›
برنامه»), register the webhooks again («تنظیمات پنل › ربات تلگرام», «ثبت دوباره Webhook» on the «دریافت پیام‌ها»
card), and set the cron up on the new host with the line the panel makes ready
([Installation](Installation.md#shared-hosting), step 6).

Backing up and restoring in one step is on the [Roadmap](Roadmap.md).
