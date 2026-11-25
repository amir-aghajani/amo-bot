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

/**
 * What of an emoji's code points makes it an emoji, not a sign counted among them (━ ★ ✓ ● ■ №): a pictograph past
 * U+1F000, or one of the few below it Unicode counts as emoji (its Emoji property: ❤ ☎ ⚠ ✅ ✔ ⭐ ©); its variation
 * selector and keycap mark looked for apart (EmojiMap::DRAWN).
 */
const DRAWN =
  /[\u{1F000}-\u{1FAFF}\u00A9\u00AE\u203C\u2049\u2122\u2139\u2194-\u2199\u21A9\u21AA\u231A\u231B\u2328\u23CF\u23E9-\u23F3\u23F8-\u23FA\u24C2\u25AA\u25AB\u25B6\u25C0\u25FB-\u25FE\u2600-\u2604\u260E\u2611\u2614\u2615\u2618\u261D\u2620\u2622\u2623\u2626\u262A\u262E\u262F\u2638-\u263A\u2640\u2642\u2648-\u2653\u265F\u2660\u2663\u2665\u2666\u2668\u267B\u267E\u267F\u2692-\u2697\u2699\u269B\u269C\u26A0\u26A1\u26A7\u26AA\u26AB\u26B0\u26B1\u26BD\u26BE\u26C4\u26C5\u26C8\u26CE\u26CF\u26D1\u26D3\u26D4\u26E9\u26EA\u26F0-\u26F5\u26F7-\u26FA\u26FD\u2702\u2705\u2708-\u270D\u270F\u2712\u2714\u2716\u271D\u2721\u2728\u2733\u2734\u2744\u2747\u274C\u274E\u2753-\u2755\u2757\u2763\u2764\u2795-\u2797\u27A1\u27B0\u27BF\u2934\u2935\u2B05-\u2B07\u2B1B\u2B1C\u2B50\u2B55\u3030\u303D\u3297\u3299]/u

/**
 * Whether an emoji (one isEmoji() takes) is drawn as one, not a sign counted among them by its code point alone — only
 * these are listed and newly mapped (EmojiMap::drawnAsEmoji()).
 */
export function drawnAsEmoji(emoji: string): boolean {
  return emoji.includes('\uFE0F') || emoji.includes('\u20E3') || DRAWN.test(emoji)
}

/** The zero-width non-joiners and joiners a grapheme ends in: a half-space typed after an emoji, no part of it. */
const TAIL = /(?:\u200C|\u200D)+$/u

/**
 * A grapheme and the half-spaces it ends in, apart — the segmenter takes them in, but they belong to the reading, kept
 * outside a tag; one that is only those stays whole (EmojiMap::untailed()).
 */
export function untailed(grapheme: string): [string, string] {
  const bare = grapheme.replace(TAIL, '')
  return bare === '' ? [grapheme, ''] : [bare, grapheme.slice(bare.length)]
}

const wrap = (emoji: string, id: string) => `<tg-emoji emoji-id="${id}">${emoji}</tg-emoji>`

/**
 * Each mapped emoji of a run of words drawn premium, one as the server reads one (graphemes()), kept as typed inside its
 * tag — a half-space after it outside.
 */
function wrapEmoji(words: string, map: EmojiLookup): string {
  let out = ''
  for (const grapheme of graphemes(words)) {
    const [emoji, tail] = untailed(grapheme)
    const id = map.get(emojiKey(emoji))
    out += id !== undefined && isEmoji(emoji) ? wrap(emoji, id) + tail : grapheme
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
