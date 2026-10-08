import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  amountDraft,
  decimalInput,
  decimalOf,
  digitsOnly,
  formatAmount,
  formatBalance,
  formatBytes,
  formatCardNumber,
  formatChange,
  formatCompact,
  formatDate,
  formatDay,
  formatDays,
  formatDevices,
  formatDuration,
  formatGb,
  formatMoney,
  formatNumber,
  formatPercent,
  formatPricePerGb,
  formatSpan,
  initials,
  percentChange,
  setShopTimeZone,
  shownTimeZone,
  timeAgo,
  timeLeft,
  UNLIMITED,
  wholeOf,
  wholesOf,
} from '@/lib/format'

/** Every number and date the panels show is Persian: Persian digits and separators, Jalali dates, the bot's own words. */

/** The space the locale puts before a unit is its own (a no-break one): any space will do. */
const spaced = (text: string) => text.replace(/\s/g, ' ')

describe('numbers and money', () => {
  it('writes numbers with Persian digits and separators', () => {
    expect(formatNumber(1234567.5)).toBe('۱٬۲۳۴٬۵۶۷٫۵')
    expect(formatNumber('42')).toBe('۴۲')
  })

  it('shortens a chart axis figure', () => {
    expect(spaced(formatCompact(12_000))).toBe('۱۲ هزار')
    expect(spaced(formatCompact(1_500_000))).toBe('۱٫۵ میلیون')
  })

  it('writes a share and a signed change to one decimal', () => {
    expect(formatPercent(12.54)).toBe('۱۲٫۵٪')
    expect(formatChange(12.5)).toBe('+۱۲٫۵٪')
    expect(formatChange(-3)).toBe('−۳٪')
    expect(formatChange(0)).toBe('۰٪')
  })

  it('measures a change against the baseline, and has none without one', () => {
    expect(percentChange(150, 100)).toBe(50)
    expect(percentChange('50', '100')).toBe(-50)
    expect(percentChange(10, -20)).toBe(150)
    expect(percentChange(10, 0)).toBeNull()
  })

  it('writes money as whole Toman', () => {
    expect(formatAmount('120000.00')).toBe('۱۲۰٬۰۰۰')
    expect(formatMoney('120000.00')).toBe('۱۲۰٬۰۰۰ تومان')
    expect(formatMoney(0)).toBe('۰ تومان')
  })

  it('words a wallet below zero as a debt, as the bot does', () => {
    expect(formatBalance('80000.00')).toBe('۸۰٬۰۰۰ تومان')
    expect(formatBalance('-20000.00')).toBe('۲۰٬۰۰۰ تومان بدهی')
  })

  it('words an agency level price per gigabyte', () => {
    expect(formatPricePerGb('3000.00')).toBe('هر گیگابایت ۳٬۰۰۰ تومان')
  })

  it('hands an amount to a form as whole Toman in Latin digits', () => {
    expect(amountDraft('120000.00')).toBe('120000')
    expect(amountDraft('99.5')).toBe('100')
  })
})

describe('quotas and terms', () => {
  it('words 0 as unlimited for a term, devices and traffic', () => {
    expect(formatDays(0)).toBe(UNLIMITED)
    expect(formatDevices(0)).toBe(UNLIMITED)
    expect(formatGb(0)).toBe(UNLIMITED)
  })

  it('words a term, devices and a plan traffic', () => {
    expect(formatDays(30)).toBe('۳۰ روز')
    expect(formatDevices(2)).toBe('۲ دستگاه')
    expect(formatGb(1.5)).toBe('۱٫۵ گیگابایت')
  })

  it('writes traffic in its largest unit, one decimal under ten — a small amount as small as it is', () => {
    expect(formatBytes(512)).toBe('۵۱۲ بایت')
    expect(formatBytes(1536)).toBe('۱٫۵ کیلوبایت')
    expect(formatBytes(512 * 1024 ** 2)).toBe('۵۱۲ مگابایت')
    expect(formatBytes(1.5 * 1024 ** 3)).toBe('۱٫۵ گیگابایت')
    expect(formatBytes(10.4 * 1024 ** 3)).toBe('۱۰ گیگابایت')
    expect(formatBytes(5 * 1024 ** 5)).toBe('۵٬۱۲۰ ترابایت')
  })

  it('writes none — or below zero — in the unit traffic is counted in everywhere else', () => {
    expect(formatBytes(0)).toBe('۰ گیگابایت')
    expect(formatBytes(-1)).toBe('۰ گیگابایت')
  })

  it('words a span of minutes as the bot does', () => {
    expect(formatDuration(30)).toBe('۳۰ دقیقه')
    expect(formatDuration(120)).toBe('۲ ساعت')
    expect(formatDuration(90)).toBe('۱ ساعت و ۳۰ دقیقه')
    expect(formatDuration(1440)).toBe('۱ روز')
    expect(formatDuration(2880)).toBe('۲ روز')
    expect(formatDuration(1500)).toBe('۲۵ ساعت')
    expect(formatDuration(0)).toBe('۰ دقیقه')
    expect(formatDuration(-5)).toBe('۰ دقیقه')
    expect(formatDuration(29.6)).toBe('۳۰ دقیقه')
  })
})

