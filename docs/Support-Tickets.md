# Support tickets

«پشتیبانی», in both panels, right under the dashboard: the customers' support conversations. A ticket is a subject, the
service it is about (or none), and messages — words, each with at most one picture. Customers open them in the bot
(«☎️ پشتیبانی» › «📨 تیکت جدید», or a service's «⚠️ ارسال گزارش اختلال») or on the shop's website; you answer from either
panel or from the report group.

| Status | Means |
|---|---|
| «در انتظار پاسخ» | Waiting on you: the customer wrote last — or you opened it again |
| «پاسخ‌داده‌شده» | You wrote last; waiting on the customer |
| «بسته‌شده» | Closed — a message from either side opens it again |

The menu's count, and the dashboard's «تیکت‌های در انتظار پاسخ», are the tickets waiting on you.

## The list

The tabs are «همه» (where it opens), «در انتظار پاسخ» — counted —, «پاسخ‌داده‌شده» and «بسته‌شده». The conversation that
moved last is first; no column sorts it. Search by a ticket's number (`#12` is ticket 12 alone), the subject or the
customer; one customer's tickets come from their page («همه تیکت‌ها»). A row: the number, the subject (with the service
it is about), the customer, its status, its last activity, how many messages, the customer's rating, and «پاسخ» on one
waiting for you («مشاهده» on the rest).

## A ticket's page

The subject and status, «تیکت #12 · باز شده در …» (and since when it is closed), the customer, the service — a link to
it —, the customer's rating and note; then the conversation, oldest first: the customer's at the start, support's at
the end, each with where it was written — «وب‌سایت», «ربات», «پنل», «مدیریت وب‌سایت» (a bot admin on the shop's website),
«گروه گزارش‌ها» — and, for support's, who wrote it.
A picture opens at full size («دانلود تصویر» for one the browser cannot draw, a phone's HEIC sent as a file).

**«پاسخ به مشتری»**: «متن پاسخ» (4000 characters at most) and, optionally, one picture — JPG, PNG or WebP, 10 MB at most
—, then «ارسال پاسخ». The ticket becomes «پاسخ‌داده‌شده» and the customer is told: in Telegram, with your picture and the
buttons «✍️ پاسخ» and «🗂️ مشاهده تیکت» (a long answer cut, the whole on the website), or — a customer Telegram cannot
reach — by email; the answer is on their website either way. Answering a **closed** ticket opens it again as
«پاسخ‌داده‌شده», and drops the customer's rating.

The page's one button follows the status:

- **«بستن تیکت»** — asks first, then closes it; the customer is told («⭐ امتیاز» under it, to rate the conversation). A
  message from them opens it again.
- **«باز کردن دوباره»** — on a closed ticket: it goes back to «در انتظار پاسخ», and the customer is not told. A rated
  ticket asks first: opening it clears the rating (they may rate it again when it closes).

**Who of support wrote** shows to the panel's reader: your login, an agent's «@bot», a bot admin's handle from the
group or the website. Anyone but you reads your login as «پشتیبانی»; the customer never sees who — every answer is
«🎧 پشتیبانی» to them. The bot's admins may answer, close and open tickets from the shop's website too, while it lets
them in ([Website](Website.md#the-shops-admins)).

## From the report group

The group's «تیکت‌ها» topic hears every ticket, while a group is connected and the topic is on: a new one with its
first message — a picture uploaded on the website or a panel as its photo; one sent in the bot is only mentioned —, then
each later message (the customer's, or an answer from a panel), its closing, opening again and rating, each a reply under
the one before. While the ticket is open, the latest carries **«🔒 بستن تیکت»**, which closes it as the panel does.

**A bot admin's reply to any of a ticket's reports is the answer**, sent to the customer: words, or a photo with a
caption. The bot says under it «✅ پاسخ برای مشتری فرستاده شد.», or why not — not a bot admin, written anonymously
(turn "Remain anonymous" off in the group), a photo without a caption, a voice or a file. A ticket's reports are kept
while it is open, however old; a closed one's go a week after they were sent — reply to a newer one, or answer from the
panel ([Report group](Telegram-Group.md)).

## Limits

- A ticket holds **200 messages**, yours and the customer's; past it, the customer opens a new one.
- A customer may open 10 tickets an hour, send 30 messages in ten minutes (ratings among them) and upload 10 pictures an
  hour — receipts and tickets' together —, and a ticket takes 20 pictures uploaded by them. You are never held.
- A picture is taken while the server's disk has 200 MB free, and up to a gigabyte of pictures a day for the shop;
  past either, «… تصویری پذیرفته نمی‌شود» — send the words alone.
- **A closed ticket's uploaded pictures are deleted 30 days after it closed**; the message keeps its words and says it had
  one. Pictures sent in the bot are Telegram's.

## Watch out for

- **Opening a ticket in the panel marks nothing read** for the customer, and answering it is what tells them: a ticket
  you only read stays «در انتظار پاسخ».
- **A ticket opened while no group was connected**, or the topic off, is never reported later: answer it from the panel.
- **An answer cannot be taken back** — it reached the customer as it was sent.
