[فارسی](fa/Backups.md)

# Backups

«تنظیمات پنل › پشتیبان‌گیری», in the owner's panel and in each agent's: the shop's database saved to a file — downloaded
there, and sent to the report group once a day — and put back from such a file in a few steps. The owner's backup in
the main shop is the whole installation's; any other panel's is its own shop's. The page has four cards: «دریافت
پشتیبان», «ارسال خودکار به گروه گزارش‌ها», «بازگردانی» and «پیش از بازگردانی‌ها». Uploaded pictures and `config.php` are
not in a backup: keep them as well ([what a backup leaves out](#what-a-backup-leaves-out)).

## What a backup holds

**The owner, in the main shop**, backs up the whole installation: every shop — the main one and each agent's —, the
servers and their inbounds, the agents' bots, levels, requests, terms and traffic, the servers and plans kept from their
shops, «افزودن زمان و حجم», «هدیه همگانی» and the moves of servers' services, and every shop's settings, «زمان‌بندی
کارها» among them.

**Any other panel** — an agent's, or the owner's with an agent's shop open
([the shop on screen](Panels.md#the-shop-on-screen)) — backs up that shop alone: its customers, their wallets, groups
and notices; its plans and categories, with the servers and inbounds each plan sells on; its services and free tests;
its payment methods and cards; its orders and payments; its discount codes, sales, loyalty tiers, cashback, gift codes,
lucky wheel and draws; its referrals' commissions, gifts and alerts; its tickets and reviews; its required channels,
broadcasts, report group, website and mini app; and its bot's settings, texts and keyboards. Nothing of the owner's is
in it, and nothing of another shop: no server, nor the agent's bot, terms, suspension or traffic — those are the main
shop's, in the whole installation's backup. Nor what only the owner reads of the shop's own rows: why a delivery failed
in full — the panel's address with it — is left out, and where the owner decided something in the shop (a payment, an
order made by hand, a ticket's answer) the file names «پشتیبانی», as the agent reads it everywhere.

**Never in a backup**, since it is true only now: the sign-ins to a website or the mini app and their codes, a chat with
the bot half-way, the reports waiting to go to a group, a checkout's request keys, the updates Telegram sent, and the
panels' live feed.

## Downloading one

«دریافت پشتیبان» › «دریافت فایل پشتیبان» makes a backup now — it may take a few seconds («فایل پشتیبان در حال ساخت
است؛ چند لحظه صبر کنید.») — and the browser saves it: `amobot-backup-installation-<date>-<time>-….json.gz` for the
whole installation, `amobot-backup-<the bot's username>-…` for a shop. It is a gzip of the rows, signed with the
installation's key (`APP_KEY` in `config.php`): it restores in this installation of AmoBot alone.

Keep backup files to yourself: one holds your customers' names, phone numbers, emails and history as they are. The
secrets in it — the servers' panel passwords, the bots' tokens, the payment methods' keys — stay encrypted with
`APP_KEY`.

## In the report group

Each shop's backup also goes to its report group's «پشتیبان‌گیری» topic ([Report group](Telegram-Group.md#the-topics)),
once a day by default: the main shop's group gets the whole installation's, an agent's group its own shop's. It goes in
every shop whose group is connected and whose switch is on — on by default.

The card «ارسال خودکار به گروه گزارش‌ها» has the switch, «ارسال خودکار» — the same as the «پشتیبان‌گیری» topic's in
«بخش‌های گزارش» of «تنظیمات ربات › گروه گزارش‌ها». Its hint says how often the file goes («فایل پشتیبان هر ۲۴ ساعت یک بار
فرستاده می‌شود.» by default). How often is the owner's to set, for every shop: «ارسال پشتیبان به گروه» in «زمان‌بندی
کارها» ([the task schedule](Running-In-Production.md#the-task-schedule)) — the owner's card links «تنظیمات پنل ›
زمان‌بندی کارها», an agent's says «فاصله ارسال را مالک فروشگاه اصلی تعیین می‌کند.». The card then says when the last
one went («آخرین ارسال: …»), or what kept it — a warning, «آخرین ارسال (…) انجام نشد: …», with Telegram's words. With no
report group connected, a note says one is needed first, and links «اتصال گروه گزارش‌ها».

A run's time is shared with the other tasks: once it is over, no shop's backup begins in that run. The shops left out —
or never reached, the run having stopped half-way — go first at the next one, before those whose backup went, so no
shop misses out run after run. The file goes up as it is read from the disk, never whole in memory.

The file comes with a caption: what it is of («🗄 پشتیبان کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها», or «🗄 پشتیبان
فروشگاه»), the date, its size and rows, and where it is restored. Telegram takes 50 MB at most from a bot: a larger
backup is announced in the topic instead («⚠️ فایل از حدی که تلگرام از ربات می‌گیرد بزرگ‌تر است؛ …») — download it from
the panel.

Everyone in the group can download the file: keep the group to your team.

## Restoring

A restore puts the backup's rows in place of the ones there now: everything changed since the backup was made — orders,
payments, customers, settings — is lost. On the card «بازگردانی», in the panel the backup belongs to:

1. **«انتخاب فایل پشتیبان»** — pick the backup (`.json.gz`). A file larger than the host takes in one upload is refused
   before it is sent («حجم این فایل … است؛ هاست فایل بزرگ‌تر از … را نمی‌پذیرد.») — [limits](#limits). Nothing changes
   yet: the file is checked whole and described under «فایل انتخاب‌شده» — «زمان پشتیبان», «نسخه AmoBot», «محتوا» (the
   whole installation, or which shop) and «ردیف‌ها», with each table's rows in the fold «ردیف‌های هر جدول». What a
   restore would leave out comes next, a line each under «پیش از بازگردانی بدانید:» ([a shop's restore](#a-shops-restore)),
   then a red note of what goes back to the backup's time and what is lost. «انتخاب فایل دیگر» picks another file.
2. **Tick «می‌دانم هرچه بعد از این پشتیبان تغییر کرده، از دست می‌رود.»**, then press the red «بازگردانی» — it works only
   once the box is ticked. «انصراف» drops the file. A checked file waits 30 minutes (the page says until when); after
   that, pick it again.
3. **The state now is kept first**, as a backup of the same kind, and then the file is restored in one transaction: all
   of it, or — when anything fails — nothing. The card then says how many rows came back and names the state kept;
   «دریافت حالت قبل» saves it.

The card «پیش از بازگردانی‌ها» lists the last three states kept from before a restore, newest first, each with «دریافت»:
should a restore be the wrong one, pick that file in «بازگردانی» and restore it the same way. The oldest goes only once
a restore went through: one that failed leaves them all, and the state it kept besides.

**A file of an older version** is restored when this version still takes it — any made with AmoBot 0.8.0 or later —,
then brought along by the database upgrades since, as after an update. Those upgrades run once the rows are in, apart
from the restore's own transaction: should one fail, the rows stay restored, and restoring the same file again runs it
again. **A file older than that is refused**, saying which version made it: a version whose upgrade moves data from one
place to another restores no file made before it, whose data would not come back whole. Restore a newer backup, or the
host's own backup of the database ([without the panel](#without-the-panel)). **A file of a newer version** is refused:
update the shop first ([Upgrading](Upgrading.md)).

**A whole installation's backup** restores only in the owner's panel, in the main shop, and replaces everything a backup
holds, in every shop. What is never in a backup goes with it: everyone signed in to a website or the mini app signs in
again, a chat with a bot half-way starts over, and the reports waiting for a group are dropped.

**No restore signs anyone out of the panels** — yours or an agent's: the panels' sign-ins are not in the database.

**A shop's backup** restores only in that shop — in the agent's panel, or in the owner's with that shop open. Another
shop's file, or a whole installation's, is refused there. To take one shop back, use its own file: from its group, or
downloaded in its panel; a whole installation's file restores only whole.

### A shop's restore

A shop's restore touches its own rows alone. The owner's stay as they are now: the servers, the agent's bot, terms and
suspension, the servers kept from the shop, and the agent's traffic — a restore gives no traffic back, and takes none.
A plan the owner keeps from sale («پنهان‌شده توسط پشتیبانی») stays as the owner has it now, whatever the file says. So
does what only the owner reads, which the file never holds: why a delivery failed in full, and the owner's own name on
what they decided in the shop.

What is gone since the backup was made is said before you confirm, a line each:

- a plan's server, or inbound, that is no more: left out of the plan;
- an order or a free test whose server is no more: restored without its server;
- **a service on a server that is no more: the file is refused** — restored, it would be a service on no server.

A line of the agent's traffic about an order the file does not bring back keeps its amount, and names no order.

What is never in a backup goes with the shop's own rows: its customers signed in to its website or mini app sign in
again, a chat with its bot half-way starts over, and its reports waiting for its group are dropped. Every other shop's
stay as they are, and the panels stay signed in.

## What a backup leaves out

A backup is the database alone. It does not hold, and a restore does not change:

- **The VPN panels.** A restore changes the shop's rows, never a server's clients: a service sold after the backup keeps
  its client on its panel, which the shop no longer knows; one deleted since comes back in the shop without its client.
  The sync reads every running service from its panel again — what it used, when it ends —, and one whose client is gone
  reads as ended.
- **Uploaded files** — `storage/uploads/`: the mini apps' logos, the receipts and tickets' pictures uploaded on a website
  or in a panel, the QR backgrounds. Keep a copy of the folder (the host's File Manager compresses it).
- **`config.php`** — the database's settings, the owner's login, the main bot's token, `APP_URL`, and `APP_KEY`: the key
  every backup is signed with and the secrets in it are encrypted with. A backup restores only where `config.php` has
  the same `APP_KEY`: keep a copy of the file with your backups.
- What already left the shop: the messages its bots sent, the money a gateway took.

## Moving to another host

1. On the new host, make the database and upload a release — the version the shop runs, or a newer one
   ([Installation](Installation.md#shared-hosting), steps 1 to 3).
2. Before opening the installer, upload the old `config.php` into the app's folder: the installer keeps its `APP_KEY`.
3. Run the installer ([the web installer](Installation.md#the-web-installer)): the new database's settings at «دیتابیس»,
   the new address at «سایت و ربات». The panel's login is the one `config.php` brought, unless you set another.
4. Sign in, open «تنظیمات پنل › پشتیبان‌گیری» in the main shop, and restore the whole installation's backup on its card
   «بازگردانی».
5. Put `storage/uploads/` back with the host's File Manager.
6. Register the webhooks again («تنظیمات پنل › ربات تلگرام», «ثبت Webhook» on the «دریافت پیام‌ها» card), and set the
   cron up with the line the panel makes ready ([Installation](Installation.md#shared-hosting), steps 5 and 6).

A backup larger than the new host takes in one upload: raise the host's limit first ([limits](#limits)), or move the
database with the host's own tools ([without the panel](#without-the-panel)).

## Without the panel

The host's own backups keep what the panel's cannot — the files — and take a database of any size. Back these up
together:

- **The database** — with the host's backups (cPanel › *Backup*, or the host's backup service), or phpMyAdmin's
  *Export*.
- **`config.php`** and **`storage/uploads/`**, as above.
- **`storage/installed.lock`** — the mark that the shop is installed (a shop without it opens its installer), and the
  version the database is at, which tells an upgrade where to start. Keep the one taken with the dump.

The rest of `storage/` — logs, sessions, caches, the updater's files, `storage/backups/` — needs no backup.

To restore: put a release's files back (the one the shop ran, or a newer one), then `config.php`,
`storage/installed.lock` and `storage/uploads/`; import the dump into the database `config.php` names (phpMyAdmin's
*Import*); and when the release is newer than the backup, open «تنظیمات پنل › به‌روزرسانی» and press «ادامه
به‌روزرسانی»: it brings the database along, as after an upgrade by hand ([Upgrading](Upgrading.md#by-hand)). On a new
address, set `APP_URL` («تنظیمات پنل › برنامه»), then the webhooks and the cron as in step 6 above.

The files you put back must belong to the user PHP runs as: the app makes its files for that user
([file ownership](Running-In-Production.md#file-ownership)).

## Limits

- **An upload** may be as large as the smaller of the host's PHP settings `upload_max_filesize` and `post_max_size`; the
  card «بازگردانی» shows that limit under its button. A larger file is refused before it is sent («حجم این فایل … است؛
  هاست فایل بزرگ‌تر از … را نمی‌پذیرد.»), or by the host where PHP does not say its limit («فایل بزرگ‌تر از چیزی است که
  هاست می‌پذیرد …»): raise both in the host's PHP settings (cPanel › *MultiPHP INI Editor*), `post_max_size` a little
  above `upload_max_filesize`.
- **The panel waits up to 15 minutes** for a backup to be made and downloaded, a file to be uploaded and checked, or a
  restore to finish.
- **Telegram** takes 50 MB at most from a bot; a larger backup is downloaded from the panel.
- **What stays fixed**: three states kept from before a restore, for each kind (the whole installation's, and each
  shop's); an uploaded file waits 30 minutes for its restore; a backup made for a download is deleted after an hour, as
  is what a write left half-made (a full disk). All of them are in `storage/backups/`, which the web never reaches.

## When a file is refused

| The page says | Why |
|---|---|
| «فایل پشتیبان را انتخاب کنید.» | No file came with the request: choose the backup file again |
| «این فایل، فایل پشتیبان AmoBot نیست.» | It is no backup of AmoBot |
| «این فایل پشتیبان ناقص است یا تغییر کرده، یا در نصب دیگری از AmoBot ساخته شده است؛ …» | Cut short, changed, or signed with another `APP_KEY`: only a file this installation made, unchanged, is restored. On a new host, put the old `config.php` back |
| «این پشتیبان با AmoBot … ساخته شده که از نسخه این فروشگاه (…) تازه‌تر است؛ …» | Made by a newer version: update the shop first |
| «این پشتیبان با AmoBot … ساخته شده و نسخه این فروشگاه (…) نمی‌تواند آن را بازگرداند؛ …» | Made by a version older than this one restores — its data would not come back whole ([restoring](#restoring)): restore a newer backup, or the host's own |
| «این پشتیبان کل نصب است و فقط از پنل مالک، در فروشگاه اصلی، بازگردانده می‌شود.» | A whole installation's backup, in a shop's panel |
| «این پشتیبان یک فروشگاه است، نه کل نصب؛ …» | A shop's backup, in the owner's panel in the main shop: open that shop first |
| «این پشتیبان فروشگاه دیگری است (…)؛ …» | Another shop's backup |
| «این پشتیبان ردیف‌هایی دارد که از آن این فروشگاه نیستند؛ بازگردانده نمی‌شود.» | It holds a table or rows outside what its kind of backup holds — another shop's in a shop's backup. A file this installation wrote never does, short of a bug: report it ([Contributing](Contributing.md)) |
| «این پشتیبان … سرویس روی سرورهایی دارد که دیگر نیستند (…)؛ …» | Services on servers deleted since ([a shop's restore](#a-shops-restore)) |
| «این فایل دیگر آماده بازگردانی نیست؛ آن را دوباره بارگذاری کنید.» | Uploaded more than 30 minutes ago, or restored already |
| «حجم این فایل … است؛ هاست فایل بزرگ‌تر از … را نمی‌پذیرد.» | Larger than the host takes in one upload: the page did not send it ([limits](#limits)) |
| «فایل بزرگ‌تر از چیزی است که هاست می‌پذیرد (…)؛ …» | Larger than the host takes in one upload ([limits](#limits)) |

## Watch out for

- **Restore the right file**: everything since it was made is lost. The state before is kept, three deep, in «پیش از
  بازگردانی‌ها» — press «دریافت فایل پشتیبان» yourself too when in doubt.
- **Keep `config.php` with your backups.** Lost, or its `APP_KEY` changed, no backup made before restores, and the
  secrets in the database cannot be read.
- **Keep `storage/uploads/` too**: a backup has no picture in it.
- **Everyone in the report group gets the shop's data**: keep the group to your team.
- **Customers' passwords and two-factor sign-ins come back as the file has them**: one who changed a leaked password
  since has the old one again, and a site admin removed since is one again. The panels' own logins are in no backup —
  the owner's is `config.php`'s —, and an agent's «خروج از مرورگرهای دیگر» since the backup stands.
