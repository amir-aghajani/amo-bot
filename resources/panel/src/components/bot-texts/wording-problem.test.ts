import { describe, expect, it } from 'vitest'
import { blankWording, keptWording, telegramDecode, wordingProblem } from '@/components/bot-texts/wording-problem'
import type { BotTextRow } from '@/lib/api-types'

/*
 * The editor's warning (components/bot-texts/wording-problem) reads a wording as the server does before it stores one —
 * BotTexts::problem() and TelegramHtml::problem() — and says the same words: these are the PHP tests' own cases
 * (tests/Unit/Telegram/TelegramHtmlTest), so the two cannot drift apart unnoticed.
 */

const ACCEPTED: Record<string, string> = {
  'plain text': 'سلام، خوش آمدید',
  'a stray > and & are text': 'قیمت > ۱۰۰ & تخفیف',
  entities: '&lt;b&gt; &amp; &quot; &#128512; &#x1F600;',
  'entities without their «;», as Telegram reads them': '&lt;b&gt &amp &#65',
  'an «&» of the words': 'R&D و AT&T و &',
  'the formatting tags': '<b>a</b> <strong>b</strong> <i>c</i> <em>d</em> <u>e</u> <ins>f</ins> <s>g</s> <strike>h</strike> <del>i</del>',
  'a tag name in any case': '<B>پررنگ</B> <A href="https://t.me/amo">کانال</A>',
  'an attribute a tag does not read, in any case': '<b CLASS="x">پررنگ</b>',
  'formatting inside formatting': '<b>پررنگ <i>و کج</i></b>',
  'a variable in code': '<code>%subscription%</code>',
  'a link to a variable': '<a href="%subscription%">لینک اتصال</a>',
  'single quotes': "<a href='https://t.me/amo'>کانال</a>",
  'a bare token value': '<a href=amo.example>سایت</a>',
  'formatting in a link': '<a href="https://t.me/amo"><b>کانال</b></a>',
  spoilers: '<tg-spoiler>راز</tg-spoiler> <span class="tg-spoiler">راز</span>',
  quotes: '<blockquote>نقل‌قول</blockquote><blockquote expandable>بلند</blockquote>',
  'a code block with its language': '<pre><code class="language-php">echo 1;</code></pre>',
  'an end tag without a name closes the last one': '<b>پررنگ</>',
  'an unknown attribute is ignored': '<b class="x">پررنگ</b>',
  'a premium emoji': '<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> سلام',
  'a premium emoji in bold, a quote, a spoiler':
    '<b>خرید <tg-emoji emoji-id="1">🔥</tg-emoji></b><blockquote><tg-emoji emoji-id="2">✅</tg-emoji></blockquote><tg-spoiler><tg-emoji emoji-id="3">🎁</tg-emoji></tg-spoiler>',
  'a premium flag, keycap, joined and toned emoji':
    '<tg-emoji emoji-id="4">🇮🇷</tg-emoji><tg-emoji emoji-id="5">1️⃣</tg-emoji><tg-emoji emoji-id="6">👨‍💻</tg-emoji><tg-emoji emoji-id="7">👍🏽</tg-emoji><tg-emoji emoji-id="8">⬅️</tg-emoji>',
  'a premium emoji written as an entity': '<tg-emoji emoji-id="9">&#128512;</tg-emoji>',
  // As the Bot API writes a date: its Unix time, a format of its control characters, or none — the words as they are.
  'a date and time': '<tg-time unix="1647531900" format="wDT">۲۲:۴۵ فردا</tg-time>',
  'each format Telegram reads':
    '<tg-time unix="1647531900" format="r">x</tg-time><tg-time unix="1647531900" format="t">x</tg-time><tg-time unix="1647531900" format="wd">x</tg-time><tg-time unix="1647531900" format="DT">x</tg-time><tg-time unix="1647531900" format="">x</tg-time>',
  'a date without a format, its value bare': '<tg-time unix=1647531900>۲۲:۴۵ فردا</tg-time> <tg-time unix="1" format=wdt>x</tg-time>',
  'a date in bold, in a quote, with bold inside': '<b><tg-time unix="1647531900">x</tg-time></b><blockquote><tg-time unix="1647531900"><i>x</i></tg-time></blockquote>',
}

