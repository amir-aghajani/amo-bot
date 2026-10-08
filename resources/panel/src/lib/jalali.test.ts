import { afterEach, describe, expect, it, vi } from 'vitest'
import { setShopTimeZone } from '@/lib/format'
import { addDays, fromJalali, isDay, monthLength, monthRange, shiftMonth, today, toJalali, weekday } from '@/lib/jalali'

/*
 * The Persian calendar the date filters pick days in (lib/jalali), on Intl's own: a Gregorian day and its Jalali date
 * each way, the months' lengths (اسفند's leap day included), the week from Saturday, the API's day shape — and today,
 * the shop's day whatever the browser's clock says.
 */

afterEach(() => {
  setShopTimeZone(undefined)
})

describe('today', () => {
  it('is the shop’s day: just after Tehran’s midnight, while the browser’s UTC is still the day before', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T20:35:00Z'))
    expect(today()).toBe('2026-10-06')

    setShopTimeZone('Asia/Tehran')
    expect(today()).toBe('2026-10-07')
    expect(toJalali(today())).toEqual({ year: 1405, month: 7, day: 15 })
  })

  it('is the shop’s day west of the browser too, and the browser’s own until the shop’s zone is known', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-07T02:00:00Z'))

    setShopTimeZone('America/Los_Angeles')
    expect(today()).toBe('2026-10-06')
    setShopTimeZone('Not/A_Zone')
    expect(today()).toBe('2026-10-07')
  })
})

describe('a day in the Persian calendar', () => {
  it('is the date Iran reads, each way', () => {
    expect(toJalali('2026-10-06')).toEqual({ year: 1405, month: 7, day: 14 })
    expect(toJalali('2025-03-21')).toEqual({ year: 1404, month: 1, day: 1 })
    expect(toJalali('2025-03-20')).toEqual({ year: 1403, month: 12, day: 30 })
    expect(fromJalali({ year: 1405, month: 7, day: 14 })).toBe('2026-10-06')
    expect(fromJalali({ year: 1403, month: 12, day: 30 })).toBe('2025-03-20')
  })

  it('comes back to itself for every day of three years', () => {
    for (let day = '2024-01-01'; day < '2027-01-01'; day = addDays(day, 1)) {
      expect(fromJalali(toJalali(day)), day).toBe(day)
    }
  })

  it('knows each month’s length — اسفند has its 30th day in a leap year alone', () => {
    expect([monthLength(1405, 1), monthLength(1405, 6), monthLength(1405, 7), monthLength(1405, 11)]).toEqual([31, 31, 30, 30])
    expect([monthLength(1403, 12), monthLength(1404, 12)]).toEqual([30, 29])
    expect(monthRange(1404, 12)).toEqual({ from: '2026-02-20', to: '2026-03-20' })
  })

  it('steps between months across the turn of the year', () => {
    expect(shiftMonth({ year: 1405, month: 1 }, -1)).toEqual({ year: 1404, month: 12 })
    expect(shiftMonth({ year: 1404, month: 12 }, 1)).toEqual({ year: 1405, month: 1 })
    expect(shiftMonth({ year: 1405, month: 7 }, -19)).toEqual({ year: 1403, month: 12 })
  })

  it('places a day in a week that starts on Saturday', () => {
    expect([weekday('2026-10-03'), weekday('2026-10-06'), weekday('2026-10-09')]).toEqual([0, 3, 6])
  })

  it('travels in the API’s shape alone', () => {
    expect(['2026-10-06', '2024-02-29'].map(isDay)).toEqual([true, true])
    expect(['2026-02-29', '2026-10-6', '06-10-2026', 'today', ''].map(isDay)).toEqual([false, false, false, false, false])
  })
})
