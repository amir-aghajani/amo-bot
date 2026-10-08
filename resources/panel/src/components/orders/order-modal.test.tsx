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
 * An order as the admin works on it (components/orders/order-modal), when the order moves on under the admin: a cancel
 * waiting on its strip is withdrawn with a word; one confirmed and refused because the order moved on meanwhile is said
 * once — the server's words standing for the withdrawal, never both —, even though nothing is left to do; and the focus,
 * its strip gone, is on that word — never on the document.
 */

const CANCELLED = orderRow({ status: 'cancelled', notes: 'لغو توسط پشتیبانی', actions: { retry: false, cancel: false } })

/** The dialog as the orders page holds it: a refusal reads the order again — as `refreshed`. */
function Working({ first, refreshed }: { first: OrderRow; refreshed: OrderRow }) {
  const [order, setOrder] = useState(first)
  return <OrderModal order={order} onClose={vi.fn()} onChanged={setOrder} onStale={() => setOrder(refreshed)} />
}

const dialog = () => screen.getByRole('dialog', { name: `سفارش ${idLabel(12)}` })

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

  it('confirmed and refused because the order moved on meanwhile is said once, the focus on it', async () => {
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

  it('waiting on its strip when the order moves on is withdrawn with a word, which takes the focus the strip had', () => {
    const { rerender } = render(<OrderModal order={orderRow()} {...props} />, { wrapper: providers().wrapper })
    openCancel()
    within(dialog()).getByLabelText('توضیح برای مشتری').focus()

    rerender(<OrderModal order={CANCELLED} {...props} />)

    const word = within(dialog()).getByText(/این سفارش همین الان تغییر کرد و «لغو سفارش» دیگر برایش ممکن نیست/)
    expect(document.activeElement?.contains(word)).toBe(true)
  })
})
