import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { OrdersResponse, Session } from '@/lib/api-types'
import { OrdersPage } from '@/pages/orders'
import { AGENT_SHOP, OWNER, signedIn } from '@/test/render'
import { orderRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The orders screen (pages/orders) with nothing to list where it looks: an empty status tab says so in its own wording —
 * «با این جستجو یا فیلتر…» only when a search or a filter narrows the list —, and the queue that waits on support says
 * what it waits for. Its «نوع» filter offers what a shop's orders are for: a service's extra traffic and time in every
 * shop, an agent's traffic in the main one alone. A purchase priced at its plan's first-purchase price or its agent
 * price says so under its amount; what an order bought is the server's `package_name`.
 */

const NOTHING: OrdersResponse = { orders: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1, sort: 'created', dir: 'desc', stuck: 0, sold: 0, sold_amount: '0.00' } }

/** The screen at `at`, the server finding no order there. */
function open(at: string, session: Session = OWNER) {
  server().on('GET', '/api/admin/orders', json(NOTHING))
  render(<OrdersPage />, { wrapper: signedIn({ at, session }).wrapper })
}

/** The types the «نوع» filter offers, its list opened. */
async function types() {
  fireEvent.keyDown(await screen.findByRole('combobox', { name: 'نوع همه' }), { key: 'Enter' })
  return screen.getAllByRole('option').map((option) => option.textContent)
}

describe('the «نوع» filter', () => {
  it('offers a service’s extra traffic, time and devices, and in the main shop an agent’s traffic', async () => {
    open('/orders')
    expect(await types()).toEqual(['همه', 'خرید', 'تمدید', 'حجم اضافه', 'زمان اضافه', 'کاربر اضافه', 'شارژ کیف پول', 'خرید حجم نمایندگی'])
  })

  it('in an agent’s shop offers no agent’s traffic', async () => {
    open('/orders', AGENT_SHOP)
    expect(await types()).toEqual(['همه', 'خرید', 'تمدید', 'حجم اضافه', 'زمان اضافه', 'کاربر اضافه', 'شارژ کیف پول'])
  })
})

describe('an order’s amount', () => {
  it('says a purchase was priced at its plan’s first-purchase price or its agent price, and nothing of any other order', async () => {
    const purchase = orderRow({ id: 12, type: 'purchase', amount: '0.00', pricing: 'first_purchase', plan: { id: 3, name: 'تست' }, server: { id: 1, name: 'آلمان' } })
    const agents = orderRow({ id: 14, type: 'purchase', amount: '90000.00', pricing: 'agent', plan: { id: 3, name: 'طلایی' }, server: { id: 1, name: 'آلمان' } })
    server().on('GET', '/api/admin/orders', json({ ...NOTHING, orders: [purchase, orderRow({ id: 13 }), agents], meta: { ...NOTHING.meta, total: 3 } }))
    render(<OrdersPage />, { wrapper: signedIn({ at: '/orders' }).wrapper })

    const word = await screen.findByText('قیمت خرید اول')
    expect(word.closest('td')?.textContent).toBe('۰ تومانقیمت خرید اول')
    expect(word.closest('tr')?.textContent).toContain('#12')
    expect(screen.getAllByText('قیمت خرید اول')).toHaveLength(1)
    const agent = screen.getByText('قیمت نماینده')
    expect(agent.closest('td')?.textContent).toBe('۹۰٬۰۰۰ تومانقیمت نماینده')
    expect(agent.closest('tr')?.textContent).toContain('#14')
    expect(screen.getAllByText('قیمت نماینده')).toHaveLength(1)
  })
})

describe('what an order was for', () => {
  it('is what it bought in the server’s words — a service the customer built as one —, over its server', async () => {
    const built = orderRow({ id: 15, type: 'purchase', amount: '140000.00', package_name: 'سرویس دلخواه · ۵۰ گیگ · ۳۰ روز', server: { id: 1, name: 'آلمان' } })
    server().on('GET', '/api/admin/orders', json({ ...NOTHING, orders: [built], meta: { ...NOTHING.meta, total: 1 } }))
    render(<OrdersPage />, { wrapper: signedIn({ at: '/orders' }).wrapper })

    expect((await screen.findByText('سرویس دلخواه · ۵۰ گیگ · ۳۰ روز')).closest('td')?.textContent).toBe('سرویس دلخواه · ۵۰ گیگ · ۳۰ روزآلمان')
  })
})

describe('an empty status tab', () => {
  it('says the tab is empty, with its own empty text', async () => {
    open('/orders?status=cancelled')

    expect(await screen.findByText('سفارشی در این وضعیت نیست.')).toBeTruthy()
    expect(screen.queryByText(/جستجو یا فیلتر/)).toBeNull()
  })

  it('— the queue that waits on support, what it waits for', async () => {
    open('/orders?status=stuck')

    expect(await screen.findByText('فعلا سفارشی نیست که پرداخت شده و تحویل نشده باشد.')).toBeTruthy()
  })

  it('says nothing matched when a search or a filter narrows it', async () => {
    open('/orders?status=stuck&search=amir')

    expect(await screen.findByText('با این جستجو یا فیلتر چیزی نیست.')).toBeTruthy()
  })
})
