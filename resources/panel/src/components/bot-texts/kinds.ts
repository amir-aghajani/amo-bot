import type { BotTextKind } from '@/lib/api-types'

/** What each kind of text is called on the screen. */
export const KIND_LABELS: Record<BotTextKind, string> = {
  message: 'پیام',
  caption: 'کپشن عکس',
  popup: 'اعلان',
  button: 'دکمه',
  part: 'بخشی از پیام',
}
