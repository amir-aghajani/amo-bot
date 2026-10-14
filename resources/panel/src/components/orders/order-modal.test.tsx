import { useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OrderModal } from '@/components/orders/order-modal'
import type { OrderRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { providers, until } from '@/test/render'
import { orderRow } from '@/test/rows'
import { refusal, server } from '@/test/server'

/*
 * An order as the admin works on it (components/orders/order-modal): what it brings, when that is an amount — a service's
 * extra traffic, time or devices, an agent's traffic —, or what a service the customer built is built of, in its plan's
 * place; a renewal's method; its amount, one at its plan's first-purchase price or its agent price
 * saying so beside it; and when the order moves on under the admin: a cancel waiting on its strip is withdrawn with a word; one confirmed
 * and refused because the order moved on meanwhile is said once — the server's words standing for the withdrawal, never
 * both —, even though nothing is left to do; and the focus, its strip gone, is on that word — never on the document.
 */

const CANCELLED = orderRow({ status: 'cancelled', notes: 'لغو توسط پشتیبانی', actions: { retry: false, cancel: false } })

/** The dialog as the orders page holds it: a refusal reads the order again — as `refreshed`. */
function Working({ first, refreshed }: { first: OrderRow; refreshed: OrderRow }) {
  const [order, setOrder] = useState(first)
  return <OrderModal order={order} onClose={vi.fn()} onChanged={setOrder} onStale={() => setOrder(refreshed)} />
}

const dialog = () => screen.getByRole('dialog', { name: `سفارش ${idLabel(12)}` })

/** What a fact of the dialog says, by its label. */
const fact = (label: string) => within(dialog()).getByText(label, { selector: 'dt' }).nextElementSibling?.textContent

describe('what it brings', () => {
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onStale: vi.fn() }
  /** A service's order: plan 3 «طلایی», its service amir_1 on «آلمان». */
  const SERVICE = { plan: { id: 3, name: 'طلایی' }, package_name: 'طلایی', server: { id: 1, name: 'آلمان' }, subscription: { id: 1, name: 'amir_1', status: 'active' as const } }

  it('is a service’s extra traffic, by its amount', () => {
    render(<OrderModal order={orderRow({ type: 'extra_traffic', traffic_bytes: 10 * 1024 ** 3, ...SERVICE })} {...props} />, { wrapper: providers().wrapper })

    expect(fact('نوع')).toBe('حجم اضافه')
    expect(fact('حجم اضافه')).toBe('۱۰ گیگابایت')
    expect(fact('سرویس')).toContain('amir_1')
  })

  it('is a service’s extra time, in days', () => {
    render(<OrderModal order={orderRow({ type: 'extra_time', days: 7, ...SERVICE })} {...props} />, { wrapper: providers().wrapper })

    expect(fact('زمان اضافه')).toBe('۷ روز')
  })

  it('is a service’s extra devices, counted in users', () => {
    render(<OrderModal order={orderRow({ type: 'extra_devices', devices: 2, ...SERVICE })} {...props} />, { wrapper: providers().wrapper })

    expect(fact('نوع')).toBe('کاربر اضافه')
    expect(fact('کاربر اضافه')).toBe('۲ کاربر')
  })

  it('is a service the customer built, renewed as one: what it is built of, in the server’s words, in its plan’s place', () => {
    const built = { ...SERVICE, plan: null, package_name: 'سرویس دلخواه · ۲۰ گیگ · ۶۰ روز', traffic_bytes: 20 * 1024 ** 3, days: 60 }
    render(<OrderModal order={orderRow({ type: 'renewal', ...built })} {...props} />, { wrapper: providers().wrapper })

    expect(fact('نوع')).toBe('تمدید')
    expect(fact('پلن')).toBe('سرویس دلخواه · ۲۰ گیگ · ۶۰ روز')
  })

  it('is a renewal by the method it renewed by', () => {
    const { unmount } = render(<OrderModal order={orderRow({ type: 'renewal', renewal_method: 'reset_traffic', ...SERVICE })} {...props} />, { wrapper: providers().wrapper })
    expect(fact('روش تمدید')).toBe('ریست حجم + افزایش روز')
    unmount()

    render(<OrderModal order={orderRow({ type: 'extra_time', days: 7, ...SERVICE })} {...props} />, { wrapper: providers().wrapper })
    expect(within(dialog()).queryByText('روش تمدید', { selector: 'dt' })).toBeNull()
  })

  it('is an agent’s traffic, by its amount', () => {
    render(<OrderModal order={orderRow({ type: 'traffic', traffic_bytes: 50 * 1024 ** 3 })} {...props} />, { wrapper: providers().wrapper })
    expect(fact('حجم')).toBe('۵۰ گیگابایت')
  })

  it('shows nothing for a top-up besides its amount of money', () => {
    render(<OrderModal order={orderRow()} {...props} />, { wrapper: providers().wrapper })
    expect(within(dialog()).queryByText(/^(حجم|زمان)/, { selector: 'dt' })).toBeNull()
  })
})

