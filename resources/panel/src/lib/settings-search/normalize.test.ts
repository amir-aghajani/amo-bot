import { describe, expect, it } from 'vitest'
import { normalize, normalizeMapped } from '@/lib/settings-search/normalize'

/** Text as the settings search compares it (lib/settings-search/normalize): one Persian, however it was typed. */

describe('normalize', () => {
  it('writes the Arabic forms of letters as Persian', () => {
    expect(normalize('كيف')).toBe('کیف')
    expect(normalize('ى ي ئ')).toBe('ی ی ی')
    expect(normalize('ة ۀ')).toBe('ه ه')
    expect(normalize('أ إ ٱ آ')).toBe('ا ا ا ا')
    expect(normalize('ؤ')).toBe('و')
    // آ is found by ا and by itself.
    expect(normalize('آپدیت')).toBe(normalize('اپدیت'))
  })

  it('drops harakat, tanwin, the superscript alef, tatweel and a lone hamza', () => {
    expect(normalize('مُدِیرٌ')).toBe('مدیر')
    expect(normalize('رحمٰن')).toBe('رحمن')
    expect(normalize('کـــیف')).toBe('کیف')
    expect(normalize('جزء')).toBe('جز')
  })

  it('reads a half-space as a space between words, whichever way the words were joined', () => {
    expect(normalize('کیف\u200cپول')).toBe('کیف پول')
    expect(normalize('کیف\u200dپول')).toBe('کیف پول')
    expect(normalize('کیف  پول')).toBe('کیف پول')
  })

  it('writes Persian and Arabic-Indic digits as Latin ones', () => {
    expect(normalize('۱۲۳۴۵۶۷۸۹۰')).toBe('1234567890')
    expect(normalize('٠١٢٣٤٥٦٧٨٩')).toBe('0123456789')
  })

  it('puts Latin in lower case without accents, and punctuation as a space', () => {
    expect(normalize('SMTP (Mail)')).toBe('smtp mail')
    expect(normalize('Café')).toBe('cafe')
    expect(normalize('«بک\u200cآپ»، بکاپ؛ x-ui/3x_ui: config.php')).toBe('بک اپ بکاپ x ui 3x ui config php')
    expect(normalize('  ؟  ')).toBe('')
  })

  it('remembers where each character came from, across half-spaces and dropped marks', () => {
    const { text, starts, ends } = normalizeMapped('کیف\u200cپُول')
    expect(text).toBe('کیف پول')
    // «پ» is the 5th character of the original, «و» the 7th (after the damma).
    expect(starts[4]).toBe(4)
    expect(starts[5]).toBe(6)
    expect(ends[6]).toBe(8)
    // The damma after «پ» is marked with it.
  })
})
