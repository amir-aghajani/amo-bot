import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { PaymentRow } from '@/lib/api-types'
import { PaymentsPage } from '@/pages/payments'
import { signedIn } from '@/test/render'
import { paymentRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The payments screen (pages/payments): what each payment paid for — the amount an order of a service's extra traffic or
 * time brings, else the plan, else the order's type — over the order's type and number.
 */

/** The screen listing `payments`. */
function open(payments: PaymentRow[]) {
  server().on(
    'GET',
    '/api/admin/payments',
    json({ payments, meta: { page: 1, per_page: 25, total: payments.length, last_page: 1, sort: 'created', dir: 'desc', awaiting_review: 0, paid: 0, paid_amount: '0.00' } }),
  )
  render(<PaymentsPage />, { wrapper: signedIn({ at: '/payments' }).wrapper })
}

/** What the row of the order numbered `order` says it paid for, its lines run together. */
const paidFor = (order: number) => screen.getByText(`#${order}`).closest('div')?.textContent

describe('what a payment paid for', () => {
  it('says how much a service’s extra brings, over its type and its order', async () => {
    open([
      paymentRow({ id: 7, order: { ...paymentRow().order, id: 31, type: 'extra_traffic', plan: 'طلایی', subscription_id: 1, traffic_bytes: 10 * 1024 ** 3 } }),
      paymentRow({ id: 8, order: { ...paymentRow().order, id: 32, type: 'extra_time', plan: 'طلایی', subscription_id: 1, days: 7 } }),
      paymentRow({ id: 9, order: { ...paymentRow().order, id: 33, type: 'purchase', plan: 'طلایی' } }),
      paymentRow({ id: 10, order: { ...paymentRow().order, id: 34 } }),
    ])

    expect(await screen.findByText('۱۰ گیگابایت')).toBeTruthy()
    expect(paidFor(31)).toBe('۱۰ گیگابایتحجم اضافه · سفارش #31')
    expect(paidFor(32)).toBe('۷ روززمان اضافه · سفارش #32')
    expect(paidFor(33)).toBe('طلاییخرید · سفارش #33')
    expect(paidFor(34)).toBe('شارژ کیف پولسفارش #34')
  })
})