describe('typed digits and card numbers', () => {
  it('keeps only the digits typed, Persian and Arabic ones as Latin', () => {
    expect(digitsOnly('۱۲۰٬۰۰۰ تومان')).toBe('120000')
    expect(digitsOnly('٣٤')).toBe('34')
    expect(digitsOnly('6037-۹۹۷۷')).toBe('60379977')
    expect(digitsOnly('abc')).toBe('')
  })

  it('groups a card number by four, as far as it goes', () => {
    expect(formatCardNumber('6037997700001119')).toBe('6037 9977 0000 1119')
    expect(formatCardNumber('603799')).toBe('6037 99')
    expect(formatCardNumber('6037')).toBe('6037')
  })
})

describe('dates and times', () => {
  const NOW = new Date('2026-09-17T15:00:00Z')

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('writes a moment as a Jalali date with Persian digits', () => {
    const shown = formatDate('2026-09-17T15:00:00Z')
    expect(shown).toContain('۲۶ شهریور ۱۴۰۵')
    expect(shown).toContain('۱۵:۰۰')
    expect(formatDate(NOW.getTime(), { dateStyle: 'medium' })).toBe('۲۶ شهریور ۱۴۰۵')
    expect(formatDate(NOW, { dateStyle: 'medium' })).toBe('۲۶ شهریور ۱۴۰۵')
  })

  it('writes a dash for a moment there is none of', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate(undefined)).toBe('—')
    expect(formatDate('')).toBe('—')
    expect(timeAgo(null)).toBe('—')
    expect(timeAgo('')).toBe('—')
  })

  it('writes a chart day as a short Jalali day', () => {
    expect(formatDay('2026-09-17')).toBe('۲۶ شهریور')
  })

  it('says how long ago, and «همین حالا» within the minute either way', () => {
    expect(timeAgo('2026-09-14T15:00:00Z')).toBe('۳ روز قبل')
    expect(timeAgo(NOW.getTime() - 30_000)).toBe('همین حالا')
    expect(timeAgo(new Date(NOW.getTime() + 30_000))).toBe('همین حالا')
  })

  it('says it in the largest unit, rounded — and ahead for a moment to come', () => {
    expect(timeAgo('2026-09-17T14:58:30Z')).toBe('۲ دقیقه قبل')
    expect(timeAgo('2026-09-17T09:20:00Z')).toBe('۶ ساعت قبل')
    expect(timeAgo('2026-08-08T15:00:00Z')).toBe('۱ ماه قبل')
    expect(timeAgo('2025-09-30T15:00:00Z')).toBe('۱ سال قبل')
    expect(timeAgo('2023-09-17T15:00:00Z')).toBe('۳ سال قبل')
    expect(timeAgo('2026-09-17T17:00:00+00:00')).toBe('در ۲ ساعت')
  })

  it('says how long until a deadline as the bot does', () => {
    expect(timeLeft('2026-09-27T15:00:00Z')).toBe('۱۰ روز مانده')
    expect(timeLeft('2026-09-18T19:00:00Z')).toBe('۲ روز مانده')
    expect(timeLeft('2026-09-18T15:00:00Z')).toBe('۱ روز مانده')
    expect(timeLeft('2026-09-17T20:00:00Z')).toBe('کمتر از یک روز مانده')
    expect(timeLeft('2026-09-17T15:00:00Z')).toBe('تمام شده')
    expect(timeLeft('2026-09-01T00:00:00Z')).toBe('تمام شده')
  })

  it('writes a span of seconds in its largest unit', () => {
    expect(formatSpan(3 * 86_400)).toBe('۳ روز')
    expect(formatSpan(5 * 3600 + 120)).toBe('۵ ساعت')
    expect(formatSpan(-10)).toBe('۰ ثانیه')
  })
})

