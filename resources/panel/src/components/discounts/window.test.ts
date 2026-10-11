import { afterEach, describe, expect, it } from 'vitest'
import { dayOf, jalaliText, windowEnd, windowRequest } from '@/components/discounts/window'
import { setShopTimeZone } from '@/lib/format'

/*
 * A discount's window (components/discounts/window): each end typed as a Jalali day and an hour on the shop's clock,
 * sent as that day and hour, and read back from the moment the API keeps on the same clock — whatever the browser's.
 */

afterEach(() => setShopTimeZone(undefined))

describe('a Jalali day typed', () => {
  it('names its Gregorian day, Persian digits and dashes too', () => {
    expect(dayOf('1405/07/20')).toBe('2026-10-12')
    expect(dayOf(' ۱۴۰۵-۷-۲۰ ')).toBe('2026-10-12')
    expect(dayOf('1404/12/29')).toBe('2026-03-20')
  })

  it('that is no day names none', () => {
    expect(dayOf('1405/13/01')).toBeNull()
    expect(dayOf('1405/07/31')).toBeNull()
    expect(dayOf('فردا')).toBeNull()
  })
})

describe('an end', () => {
  it('is sent as its day and hour on the shop’s clock, none as null, and a day that is none as typed', () => {
    expect(windowRequest({ day: '1405/07/20', hour: '18' })).toBe('2026-10-12T18:00')
    expect(windowRequest({ day: '1405/07/20', hour: '0' })).toBe('2026-10-12T00:00')
    expect(windowRequest({ day: '', hour: '0' })).toBeNull()
    expect(windowRequest({ day: '1405/7/40', hour: '0' })).toBe('1405/7/40')
  })

  it('is read back on the shop’s clock', () => {
    setShopTimeZone('Asia/Tehran')

    expect(windowEnd('2026-10-12T14:30:00+00:00')).toEqual({ day: jalaliText('2026-10-12'), hour: '18' })
    expect(windowEnd('2026-10-12T21:00:00+00:00')).toEqual({ day: '1405/07/21', hour: '0' })
    expect(windowEnd(null, '23')).toEqual({ day: '', hour: '23' })
  })
})
