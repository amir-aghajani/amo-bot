import { describe, expect, it } from 'vitest'
import { searchSettings, type SettingEntry } from '@/lib/settings-search'

/** The settings search's matching and ranking (lib/settings-search/search), on a small index of its own. */

function entry(id: string, title: string, extra: Partial<SettingEntry> = {}): SettingEntry {
  return { id: `setting:/test#${id}`, kind: 'setting', title, path: '/test', trail: ['تنظیمات ربات'], ...extra }
}

const INDEX: SettingEntry[] = [
  entry('wallet', 'کیف‌پول من', { trail: ['تنظیمات ربات', 'کیف‌پول'] }),
  entry('auto-renew', 'تمدید خودکار'),
  entry('renew-method', 'روش تمدید خودکار'),
  entry('reminder', 'یادآوری', { hint: 'چند روز پیش از تمدید خبر می‌دهد' }),
  entry('backup', 'Backup files', { keywords: ['بکاپ'] }),
  entry('manager', 'مُدیرِ سایت'),
  entry('card', 'شماره کارت'),
  entry('carts', 'شماره کارت‌ها'),
]

const ids = (query: string, options = {}) => searchSettings(INDEX, query, options).map((match) => match.entry.id.replace('setting:/test#', ''))

describe('searchSettings', () => {
  it('finds nothing for an empty query, or one of punctuation alone', () => {
    expect(searchSettings(INDEX, '')).toEqual([])
    expect(searchSettings(INDEX, '  ؟ ')).toEqual([])
  })

  it('finds every word of the query, or nothing (AND)', () => {
    expect(ids('تمدید خودکار')).toEqual(['auto-renew', 'renew-method'])
    expect(ids('تمدید کیف')).toEqual([])
  })

  it('takes the word being typed (the last) as a word’s start', () => {
    expect(ids('تمد')).toContain('auto-renew')
    expect(ids('تمدید خود')).toEqual(['auto-renew', 'renew-method'])
    expect(ids('backu')).toEqual(['backup'])
  })

  it('forgives a typo — one in a word of 4 letters or more, two from 8 — even in the word being typed', () => {
    expect(ids('تمدد خودکار')[0]).toBe('auto-renew')
    expect(ids('backpu')).toEqual(['backup'])
    expect(ids('bakcu')).toEqual(['backup'])
    expect(ids('یاداوریی')).toEqual(['reminder'])
    // Under four letters, a word is as typed: «کرت» is no «کارت».
    expect(ids('کرت')).toEqual([])
  })

  it('finds words across a half-space, typed with it, with a space or without either', () => {
    expect(ids('کیف‌پول')[0]).toBe('wallet')
    expect(ids('کیف پول')[0]).toBe('wallet')
    expect(ids('کیفپول')[0]).toBe('wallet')
  })

  it('finds a query typed on the other keyboard layout, below the same typed right', () => {
    expect(ids(';dt')[0]).toBe('wallet')
    const right = searchSettings(INDEX, 'کیف')[0]!
    const swapped = searchSettings(INDEX, ';dt')[0]!
    expect(swapped.score).toBeLessThan(right.score)
    expect(ids('ذشزنعح')).toEqual(['backup'])
  })

  it('ranks the title above a hint, an exact word above a typo, and the title that is the query first', () => {
    // «تمدید» is in the hint of «یادآوری», in the titles of the others.
    expect(ids('تمدید')).toEqual(['auto-renew', 'renew-method', 'reminder'])
    expect(ids('کارت')).toEqual(['card', 'carts'])
    const exact = searchSettings(INDEX, 'خودکار')[0]!
    const typo = searchSettings(INDEX, 'خودکاد')[0]!
    expect(exact.score).toBeGreaterThan(typo.score)
  })

  it('finds nothing by a typo in a hint alone', () => {
    // «تمدد» is one letter off «تمدید»: the titles have it, the reminder's hint alone has it too.
    expect(ids('تمدد')).toEqual(['auto-renew', 'renew-method'])
  })

  it('forgives a 4-letter word a missed letter, never another letter: «کرون» is no «کردن»', () => {
    const index = [entry('round', 'گرد کردن'), entry('cron', 'توکن Cron', { keywords: ['کرون'] })]
    expect(searchSettings(index, 'کرون').map((match) => match.entry.id.replace('setting:/test#', ''))).toEqual(['cron'])
    expect(ids('تمدد')).toContain('auto-renew')
  })

  it('ranks an exact keyword above a title found only by a typo', () => {
    const index = [entry('send', 'ارسال خودکار'), entry('schedule', 'زمان‌بندی', { keywords: ['خودکاد'] })]
    expect(searchSettings(index, 'خودکاد').map((match) => match.entry.id.replace('setting:/test#', ''))).toEqual(['schedule', 'send'])
  })

  it('finds an entry by its keywords', () => {
    expect(ids('بکاپ')).toEqual(['backup'])
  })

  it('lifts a favorite, and what was opened lately, above its equals', () => {
    expect(ids('تمدید خودکار', { favorites: ['setting:/test#renew-method'] })).toEqual(['auto-renew', 'renew-method'])
    expect(ids('شماره', {})).toEqual(['card', 'carts'])
    expect(ids('شماره', { favorites: ['setting:/test#carts'] })).toEqual(['carts', 'card'])
    expect(ids('شماره', { recent: ['setting:/test#carts'] })).toEqual(['carts', 'card'])
  })

  it('keeps to the limit', () => {
    expect(searchSettings(INDEX, 'تمدید', { limit: 1 })).toHaveLength(1)
  })

  it('marks what matched on the title and the trail as shown — half-spaces and diacritics included', () => {
    const [wallet] = searchSettings(INDEX, 'کیفپول')
    expect(wallet!.marks.title).toEqual([[0, 7]])
    expect(wallet!.marks.trail).toEqual([[], [[0, 7]]])

    expect(searchSettings(INDEX, 'پول')[0]!.marks.title).toEqual([[4, 7]])
    expect(searchSettings(INDEX, 'کیف پول')[0]!.marks.title).toEqual([
      [0, 3],
      [4, 7],
    ])
    // «مُدیرِ»: the word's six characters, harakat and all, for the four letters typed.
    expect(searchSettings(INDEX, 'مدیر')[0]!.marks.title).toEqual([[0, 6]])
    // The word being typed is marked as far as it was typed.
    expect(searchSettings(INDEX, 'خود')[0]!.marks.title).toEqual([[6, 9]])
  })
})
