import { premiumEmojiTag } from '@/components/bot-texts/premium-emoji-picker'
import { isEmoji, leadingPremium } from '@/components/bot-texts/wording-problem'
import type { CustomEmojiRow } from '@/lib/api-types'

/** A label's first character as a reader sees one: an emoji with its modifiers and joiners, whole. */
function firstGrapheme(text: string): string {
  for (const { segment } of new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)) return segment
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
