import { fireEvent, render, screen, within } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import CustomScreen from '@/apps/app/screens/buy/custom'
import * as app from '@/apps/app/test/app'
import { API, CARD, storeCheckout } from '@/apps/app/test/rows'
import type { StoreCustomOrderRequest, StoreCustomQuoteRequest, StoreCustomServicesResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «سرویس دلخواه» (screens/buy/custom): the GB and days move on their sliders and the shop's amounts, each with the rate
 * it is priced at and the cheaper one ahead; the customer's price is quoted as they settle, and pay buys what was
 * quoted, at that price, with its key. A code is checked with the quote, and taken again once that quote failed.
 */

/** The builder as the docs set it: a GB 3,000 (from 50: 2,500), a day 2,000 (from 30: 1,500), two devices, on Germany. */
const BUILDER: StoreCustomServicesResponse = {
  custom_services: {
    offered: true,
    traffic: {
      tiers: [
        { from: 1, price: '3000.00' },
        { from: 50, price: '2500.00' },
      ],
      min: 1,
      max: 500,
      options: [10, 50, 100],
    },
    days: {
      tiers: [
        { from: 1, price: '2000.00' },
        { from: 30, price: '1500.00' },
      ],
      min: 1,
      max: 90,
      options: [30, 60],
    },
    devices: 2,
    servers: [{ id: 1, name: 'آلمان' }],
  },
}

/** The builder's price for a request, worked out by its tiers (no discount). */
function quote({ gb, days }: StoreCustomQuoteRequest) {
  const traffic = Number(gb) * (Number(gb) >= 50 ? 2500 : 3000)
  const term = Number(days) * (Number(days) >= 30 ? 1500 : 2000)
  const money = (amount: number) => `${amount}.00`

  return {
    quote: {
      gb: Number(gb),
      days: Number(days),
      devices: 2,
      server_id: 1,
      traffic_price: money(traffic),
      days_price: money(term),
      base: money(traffic + term),
      discounts: [],
      amount: money(traffic + term),
    },
  }
}

function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

describe('the custom builder', () => {
  it('quotes the service as the customer settles it, and buys it at the quote shown', async () => {
    server()
      .on('GET', `${API}/custom-services`, json(BUILDER))
      .on('POST', `${API}/custom-services/quote`, (request) => json(quote(request.body as StoreCustomQuoteRequest)))
      .on('GET', `${API}/payment-methods`, json({ methods: [CARD] }))
      .on('POST', `${API}/custom-services/orders`, json({ checkout: storeCheckout() }))
    render(
      app.screen(
        <Routes>
          <Route path="buy/custom" element={<CustomScreen />} />
          <Route path="pay/:orderId" element={<Where />} />
        </Routes>,
        { at: '/buy/custom', route: '*' },
      ),
    )

    // Ten GB and thirty days at first: 30,000 and 45,000.
    await screen.findByRole('button', { name: 'پرداخت ۷۵٬۰۰۰ تومان' })
    const traffic = within(screen.getByRole('heading', { name: 'حجم' }).closest('section') as HTMLElement)
    expect(traffic.getByText('هر گیگابایت ۳٬۰۰۰ تومان · از ۵۰ گیگابایت، هر گیگابایت ۲٬۵۰۰ تومان')).toBeTruthy()

    fireEvent.click(traffic.getByRole('button', { name: '۵۰ گیگابایت' }))
    expect(traffic.getByText('هر گیگابایت ۲٬۵۰۰ تومان')).toBeTruthy()
    const pay = await screen.findByRole('button', { name: 'پرداخت ۱۷۰٬۰۰۰ تومان' })
    await until(() => expect((pay as HTMLButtonElement).disabled).toBe(false))
    expect(server().sent('POST', `${API}/custom-services/quote`).at(-1)?.body).toEqual({ gb: 50, days: 30, server_id: 1 })
    fireEvent.click(pay)

    await until(() => expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/pay/52'))
    const [order] = server().sent('POST', `${API}/custom-services/orders`)
    expect(order?.body).toEqual({ gb: 50, days: 30, server_id: 1, method_id: 2, expected_amount: '170000.00' } satisfies StoreCustomOrderRequest)
    expect(order?.headers['idempotency-key']).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('takes the code again once its quote failed for another reason, never checking it on and on', async () => {
    let failing = false
    server()
      .on('GET', `${API}/custom-services`, json(BUILDER))
      .on('POST', `${API}/custom-services/quote`, (request) => (failing ? refusal(500, 'خطای سرور.') : json(quote(request.body as StoreCustomQuoteRequest))))
      .on('GET', `${API}/payment-methods`, json({ methods: [CARD] }))
    render(app.screen(<CustomScreen />, { at: '/buy/custom' }))
    await screen.findByRole('button', { name: 'پرداخت ۷۵٬۰۰۰ تومان' })

    failing = true
    fireEvent.click(screen.getByRole('button', { name: 'کد تخفیف دارید؟' }))
    fireEvent.change(screen.getByLabelText('کد تخفیف'), { target: { value: 'NOWRUZ' } })
    fireEvent.click(screen.getByRole('button', { name: 'اعمال' }))

    expect((await screen.findByRole('alert')).textContent).toContain('خطای سرور.')
    await until(() => expect((screen.getByRole('button', { name: 'اعمال' }) as HTMLButtonElement).disabled).toBe(false))
    expect(server().sent('POST', `${API}/custom-services/quote`).at(-1)?.body).toEqual({ gb: 10, days: 30, server_id: 1, discount_code: 'NOWRUZ' })
  })
})
