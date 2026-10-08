import type { BotTextKind, BotTextRow } from '@/lib/api-types'

/*
 * What the server would refuse in a bot text's wording, in its own words, worked out as the admin types — a mirror of
 * BotTexts::problem() and TelegramHtml::problem(), so the editor warns before a save is refused. The server stays the
 * judge: this only warns, and what it does not know (the length, which the editor counts) it leaves to the save.
 */

/** A `%name%` the bot fills in, the name captured (BotTexts::VARIABLE). */
const VARIABLE = /%([A-Za-z_][A-Za-z0-9_]*)%/g

/** A tag in a text Telegram shows as typed (a popup, a button). */
const ANY_TAG = /<\/?[a-z][a-z-]*[^>]*>/i

/**
 * A wording as the server keeps it (BotTexts::save()): its line breaks a newline each, trimmed — a part only on its end,
 * its leading line breaks being its spacing in the text that takes it in.
 */
export function keptWording(kind: BotTextKind, input: string): string {
  const value = input.replace(/\r\n?/g, '\n')
  return kind === 'part' ? value.trimEnd() : value.trim()
}

/**
 * What is wrong with the wording of `text` as the server would say it, or null: empty, a button on two lines, a variable
 * the text does not have or a required one missing, tags where there is no formatting — and, in Telegram HTML, what
 * Telegram would not take (telegramHtmlProblem()).
 */
export function wordingProblem(text: Pick<BotTextRow, 'kind' | 'html' | 'variables'>, input: string): string | null {
  const value = keptWording(text.kind, input)
  if (value === '' && text.kind !== 'part') return 'متن نمی‌تواند خالی باشد.'
  if (text.kind === 'button' && value.includes('\n')) return 'متن دکمه باید یک خط باشد.'

  const used = [...value.matchAll(VARIABLE)].map((match) => match[1] ?? '')
  for (const name of new Set(used)) {
    if (!text.variables.some((variable) => variable.name === name)) {
      return text.variables.length === 0 ? `این متن متغیری ندارد؛ %${name}% را بردارید.` : `متغیر %${name}% در این متن وجود ندارد.`
    }
  }
  const missing = text.variables.find((variable) => variable.required && !used.includes(variable.name))
  if (missing) return `متغیر %${missing.name}% باید در متن بماند.`

  if (!text.html) {
    return ANY_TAG.test(value) ? 'این متن قالب‌بندی ندارد و تگ‌ها همان‌طور که نوشته شده‌اند نشان داده می‌شوند؛ آن‌ها را بردارید.' : null
  }

  return telegramHtmlProblem(value)
}

/** The tags Telegram reads; `span` only as a spoiler. */
const TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'tg-spoiler', 'span', 'a', 'code', 'pre', 'blockquote', 'tg-emoji']

const ALLOWED = 'b، i، u، s، code، pre، a، blockquote، tg-spoiler و tg-emoji'
const STRAY = 'نماد < فقط برای شروع تگ است؛ برای نوشتن خودش &lt; بنویسید.'
const EMOJI_ID = 'تگ <tg-emoji> باید شناسه عددی ایموجی پرمیوم را داشته باشد؛ مثلا <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>.'
const EMOJI_CONTENT = 'داخل <tg-emoji> فقط یک ایموجی معمولی می‌آید — همانی که جاهایی که ایموجی پرمیوم نشان داده نمی‌شود دیده می‌شود.'

/** PHP's ctype_space: the ASCII spaces. */
const isSpace = (char: string | undefined): boolean => char !== undefined && ' \t\n\r\v\f'.includes(char)

/** A bare attribute value's characters: ASCII letters and digits, «.» and «-». */
const isBare = (char: string | undefined): boolean => char !== undefined && /^[A-Za-z0-9.-]$/.test(char)

/** An attribute's value with its entities read, as the server reads it (html_entity_decode). */
function decoded(value: string): string {
  return value.includes('&') ? (new DOMParser().parseFromString(`<body>${value}`, 'text/html').body.textContent ?? value) : value
}

