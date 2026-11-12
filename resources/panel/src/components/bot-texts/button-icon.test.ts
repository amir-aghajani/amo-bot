import { describe, expect, it } from 'vitest'
import { iconPrefix, leadingEmoji, withButtonIcon, withLeadingEmoji, withoutButtonIcon } from '@/components/bot-texts/button-icon'
import type { CustomEmojiRow } from '@/lib/api-types'

/*
 * A button text's icon (components/bot-texts/button-icon): its premium emoji at the start of the wording, as the bot sends
 * it — in place of the icon it had or of the label's own plain emoji —, and taken off, that plain emoji back.
 */

const emoji = (id: string, plain: string): CustomEmojiRow => ({ id, emoji: plain, format: 'static', repaint: false, updated_at: '2026-10-06T09:00:00Z' })
const tag = (id: string, plain: string) => `<tg-emoji emoji-id="${id}">${plain}</tg-emoji>`

describe('a button text’s icon', () => {
  it('takes the place of the label’s own emoji — a joined or toned one whole —, or goes before a label without one', () => {
    expect(withButtonIcon('❌ انصراف', emoji('41', '🚫'))).toBe(`${tag('41', '🚫')} انصراف`)
    expect(withButtonIcon('⬅️ بازگشت', emoji('41', '⬅️'))).toBe(`${tag('41', '⬅️')} بازگشت`)
    expect(withButtonIcon('👨‍💻 پشتیبانی', emoji('41', '💬'))).toBe(`${tag('41', '💬')} پشتیبانی`)
    expect(withButtonIcon('بعدی', emoji('41', '➡️'))).toBe(`${tag('41', '➡️')} بعدی`)
    expect(withButtonIcon('%rating% ⭐', emoji('41', '⭐'))).toBe(`${tag('41', '⭐')} %rating% ⭐`)
    expect(withButtonIcon('🔥خرید', emoji('41', '🔥')), 'an emoji run into the words is part of them').toBe(`${tag('41', '🔥')} 🔥خرید`)
  })

  it('takes the place of the icon it had', () => {
    expect(withButtonIcon(`${tag('41', '🚫')} انصراف`, emoji('42', '❌'))).toBe(`${tag('42', '❌')} انصراف`)
  })

  it('stays out of the label’s field, with the spaces after it', () => {
    expect(iconPrefix(`${tag('41', '🚫')}  انصراف`)).toBe(`${tag('41', '🚫')}  `)
    expect(iconPrefix(tag('41', '🚫'))).toBe(tag('41', '🚫'))
    expect(iconPrefix(`انصراف ${tag('41', '🚫')}`)).toBe('')
  })

  it('taken off, leaves its plain emoji where it was', () => {
    expect(withoutButtonIcon(`${tag('41', '🚫')} انصراف`)).toBe('🚫 انصراف')
    expect(withoutButtonIcon('❌ انصراف')).toBe('❌ انصراف')
  })
})

describe('a name’s leading emoji', () => {
  it('is one as a reader sees it, as the server reads it', () => {
    expect(leadingEmoji('🔥 طلایی')).toBe('🔥')
    expect(leadingEmoji('🛍️ویژه')).toBe('🛍️')
    expect(leadingEmoji('🇩🇪 آلمان')).toBe('🇩🇪')
    expect(leadingEmoji('طلایی 🔥')).toBeNull()
    expect(leadingEmoji('1 ماهه')).toBeNull()
  })

  it('takes the picked emoji’s place, run into the words or not, or goes before a name without one', () => {
    expect(withLeadingEmoji('⭐ طلایی', '🔥')).toBe('🔥 طلایی')
    expect(withLeadingEmoji('⭐طلایی', '🔥')).toBe('🔥 طلایی')
    expect(withLeadingEmoji('طلایی', '🔥')).toBe('🔥 طلایی')
  })
})
