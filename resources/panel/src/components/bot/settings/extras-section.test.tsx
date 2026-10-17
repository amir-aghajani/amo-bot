import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ExtrasSection } from '@/components/bot/settings/extras-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «حجم، زمان و کاربر اضافه» in the bot settings (components/bot/settings/extras-section): the switch and the shop's price list,
 * saved as one PUT of the group — the switch, every field as typed, the amounts offered that say something —; a change is
 * what the server would keep, so a blank row, the rows' order or how a number is written is none; switched off, the
 * prices wait held as they are kept (useHeldWhile); and a refusal lands under the field it names (`extra_…`), the
 * amounts offered marked as one field, each going as its own field is typed.
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

    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم، زمان و کاربر اضافه' }))
    type('قیمت هر گیگابایت (تومان)', '۳٬۰۰۰')
    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
    type('حجم پیشنهادی ۱ (گیگابایت)', '10')
    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
    type('قیمت هر کاربر برای ۳۰ روز (تومان)', '30000')
    fireEvent.click(within(screen.getByRole('group', { name: 'کاربر اضافه' })).getByRole('button', { name: 'افزودن مقدار' }))
    type('تعداد پیشنهادی ۱ (کاربر)', '2')
    type('قیمت تعداد پیشنهادی ۱ (تومان)', '50000')
    type('بیشترین (کاربر)', '3')
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
      extra_devices_price: '30000',
      extra_devices_options: [{ quantity: '2', price: '50000' }],
      extra_devices_min: '1',
      extra_devices_max: '3',
    })
  })

  it('of devices are for 30 days, each service paying its share of the days it has left', async () => {
    await show(botSettingsRow({ extras_enabled: true, extra_devices_price: '30000.00', extra_devices_options: [{ quantity: 2, price: '50000.00' }] }))
    const devices = screen.getByRole('group', { name: 'کاربر اضافه' })

    expect(within(devices).getByRole('list', { name: 'دکمه‌های کاربر اضافه' }).textContent).toBe('۲ کاربر · ۵۰٬۰۰۰ تومان')
    expect(within(devices).getByText('یا تعداد دلخواه بین ۱ تا ۵ کاربر، هر کاربر ۳۰٬۰۰۰ تومان')).toBeTruthy()
    expect(within(devices).getByText(/هر سرویس به نسبت روزهای مانده‌اش می‌پردازد/)).toBeTruthy()

    type('قیمت هر کاربر برای ۳۰ روز (تومان)', '')
    fireEvent.change(within(devices).getByLabelText('قیمت تعداد پیشنهادی ۱ (تومان)'), { target: { value: '' } })
    expect(within(devices).getByText('با این قیمت‌ها کاربر اضافه فروخته نمی‌شود.')).toBeTruthy()
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
    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم، زمان و کاربر اضافه' }))
    expect(unsaved()).toBe(true)
  })
})

describe('the shop’s prices while nothing is sold', () => {
  it('wait as they are kept, held — the amounts offered whole —, and one changed stays open, as the save still carries it', async () => {
    const traffic = await show()
    type('قیمت هر روز (تومان)', '5000')

    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم، زمان و کاربر اضافه' }))

    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)').disabled).toBe(true)
    expect(screen.getByLabelText<HTMLInputElement>('حجم پیشنهادی ۱ (گیگابایت)').disabled).toBe(true)
    expect(within(traffic).getByRole<HTMLButtonElement>('button', { name: 'افزودن مقدار' }).disabled).toBe(true)
    expect(within(traffic).getByRole<HTMLButtonElement>('button', { name: 'حذف حجم پیشنهادی ۱' }).disabled).toBe(true)
    expect(screen.getByLabelText<HTMLInputElement>('بیشترین (گیگابایت)').disabled).toBe(true)
    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر روز (تومان)').disabled).toBe(false)
  })

  it('open once it is switched on', async () => {
    await show({ ...PRICED, extras_enabled: false })
    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)').disabled).toBe(true)

    fireEvent.click(screen.getByRole('switch', { name: 'فروش حجم، زمان و کاربر اضافه' }))

    expect(screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)').disabled).toBe(false)
    expect(screen.getByLabelText<HTMLInputElement>('حجم پیشنهادی ۱ (گیگابایت)').disabled).toBe(false)
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
