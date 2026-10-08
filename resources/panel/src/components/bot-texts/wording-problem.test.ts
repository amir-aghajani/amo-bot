import { describe, expect, it } from 'vitest'
import { wordingProblem } from '@/components/bot-texts/wording-problem'
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
  'the formatting tags': '<b>a</b> <strong>b</strong> <i>c</i> <em>d</em> <u>e</u> <ins>f</ins> <s>g</s> <strike>h</strike> <del>i</del>',
  'any case': '<B>پررنگ</B>',
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
  'words in a premium emoji': ['<tg-emoji emoji-id="5">لایک</tg-emoji>', 'فقط یک ایموجی'],
  'a tag in a premium emoji': ['<tg-emoji emoji-id="5"><b>👍</b></tg-emoji>', 'فقط یک ایموجی'],
  'an empty premium emoji': ['<tg-emoji emoji-id="5"></tg-emoji>', 'فقط یک ایموجی'],
  'a digit as a premium emoji': ['<tg-emoji emoji-id="5">1</tg-emoji>', 'فقط یک ایموجی'],
  'two emoji with a space in one': ['<tg-emoji emoji-id="5">👍 👍</tg-emoji>', 'فقط یک ایموجی'],
  'a premium emoji in a link': ['<a href="x"><tg-emoji emoji-id="5">👍</tg-emoji></a>', 'داخل لینک'],
  'a premium emoji in code': ['<code><tg-emoji emoji-id="5">👍</tg-emoji></code>', 'داخل <code>'],
  'an unclosed premium emoji': ['<tg-emoji emoji-id="5">👍', 'تگ <tg-emoji> بسته نشده است'],
}

/** A message in Telegram's HTML whose one variable, the link, may be left out: what a wording is read as there alone. */
const HTML: Pick<BotTextRow, 'kind' | 'html' | 'variables'> = {
  kind: 'message',
  html: true,
  variables: [{ name: 'subscription', description: 'لینک', sample: 'https://sub.example.com/s/1', required: false }],
}

describe('Telegram HTML', () => {
  it.each(Object.entries(ACCEPTED))('takes %s', (_, text) => {
    expect(wordingProblem(HTML, text)).toBeNull()
  })

  it.each(Object.entries(REFUSED))('names %s', (_, [text, says]) => {
    expect(wordingProblem(HTML, text)).toContain(says)
  })
})

/** A text of the catalogue: a message with a required variable and an optional one. */
const MESSAGE: Pick<BotTextRow, 'kind' | 'html' | 'variables'> = {
  kind: 'message',
  html: true,
  variables: [
    { name: 'subscription', description: 'لینک', sample: 'https://sub.example.com/s/1', required: true },
    { name: 'plan', description: 'پلن', sample: 'طلایی', required: false },
  ],
}

describe('a wording', () => {
  it('is fine as the server would keep it', () => {
    expect(wordingProblem(MESSAGE, '  <b>%plan%</b>\n%subscription%  ')).toBeNull()
    expect(wordingProblem({ kind: 'part', html: true, variables: [] }, '')).toBeNull()
  })

  it('names what the server would refuse beside the HTML, in its words', () => {
    expect(wordingProblem(MESSAGE, '   ')).toBe('متن نمی‌تواند خالی باشد.')
    expect(wordingProblem(MESSAGE, '%subscription% %days%')).toBe('متغیر %days% در این متن وجود ندارد.')
    expect(wordingProblem({ kind: 'popup', html: false, variables: [] }, 'باشه %name%')).toBe('این متن متغیری ندارد؛ %name% را بردارید.')
    expect(wordingProblem(MESSAGE, 'لینک شما')).toBe('متغیر %subscription% باید در متن بماند.')
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, 'خرید\nسرویس')).toBe('متن دکمه باید یک خط باشد.')
    expect(wordingProblem({ kind: 'button', html: false, variables: [] }, '<b>خرید</b>')).toContain('قالب‌بندی ندارد')
    expect(wordingProblem(MESSAGE, '<b>%subscription%')).toContain('تگ <b> بسته نشده است')
  })
})
