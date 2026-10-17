import type { BotTextKind, BotTextRow } from '@/lib/api-types'

/*
 * What the server would refuse in a bot text's wording, worked out as the admin types — a mirror of BotTexts::problem()
 * and TelegramHtml::problem() that uses the server's messages, so the editor warns before a save is refused. The server
 * stays the judge: this only warns, and leaves the length (which the editor counts) to the save.
 */

/** A `%name%` the bot fills in, the name captured (BotTexts::VARIABLE). */
const VARIABLE = /%([A-Za-z_][A-Za-z0-9_]*)%/g

/** A tag in a text Telegram shows as typed (a popup, a button). */
const ANY_TAG = /<\/?[a-z][a-z-]*[^>]*>/i

/** What PHP's trim() takes off a wording's ends: ASCII spaces (not the form feed) and NUL — a no-break space stays. */
const TRIMMED_START = /^[ \t\n\r\0\v]+/
const TRIMMED_END = /[ \t\n\r\0\v]+$/

/** A wording as the server keeps it (BotTexts::save()): newlines normalised and trimmed — a part only at its end, its leading breaks being its spacing. */
export function keptWording(kind: BotTextKind, input: string): string {
  const value = input.replace(/\r\n?/g, '\n').replace(TRIMMED_END, '')
  return kind === 'part' ? value : value.replace(TRIMMED_START, '')
}

/**
 * A tag as PHP's strip_tags() takes it out: from its «<» to its «>» (or the end), a «>» inside a quoted value not ending
 * it; a «<» before a space is the words'.
 */
const TAG = /<(?![ \t\n\r\v\f])(?:"[^"]*"|'[^']*'|[^'">])*>?/g

/** What the customer sees of an HTML wording (TelegramHtml::visible()): its tags out, its entities read as Telegram reads them. */
export function visibleText(html: string): string {
  return telegramDecode(html.replace(TAG, ''))
}

/**
 * What draws nothing (Text::blank()'s INVISIBLE, which the server's blank check reads by): a space of any kind, a line
 * break or other control, a zero-width or other format character, and the letters and marks made to look empty — the
 * Hangul fillers, the blank Braille pattern, the combining grapheme joiner, the Khmer inherent vowels.
 */
const INVISIBLE = /[\p{Z}\p{Cc}\p{Cf}\u{034F}\u{115F}\u{1160}\u{17B4}\u{17B5}\u{2800}\u{3164}\u{FFA0}]+/gu

/**
 * Whether a kept wording (keptWording()) is blank as Telegram reads it, which the server refuses as empty: nothing the
 * customer sees is left once what shows nothing is taken out — a no-break or zero-width space alone passes trim(). A
 * part may be blank: it then shows nothing in the text that takes it in.
 */
export function blankWording(text: Pick<BotTextRow, 'kind' | 'html'>, kept: string): boolean {
  return text.kind !== 'part' && (text.html ? visibleText(kept) : kept).replace(INVISIBLE, '') === ''
}

/** The server's refusal of a wording with nothing in it (BotTexts::EMPTY). */
const EMPTY = 'متن نمی‌تواند خالی باشد.'

/**
 * What the server would refuse in a wording, or null — the first of, in the server's order (the length aside): empty, a
 * button on two lines, an unknown or missing required variable, tags where there is no formatting or Telegram HTML that
 * Telegram would not take, then blank as Telegram reads it (blankWording()) — the HTML before it, so an unfinished tag,
 * which strip_tags() swallows, is told as what it is.
 */