describe('its amount', () => {
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onStale: vi.fn() }

  it('says a purchase was priced at its plan’s first-purchase price', () => {
    render(<OrderModal order={orderRow({ type: 'purchase', amount: '30000.00', pricing: 'first_purchase', plan: { id: 3, name: 'طلایی' }, server: { id: 1, name: 'آلمان' } })} {...props} />, {
      wrapper: providers().wrapper,
    })

    expect(fact('مبلغ')).toBe('۳۰٬۰۰۰ تومان قیمت خرید اول')
  })

  it('says a renewal was priced at its plan’s agent price — an agent’s, or a «همکار» group’s customer’s', () => {
    render(<OrderModal order={orderRow({ type: 'renewal', amount: '90000.00', pricing: 'agent', plan: { id: 3, name: 'طلایی' }, server: { id: 1, name: 'آلمان' } })} {...props} />, {
      wrapper: providers().wrapper,
    })

    expect(fact('مبلغ')).toBe('۹۰٬۰۰۰ تومان قیمت نماینده')
  })

  it('of any other order is the amount alone', () => {
    render(<OrderModal order={orderRow()} {...props} />, { wrapper: providers().wrapper })

    expect(fact('مبلغ')).toBe('۵۰٬۰۰۰ تومان')
  })

  it('of one support made by hand says how it was paid, who made it and their note', () => {
    const manual = orderRow({ type: 'purchase', status: 'fulfilled', amount: '100000.00', manual: { by: 'root', payment: 'outside', note: 'نقدی در دفتر' } })
    render(<OrderModal order={manual} {...props} />, { wrapper: providers().wrapper })

    expect(fact('مبلغ')).toBe('۱۰۰٬۰۰۰ تومان پرداخت خارج از فروشگاه')
    expect(fact('ثبت دستی')).toBe('توسط root')
    expect(fact('یادداشت پشتیبانی')).toBe('نقدی در دفتر')
  })
})

/** «لغو سفارش», its strip open. */
function openCancel() {
  fireEvent.click(within(dialog()).getByRole('button', { name: 'لغو سفارش' }))
  expect(within(dialog()).getByLabelText('توضیح برای مشتری')).toBeTruthy()
}

describe('a cancel', () => {
  const props = { onClose: vi.fn(), onChanged: vi.fn(), onStale: vi.fn() }
  /** A card payment whose receipt waits for review, and one the customer left pending. */
  const receipt: OrderRow['payments'][number] = {
    id: 7,
    status: 'awaiting_review',
    amount: '50000.00',
    gateway: 'manual',
    method: 'کارت به کارت (ملت)',
    paid_at: null,
    created_at: '2026-10-06T08:55:00Z',
  }
  const pending: OrderRow['payments'][number] = { ...receipt, id: 8, status: 'pending' }

  it('names in its confirmation the receipts in review it drops with the order', () => {
    render(<OrderModal order={orderRow({ payments: [receipt, { ...receipt, id: 9 }, pending] })} {...props} />, { wrapper: providers().wrapper })

    openCancel()

    expect(within(dialog()).getByText('سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند، از جمله ۲ رسید در انتظار بررسی، و مشتری خبردار می‌شود.')).toBeTruthy()
  })

  it('without a receipt in review, confirms the order and its payments alone', () => {
    render(<OrderModal order={orderRow({ payments: [pending] })} {...props} />, { wrapper: providers().wrapper })

    openCancel()

    expect(within(dialog()).getByText('سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند؛ اگر مشتری پرداختی را شروع کرده بود، خبردار می‌شود.')).toBeTruthy()
    expect(within(dialog()).queryByText(/رسید در انتظار بررسی/)).toBeNull()
  })

  it('confirmed and refused because the order changed meanwhile is shown once, with the focus on it', async () => {
    const paidOnly = 'فقط سفارش‌های پرداخت‌نشده لغو می‌شوند.'
    server().on('POST', '/api/admin/orders/12/cancel', refusal(422, paidOnly, { status: [paidOnly] }))
    render(<Working first={orderRow()} refreshed={CANCELLED} />, { wrapper: providers().wrapper })
    openCancel()

    fireEvent.click(within(dialog()).getByRole('button', { name: 'لغو سفارش' }))

    await until(() => expect(within(dialog()).queryByLabelText('توضیح برای مشتری')).toBeNull())
    expect(within(dialog()).getAllByText(paidOnly)).toHaveLength(1)
    expect(within(dialog()).queryByText(/همین الان تغییر کرد/)).toBeNull()
    expect(within(dialog()).queryByRole('button', { name: 'لغو سفارش' })).toBeNull()
    expect(document.activeElement?.textContent).toContain(paidOnly)
  })

  it('waiting on its strip when the order changes is withdrawn with a message, which takes the strip’s focus', () => {
    const { rerender } = render(<OrderModal order={orderRow()} {...props} />, { wrapper: providers().wrapper })
    openCancel()
    within(dialog()).getByLabelText('توضیح برای مشتری').focus()

    rerender(<OrderModal order={CANCELLED} {...props} />)

    const word = within(dialog()).getByText(/این سفارش همین الان تغییر کرد و «لغو سفارش» دیگر برایش ممکن نیست/)
    expect(document.activeElement?.contains(word)).toBe(true)
  })
})