const REFUSED: Record<string, [string, string]> = {
  'a lone <': ['اگر حجم < ۱ گیگ شد', '&lt;'],
  'a < at the end': ['کمتر از <', '&lt;'],
  'a line break tag': ['خط اول<br>خط دوم', 'Enter'],
  'a self-closed line break': ['خط اول<br/>خط دوم', 'Enter'],
  'a tag Telegram does not know': ['<p>بند</p>', 'تگ <p> را نمی‌شناسد'],
  'an open tag': ['<b>پررنگ', 'تگ <b> بسته نشده است'],
  'a close without an open': ['پررنگ</b>', 'تگ </b> بسته شده ولی پیش از آن باز نشده است'],
  'a close of another tag': ['<b>پررنگ</i>', 'تگ </i> بسته شده ولی پیش از آن باز نشده است'],
  'crossed tags': ['<b><i>پررنگ</b></i>', 'پیش از </b> باید </i> بسته شود'],
  'an unfinished tag': ['<b پررنگ', 'تگ <b> با > بسته نشده است'],
  'an unfinished end tag': ['<b>x</b', 'تگ </b> با > بسته نشده است'],
  'a span that is no spoiler': ['<span>متن</span>', 'tg-spoiler'],
  'an unquoted variable': ['<a href=%subscription%>لینک</a>', 'داخل "…"'],
  'an attribute without a value': ['<a href>لینک</a>', 'باید = و مقدار بیاید'],
  'an unclosed quote': ['<a href="https://t.me>لینک</a>', 'بسته نشده است'],
  'an attribute without a name': ['<a ="x">لینک</a>', 'نام ویژگی'],
  'formatting in code': ['<code><b>کد</b></code>', 'داخل <code>'],
  'formatting in pre': ['<pre><i>کد</i></pre>', 'داخل <pre>'],
  'a link in a link': ['<a href="x"><a href="y">لینک</a></a>', 'داخل لینک'],
  'code in a link': ['<a href="x"><code>لینک</code></a>', 'داخل لینک'],
  'a quote in a quote': ['<blockquote><blockquote>x</blockquote></blockquote>', 'نقل‌قول'],
  'a premium emoji without its id': ['<tg-emoji>👍</tg-emoji>', 'شناسه عددی'],
  'a premium emoji with an id that is no number': ['<tg-emoji emoji-id="like">👍</tg-emoji>', 'شناسه عددی'],
  'a premium emoji whose id ends in a line break': ['<tg-emoji emoji-id="5\n">👍</tg-emoji>', 'شناسه عددی'],
  'words in a premium emoji': ['<tg-emoji emoji-id="5">لایک</tg-emoji>', 'فقط یک ایموجی'],
  'a tag in a premium emoji': ['<tg-emoji emoji-id="5"><b>👍</b></tg-emoji>', 'فقط یک ایموجی'],
  'an empty premium emoji': ['<tg-emoji emoji-id="5"></tg-emoji>', 'فقط یک ایموجی'],
  'a digit as a premium emoji': ['<tg-emoji emoji-id="5">1</tg-emoji>', 'فقط یک ایموجی'],
  'two emoji with a space in one': ['<tg-emoji emoji-id="5">👍 👍</tg-emoji>', 'فقط یک ایموجی'],
  'a premium emoji in a link': ['<a href="x"><tg-emoji emoji-id="5">👍</tg-emoji></a>', 'داخل لینک'],
  'a premium emoji in code': ['<code><tg-emoji emoji-id="5">👍</tg-emoji></code>', 'داخل <code>'],
  'an unclosed premium emoji': ['<tg-emoji emoji-id="5">👍', 'تگ <tg-emoji> بسته نشده است'],
  // A no-break space is no space to the server's parser: the end tag is another name, not the emoji's — whatever is inside.
  'a premium emoji’s end tag with a no-break space': ['<tg-emoji emoji-id="5">x</tg-emoji >', 'بسته شده ولی پیش از آن باز نشده است'],
  // Telegram compares attribute names as written: one in capitals is passed by — the link lost, the tag refused.
  'a link’s address in capitals': ['<a HREF="https://t.me/amo">کانال</a>', 'نام «HREF» را با حروف کوچک بنویسید: href'],
  'a spoiler’s class in capitals': ['<span CLASS="tg-spoiler">راز</span>', 'class'],
  'a premium emoji’s id in capitals': ['<tg-emoji Emoji-Id="5">👍</tg-emoji>', 'emoji-id'],
  'an expandable quote in capitals': ['<blockquote EXPANDABLE>بلند</blockquote>', 'expandable'],
  'a date’s time in capitals': ['<tg-time UNIX="1647531900">x</tg-time>', 'نام «UNIX» را با حروف کوچک بنویسید: unix'],
  'a date’s format in capitals': ['<tg-time unix="1647531900" Format="t">x</tg-time>', 'format'],
  // A date: its Unix time, a format of Telegram's, and words.
  'a date without its time': ['<tg-time format="t">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a date whose time is no number': ['<tg-time unix="فردا">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a date before 1970': ['<tg-time unix="-5">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a date at zero': ['<tg-time unix="0">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a date past what Telegram holds': ['<tg-time unix="2147483648">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a date whose time ends in a line break': ['<tg-time unix="1647531900\n">x</tg-time>', 'ثانیه‌های یونیکس'],
  'a format that ends in a line break': ['<tg-time unix="1647531900" format="t\n">x</tg-time>', 'مقدار format'],
  'a format Telegram does not read': ['<tg-time unix="1647531900" format="Y-m-d">x</tg-time>', 'مقدار format'],
  'relative time with more': ['<tg-time unix="1647531900" format="rT">x</tg-time>', 'مقدار format'],
  'a format out of order': ['<tg-time unix="1647531900" format="Tw">x</tg-time>', 'مقدار format'],
  'a bare format with capitals, read in lower case': ['<tg-time unix="1647531900" format=wDT>x</tg-time>', 'داخل "…"'],
  'an empty date': ['<tg-time unix="1647531900" format="t"></tg-time>', 'داخل <tg-time> متنی بنویسید'],
  'a date of nothing but a tag': ['<tg-time unix="1647531900"><b> </b></tg-time>', 'داخل <tg-time> متنی بنویسید'],
  'an unclosed date': ['<tg-time unix="1647531900">x', 'تگ <tg-time> بسته نشده است'],
  // What holds no other entity: a link, a date.
  'a date in a link': ['<a href="x"><tg-time unix="1">x</tg-time></a>', 'داخل لینک (<a>) تگ <tg-time>'],
  'a link in a date': ['<tg-time unix="1"><a href="x">x</a></tg-time>', 'داخل زمان (<tg-time>) تگ <a>'],
  'a premium emoji in a date': ['<tg-time unix="1"><tg-emoji emoji-id="5">👍</tg-emoji></tg-time>', 'داخل زمان (<tg-time>) تگ <tg-emoji>'],
  'a date in a date': ['<tg-time unix="1"><tg-time unix="2">x</tg-time></tg-time>', 'داخل زمان'],
  'code in a date': ['<tg-time unix="1"><code>x</code></tg-time>', 'داخل زمان'],
  'a date in code': ['<code><tg-time unix="1">x</tg-time></code>', 'داخل <code>'],
  // Entities Telegram does not read: shown as typed — or, half a character, the whole message refused.
  'a named entity Telegram does not know': ['قیمت&nbsp;ویژه', 'تلگرام «&nbsp;» را نمی‌شناسد'],
  another: ['&copy; فروشگاه', '«&copy;»'],
  'a known name in capitals': ['&LT;b&GT;', '«&LT;»'],
  'a hex number after a capital X': ['&#X1F600;', '«&#X1F600;»'],
  zero: ['&#0;', '«&#0;»'],
  'past the last character': ['&#1114111;', '«&#1114111;»'],
  'too long to be a number': ['&#00000000065;', '«&#00000000065;»'],
  'half an emoji': ['&#55357;&#56832;', 'نیمی از یک نویسه'],
}

/** A message in Telegram's HTML whose one variable, the link, may be left out: what a wording is read as there alone. */
const HTML: Pick<BotTextRow, 'kind' | 'html' | 'variables'> = {
  kind: 'message',
  html: true,
  variables: [{ name: 'subscription', description: 'لینک', sample: 'https://sub.example.com/s/1', html: false, required: false }],
}

describe('Telegram HTML', () => {
  it.each(Object.entries(ACCEPTED))('takes %s', (_, text) => {
    expect(wordingProblem(HTML, text)).toBeNull()
  })

  // Read in a part, which may be blank: each reaches Telegram's parser as TelegramHtmlTest reads it alone — in a message,
  // one whose tags leave nothing to see is refused as empty first (below).
  it.each(Object.entries(REFUSED))('names %s', (_, [text, says]) => {
    expect(wordingProblem({ ...HTML, kind: 'part' }, text)).toContain(says)
  })

  it('says each refusal in the server’s own words', () => {
    expect(wordingProblem(HTML, '<a HREF="https://t.me/amo">کانال</a>')).toBe('در تگ <a> نام «HREF» را با حروف کوچک بنویسید: href؛ تلگرام آن را با حروف بزرگ نمی‌خواند.')
    expect(wordingProblem(HTML, 'قیمت&nbsp;ویژه')).toBe(
      'تلگرام «&nbsp;» را نمی‌شناسد و همان‌طور که نوشته شده نشان می‌دهد؛ فقط &lt; و &gt; و &amp; و &quot; و نمادهای عددی مثل &#128512; یا &#x1F600; خوانده می‌شوند. برای خود نماد & بنویسید &amp;.',
    )
    expect(wordingProblem(HTML, '&#55357;&#56832;')).toBe('«&#55357;» نیمی از یک نویسه است و تلگرام پیامی با آن را نمی‌پذیرد؛ خود نویسه را بنویسید، یا عدد کامل آن را مثل &#128512;.')
    expect(wordingProblem(HTML, '<p>بند</p>')).toBe('تلگرام تگ <p> را نمی‌شناسد؛ تگ‌های مجاز: b، i، u، s، code، pre، a، blockquote، tg-spoiler، tg-emoji و tg-time.')
    expect(wordingProblem(HTML, '<tg-time unix="1" format="Y">x</tg-time>')).toBe(
      'مقدار format در تگ <tg-time> یا r است، یا از w، سپس d یا D، سپس t یا T ساخته می‌شود (مثلا wDT)؛ یا format را ننویسید تا متن همان‌طور که هست نشان داده شود.',
    )
  })
})

describe('Telegram’s entities, decoded', () => {
  it('are its four names and numbers — decimal, or hex after a lowercase x —, «;» or not; any other «&» stays as typed', () => {
    expect(telegramDecode('&lt;&gt;&amp;&quot; &lt&#65&#x42; &#128512;')).toBe('<>&" <AB 😀')
    expect(telegramDecode('&nbsp;&copy; &LT; &#X41; &#0; &#1114111; &#00000000065; R&D &')).toBe('&nbsp;&copy; &LT; &#X41; &#0; &#1114111; &#00000000065; R&D &')
  })

  it('leave half a character as typed', () => {
    expect(telegramDecode('&#55357;')).toBe('&#55357;')
  })
})

/** A text of the catalogue: a message with a required variable and an optional one. */
const MESSAGE: Pick<BotTextRow, 'kind' | 'html' | 'variables'> = {
  kind: 'message',
  html: true,
  variables: [
    { name: 'subscription', description: 'لینک', sample: 'https://sub.example.com/s/1', html: false, required: true },
    { name: 'plan', description: 'پلن', sample: 'طلایی', html: false, required: false },
  ],
}

describe('a wording', () => {
  it('is fine as the server would keep it', () => {
    expect(wordingProblem(MESSAGE, '  <b>%plan%</b>\n%subscription%  ')).toBeNull()
    expect(wordingProblem({ kind: 'part', html: true, variables: [] }, '')).toBeNull()
  })

  it('names what the server would refuse besides the HTML, with the server’s message', () => {
    expect(wordingProblem(MESSAGE, '   ')).toBe('متن نمی‌تواند خالی باشد.')
    expect(wordingProblem(MESSAGE, '%subscription% %days%')).toBe('متغیر %days% در این متن وجود ندارد.')
    expect(wordingProblem({ kind: 'popup', html: false, variables: [] }, 'باشه %name%')).toBe('این متن متغیری ندارد؛ %name% را بردارید.')
    expect(wordingProblem(MESSAGE, 'لینک شما')).toBe('متغیر %subscription% باید در متن بماند.')
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, 'خرید\nسرویس')).toBe('متن دکمه باید یک خط باشد.')
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, '<b>خرید</b>')).toContain('قالب‌بندی ندارد')
    expect(wordingProblem(MESSAGE, '<b>%subscription%')).toContain('تگ <b> بسته نشده است')
  })
})

describe('a wording as the server keeps it', () => {
  it('loses at its ends what PHP’s trim() takes — ASCII spaces —, never a no-break space nor a form feed', () => {
    expect(keptWording('message', ' \t\r\n\v سلام \n')).toBe('سلام')
    expect(keptWording('message', ' سلام  ')).toBe(' سلام ')
    expect(keptWording('message', 'سلام\f')).toBe('سلام\f')
    expect(keptWording('part', '\n\nتمدید \r\n')).toBe('\n\nتمدید')
    // Kept as the server keeps it, a no-break space alone is still there — though Telegram shows nothing (below).
    expect(keptWording('message', ' ')).toBe(' ')
  })
})

describe('a blank wording', () => {
  const EMPTY = 'متن نمی‌تواند خالی باشد.'
  const PROMPT: Pick<BotTextRow, 'kind' | 'html' | 'variables'> = { kind: 'message', html: true, variables: [] }

  // The PHP tests' own cases (tests/Feature/BotTextsTest::refused): kept by trim(), shown as nothing by Telegram.
  it('is refused as empty though trim() keeps it: a space of any kind, zero-width characters, formatting around nothing', () => {
    expect(wordingProblem(PROMPT, ' ')).toBe(EMPTY)
    expect(wordingProblem(PROMPT, '​‌﻿\n⁠')).toBe(EMPTY)
    expect(wordingProblem(PROMPT, '<b> </b>&#160;')).toBe(EMPTY)
    expect(wordingProblem(PROMPT, '<b></b>')).toBe(EMPTY)
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, ' ')).toBe(EMPTY)
    expect(wordingProblem({ kind: 'popup', html: false, variables: [] }, '　')).toBe(EMPTY)
    // The letters made to look empty (Text::blank()): a Hangul filler, the blank Braille pattern.
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, '\u{3164}')).toBe(EMPTY)
    expect(wordingProblem(PROMPT, '<b>\u{2800}\u{2800}</b>')).toBe(EMPTY)
  })

  // The server checks the HTML before the blank: what strip_tags() would swallow — an unfinished tag takes what follows
  // it, an empty premium emoji is all tags — is told as what it is, not as nothing.
  it('is told by its markup first, where Telegram would refuse that too', () => {
    expect(wordingProblem(PROMPT, '<b پررنگ')).toBe('تگ <b> با > بسته نشده است.')
    expect(wordingProblem(PROMPT, '<tg-emoji emoji-id="5"></tg-emoji>')).toContain('فقط یک ایموجی معمولی')
    expect(blankWording(PROMPT, '<b پررنگ')).toBe(true)
  })

  it('is not one with anything the customer sees — an entity Telegram shows as typed is text —, and a part may be blank', () => {
    expect(wordingProblem(PROMPT, 'سلام ')).toBeNull()
    expect(wordingProblem(PROMPT, '&nbsp;')).not.toBe(EMPTY)
    expect(wordingProblem({ kind: 'part', html: true, variables: [] }, ' ')).toBeNull()
    expect(blankWording({ kind: 'part', html: true }, '​')).toBe(false)
    expect(blankWording({ kind: 'message', html: true }, '<i> </i>')).toBe(true)
  })
})
