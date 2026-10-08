# Bot settings

«تنظیمات ربات»: the bot's switches and rules — every shop its own, the same page in your panel and an agent's —, a
section each in the settings column. Each card is saved on its own («ذخیره», «بازگردانی تغییرات» — both held while
nothing differs from what is saved), every refusal under its field at once, and the bot follows a saved change within
seconds; leaving a card with a change unsaved asks first. The agency's
rules are the main shop's, on the agents page ([Agency](Agency.md)).

## General

«عمومی»:

- **«ربات فعال باشد»** (on) — off, every customer gets «ربات خاموش» (`⛔️ ربات فعلا غیرفعال است. …`, a text you may reword)
  to whatever they send, the menu taken away, **and the shop's website takes no orders** (a purchase, a renewal, a
  top-up, a receipt — «فروشگاه فعلا سفارش نمی‌گیرد؛ …»). The bot's admins still pass, to try it. The page says the bot is
  off above every section. For a pause, or when the servers are down.
- **«تایید شماره موبایل»** (off) — on, a customer must send their own Telegram number — one tap of «📱 ارسال شماره موبایل»
  — before anything else: a number typed, or someone else's card, is refused. Asked once; the number shows on their
  customer page.
- **«راه ارتباط با پشتیبانی»** — a handle or link, 190 characters at most, shown on the bot's «☎️ پشتیبانی» above its
  ticket buttons («نیاز به کمک دارید؟ با ما در تماس باشید: …»); empty, the screen offers the tickets alone. The shop's
  website gets it as a link when it is one: `@handle`, an `https://` address, `t.me/…`, or a phone number.

## Channels

«کانال‌ها» — channels or groups a customer must join before using the bot:

- **«عضویت اجباری در کانال»** (off) — the rule. Checked after the phone, for customers only. On with an empty list, it
  asks nothing.
- **«کانال‌های اجباری»** — the list, which changes at once: «افزودن کانال» takes a link (`https://t.me/mychannel`), an
  `@name`, or — for a private channel — its numeric id (`-1001234567890`; an invite link cannot be looked up). **Make the
  bot an admin there first**: it must see the members, and for a private channel make the invite link itself (the
  «افزودن اعضا» right). ▲▼ the order, «بررسی دوباره» to check a channel again, «حذف» to take it off the list (the bot
  stays in the channel).

A customer missing a channel gets a button for each and «✅ عضو شدم», which checks again. Membership is trusted five
minutes once confirmed. A channel the bot can no longer see into lets everyone through, flagged on the list («مدیر
نیست»): fix it and press «بررسی دوباره».

## Wallet

«کیف پول» — the bot's «💰 کیف پول + شارژ»:

- **«حداقل مبلغ شارژ (تومان)»** — 1,000 to 500,000,000, 10,000 by default — `10000`, `10,000` or `۱۰٬۰۰۰`.
- **«مبلغ‌های پیشنهادی (تومان)»** — the amounts offered as buttons, apart by spaces, «،» or commas: 50000، 100000،
  200000، 500000 by default; 8 at most, none below the least; empty, «✏️ مبلغ دلخواه» alone. An amount may set its
  thousands apart (`50,000, 100,000` is two amounts); a comma between digits that are not a group of three splits them.

A customer picks an amount, or types any other from the least to 500,000,000 (Persian digits, separators and «تومان»
are fine). A top-up is paid by any way to pay but the wallet.

## Renewal

«تمدید سرویس», two cards:

- **«تمدید سرویس»** — the rule of every renewal, the customer's and the automatic one. The days left always carry into the
  renewed term. **«حجم باقی‌مانده به دوره جدید منتقل شود»** (off): off, the traffic the paid period leaves unused stays
  usable until that period ends, then goes, and the new period starts with the plan's traffic; on, it is added to the new
  period. Renewing early never takes anything away before its time.
- **«تمدید خودکار»** — the customer's switch on a service's screen («🔁 تمدید خودکار»): «چند روز پیش از پایان سرویس» (1 to
  30, 2 by default) the plan's price is taken from their wallet and the service renewed. «برای سرویس‌های جدید روشن باشد»
  (off) starts services bought from then on with it on. The switch is offered on a service that is active, ends some
  day and still has its plan — **and only while the wallet is switched on** («روش‌های پرداخت»). A wallet short of it is
  told once, and tried every half hour until it can pay; the panel's word on the end is asked first, so nothing is
  charged while a panel does not answer.

## Reminders

«یادآوری» — both off until switched on, each said once while the service stays past its line, and again after a
renewal or more traffic:

- **«یادآوری نزدیک شدن تاریخ پایان»** — «چند روز پیش از پایان سرویس», 1 to 30, 3 by default. A service whose «تمدید
  خودکار» is on hears from the renewal instead.
- **«یادآوری تمام شدن حجم»** — «بعد از مصرف چند درصد حجم», 50 to 99, 80 by default. Unlimited services never.

Each comes with «📊 مشاهده سرویس». The services are read from their panels every 15 minutes, and the reminders follow.

## Referrals

«زیرمجموعه‌گیری» — the program's switch, its share and «فقط اولین پرداخت هر زیرمجموعه» ([Referrals](Referrals.md)).

## The report group

«گروه گزارش‌ها» — connecting the shop's Telegram group, and «بخش‌های گزارش», a switch per topic, all on by default
([Report group](Telegram-Group.md)).

## The QR card

«کد QR»:

- **«ساخت کد QR»** (on) — a delivered service, a moved one, a new link and «🔗 لینک اشتراک» come as a picture: the
  subscription link as a QR code the customer scans into their app, the service's text as its caption. Off — or without
  PHP's GD, or a caption too long for Telegram — the text goes alone, the link in it.
- **«پس‌زمینه کد QR»** — the picture the code is drawn on: a dark one with a white square in the middle ships with the
  shop. «آپلود تصویر جدید» takes a PNG, JPG or WebP of 5 MB at most (16 megapixels; a side over 2048 pixels is shrunk) —
  a square one, the white square in its middle, works best —, at once; «بازگشت به پیش‌فرض» puts the shipped one back.

## Watch out for

- **The bot's admins are customers given the role** on «کاربران» ([Customers](Customers.md#the-bots-admins)) — your panel
  login is not one of them.
- **Reminders, automatic renewals and approvals by themselves need the scheduler** running — the cron or the poller
  ([Running in production](Running-In-Production.md)).
- **Viewing an agent's shop**, you edit that agent's bot.