export function wordingProblem(text: Pick<BotTextRow, 'kind' | 'html' | 'variables'>, input: string): string | null {
  const value = keptWording(text.kind, input)
  if (value === '' && text.kind !== 'part') return EMPTY
  if (text.kind === 'button' && value.includes('\n')) return 'متن دکمه باید یک خط باشد.'

  const used = [...value.matchAll(VARIABLE)].map((match) => match[1] ?? '')
  for (const name of new Set(used)) {
    if (!text.variables.some((variable) => variable.name === name)) {
      return text.variables.length === 0 ? `این متن متغیری ندارد؛ %${name}% را بردارید.` : `متغیر %${name}% در این متن وجود ندارد.`
    }
  }
  const missing = text.variables.find((variable) => variable.required && !used.includes(variable.name))
  if (missing) return `متغیر %${missing.name}% باید در متن بماند.`

  const markup = text.html ? telegramHtmlProblem(value) : ANY_TAG.test(value) ? 'این متن قالب‌بندی ندارد و تگ‌ها همان‌طور که نوشته شده‌اند نشان داده می‌شوند؛ آن‌ها را بردارید.' : null
  if (markup !== null) return markup

  return blankWording(text, value) ? EMPTY : null
}

/** The tags Telegram reads; `span` only as a spoiler. */
const TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'tg-spoiler', 'span', 'a', 'code', 'pre', 'blockquote', 'tg-emoji', 'tg-time']

/** The attributes Telegram reads of a tag, by these names alone: written in capitals, it passes them by (TelegramHtml::ATTRIBUTES). */
const ATTRIBUTES: Partial<Record<string, string[]>> = {
  a: ['href'],
  span: ['class'],
  code: ['class'],
  'tg-emoji': ['emoji-id'],
  blockquote: ['expandable'],
  'tg-time': ['unix', 'format'],
}

/** What holds no other entity but formatting, as the refusal names it, and what may not go inside one (TelegramHtml::WHOLE). */
const WHOLE: Record<string, string> = { a: 'لینک (<a>)', 'tg-time': 'زمان (<tg-time>)' }
const NOT_IN_WHOLE = ['a', 'code', 'pre', 'blockquote', 'tg-emoji', 'tg-time']

/** A date and time's `format` Telegram reads: «r» alone, or «w», «d»/«D», «t»/«T» in that order — or none (TelegramHtml::TIME_FORMAT). */
const TIME_FORMAT = /^(?:r|w?[dD]?[tT]?)$/

/** The latest Unix time Telegram's dates hold (a signed 32-bit number). */
const TIME_MOST = 2_147_483_647

/** The named entities Telegram reads, by the character each stands for; any other it shows as typed. */
const NAMED: Partial<Record<string, number>> = { lt: 0x3c, gt: 0x3e, amp: 0x26, quot: 0x22 }

/** An entity as Telegram reads one from its «&»: a number (a lowercase x before hex digits) or letters, «;» optional. */
const ENTITY = '&(?:#x[0-9a-fA-F]*|#[0-9]*|[A-Za-z]*);?'

