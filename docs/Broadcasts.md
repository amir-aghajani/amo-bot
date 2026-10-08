# Broadcasts

«ربات › ارسال همگانی»: «پیام همگانی» — a message to many customers, in both panels — and, the owner's alone, «هدیه
همگانی» — days and traffic for many services at once.

## A message to many customers

A broadcast is **composed in the bot**, by one of its admins ([the bot's admins](Customers.md#the-bots-admins)): it is
their own Telegram message that goes out, with its media and formatting. The panel follows the runs and controls them.

1. Send `/broadcast` to the bot, then the message — text, a photo, a video, any message; a channel's post forwarded to
   the bot is forwarded on.
2. The bot answers with a **draft card**: what the message is, how it goes, to whom and how many, pinned or not, its
   buttons. Its buttons change them:
   - **«↪️ روش»** — «کپی» (from the bot, your link buttons under it) or «فوروارد» (with "Forwarded from" its first sender;
     a forward takes no buttons).
   - **«👥 مخاطب»** — «همه کاربران», «خریداران» (anyone who bought a service; a top-up alone is no purchase),
     «غیرفعال‌ها» (no service running now), «غیرخریداران», one of the shop's customer groups, «نماینده‌ها» (the main bot's
     agents), or one server's customers (a service running there) — each with how many it reaches.
   - **«📌 پین»** — pinned, quietly, in each chat.
   - **«🔘 دکمه‌ها»** — link buttons under a copy: a row a line, the buttons of a row apart by `|`, each `text - link`
     (an `http://`, `https://` or `tg://` link; 8 rows of 4 at most):
     ```
     عضویت در کانال - https://t.me/mychannel
     سایت - https://example.com | پشتیبانی - https://t.me/support
     ```
   Sending another message replaces the message and keeps the rest.
3. **«✅ ارسال به n نفر»** sends it; «❌ انصراف» drops the draft.

**Who is reached**: customers who are not banned and have a Telegram account — website customers without one are
neither counted nor reached. One who blocked the bot is counted («مسدود») and not sent to. Nobody gets it twice.

**The card becomes the run's progress** — sent, blocked, failed, how far —, redrawn as it goes, with «⏸️ توقف»,
«▶️ ادامه» and «✖️ لغو ارسال» (in the bot, at one press; those already sent keep it). Once a pinned run is over, «📌 لغو
پین» unpins it in every chat, with a progress card of its own.

**The pace**: 20 messages a second at most (a pin is one more). The first 50 go as you press «ارسال»; the rest go with
the scheduler, up to 600 a minute — **without the cron or the poller running, a broadcast stops at its first 50**
([Running in production](Running-In-Production.md)). When the scheduler finishes a run, the admin who sent it gets a
summary.

### The runs in the panel

«پیام همگانی» lists every run, newest first, as it goes: the message (its kind, its first words, copy or forward, pinned,
its buttons), the audience, the progress, the status — «در حال ارسال», «متوقف‌شده», «انجام‌شده», «لغوشده» — and who sent
it. Its menu: «توقف», «ادامه», «لغو پین» (asks first; the scheduler does the unpinning) and «لغو ارسال» (asks first).
From the panel, «ادامه» leaves the next batch to the scheduler. An agent's panel has this section alone, for their bot.

## The mass gift

«هدیه همگانی» › «هدیه جدید»: days and traffic for many services at once — a holiday gift, a long outage made good:

- **«به چه سرویس‌هایی»** — «همه سرویس‌ها», «فقط سرویس‌های نماینده‌ها» (what the bots of the agencies that stand sold)
  or «سرویس‌های یک سرور».
- «تعداد روز» (0 to 365), «حجم (گیگابایت)» (0 to 10000), «دلیل» (300 characters, the customers read it), «سرویس‌های در
  انتظار اولین اتصال هم شامل شوند» (off) and «پیام به مشتری‌ها» (on) — as a [server's](Servers.md#adding-days-and-traffic).

«شروع هدیه» gives it as **one part per server**, each worked through on its own — service by service, the panel's word
deciding at each one's turn, exactly as a server's «افزودن زمان و حجم»: a panel out of reach holds its own server's part
only, and the rest go on. It reaches the services of every shop that existed as you gave it, and each customer is told by
their own shop's bot. The card works it while open, the scheduler every minute otherwise; «توقف» stops every part still
going — what was given stays given.

«هدیه جدید» waits while a gift on the list is running, and a server already running one refuses a new gift that would
reach it. The list shows the latest ten gifts — a server's own among them —, each server's part on that server's page
too, where it can be stopped alone.

## Watch out for

- **The bot's «✖️ لغو ارسال» does not ask first**; the panel's does.
- **A group or server deleted** after the draft leaves its audience empty: the card offers no «ارسال», and a run under way
  ends at its next batch.
- **Traffic a mass gift gives agents' customers** is not drawn from the agents' pools.
