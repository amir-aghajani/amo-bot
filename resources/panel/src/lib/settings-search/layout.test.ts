import { describe, expect, it } from 'vitest'
import { swapLayout } from '@/lib/settings-search/layout'

/** A query typed on the other keyboard layout (lib/settings-search/layout): the standard Persian layout and QWERTY. */

describe('swapLayout', () => {
  it('reads Latin keys as the Persian letters they type', () => {
    expect(swapLayout(';dt')).toBe('کیف')
    expect(swapLayout('m,g')).toBe('پول')
    expect(swapLayout('sghl')).toBe('سلام')
    expect(swapLayout("'v,i")).toBe('گروه')
    expect(swapLayout('[]')).toBe('جچ')
  })

  it('reads Persian letters as the Latin keys that type them', () => {
    expect(swapLayout('سثففهدلس')).toBe('settings')
    expect(swapLayout('زقخد')).toBe('cron')
    expect(swapLayout('كيف')).toBe(';dt')
  })

  it('gives Shift its Persian letters, and leaves what no key types as it is', () => {
    expect(swapLayout('Hd')).toBe('آی')
    expect(swapLayout('Cg')).toBe('ژل')
    expect(swapLayout('123 ۴')).toBe('123 ۴')
  })

  it('is the whole standard layout, in Persian letters (ی and ک, not their Arabic forms)', () => {
    const keys = "qwertyuiop[]asdfghjkl;'zxcvbnm,"
    expect(swapLayout(keys)).toBe('ضصثقفغعهخحجچشسیبلاتنمکگظطزرذدپو')
    expect(swapLayout(swapLayout(keys))).toBe(keys)
    expect(swapLayout('d').codePointAt(0)).toBe(0x6cc)
    expect(swapLayout(';').codePointAt(0)).toBe(0x6a9)
  })
})