describe("the shop's time zone", () => {
  afterEach(() => {
    setShopTimeZone(undefined)
  })

  it('writes a moment as the shop reads it, whatever zone the browser is in', () => {
    // The browser here is in UTC (vite.config.ts): 21:00 on 5 October there is 00:30 on the 6th in a shop in Tehran.
    expect(formatDate('2026-10-05T21:00:00Z')).toBe('۱۳ مهر ۱۴۰۵، ۲۱:۰۰')

    setShopTimeZone('Asia/Tehran')

    expect(formatDate('2026-10-05T21:00:00Z')).toBe('۱۴ مهر ۱۴۰۵، ۰:۳۰')
    expect(formatDate('2026-10-05T21:00:00Z', { dateStyle: 'medium' })).toBe('۱۴ مهر ۱۴۰۵')
  })

  it('keeps the browser’s own zone for one it does not know', () => {
    setShopTimeZone('Nowhere/Land')

    expect(formatDate('2026-10-05T21:00:00Z')).toBe('۱۳ مهر ۱۴۰۵، ۲۱:۰۰')
  })

  it('names the zone it reads in: the shop’s once known, the browser’s until then', () => {
    expect(shownTimeZone()).toEqual({ zone: 'UTC', shop: false })

    setShopTimeZone('Asia/Tehran')
    expect(shownTimeZone()).toEqual({ zone: 'Asia/Tehran', shop: true })
  })

  it('writes the dashboard’s days as the very days the shop counted, in any zone', () => {
    setShopTimeZone('Pacific/Kiritimati')

    expect(formatDay('2026-09-17')).toBe('۲۶ شهریور')
    expect(formatDay('2026-10-06', { weekday: 'long', day: 'numeric', month: 'long' })).toBe('سه‌شنبه ۱۴ مهر')
  })
})

describe('initials', () => {
  it('takes the first letters of the first two words', () => {
    expect(initials('sara ahmadi')).toBe('SA')
    expect(initials('  Shop   Owner  Name ')).toBe('SO')
    expect(initials('سارا احمدی')).toBe('سا')
    expect(initials('')).toBe('')
  })

  it('takes a handle’s first letter, not its «@» — an agent signs in as their bot', () => {
    expect(initials('@agent_shop_bot')).toBe('A')
    expect(initials(' @reza_shop_bot')).toBe('R')
  })
})

describe('a typed amount', () => {
  it('is read as the API takes a decimal, however a Persian keyboard typed it — the point kept a point', () => {
    expect(decimalOf('۱٫۵')).toBe('1.5')
    expect(decimalOf('1.5')).toBe('1.5')
    expect(decimalOf('۱/۵')).toBe('1.5')
    expect(decimalOf('٢٫٢٥')).toBe('2.25')
    expect(decimalOf(' ۵۰٬۰۰۰ ')).toBe('50000')
    expect(decimalOf('50,000.75')).toBe('50000.75')
    expect(decimalOf('120 000')).toBe('120000')
  })

  it('is no number at all rather than another one: three decimals, two points, words, a sign, nothing', () => {
    for (const typed of ['1.234', '۱٫۲٫۳', 'پنجاه', '50 گیگ', '-5', '٫۵', '۱٫', '']) expect(decimalOf(typed)).toBeNull()
  })

  it('takes thousands set apart only between groups of three, as the API does — a comma is never a point', () => {
    for (const typed of ['2,5', '۱٬۵', '12,34', '1,2345', '1 50']) expect(decimalOf(typed)).toBeNull()
    expect(decimalOf('1,234,567')).toBe('1234567')
  })

  it('goes in a request in the API’s form — or as typed, for the API to refuse in its own words', () => {
    expect(decimalInput('۱٫۵')).toBe('1.5')
    expect(decimalInput(' پنجاه ')).toBe('پنجاه')
  })
})

describe('a typed whole number, and a list of them', () => {
  it('is read as the API reads a setting’s: its thousands set apart between groups of three, nothing else', () => {
    expect([wholeOf('10,000'), wholeOf('۱۰٬۰۰۰'), wholeOf('10000')]).toEqual(['10000', '10000', '10000'])
    for (const typed of ['1,5000', '10.5', '10000 تومان', '']) expect(wholeOf(typed)).toBeNull()
  })

  it('is a list apart by spaces, «،» or a comma — but a comma between groups of three, which splits no amount', () => {
    expect(wholesOf('50,000, 100,000')).toEqual(['50000', '100000'])
    expect(wholesOf('50000,100000')).toEqual(['50000', '100000'])
    expect(wholesOf('۵۰٬۰۰۰، ۱۰۰٬۰۰۰')).toEqual(['50000', '100000'])
    expect(wholesOf('1,5')).toEqual(['1', '5'])
    expect(wholesOf('')).toEqual([])
    expect(wholesOf('50000, پنجاه')).toBeNull()
  })
})