/** What an admin means as an entity: a number, or a name closed by «;» — unlike the «&» of «R&D». */
const LOOKS_LIKE_ENTITY = /&(?:#[xX]?[0-9a-fA-F]+;?|[A-Za-z][A-Za-z0-9]*;)/y

const ALLOWED = 'b، i، u، s، code، pre، a، blockquote، tg-spoiler، tg-emoji و tg-time'
const STRAY = 'نماد < فقط برای شروع تگ است؛ برای نوشتن خودش &lt; بنویسید.'
const EMOJI_ID = 'تگ <tg-emoji> باید شناسه عددی ایموجی پرمیوم را داشته باشد؛ مثلا <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>.'
const EMOJI_CONTENT = 'داخل <tg-emoji> فقط یک ایموجی معمولی می‌آید — همانی که جاهایی که ایموجی پرمیوم نشان داده نمی‌شود دیده می‌شود.'
const TIME_UNIX = 'تگ <tg-time> باید زمان را به ثانیه‌های یونیکس در unix داشته باشد؛ مثلا <tg-time unix="1767225600" format="D">۱۱ دی ۱۴۰۴</tg-time>.'
const TIME_FORMAT_REFUSED = 'مقدار format در تگ <tg-time> یا r است، یا از w، سپس d یا D، سپس t یا T ساخته می‌شود (مثلا wDT)؛ یا format را ننویسید تا متن همان‌طور که هست نشان داده شود.'
const TIME_FORMAT_BARE = 'مقدار format در تگ <tg-time> را داخل "…" بنویسید؛ بدون آن تلگرام حروف بزرگ آن را کوچک می‌خواند.'
const TIME_CONTENT = 'داخل <tg-time> متنی بنویسید؛ همان است که جاهایی که زمان نشان داده نمی‌شود دیده می‌شود.'

/** PHP's ctype_space: the ASCII spaces. */
const isSpace = (char: string | undefined): boolean => char !== undefined && ' \t\n\r\v\f'.includes(char)

/** A bare attribute value's characters: ASCII letters and digits, «.» and «-». */
const isBare = (char: string | undefined): boolean => char !== undefined && /^[A-Za-z0-9.-]$/.test(char)

/** Half of a character written as a number: Telegram refuses a message with one. */
const isSurrogate = (code: number): boolean => code >= 0xd800 && code <= 0xdfff

/**
 * The character an entity (ENTITY) stands for as Telegram reads it (TelegramHtml::codePoint()) — one of NAMED, or a
 * number: decimal, or hex after a lowercase x —; null where it reads none and shows it as typed: an unknown name, no
 * digits, zero, past the last character, or too long to be one.
 */
function codePoint(entity: string): number | null {
  if (!entity.startsWith('&#')) return NAMED[entity.replace(/^&+|;+$/g, '')] ?? null
  const number = entity.replace(/;+$/, '')
  const hex = number[2] === 'x'
  const digits = number.slice(hex ? 3 : 2)
  if (digits === '' || number.length >= 10) return null
  const code = Number.parseInt(digits, hex ? 16 : 10)
  return code === 0 || code >= 0x10ffff ? null : code
}

/**
 * The text with the entities Telegram reads decoded — its four names and numbers (TelegramHtml::decode()); any other «&»
 * stays as typed, as Telegram shows it (`&nbsp;`, `&#X41;`), and so does half a character.
 */
export function telegramDecode(text: string): string {
  return text.replace(new RegExp(ENTITY, 'g'), (entity) => {
    const code = codePoint(entity)
    return code === null || isSurrogate(code) ? entity : String.fromCodePoint(code)
  })
}

/**
 * The «&» at `at` as Telegram reads it (TelegramHtml::entity()): an entity it reads — its length, to step over —, a «&» of
 * the words (1) — or what is wrong: a number that is half a character (Telegram refuses the whole message), or what an
 * admin meant as an entity and Telegram shows as typed (`&nbsp;`, `&#X41;`).
 */
function entityAt(text: string, at: number): { length: number } | { problem: string } {
  const read = new RegExp(ENTITY, 'y')
  read.lastIndex = at
  const entity = read.exec(text)?.[0] ?? '&'
  const code = codePoint(entity)
  if (code !== null && !isSurrogate(code)) return { length: entity.length }
  if (code !== null) return { problem: `«${entity}» نیمی از یک نویسه است و تلگرام پیامی با آن را نمی‌پذیرد؛ خود نویسه را بنویسید، یا عدد کامل آن را مثل &#128512;.` }
  LOOKS_LIKE_ENTITY.lastIndex = at
  const meant = LOOKS_LIKE_ENTITY.exec(text)?.[0]
  if (meant !== undefined) {
    return {
      problem: `تلگرام «${meant}» را نمی‌شناسد و همان‌طور که نوشته شده نشان می‌دهد؛ فقط &lt; و &gt; و &amp; و &quot; و نمادهای عددی مثل &#128512; یا &#x1F600; خوانده می‌شوند. برای خود نماد & بنویسید &amp;.`,
    }
  }
  return { length: 1 }
}

/**
 * The first thing Telegram would not take in an HTML text, or null — mirroring TelegramHtml::problem(), which reads it
 * as Telegram's parser does (tag names in any case, attribute names exactly as written): Bot API tags only,
 * `name="value"` attributes, tags closed in order, no stray «<», entities Telegram reads, nothing Telegram would silently
 * drop, and a premium emoji as a numeric id around one plain emoji.
 */
function telegramHtmlProblem(text: string): string | null {
  const open: string[] = []
  let i = 0

  /** The tag name from `i`: up to a space or «>» (`i` ends there). */
  const word = (): string => {
    const start = i
    while (i < text.length && !isSpace(text[i]) && text[i] !== '>') i++
    return text.slice(start, i)
  }
  const skipSpaces = () => {
    while (i < text.length && isSpace(text[i])) i++
  }

  /** The attributes up to the tag's «>» (`i` ends on it), or what is wrong with them. */
  const attributes = (tag: string): Map<string, string> | string => {
    const found = new Map<string, string>()
    const unclosed = `تگ <${tag}> با > بسته نشده است.`
    for (;;) {
      skipSpaces()
      const char = text[i]
      if (char === undefined) return unclosed
      if (char === '>') return found

      const start = i
      while (i < text.length && !isSpace(text[i]) && text[i] !== '=' && text[i] !== '>') i++
      // As written: Telegram reads `href`, not `HREF`.
      const name = text.slice(start, i)
      if (name === '') return `در تگ <${tag}> پیش از = نام ویژگی نیامده است.`
      const read = name.toLowerCase()
      if (name !== read && (ATTRIBUTES[tag] ?? []).includes(read)) {
        return `در تگ <${tag}> نام «${name}» را با حروف کوچک بنویسید: ${read}؛ تلگرام آن را با حروف بزرگ نمی‌خواند.`
      }
      skipSpaces()
      const next = text[i]
      if (next === undefined) return unclosed
      if (next !== '=') {
        if (tag === 'blockquote' && name === 'expandable') continue
        return `در تگ <${tag}>، بعد از «${name}» باید = و مقدار بیاید؛ مثلا href="…".`
      }
      i++
      skipSpaces()

      const quote = text[i]
      if (quote === '"' || quote === "'") {
        const end = text.indexOf(quote, i + 1)
        if (end === -1) return `مقدار ${name} در تگ <${tag}> با ${quote} بسته نشده است.`
        found.set(name, telegramDecode(text.slice(i + 1, end)))
        i = end + 1
        continue
      }

      const valueStart = i
      while (i < text.length && isBare(text[i])) i++
      const after = text[i]
      if (after === undefined) return unclosed
      if (!isSpace(after) && after !== '>') return `مقدار ${name} در تگ <${tag}> را داخل "…" بنویسید.`
      // A bare value is read in lower case: a date's format would mean another (D, a long date, read as d).
      const value = text.slice(valueStart, i)
      if (tag === 'tg-time' && name === 'format' && value !== value.toLowerCase()) return TIME_FORMAT_BARE
      found.set(name, value.toLowerCase())
    }
  }

  for (; i < text.length; i++) {
    if (text[i] === '&') {
      const entity = entityAt(text, i)
      if ('problem' in entity) return entity.problem
      i += entity.length - 1
      continue
    }
    if (text[i] !== '<') continue

    if (text[i + 1] === '/') {
      i += 2
      const name = word().toLowerCase()
      skipSpaces()
      if (text[i] !== '>') return `تگ </${name}> با > بسته نشده است.`
      const current = open.pop()
      if (current === undefined) return `تگ </${name}> بسته شده ولی پیش از آن باز نشده است.`
      if (name !== '' && name !== current) {
        return open.includes(name) ? `پیش از </${name}> باید </${current}> بسته شود.` : `تگ </${name}> بسته شده ولی پیش از آن باز نشده است.`
      }
      continue
    }

    i++
    const name = word().toLowerCase()
    if (!/^[a-z]/.test(name)) return STRAY
    if (!TAGS.includes(name)) {
      return name === 'br' || name === 'br/' ? 'تلگرام تگ <br> ندارد؛ برای رفتن به خط بعد فقط Enter بزنید.' : `تلگرام تگ <${name}> را نمی‌شناسد؛ تگ‌های مجاز: ${ALLOWED}.`
    }

    const found = attributes(name)
    if (typeof found === 'string') return found
    if (name === 'span' && found.get('class') !== 'tg-spoiler') return 'تگ <span> فقط به صورت <span class="tg-spoiler"> پذیرفته می‌شود.'
    const content = name === 'tg-emoji' ? premiumEmoji(text, i, found) : name === 'tg-time' ? time(text, i, found) : null
    if (content !== null) return content

    const problem = nesting(name, open)
    if (problem !== null) return problem
    open.push(name)
  }

  const last = open.at(-1)
  return last === undefined ? null : `تگ <${last}> بسته نشده است؛ آخر آن </${last}> بگذارید.`
}

/**
 * What Telegram would drop: formatting inside <code>/<pre> (a <code> right inside a <pre> is its language block); a link,
 * code, a quote, a premium emoji or a date inside a link or a date (WHOLE); a quote inside a quote.
 */
function nesting(tag: string, open: string[]): string | null {
  for (const verbatim of ['code', 'pre']) {
    if (open.includes(verbatim) && !(tag === 'code' && open.at(-1) === 'pre')) {
      return `داخل <${verbatim}> تگ دیگری نمی‌شود گذاشت؛ متن آن همان‌طور که هست نشان داده می‌شود.`
    }
  }
  for (const [whole, words] of Object.entries(WHOLE)) {
    if (open.includes(whole) && NOT_IN_WHOLE.includes(tag)) return `داخل ${words} تگ <${tag}> نمی‌شود گذاشت.`
  }
  if (tag === 'blockquote' && open.includes('blockquote')) return 'نقل‌قول (<blockquote>) داخل نقل‌قول دیگر نمی‌شود.'
  return null
}

/**
 * A date and time's tag, whose «>» is at `end` (TelegramHtml::time()): a Unix time in `unix`, a `format` Telegram reads,
 * and words up to its </tg-time> — what the customer sees where the time cannot be shown.
 */
function time(text: string, end: number, found: Map<string, string>): string | null {
  const unix = found.get('unix') ?? ''
  if (!/^[0-9]{1,10}$/.test(unix) || Number(unix) < 1 || Number(unix) > TIME_MOST) return TIME_UNIX
  if (!TIME_FORMAT.test(found.get('format') ?? '')) return TIME_FORMAT_REFUSED
  const rest = text.slice(end + 1)
  // Spaces as the server's pattern reads them, ASCII only.
  const close = /<\/tg-time[ \t\n\r\v\f]*>/i.exec(rest)
  // No end tag: told at the end, like any tag left open.
  if (close === null) return null

  return trimmed(visibleText(rest.slice(0, close.index))) === '' ? TIME_CONTENT : null
}

/** PHP's trim(): ASCII spaces (not the form feed) and NUL off both ends. */
const trimmed = (text: string): string => text.replace(TRIMMED_START, '').replace(TRIMMED_END, '')

/** A premium emoji's tag, whose «>» is at `end`: a numeric id, and one plain emoji up to its </tg-emoji>. */
function premiumEmoji(text: string, end: number, found: Map<string, string>): string | null {
  if (!/^\d{1,20}$/.test(found.get('emoji-id') ?? '')) return EMOJI_ID
  const rest = text.slice(end + 1)
  // Spaces as the server's pattern reads them, ASCII only (a no-break space is not one).
  const close = /<\/tg-emoji[ \t\n\r\v\f]*>/i.exec(rest)
  // No end tag: told at the end, like any tag left open.
  if (close === null) return null

  return isEmoji(telegramDecode(rest.slice(0, close.index))) ? null : EMOJI_CONTENT
}

/** One emoji — a pictograph with its modifiers and joiners, a flag, a keycap — rather than text. */
function isEmoji(text: string): boolean {
  if (text === '' || [...text].length > 16 || /[\s\p{L}<>&]/u.test(text)) return false
  // A digit, a sign or a bare arrow is text; a keycap (1️⃣) and an emoji arrow (↔️) carry their marks.
  if (/^[\p{N}\p{P}\p{Sm}]+$/u.test(text)) return false
  // The keycap's mark (U+20E3) combines with what is before it: looked for on its own, not inside a class.
  return /[©®‼-㊙\u{1F000}-\u{1FAFF}]/u.test(text) || text.includes('⃣')
}
