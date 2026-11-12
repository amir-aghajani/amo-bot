import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import CatalogScreen from '@/apps/app/screens/buy/catalog'
import { storePlan } from '@/apps/app/screens/buy/test-rows'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StorePlansResponse } from '@/lib/api-types'
import { json, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The catalogue (screens/buy/catalog): the plans by category, each card with its label, what it gives and the
 * customer's price — what it was before struck through, the discount's words and, while a sale ends within days, the
 * time left —; chips narrow it by category, by term and by location while the plans differ in them; the builder and the
 * test account above. While the shop takes no orders it says so, and no card opens.
 */

/** Two categories and the plans of none: monthly plans in Germany and the Netherlands, a three-month one, an unlimited one. */
function catalogue(): StorePlansResponse {
  const soon = new Date(Date.now() + 2 * 86_400_000 + 30 * 60_000).toISOString()

  return {
    groups: [
      {
        category: { id: 1, name: 'یک ماهه' },
        plans: [
          storePlan({ badge: 'bestseller', offer_price: '96000.00', discount: { source: 'sale', label: 'فروش ویژه پاییز ۲۰٪', percent: 20, ends_at: soon } }),
          storePlan({ id: 4, name: 'یک ماهه ۱۰۰ گیگ', badge: 'vip', price: '200000.00', offer_price: '200000.00', traffic_gb: 100, locations: [{ id: 2, name: 'هلند' }] }),
        ],
      },
      {
        category: { id: 2, name: 'سه ماهه' },
        plans: [
          storePlan({
            id: 5,
            name: 'سه ماهه ۱۵۰ گیگ',
            badge: 'recommended',
            price: '300000.00',
            offer_price: '300000.00',
            duration_days: 90,
            duration_hours: 2160,
            category_id: 2,
            locations: [{ id: 1, name: 'آلمان' }],
          }),
        ],
      },
      { category: { id: 3, name: 'خالی' }, plans: [] },
      {
        category: null,
        plans: [
          storePlan({
            id: 7,
            name: 'نامحدود سه ماهه',
            description: null,
            price: '450000.00',
            first_purchase_price: '90000.00',
            offer_price: '90000.00',
            traffic_gb: 0,
            duration_days: 90,
            duration_hours: 2160,
            devices: 0,
            category_id: null,
            locations: [{ id: 2, name: 'هلند' }],
          }),
        ],
      },
    ],
  }
}

function open(shop = app.shop()) {
  server().on('GET', `${API}/plans`, json(catalogue()))
  render(app.screen(<CatalogScreen />, { at: '/buy', shop }))
}

const card = (name: string) => screen.getByRole('link', { name: new RegExp(name) })
const chips = (row: string) => within(screen.getByRole('group', { name: row }))
const listed = () => ['یک ماهه ۵۰ گیگ', 'یک ماهه ۱۰۰ گیگ', 'سه ماهه ۱۵۰ گیگ', 'نامحدود سه ماهه'].filter((name) => screen.queryByRole('link', { name: new RegExp(name) }) !== null)

describe('the catalogue', () => {
  it('shows each plan’s label, what it gives and its price for the customer, the price before struck through', async () => {
    open()

    await screen.findByRole('link', { name: /یک ماهه ۵۰ گیگ/ })
    const sale = card('یک ماهه ۵۰ گیگ')
    expect(sale.getAttribute('href')).toBe('/buy/plans/3')
    expect(within(sale).getByText('پرفروش‌ترین')).toBeTruthy()
    expect(within(sale).getByText('۵۰ گیگابایت')).toBeTruthy()
    expect(within(sale).getByText('۳۰ روز')).toBeTruthy()
    expect(within(sale).getByText('۲ کاربر')).toBeTruthy()
    expect(within(sale).getByText('۹۶٬۰۰۰')).toBeTruthy()
    expect(within(sale).getByText('۱۲۰٬۰۰۰').tagName).toBe('S')
    expect(within(sale).getByText('فروش ویژه پاییز ۲۰٪')).toBeTruthy()
    // The sale ends in two days: its time left ticks beside it.
    expect(within(sale).getByText(/^۲ روز ۰۰:/)).toBeTruthy()

    expect(within(card('یک ماهه ۱۰۰ گیگ')).getByText('VIP')).toBeTruthy()
    expect(within(card('یک ماهه ۱۰۰ گیگ')).queryByText('۲۰۰٬۰۰۰')?.tagName).toBe('SPAN')
    expect(within(card('سه ماهه ۱۵۰ گیگ')).getByText('پیشنهاد ما')).toBeTruthy()

    const first = card('نامحدود سه ماهه')
    expect(within(first).getByText('قیمت خرید اول')).toBeTruthy()
    expect(within(first).getByText('۴۵۰٬۰۰۰').tagName).toBe('S')
    expect(within(first).getByText('حجم نامحدود')).toBeTruthy()
    expect(within(first).getByText('کاربر نامحدود')).toBeTruthy()

    // The plans of no category under the bot's own heading; an empty category is no chip.
    expect(screen.getByRole('heading', { name: 'سایر پلن‌ها' })).toBeTruthy()
    expect(chips('دسته‌بندی').queryByRole('button', { name: 'خالی' })).toBeNull()

    expect(screen.getByRole('link', { name: /سرویس دلخواه/ }).getAttribute('href')).toBe('/buy/custom')
    expect(screen.getByRole('link', { name: /اکانت تست رایگان/ }).getAttribute('href')).toBe('/test')
  })

  it('narrows the plans by term and by location, and a category starts its own', async () => {
    open()

    await screen.findByRole('link', { name: /یک ماهه ۵۰ گیگ/ })
    expect(
      chips('مدت')
        .getAllByRole('button')
        .map((chip) => chip.textContent),
    ).toEqual(['همه مدت‌ها', '۱ ماهه', '۳ ماهه'])
    expect(
      chips('لوکیشن')
        .getAllByRole('button')
        .map((chip) => chip.textContent),
    ).toEqual(['همه لوکیشن‌ها', 'آلمان', 'هلند'])

    fireEvent.click(chips('مدت').getByRole('button', { name: '۳ ماهه' }))
    expect(listed()).toEqual(['سه ماهه ۱۵۰ گیگ', 'نامحدود سه ماهه'])

    fireEvent.click(chips('لوکیشن').getByRole('button', { name: 'هلند' }))
    expect(listed()).toEqual(['نامحدود سه ماهه'])
    expect(chips('لوکیشن').getByRole('button', { name: 'هلند' }).getAttribute('aria-pressed')).toBe('true')

    // A category of one term has no term to pick; the location picked before is let go.
    fireEvent.click(chips('دسته‌بندی').getByRole('button', { name: 'یک ماهه' }))
    expect(listed()).toEqual(['یک ماهه ۵۰ گیگ', 'یک ماهه ۱۰۰ گیگ'])
    expect(screen.queryByRole('group', { name: 'مدت' })).toBeNull()

    fireEvent.click(chips('لوکیشن').getByRole('button', { name: 'آلمان' }))
    expect(listed()).toEqual(['یک ماهه ۵۰ گیگ'])
  })

  it('says the shop takes no orders now, and opens no plan', async () => {
    open(app.shop({ shop: { ...app.shop().shop, taking_orders: false } }))

    await screen.findByText('یک ماهه ۵۰ گیگ')
    expect(screen.getByText(/فعلا خرید ممکن نیست/)).toBeTruthy()
    expect(screen.queryByRole('link', { name: /یک ماهه ۵۰ گیگ/ })).toBeNull()
    expect(screen.queryByRole('link', { name: /سرویس دلخواه/ })).toBeNull()
  })
})
