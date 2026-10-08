# Backups

Back up these, daily:

- **The database** — the host's own backups (cPanel › *Backup*, or its backup service), or phpMyAdmin's *Export*.
- **`config.php`** — keep it with the dump. The secrets the database keeps — the panels' credentials of the servers,
  the agents' bots' tokens, the websites' secrets — are encrypted with its `APP_KEY` and cannot be read without it.
- **`storage/uploads/`** — what the shop keeps on disk: the QR backgrounds (`qr/`), the receipts customers uploaded from
  a shop's website (`receipts/`) and the pictures of support tickets uploaded from a website or a panel (`tickets/`). A
  receipt or a ticket's picture sent in the bot is not here: Telegram keeps it.
- **`storage/installed.lock`** — the mark that the shop is installed: a shop restored without it opens its installer.

To restore, put a release's files back, then `config.php`, `storage/installed.lock` and `storage/uploads/`, and import
the dump into the database `config.php` names (phpMyAdmin's *Import*). The files restored must belong to the user PHP
runs as — the app makes its files for that user ([file ownership](Running-In-Production.md#file-ownership)). Moving the
shop to another host is the same, then the webhooks set again from the new address («تنظیمات پنل › ربات تلگرام», the
«دریافت پیام‌ها» card) when `APP_URL` changed.

The rest of `storage/` — logs, sessions, caches — need no backup. Backing up and restoring in one step is on the
[Roadmap](Roadmap.md).
