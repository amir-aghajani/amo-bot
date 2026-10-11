import { describe, expect, it } from 'vitest'
import { extraOffer, extraPricesAs, extraPricesDraft, extraPricesIn, extraPricesRead, extraPricesRequest, NO_EXTRA_PRICES, tomanOf, type ExtraPricesDraft } from '@/components/extras/draft'
import type { ExtraPrices } from '@/lib/api-types'

/*
 * A price list of «حجم و زمان اضافه» as a form holds it (components/extras/draft): read from what the server keeps, sent
 * as typed, measured as the server keeps it — a blank row, the rows' order or how a number is written is no change —, and
 * what the bot offers by it: an amount at its own price, else the unit price times it; any amount typed between the least
 * and the most while a unit price prices it; without one, only the amounts with a price of their own.
 */

/** The shop's price list as the server answers it: 3٬000 a GB, 10 GB at the unit price and 50 GB at 120٬000; time priced by its periods alone. */
const KEPT: ExtraPrices = {
  traffic_price: '3000.00',
  traffic_options: [
    { quantity: 10, price: null },
    { quantity: 50, price: '120000.00' },
  ],
  traffic_min: 1,
  traffic_max: 100,
  time_price: null,
  time_options: [{ quantity: 7, price: '20000.00' }],
  time_min: 1,
  time_max: 30,
}

/** A price list as typed: NO_EXTRA_PRICES with these fields. */
const typed = (fields: Partial<ExtraPricesDraft>): ExtraPricesDraft => ({ ...NO_EXTRA_PRICES, ...fields })

const row = (id: number, quantity: string, price = '') => ({ id, quantity, price })

describe('a price list’s draft', () => {
  it('starts from what the server keeps: whole Toman in Latin digits, a row an amount', () => {
    expect(extraPricesDraft(KEPT)).toEqual({
      traffic_price: '3000',
      traffic_options: [row(0, '10'), row(1, '50', '120000')],
      traffic_min: '1',
      traffic_max: '100',
      time_price: '',
      time_options: [row(0, '7', '20000')],
      time_min: '1',
      time_max: '30',
    })
  })

  it('is sent as typed: the rows that say something, no row’s own id', () => {
    const draft = typed({ traffic_price: '۳٬۰۰۰', traffic_options: [row(0, '10'), row(1, ' ', ''), row(2, '', '5000')], time_max: '60' })

    expect(extraPricesRequest(draft)).toEqual({
      traffic_price: '۳٬۰۰۰',
      traffic_options: [
        { quantity: '10', price: '' },
        { quantity: '', price: '5000' },
      ],
      traffic_min: '1',
      traffic_max: '100',
      time_price: '',
      time_options: [],
      time_min: '1',
      time_max: '60',
    })
  })

  it('is held by a form under its own names, and read back', () => {
    const draft = extraPricesDraft(KEPT)
    const held = extraPricesAs('extras.', draft)

    expect(held['extras.traffic_price']).toBe('3000')
    expect(held['extras.time_options']).toEqual([row(0, '7', '20000')])
    expect(extraPricesIn('extras.', held)).toEqual(draft)
  })
})

describe('what the server keeps of it', () => {
  const kept = extraPricesRead(extraPricesDraft(KEPT))

  it('is the same whatever rows say nothing, the rows’ order or how a number is written', () => {
    const retyped = typed({
      traffic_price: '۳٬۰۰۰ تومان',
      traffic_options: [row(5, '۵۰', '120,000'), row(6, ''), row(7, '010')],
      traffic_max: '۱۰۰',
      time_options: [row(3, ' 7 ', '۲۰۰۰۰')],
    })

    expect(extraPricesRead(retyped)).toEqual(kept)
  })

  it('differs by a price, an amount or a bound', () => {
    const draft = extraPricesDraft(KEPT)

    expect(extraPricesRead({ ...draft, traffic_price: '3500' })).not.toEqual(kept)
    expect(extraPricesRead({ ...draft, time_options: [row(0, '14', '20000')] })).not.toEqual(kept)
    expect(extraPricesRead({ ...draft, time_max: '31' })).not.toEqual(kept)
  })

  it('reads a price as whole Toman — its thousands set apart, a unit after it, or the API’s own form —, and nothing else', () => {
    expect([tomanOf('۳۰٬۰۰۰ تومان'), tomanOf('30,000'), tomanOf('30000.00'), tomanOf('۳۰۰۰۰')]).toEqual([30000, 30000, 30000, 30000])
    expect([tomanOf('1.5'), tomanOf('سی هزار'), tomanOf('')]).toEqual([null, null, null])
  })
})

describe('what the bot offers by it', () => {
  it('prices an amount by its own price, else by the unit price; any amount between the bounds typed at the unit price', () => {
    expect(extraOffer(extraPricesDraft(KEPT), 'traffic')).toEqual({
      buttons: [
        { quantity: 10, price: 30000 },
        { quantity: 50, price: 120000 },
      ],
      typed: { min: 1, max: 100, price: 3000 },
    })
  })

  it('without a unit price sells the amounts with a price of their own alone, and nothing typed', () => {
    expect(extraOffer(extraPricesDraft(KEPT), 'time')).toEqual({ buttons: [{ quantity: 7, price: 20000 }], typed: null })
  })

  it('has no price for an amount without its own while no unit price prices it', () => {
    expect(extraOffer(typed({ time_options: [row(0, '30')] }), 'time')).toEqual({ buttons: [{ quantity: 30, price: null }], typed: null })
  })

  it('the smallest first, each amount once, none that is no whole number above zero, none at a price it cannot read', () => {
    const draft = typed({ traffic_price: '1000', traffic_options: [row(0, '20'), row(1, '5'), row(2, '20', '9000'), row(3, '0'), row(4, 'abc'), row(5, '8', 'free')] })

    expect(extraOffer(draft, 'traffic').buttons).toEqual([
      { quantity: 5, price: 5000 },
      { quantity: 20, price: 20000 },
    ])
  })

  it('offers nothing typed while the least is above the most', () => {
    expect(extraOffer(typed({ traffic_price: '1000', traffic_min: '50', traffic_max: '10' }), 'traffic').typed).toBeNull()
  })

  it('sells nothing from a price list with nothing priced', () => {
    expect(extraOffer(NO_EXTRA_PRICES, 'traffic')).toEqual({ buttons: [], typed: null })
  })
})
