# Reviews

«نظرات», in both panels, right after «پشتیبانی»: what customers write about the shop on its website. A review is a name,
1 to 5 stars, a few words and — optionally — where they use the service from; the website shows it only once you
approve it. Every shop has its own, the main one and each agent's — for a shop with a website
([Website](Website.md)): reviews are written there, never in the bot, and only while «تنظیمات وب‌سایت › نظرات» has
**«نظرات در وب‌سایت»** on ([Website](Website.md#reviews)) — off, as a website starts, the site shows none and takes none.

| Status | Means |
|---|---|
| «در انتظار بررسی» | Written, waiting on you — the website does not show it |
| «تاییدشده» | Approved: the website shows it, in its list and in its average |
| «ردشده» | Rejected: kept here, never shown |

## How one arrives

A customer signed in on the website may write one — linked to their account then —, and so may a guest while the website
asks a captcha («تنظیمات وب‌سایت › ورود با ایمیل» › «تایید امنیتی»): with none, guests write no review. It waits
«در انتظار بررسی», counted beside «نظرات» in the menu and on the dashboard's «نیازمند توجه»
(«نظرات در انتظار بررسی»), and the report group's «نظرات» topic hears it: the number, the name it is signed with, the
stars, where they use the service from, who wrote it — the customer, or «مهمان، بدون ورود به حساب» —, and the words,
then «⏳ در انتظار بررسی؛ …». The group has no buttons for it: you decide here ([Report group](Telegram-Group.md)).

## The list

The tabs are «همه», «در انتظار بررسی» — counted —, «تاییدشده» and «ردشده»; the newest first. Search by a review's number
(`#12` is review 12 alone), the name it is signed with, its words, or the customer who wrote it. A row: its number, the
name and who wrote it (their customer page, or «مهمان»), the stars, the words whole with where they use the service from,
its status with who decided and when, and when it was written. Its menu:

- **«تایید»** — at once: the website shows it.
- **«رد»** — at once: the website does not show it — an approved one is hidden again.
- **«حذف»** — asks first: the review is gone for good. To keep it off the website, rejecting is enough.

A decision can be taken back the other way at any time — a rejected review approved, an approved one rejected —; one
already taken, a moment ago from elsewhere, is refused in the words of where the review stands («این نظر تایید شده
است.», «این نظر رد شده است.»). **Nobody is told**: not the writer, whichever you decide. Who
decided is kept on the review; anyone but you reads your login as «پشتیبانی». The bot's admins may work this page from
the shop's website too, while it lets them in ([Website](Website.md#the-shops-admins)) — but never decide their own
review: another admin, or you, does.

## What the shop holds back

- **200 reviews waiting on you at most**: past them, the website takes no new review until you decide some.
- From one address network, 20 reviews an hour; from one signed-in customer, 3 a day; from every guest together, 20 an
  hour.
- The website's captcha, on every guest's review — without it, the signed-in customers' alone.

What the website's developer builds — the list, the average, the form: [Store API: reviews](Store-API-Reviews.md).

## Watch out for

- **Approving publishes the words as written**: a link, a phone number, an insult go up with them. A review cannot be
  edited — reject it, or delete it.
- **A guest's name is whatever they typed**: only a signed-in writer is a known customer.
- **A full queue stops new reviews**: decide the waiting ones, or the website's form refuses everyone.
- **A website starts with reviews off**: until «نظرات در وب‌سایت» is on, the site shows none of those you approved.
