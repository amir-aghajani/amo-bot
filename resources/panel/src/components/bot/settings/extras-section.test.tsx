import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ExtrasSection } from '@/components/bot/settings/extras-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «حجم و زمان اضافه» in the bot settings (components/bot/settings/extras-section): the switch and the shop's price list,
 * saved as one PUT of the group — the switch, every field as typed, the amounts offered that say something —; a change is
 * what the server would keep, so a blank row, the rows' order or how a number is written is none; and a refusal lands
 * under the field it names (`extra_…`), the amounts offered marked as one field, each going as its own field is typed.
 */

/** The shop's prices: 3٬000 a GB, 10 GB at that and 50 GB at 120٬000; a week at 20٬000. */
const PRICED = botSettingsRow({
  extras_enabled: true,
  extra_traffic_price: '3000.00',
  extra_traffic_options: [
    { quantity: 10, price: null },
    { quantity: 50, price: '120000.00' },
  ],
  extra_time_options: [{ quantity: 7, price: '20000.00' }],
})

async function show(settings: BotSettingsData = PRICED) {
  render(<ExtrasSection settings={settings} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('group', { name: 'حجم اضافه' })
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const unsaved = () => screen.queryByText('تغییرات ذخیره نشده') !== null
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('the shop’s prices', () => {
  it('are saved as the group’s PUT: the switch, every field as typed, the amounts that say something', async () => {
    const saved: BotSettingsSaved = { settings: PRICED }
    server().on('PUT', '/api/admin/bot/settings/extras', json(saved))
    const traffic = await show(botSettingsRow())

    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم و زمان اضافه' }))
    type('قیمت هر گیگابایت (تومان)', '۳٬۰۰۰')
    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
    type('حجم پیشنهادی ۱ (گیگابایت)', '10')
    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
    save()

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/extras')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/bot/settings/extras')[0]?.body).toEqual({
      extras_enabled: true,
      extra_traffic_price: '۳٬۰۰۰',
      extra_traffic_options: [{ quantity: '10', price: '' }],
      extra_traffic_min: '1',
      extra_traffic_max: '100',
      extra_time_price: '',
      extra_time_options: [],
      extra_time_min: '1',
      extra_time_max: '30',
    })
  })

  it('changed only by what the server would keep: not a blank row, the rows’ order or how a number is written', async () => {
    const traffic = await show()

    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
    type('قیمت هر گیگابایت (تومان)', '۳,۰۰۰ تومان')
    type('حجم پیشنهادی ۱ (گیگابایت)', '50')
    type('قیمت حجم پیشنهادی ۱ (تومان)', '120,000')
    type('حجم پیشنهادی ۲ (گیگابایت)', '۱۰')
    type('قیمت حجم پیشنهادی ۲ (تومان)', '')
    expect(unsaved()).toBe(false)
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')

    type('قیمت حجم پیشنهادی ۲ (تومان)', '25000')
    expect(unsaved()).toBe(true)
  })

  it('switched off is a change of its own', async () => {
    await show()
    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم و زمان اضافه' }))
    expect(unsaved()).toBe(true)
  })
})

describe('a refusal', () => {
  const twice = '۱۰ گیگابایت دو بار آمده است.'
  const range = 'بیشترین زمان دلخواه نمی‌تواند از کمترین زمان دلخواه کمتر باشد.'

  it('lands under the field it names — the amounts as one field, the first to fix, focused —, each going as its own field is typed', async () => {
    server().on('PUT', '/api/admin/bot/settings/extras', refusal(422, twice, { extra_traffic_options: [twice], extra_time_max: [range] }))
    await show()
    type('بیشترین (روز)', '0')
    save()

    await until(() => expect(screen.getByText(twice)).toBeTruthy())
    const amounts = screen.getByRole('group', { name: 'حجم‌های پیشنهادی' })
    expect(amounts.hasAttribute('data-invalid')).toBe(true)
    expect(document.activeElement).toBe(amounts)
    expect(screen.getByText(range)).toBeTruthy()
    expect(screen.getByLabelText('بیشترین (روز)').getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByText('۲ فیلد نیاز به اصلاح دارد')).toBeTruthy()

    type('بیشترین (روز)', '60')
    expect(screen.queryByText(range)).toBeNull()
    expect(screen.getByText(twice)).toBeTruthy()

    type('حجم پیشنهادی ۲ (گیگابایت)', '20')
    expect(screen.queryByText(twice)).toBeNull()
    expect(amounts.hasAttribute('data-invalid')).toBe(false)
  })

  it('of an amount without a price is shown once: the server’s message replaces the preview’s', async () => {
    const unpriced = '۱۰ گیگابایت قیمت ندارد؛ قیمت آن، یا قیمت هر گیگابایت را بنویسید.'
    server().on('PUT', '/api/admin/bot/settings/extras', refusal(422, unpriced, { extra_traffic_options: [unpriced] }))
    await show(botSettingsRow({ extras_enabled: true, extra_traffic_options: [{ quantity: 10, price: '30000.00' }] }))
    type('قیمت حجم پیشنهادی ۱ (تومان)', '')
    expect(screen.getAllByText(unpriced)).toHaveLength(1)

    save()

    await until(() => expect(screen.getByRole('group', { name: 'حجم‌های پیشنهادی' }).hasAttribute('data-invalid')).toBe(true))
    expect(screen.getAllByText(unpriced)).toHaveLength(1)
  })
})
