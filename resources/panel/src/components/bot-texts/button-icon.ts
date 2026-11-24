import { premiumEmojiTag } from '@/components/bot-texts/premium-emoji-picker'
import { isEmoji, leadingPremium } from '@/components/bot-texts/wording-problem'
import type { CustomEmojiRow } from '@/lib/api-types'

/**
 * The prepended marks (Grapheme_Cluster_Break=Prepend, Unicode 15 as PCRE2 10.44 has it): the Arabic number signs, the
 * end of an ayah and their like, which go with the character after them.
 */
const PREPENDED = /^[\u0600-\u0605\u06DD\u070F\u0890\u0891\u08E2\u0D4E\u{110BD}\u{110CD}\u{111C2}\u{111C3}\u{1193F}\u{11941}\u{11A3A}\u{11A84}-\u{11A89}\u{11D46}\u{11F02}]*/u
const PICTOGRAPH = /^\p{Extended_Pictographic}/u
/** A flag, or a regional indicator alone, after the marks prepended to it. */
const FLAG = new RegExp(`${PREPENDED.source}[\\u{1F1E6}-\\u{1F1FF}]{1,2}`, 'u')

/**
 * A text's characters as a reader sees them — an emoji with its modifiers and joiners, whole — split as the server's PCRE
 * splits them (`\X`, PCRE2 10.44 as PHP bundles it), which the browser's segmenter does otherwise in two places: a
 * prepended mark before an emoji is a character of its own, and a flag (or a regional indicator alone) ends where it
 * does, a variation selector, a joiner or a mark after it a character of its own.
 */
export function* graphemes(text: string): Generator<string> {
  for (const { segment } of new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)) {
    let rest = segment
    const prepended = PREPENDED.exec(rest)?.[0] ?? ''
    if (prepended !== '' && PICTOGRAPH.test(rest.slice(prepended.length))) {
      yield prepended
      rest = rest.slice(prepended.length)
    }
    const flag = FLAG.exec(rest)?.[0] ?? ''
    if (flag !== '' && flag.length < rest.length) {
      yield flag
      rest = rest.slice(flag.length)
    }
    yield rest
  }
}

/** A label's first character as a reader sees one (graphemes()): an emoji with its modifiers and joiners, whole. */
function firstGrapheme(text: string): string {
  for (const grapheme of graphemes(text)) return grapheme
  return ''
}

/** ASCII spaces at the start, as the server's ltrim() takes them. */
const LEADING_SPACES = /^[ \t]+/

/**
 * A button text's wording with `emoji` as its icon: its premium emoji at the start of the label (the bot sends it as the
 * button's icon, which Telegram draws before the label), in place of the icon it had — or of the plain emoji the label
 * started with: the icon is the button's emoji.
 */
export function withButtonIcon(value: string, emoji: CustomEmojiRow): string {
  const icon = leadingPremium(value)
  let label = (icon ? value.slice(icon.tag.length) : value).replace(LEADING_SPACES, '')
  if (!icon) {
    const first = firstGrapheme(label)
    if (first !== '' && isEmoji(first) && /^[ \t]/.test(label.slice(first.length))) label = label.slice(first.length).replace(LEADING_SPACES, '')
  }
  return `${premiumEmojiTag(emoji)} ${label}`
}

/**
 * What of a button text's wording is its icon — its leading premium emoji and the spaces after it —, kept out of the
 * label's field (the editor's «آیکون دکمه» shows it); '' without one.
 */
export function iconPrefix(value: string): string {
  const icon = leadingPremium(value)
  return icon ? icon.tag + (/^[ \t]*/.exec(value.slice(icon.tag.length))?.[0] ?? '') : ''
}

/** The emoji a name starts with — one as a reader sees it —, or null (PremiumText::leadingEmoji()). */
export function leadingEmoji(name: string): string | null {
  const first = firstGrapheme(name)
  return first !== '' && isEmoji(first) ? first : null
}

/** A name with `plain` as its leading emoji, in place of the emoji it starts with or before it, a space after it. */
export function withLeadingEmoji(name: string, plain: string): string {
  const leading = leadingEmoji(name)
  return `${plain} ${(leading === null ? name : name.slice(leading.length)).replace(LEADING_SPACES, '')}`
}

/** A button text's wording without its icon: the plain emoji it stands for back at the start of the label. */
export function withoutButtonIcon(value: string): string {
  const icon = leadingPremium(value)
  return icon ? icon.emoji + value.slice(icon.tag.length) : value
}