/**
 * The first thing Telegram would not take in an HTML text, in the admin's words (TelegramHtml::problem(), read the same
 * way: the Bot API's tags only, attributes as `name="value"`, every tag closed in order, a «<» that opens no tag refused,
 * nothing Telegram would drop without a word — formatting inside <code>/<pre>, a link in a link, a quote in a quote —,
 * and a premium emoji as its numeric id with one plain emoji inside); null when it is fine.
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
      const name = text.slice(start, i).toLowerCase()
      if (name === '') return `در تگ <${tag}> پیش از = نام ویژگی نیامده است.`
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
        found.set(name, decoded(text.slice(i + 1, end)))
        i = end + 1
        continue
      }

      const valueStart = i
      while (i < text.length && isBare(text[i])) i++
      const after = text[i]
      if (after === undefined) return unclosed
      if (!isSpace(after) && after !== '>') return `مقدار ${name} در تگ <${tag}> را داخل "…" بنویسید.`
      found.set(name, text.slice(valueStart, i).toLowerCase())
    }
  }

  for (; i < text.length; i++) {
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
    if (name === 'tg-emoji') {
      const problem = premiumEmoji(text, i, found)
      if (problem !== null) return problem
    }

    const problem = nesting(name, open)
    if (problem !== null) return problem
    open.push(name)
  }

  const last = open.at(-1)
  return last === undefined ? null : `تگ <${last}> بسته نشده است؛ آخر آن </${last}> بگذارید.`
}

/**
 * What Telegram would drop: formatting inside <code>/<pre> (a <code> right inside a <pre> is its language block), a link
 * or <code> inside a link, a quote inside a quote or a link.
 */
function nesting(tag: string, open: string[]): string | null {
  for (const verbatim of ['code', 'pre']) {
    if (open.includes(verbatim) && !(tag === 'code' && open.at(-1) === 'pre')) {
      return `داخل <${verbatim}> تگ دیگری نمی‌شود گذاشت؛ متن آن همان‌طور که هست نشان داده می‌شود.`
    }
  }
  if (open.includes('a') && ['a', 'code', 'pre', 'blockquote'].includes(tag)) return `داخل لینک (<a>) تگ <${tag}> نمی‌شود گذاشت.`
  if (tag === 'blockquote' && open.includes('blockquote')) return 'نقل‌قول (<blockquote>) داخل نقل‌قول دیگر نمی‌شود.'
  if (tag === 'tg-emoji' && open.includes('a')) return 'ایموجی پرمیوم (<tg-emoji>) داخل لینک نمی‌شود.'
  return null
}

/** A premium emoji's tag, whose «>» is at `end`: a numeric id, and one plain emoji up to its </tg-emoji>. */
function premiumEmoji(text: string, end: number, found: Map<string, string>): string | null {
  if (!/^\d{1,20}$/.test(found.get('emoji-id') ?? '')) return EMOJI_ID
  const rest = text.slice(end + 1)
  const close = /<\/tg-emoji\s*>/i.exec(rest)
  // No end tag: told at the end, like any tag left open.
  if (close === null) return null

  return isEmoji(decoded(rest.slice(0, close.index))) ? null : EMOJI_CONTENT
}

/** One emoji — a pictograph with its modifiers and joiners, a flag, a keycap — rather than text. */
function isEmoji(text: string): boolean {
  if (text === '' || [...text].length > 16 || /[\s\p{L}<>&]/u.test(text)) return false
  // A digit, a sign or a bare arrow is text; a keycap (1️⃣) and an emoji arrow (↔️) carry their marks.
  if (/^[\p{N}\p{P}\p{Sm}]+$/u.test(text)) return false
  // The keycap's mark (U+20E3) combines with what is before it: looked for on its own, not inside a class.
  return /[©®‼-㊙\u{1F000}-\u{1FAFF}]/u.test(text) || text.includes('⃣')
}
