import { graphemes, leadingEmoji } from '@/components/bot-texts/button-icon'
import { isEmoji, telegramHtmlProblem, type HtmlTag } from '@/components/bot-texts/wording-problem'
import type { BotTextKind, PremiumTextsResponse } from '@/lib/api-types'

/*
 * «ایموجی پرمیوم متن‌ها» in the editor's preview: the shop's mapped plain emoji drawn premium, as the bot sends them — a
 * mirror of EmojiMap::apply() and EmojiMap::label(). The shop's own words only, before the variables are filled.
 */

/** The map while it is on: an emoji's key (emojiKey()) → its premium emoji's id. */
export type EmojiLookup = ReadonlyMap<string, string>

/** Where premium emoji never go: inside one already, code, a link's or a date's words (EmojiMap::SKIP). */
const SKIP = ['tg-emoji', 'code', 'pre', 'a', 'tg-time']

/** An emoji as the map compares it: without the variation selectors — ❤ and ❤️ are one heart (EmojiMap::key()). */
export function emojiKey(emoji: string): string {
  return emoji.replace(/[\u{FE0E}\u{FE0F}]/gu, '')
}

/** The saved map (GET /bot/premium-texts) while it is on; empty while off or not read yet (EmojiMap::lookup()). */
export function emojiLookup(answer: PremiumTextsResponse | undefined): EmojiLookup {
  if (!answer?.premium_texts.enabled) return new Map()
  return new Map(answer.premium_texts.items.map((item) => [emojiKey(item.emoji), item.emoji_id]))
}

const wrap = (emoji: string, id: string) => `<tg-emoji emoji-id="${id}">${emoji}</tg-emoji>`

/** Each mapped emoji of a run of words drawn premium, one as the server reads one (graphemes()), kept as typed inside its tag. */
function wrapEmoji(words: string, map: EmojiLookup): string {
  let out = ''
  for (const grapheme of graphemes(words)) {
    const id = map.get(emojiKey(grapheme))
    out += id !== undefined && isEmoji(grapheme) ? wrap(grapheme, id) : grapheme
  }
  return out
}

/**
 * Each run of words outside the tags SKIP names rewritten — tags, attributes and the words inside those as they are
 * (TelegramHtml::mapWords()). HTML Telegram would not take comes back as it is: nothing is rewritten in what cannot be
 * read for sure. The tags are where the problem check read them, as the server reads them for both.
 */
function mapWords(text: string, map: EmojiLookup): string {
  if (!text.includes('<')) return wrapEmoji(text, map)
  const tags: HtmlTag[] = []
  if (telegramHtmlProblem(text, tags) !== null) return text

  const open: string[] = []
  let out = ''
  let at = 0
  const words = (to: number) => {
    const run = text.slice(at, to)
    out += open.some((tag) => SKIP.includes(tag)) ? run : wrapEmoji(run, map)
  }
  for (const tag of tags) {
    words(tag.start)
    out += text.slice(tag.start, tag.end + 1)
    if (tag.closing) open.pop()
    else open.push(tag.name)
    at = tag.end + 1
  }
  words(text.length)
  return out
}

/**
 * A wording of the shop's own (its variables not filled yet) with its mapped plain emoji drawn premium, as the bot sends
 * it (EmojiMap::apply()): in a text Telegram reads as HTML, every one in its words outside a premium emoji, code, a link
 * or a date; a button's is mapLabel()'s, a popup draws none.
 */
export function mapWording(wording: string, kind: BotTextKind, map: EmojiLookup): string {
  return map.size === 0 || kind === 'button' || kind === 'popup' ? wording : mapWords(wording, map)
}

/** What PHP's trim() takes off: ASCII spaces (not the form feed) and NUL. */
const TRIMMED = /^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g

/**
 * A button's label, its variables filled, with the wording's leading emoji drawn premium — the button's icon then — when
 * the map has it (EmojiMap::label()); unless a premium emoji stands right after it (a name's own, the icon then), or no
 * words do.
 */
export function mapLabel(wording: string, label: string, map: EmojiLookup): string {
  const leading = leadingEmoji(wording)
  const id = leading === null ? undefined : map.get(emojiKey(leading))
  if (leading === null || id === undefined || !label.startsWith(leading)) return label
  const after = label.slice(leading.length).replace(/^ +/, '')
  if (after.startsWith('<tg-emoji') || after.replace(TRIMMED, '') === '') return label

  return wrap(leading, id) + label.slice(leading.length)
}
