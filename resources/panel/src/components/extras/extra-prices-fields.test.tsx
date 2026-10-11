import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { extraPricesAs, NO_EXTRA_PRICES, type ExtraPricesDraft } from '@/components/extras/draft'
import { ExtraPricesFields } from '@/components/extras/extra-prices-fields'
import type { Session } from '@/lib/api-types'
import { useForm } from '@/lib/use-form'
import { AGENT_SHOP, OWNER, signedIn } from '@/test/render'

/*
 * The price list's editor (components/extras/extra-prices-fields), as any form holds it: each extra's unit price, the
 * amounts offered — a row each, added with the focus on its amount, removed with the focus on the row that takes its
 * place, eight at most —, the bounds of what may be typed, and what the bot offers by them as they are typed: each amount
 * at its own price or the unit price, any amount between the bounds at the unit price, an amount without a price flagged,
 * or that nothing is sold. In an agent's shop, the traffic is said to come out of the agent's.
 */

/** A form holding the price list under its own names (`x_…`), as the settings card and the plan form hold theirs. */
function Host({ prices }: { prices: ExtraPricesDraft }) {
  const form = useForm(extraPricesAs('x_', prices))
  return <ExtraPricesFields form={form} prefix="x_" />
}

async function show(prices: Partial<ExtraPricesDraft> = {}, session: Session = OWNER) {
  render(<Host prices={{ ...NO_EXTRA_PRICES, ...prices }} />, { wrapper: signedIn({ session }).wrapper })
  return screen.findByRole('group', { name: 'حجم اضافه' })
}

const row = (id: number, quantity: string, price = '') => ({ id, quantity, price })

/** The buttons the bot would draw for an extra, in reading order. */
const buttons = (title: string) =>
  within(screen.getByRole('list', { name: `دکمه‌های ${title}` }))
    .getAllByRole('listitem')
    .map((button) => button.textContent)

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('the bot’s offer', () => {
  it('prices each amount by its own price or the unit price, and says what may be typed', async () => {
    await show({ traffic_price: '3000', traffic_options: [row(0, '50', '120000'), row(1, '10')], traffic_max: '100' })

    expect(buttons('حجم اضافه')).toEqual(['۱۰ گیگابایت · ۳۰٬۰۰۰ تومان', '۵۰ گیگابایت · ۱۲۰٬۰۰۰ تومان'])
    expect(screen.getByText('یا حجم دلخواه بین ۱ تا ۱۰۰ گیگابایت، هر گیگابایت ۳٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText('= ۳٬۰۰۰ تومان')).toBeTruthy()
  })

  it('follows what is typed, in Persian digits too', async () => {
    await show({ time_options: [row(0, '7', '20000')] })
    expect(buttons('زمان اضافه')).toEqual(['۷ روز · ۲۰٬۰۰۰ تومان'])

    type('قیمت هر روز (تومان)', '۲٬۵۰۰')
    type('زمان پیشنهادی ۱ (روز)', '۱۴')

    expect(buttons('زمان اضافه')).toEqual(['۱۴ روز · ۲۰٬۰۰۰ تومان'])
    expect(screen.getByText('یا زمان دلخواه بین ۱ تا ۳۰ روز، هر روز ۲٬۵۰۰ تومان')).toBeTruthy()
  })

  it('says an amount has no price while there is no unit price, with the server’s message', async () => {
    await show({ traffic_options: [row(0, '10')] })

    expect(buttons('حجم اضافه')).toEqual(['۱۰ گیگابایت · بدون قیمت'])
    expect(screen.getByText('۱۰ گیگابایت قیمت ندارد؛ قیمت آن، یا قیمت هر گیگابایت را بنویسید.')).toBeTruthy()
    expect(screen.queryByText(/حجم دلخواه بین/)).toBeNull()
  })

  it('says when nothing is sold, and why typing does nothing without a unit price', async () => {
    await show()

    expect(screen.getByText('با این قیمت‌ها حجم اضافه فروخته نمی‌شود.')).toBeTruthy()
    expect(screen.getByText('با این قیمت‌ها زمان اضافه فروخته نمی‌شود.')).toBeTruthy()
    expect(screen.queryByRole('list', { name: 'دکمه‌های حجم اضافه' })).toBeNull()
    expect(screen.getByText(/تا قیمت هر گیگابایت خالی است، مشتری حجم دلخواه نمی‌نویسد/)).toBeTruthy()
  })

  it('in an agent’s shop says the traffic comes out of the agent’s', async () => {
    await show({}, AGENT_SHOP)
    expect(screen.getByText(/در فروشگاه نماینده از حجم نمایندگی کم می‌شود/)).toBeTruthy()
  })
})

describe('the amounts offered', () => {
  it('take a row each: a new one with the focus on its amount', async () => {
    const traffic = await show()

    fireEvent.click(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))

    expect(document.activeElement).toBe(screen.getByLabelText('حجم پیشنهادی ۱ (گیگابایت)'))
  })

  it('removed, hand the focus to the row that takes the place — or, the last gone, to adding one', async () => {
    const traffic = await show({ traffic_price: '1000', traffic_options: [row(0, '10'), row(1, '20'), row(2, '30')] })

    fireEvent.click(screen.getByRole('button', { name: 'حذف حجم پیشنهادی ۱' }))
    expect(screen.getByLabelText<HTMLInputElement>('حجم پیشنهادی ۱ (گیگابایت)').value).toBe('20')
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'حذف حجم پیشنهادی ۱' }))

    fireEvent.click(screen.getByRole('button', { name: 'حذف حجم پیشنهادی ۲' }))
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'حذف حجم پیشنهادی ۱' }))

    fireEvent.click(screen.getByRole('button', { name: 'حذف حجم پیشنهادی ۱' }))
    expect(document.activeElement).toBe(within(traffic).getByRole('button', { name: 'افزودن مقدار' }))
  })

  it('are eight at most: adding is held then — keeping the focus —, and says why', async () => {
    const traffic = await show({ traffic_price: '1000', traffic_options: Array.from({ length: 8 }, (_, i) => row(i, String(i + 1))) })
    const add = within(traffic).getByRole('button', { name: 'افزودن مقدار' })

    add.focus()
    fireEvent.click(add)

    expect(screen.queryByLabelText('حجم پیشنهادی ۹ (گیگابایت)')).toBeNull()
    expect(add.getAttribute('aria-disabled')).toBe('true')
    expect(document.activeElement).toBe(add)
    expect(within(traffic).getByText('حداکثر ۸ مقدار؛ برای مقدار تازه یکی را بردارید.')).toBeTruthy()
  })

  it('left blank at a price, sell at the unit price — which the field shows', async () => {
    await show({ traffic_price: '3000', traffic_options: [row(0, '10')] })
    expect(screen.getByLabelText('قیمت حجم پیشنهادی ۱ (تومان)').getAttribute('placeholder')).toBe('30000')
  })
})
