# Bot texts and keyboards

«ربات › متن‌های ربات» and «ربات › کیبوردها», in both panels: everything the bot says to a customer, and its menu — every
shop its own.

## The bot's texts

Everything the bot says to a customer is a text you may reword — 231 of them in the main shop (an agent's has no agency
texts) —, listed by group, each with its kind, its title and where the customer meets it. Search by a title or a piece
of the wording; «تغییر یافته» lists the ones you changed. Every text, its kind and its variables:
[the bot texts reference](Bot-Texts-Reference.md). The words of `/broadcast`, `/emoji` and the report group are fixed:
only the bot's admins read them.

| Kind | Where it ends up | Formatting | At most |
|---|---|---|---|
| «پیام» | A message | Telegram's HTML | 3500 characters |
| «کپشن عکس» | The QR card's caption — a longer one goes as a message without the picture | Telegram's HTML | 850 |
| «بخشی از پیام» | A piece another text takes in (a status, a note, a hint); its leading empty lines are its distance from what is above it, and emptied, it is left out | Telegram's HTML | 500 |
| «اعلان» | The short notice on a tapped button | None — shown as written | 190 |
| «دکمه» | A button's label | None, one line | 60 |

**Editing one** — its title opens the editor:

- The toolbar wraps the selection in Telegram's tags: «پررنگ», «کج», «زیرخط», «خط‌خورده», «کد» (a tap copies it), «مخفی»
  (a tap shows it), «نقل‌قول» and «لینک» — type the address into its `href=""`. To write a `<` itself, write `&lt;`.
- **«متغیرها»**: a tap puts `%name%`, `%plan%`, `%subscription%`… at the caret; the bot fills them as it sends. One
  marked «لازم» cannot be left out — a delivery without its link would be no delivery.
- The counter counts as Telegram does — what the customer sees, not the tags; an emoji may count two — and turns red past
  the limit. Under the field, as you type, the first thing a save would refuse: a tag Telegram does not know or left
  open, a variable the text does not have, a tag in a notice or a button.
- **«پیش‌نمایش»** shows it in Telegram's dark look, the variables filled with examples.
- «متن پیش‌فرض» puts the shop's own wording in the editor; «ذخیره متن» keeps it.

A text left at its default — or reset from its row's menu («بازگشت به متن پیش‌فرض», which asks first and cannot be undone)
— follows the shop's wording as later versions improve it.

### Premium emoji

Telegram's premium emoji show in what a bot sends **only while the account that made the bot in @BotFather has Telegram
Premium**. To use them:

1. A bot admin sends `/emoji` to the bot, then a message composed as the customer should see it — the premium emoji,
   formatting, `%variables%` typed in.
2. The bot keeps the emoji for the panel, sends the message back as itself — which shows whether it may use them — and
   answers with the message as a ready template («متن آماده برای «متن‌های ربات»», a tap copies it).
3. In a text's editor, «ایموجی پرمیوم» lists the latest 200; a tap puts one at the caret.

Nothing is lost when Telegram turns them away: the message goes again with their plain emoji (and buttons without their
icons), and for ten minutes every message goes plain from the start before they are tried again. The picker warns while
the last `/emoji` found them refused.

## The menu

«کیبوردها» › «منوی شروع» — the menu `/start` shows:

- **The type**: «کیبورد پایین صفحه» — a keyboard under the text field, always there; a tap sends its label — or «دکمه‌های
  شیشه‌ای» — buttons under the greeting; every screen they open gets «بازگشت» to it.
- **Rows** — 12 at most, 8 buttons a row; the first row is the top one, a row's first button its rightmost. Drag a button
  anywhere — under the last row to make a new one —, or use its dialog. ▲▼ moves a row; its bin removes it.
- **A button** — «افزودن دکمه», or a tap on one:
  - «دکمه» — what it does: «خرید اشتراک», «سرویس‌های من», «تمدید سرویس», «کیف پول + شارژ», «زیرمجموعه‌گیری», «نمایندگی»,
    «آموزش» (the text «آموزش», a short guide you may reword) or «پشتیبانی». Each once.
  - «متن دکمه» — its label, 64 characters, an emoji welcome; no two alike (a keyboard's tap arrives as its label).
  - «آیکون دکمه» — a premium emoji before the label (seen only as above). A premium emoji's tag pasted into the label is
    offered «انتقال به آیکون دکمه».
  - «رنگ دکمه» — «پیش‌فرض», «اصلی» (blue), «موفقیت» (green) or «خطر» (red), Telegram's own.
  - «جای دکمه» — «به راست», «به چپ», «ردیف بالا», «ردیف پایین».

The preview shows the menu under your greeting. «ذخیره» checks the keyboard as a whole and says what is wrong under its
row; «بازگشت به پیش‌فرض» brings the shop's own layout back — saved at once, asked first, no way back.

The shop's layout: «خرید اشتراک»; «سرویس‌های من» | «تمدید سرویس»; «آموزش» | «کیف پول + شارژ»; «پشتیبانی» |
«زیرمجموعه‌گیری»; «نمایندگی». **«زیرمجموعه‌گیری» and «نمایندگی» show only while their programs run** (an agent keeps
«نمایندگی», their account), so they can stay on the layout; a layout without one is pointed out on its program's page. An agent's
bot has no «نمایندگی».

## Watch out for

- **Relabelling a keyboard button** leaves customers with the old keyboard on screen: their tap sends the old label,
  which the bot answers «متوجه نشدم…» with the new menu — after which it works.
- **A dialog's changes are a draft** until the keyboard's «ذخیره».
- **A reset cannot be undone** — a text's, or the keyboard's.
