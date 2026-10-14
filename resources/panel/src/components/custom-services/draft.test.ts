import { describe, expect, it } from 'vitest'
import { customPrice, customServicesDraft, customServicesRead, customServicesRequest, optionsOf, tierOf, tiersOf, type TierDraft } from '@/components/custom-services/draft'
import { botSettingsRow } from '@/test/rows'

/*
 * «سرویس دلخواه»'s settings as the panel edits them, and the calculator's price: each quantity whole at the rate of the
 * tier it falls into — the last tier whose start it reaches —, within its bounds (the least never below the first
 * tier), the GB's price and the days' added; what the server keeps and refuses read the way the server reads it.
 */

const rows = (...tiers: [string, string][]): TierDraft[] => tiers.map(([from, price], id) => ({ id, from, price }))

/** The builder of the PHP tests' fixture: a GB 3,000 (from 50: 2,500), a day 2,000 (from 30: 1,500); 1–500 GB, 1–90 days. */
const draft = () =>
  customServicesDraft(
    botSettingsRow({
      custom_enabled: true,
      custom_traffic_tiers: [
        { from: 1, price: '3000.00' },
        { from: 50, price: '2500.00' },
      ],
      custom_days_tiers: [
        { from: 1, price: '2000.00' },
        { from: 30, price: '1500.00' },
      ],
      custom_servers: [1],
    }),
  )

describe('the custom builder’s draft', () => {
  it('starts from the settings as kept: prices in whole Toman, the amounts offered as one line', () => {
    const start = draft()

    expect(start.custom_traffic_tiers).toEqual(rows(['1', '3000'], ['50', '2500']))
    expect([start.custom_traffic_options, start.custom_days_options, start.custom_devices, start.custom_servers]).toEqual(['10, 20, 50, 100', '30, 60, 90', '2', [1]])
  })

  it('is sent as typed, without the blank rows or the rows’ ids', () => {
    const typed = { ...draft(), custom_traffic_tiers: rows(['50', '2500'], ['', ''], ['1', '3,000']) }

    expect(customServicesRequest(typed).custom_traffic_tiers).toEqual([
      { from: '50', price: '2500' },
      { from: '1', price: '3,000' },
    ])
    expect(customServicesRequest(typed).custom_servers).toEqual([1])
  })

  it('is no change while only the order of its tiers moved', () => {
    const turned = { ...draft(), custom_traffic_tiers: rows(['50', '2500'], ['1', '3000']) }

    expect(customServicesRead(turned)).toEqual(customServicesRead(draft()))
  })

  it('reads the tiers and the amounts as the server does', () => {
    expect(tiersOf(rows(['50', '۲٬۵۰۰'], ['1', '3000 تومان']))).toEqual([
      { from: 1, price: 3000 },
      { from: 50, price: 2500 },
    ])
    expect(tiersOf(rows(['0', '3000']))).toBeNull()
    expect(tiersOf(rows(['a', '3000']))).toBeNull()
    expect(optionsOf('10, ۲۰ 50')).toEqual([10, 20, 50])
    expect(optionsOf('10, x')).toBeNull()
  })
})

describe('the price calculator', () => {
  const tiers = [
    { from: 1, price: 3000 },
    { from: 50, price: 2500 },
  ]

  it('prices a quantity whole at the last tier it reaches', () => {
    expect([tierOf(tiers, 49)?.price, tierOf(tiers, 50)?.price, tierOf(tiers, 500)?.price, tierOf(tiers, 0)]).toEqual([3000, 2500, 2500, null])
  })

  it('adds the GB’s price and the days’', () => {
    expect(customPrice(draft(), '50', '30')).toEqual({ traffic: { quantity: 50, unit: 2500, total: 125000 }, days: { quantity: 30, unit: 1500, total: 45000 }, total: 170000 })
    expect(customPrice(draft(), '49', '29')).toMatchObject({ total: 49 * 3000 + 29 * 2000 })
  })

  it('says why there is no price: out of bounds, below the first tier, no tiers, nothing typed', () => {
    expect(customPrice(draft(), '501', '30')).toEqual({ refusals: { traffic: 'مشتری بین 1 تا 500 گیگابایت می‌سازد.' } })
    expect(customPrice({ ...draft(), custom_days_tiers: rows(['5', '2000']) }, '50', '3')).toEqual({ refusals: { days: 'مشتری بین 5 تا 90 روز می‌سازد.' } })
    expect(customPrice({ ...draft(), custom_traffic_tiers: [] }, '50', '')).toEqual({ refusals: { traffic: 'هنوز پله قیمتی ندارد؛ بدون آن فروخته نمی‌شود.', days: 'تعداد روز را بنویسید.' } })
  })
})
