[فارسی](fa/Bot-Texts-And-Keyboards.md)

# Bot texts and keyboards

«ربات › متن‌های ربات» and «ربات › کیبوردها», in both panels: everything the bot says to a customer, and its menu — every
shop its own.

## The bot's texts

Everything the bot says to a customer is a text you may reword — over 400 of them in the main shop (an agent's has
fewer: no agency texts, none of the custom builder's or of services added by their link) —, listed by group, each with
its kind, its title and where the customer meets it. Search by a title or a piece of the wording; «تغییر یافته» lists
the ones you changed. Every text, its kind and its variables: [the bot texts reference](Bot-Texts-Reference.md). The
words of `/broadcast`, `/emoji` and the report group are fixed: only the bot's admins read them.

| Kind | Where it ends up | Formatting | At most |
|---|---|---|---|
| «پیام» | A message | Telegram's HTML | 3500 characters |
| «کپشن عکس» | The QR card's caption — a longer one goes as a message without the picture | Telegram's HTML | 850 |
| «بخشی از پیام» | A piece another text takes in (a status, a note, a hint); its leading empty lines are its distance from what is above it, and emptied, it is left out | Telegram's HTML | 500 |
| «اعلان» | The short notice on a tapped button | None — shown as written | 190 |
| «دکمه» | A button's label | None, one line | 60 |

### Editing a text

A text's title opens its editor:

- **The toolbar** wraps the selection in Telegram's tags: «پررنگ», «کج», «زیرخط», «خط‌خورده», «کد» (a tap copies it),
  «مخفی» (a tap shows it), «نقل‌قول» and «لینک» — type the address into its `href=""`. To write a `<` itself, write
  `&lt;`.
- **«متغیرها»**: a tap puts `%name%`, `%plan%`, `%subscription%`… at the caret; the bot fills them as it sends. One
  marked «لازم» cannot be left out — a delivery without its link would be no delivery.
- **The counter** counts as Telegram does — what the customer sees: tags cost nothing, `&lt;` is one character, and an
  emoji may count two. It counts the wording as it will be saved, without spaces at its ends (a part keeps its leading
  empty lines), and turns red past the limit. A wording the customer would see nothing of counts 0.
- **Under the field**, as you type, comes the first thing a save would refuse (the save still checks the wording
  itself, and refuses with the same words). [What Telegram refuses](#what-telegram-refuses) lists them.
- **«پیش‌نمایش»** shows the text in Telegram's dark look, the variables filled with examples.
- **«متن پیش‌فرض»** puts the shop's own wording in the editor — asking first when you typed something unsaved, which it
  would replace —, and **«ذخیره متن»** keeps it.

A text left at its default — or reset from its row's menu («بازگشت به متن پیش‌فرض», which asks first and cannot be undone)
— follows the shop's wording as later versions improve it.

### Numbers, commands and handles

In a right-to-left line, after Persian words, Telegram would show «#12» as «12#», «/menu» as «menu/» and «@sara» as
«sara@». So the default texts carry an invisible left-to-right mark (U+200E) right before each `#`, `/` or `@` that has
words before it on its line. The bot also puts one before a value it fills in that starts with a handle or a number —
the other customer of a transfer (`%name%`), the agent's bot (`%bot%`), support's contact (`%contact%`) — wherever words
come before the variable on its line, in your own wording too. No mark goes where no word comes before it on its line —
a ticket's button «🟡 #37 · …», a transfer question's line «👤 %name%» —: the line takes the direction of its first
letter, and the mark would be it.

A number, a command or a handle you type yourself after Persian words needs the same mark. The simplest way is to copy
it from a default text, where it stands before `#%order%`, `/menu` or `@BotFather`; «متن پیش‌فرض» puts the default
wording back with its marks. The search ignores the marks: «سفارش #» finds the texts with an order's number.

### What Telegram refuses

A message Telegram cannot read is not sent at all, so a wording is checked before it is saved. The checks run in this
order, and the first that fails is the one said:

1. **An empty wording**: «متن نمی‌تواند خالی باشد.» A part («بخشی از پیام») may be empty.
2. **Too long** — past its kind's limit (the table above), counted as the counter counts.
3. **A button on two lines.**
4. **A variable the text does not have**, or a required one left out.
5. **A tag in a notice or a button**, which show the text as it is. In a text with formatting:
   - **a tag Telegram does not know** — only `b`, `i`, `u`, `s`, `code`, `pre`, `a`, `blockquote`, `tg-spoiler`,
     `tg-emoji`, `tg-time` and their other names (`strong`, `em`, `ins`, `strike`, `del`, `<span class="tg-spoiler">`)
     are read; `<br>` is none: press Enter for a new line;
   - **a tag left open, or closed out of order**; a tag inside `<code>` or `<pre>`; anything but formatting inside a link
     or a date — a link, code, a quote, a premium emoji or a date in it; a quote inside a quote;
   - **an attribute name in capitals**: Telegram reads `href`, `class`, `emoji-id`, `expandable`, `unix` and `format`
     only in lowercase (a tag's own name may be in any case);
   - **a date that will not show** — a date and time is
     `<tg-time unix="1767225600" format="wDT">۱۱ دی ۱۴۰۴</tg-time>`: the customer's own app writes the moment `unix`
     names (seconds since 1970, up to 2147483647) in their language and time zone, as `format` says — `r` for relative
     to now (in 2 hours, 3 days ago), or a weekday `w`, then a short `d` or long `D` date, then a short `t` or long `T`
     time, each at most once and in that order; without a `format`, the words show as they are. The words inside are
     what is shown where the date cannot be, and cannot be empty; a `format` with capitals must be in quotes (bare,
     Telegram reads it in lowercase);
   - **an entity Telegram does not read**: only `&lt;`, `&gt;`, `&amp;`, `&quot;` and numbers such as `&#128512;` or
     `&#x1F600;` are read — `&nbsp;` or `&copy;` would reach the customer as typed. For `&` itself, write `&amp;`.
6. **A wording that shows nothing** — only spaces, line breaks or invisible characters (a no-break space, a zero-width
   one, a Hangul filler), once its tags are dropped and its entities read: refused as empty, in the same words. A part
   is exempt. This comes after the tags, so an unfinished tag is told as what it is.

### Premium emoji

Telegram's premium emoji show in what a bot sends **only while the account that made the bot in @BotFather has Telegram
Premium**. To use them:

1. A bot admin sends `/emoji` to the bot, then a message composed as the customer should see it — the premium emoji,
   formatting, `%variables%` typed in.
2. The bot keeps the emoji for the panel, sends the message back as itself — which shows whether it may use them — and
   answers with the message as a ready template («متن آماده برای «متن‌های ربات»», a tap copies it).
3. In a text's editor, «ایموجی پرمیوم» lists the latest 200; a tap puts one at the caret, and an emoji's ✕ takes it off
   the list.

Nothing is lost when Telegram turns them away: the message goes again with their plain emoji (and buttons without their
icons), and for ten minutes every message goes plain from the start before they are tried again. The picker warns while
the bot last found them refused — by `/emoji`, or by a message Telegram turned away.

## The menu

«کیبوردها» › «منوی شروع» — the menu `/start` shows:

- **The type**: «کیبورد پایین صفحه» — a keyboard under the text field, always there; a tap sends its label — or «دکمه‌های
  شیشه‌ای» — buttons under the greeting; every screen they open gets «بازگشت» to it.
- **Rows** — 12 at most, 8 buttons a row; the first row is the top one, a row's first button its rightmost. Drag a button
  anywhere — under the last row to make a new one —, or use its dialog. ▲▼ moves a row; its bin removes it.
- **A button** — «افزودن دکمه», or a tap on one:
  - «دکمه» — what it does: «خرید اشتراک», «اکانت تست», «سرویس‌های من», «تمدید سرویس», «کیف پول + شارژ»,
    «زیرمجموعه‌گیری», «نمایندگی», «آموزش» (the text «آموزش», a short guide you may reword), «پشتیبانی», «کد هدیه» or
    «گردونه شانس». Each once.
  - «متن دکمه» — its label, 64 characters, an emoji welcome; no two alike (a keyboard's tap arrives as its label).
  - «آیکون دکمه» — a premium emoji before the label (seen only as [above](#premium-emoji)). A premium emoji's tag pasted
    into the label is offered «انتقال به آیکون دکمه».
  - «رنگ دکمه» — «پیش‌فرض», «اصلی» (blue), «موفقیت» (green) or «خطر» (red), Telegram's own.
  - «جای دکمه» — «به راست», «به چپ», «ردیف بالا», «ردیف پایین».
  - «حذف دکمه» takes it off the keyboard.

The preview shows the menu under your greeting. «ذخیره» checks the keyboard as a whole and says what is wrong under its
row; «بازگشت به پیش‌فرض» brings the shop's own layout back — saved at once, asked first, no way back.

The shop's layout: «خرید اشتراک»; «اکانت تست»; «سرویس‌های من» | «تمدید سرویس»; «آموزش» | «کیف پول + شارژ»; «پشتیبانی» |
«زیرمجموعه‌گیری»; «نمایندگی»; «کد هدیه» | «گردونه شانس». An agent's bot has no «نمایندگی».

**«اکانت تست», «زیرمجموعه‌گیری», «نمایندگی», «کد هدیه» and «گردونه شانس» show only while their programs run**, so they
can stay on the layout; in the main bot an agent keeps «نمایندگی», their account. A layout without one of them is
pointed out on its program's page while the program runs. A layout made of nothing but these buttons, with every one
of their programs off, would leave no menu at all: the bot shows the shop's built-in menu instead, in your layout's
type, until one of them comes back.

## Watch out for

- **Relabelling a keyboard button** leaves customers with the old keyboard on screen: their tap sends the old label,
  which the bot answers «متوجه نشدم…» with the new menu — after which it works.
- **A dialog's changes are a draft** until the keyboard's «ذخیره».
- **A reset cannot be undone** — a text's, or the keyboard's.
