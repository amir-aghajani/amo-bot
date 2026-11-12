import { fireEvent, render, screen, within } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { rememberedCheckout } from '@/apps/app/lib/checkout'
import PlanScreen from '@/apps/app/screens/buy/plan'
import { storePlan } from '@/apps/app/screens/buy/test-rows'
import * as app from '@/apps/app/test/app'
import { API, CARD, GATEWAY, storeCheckout, WALLET, walletRow } from '@/apps/app/test/rows'
import type { StoreOrderRequest } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server, type SentRequest } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * A plan's checkout (screens/buy/plan): the plan read again, its location, a discount code checked as it is applied, the
 * way to pay; «پرداخت …» sends `POST /orders` at the price shown (`expected_amount`) with the attempt's Idempotency-Key,
 * and the payment opens. A price the shop no longer asks is read again and confirmed with a new key.
 */

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/** Where the checkout went: the payment's address. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

/** Plan 3's checkout, the ways to pay offered and the wallet read; its payment screen a stand-in that says its address. */
function open() {
  server()
    .on('GET', `${API}/payment-methods`, json({ methods: [WALLET, CARD, GATEWAY] }))
    .on('GET', `${API}/wallet`, json(walletRow()))
  render(
    app.screen(
      <Routes>
        <Route path="buy/plans/:id" element={<PlanScreen />} />
        <Route path="pay/:orderId" element={<Where />} />
      </Routes>,
      { at: '/buy/plans/3', route: '*' },
    ),
  )
}

/** The payment button, once the plan, the ways to pay and the wallet are read. */
async function payButton(text: string) {
  const button = await screen.findByRole('button', { name: text })
  await until(() => expect((button as HTMLButtonElement).disabled).toBe(false))
  return button
}

const orders = (): SentRequest[] => server().sent('POST', `${API}/orders`)

describe('a plan’s checkout', () => {
  it('sends the order with its key and the price shown, and opens its payment', async () => {
    server()
      .on('GET', `${API}/plans/3`, json({ plan: storePlan({ checkout_note: 'روی Happ و v2rayNG تست شده است.' }) }))
      .on('POST', `${API}/orders`, json({ checkout: storeCheckout() }))
    open()

    await screen.findByText('روی Happ و v2rayNG تست شده است.')
    const locations = within(screen.getByRole('radiogroup', { name: 'لوکیشن' }))
    expect(locations.getByRole('radio', { name: /آلمان/ }).getAttribute('aria-checked')).toBe('true')
    fireEvent.click(locations.getByRole('radio', { name: /هلند/ }))

    // The wallet covers it, so it is picked first; the customer picks the card.
    const methods = within(screen.getByRole('radiogroup', { name: 'روش پرداخت' }))
    await screen.findByText('موجودی: ۱۵۰٬۰۰۰ تومان')
    expect(methods.getByRole('radio', { name: /کیف پول/ }).getAttribute('aria-checked')).toBe('true')
    fireEvent.click(methods.getByRole('radio', { name: /کارت به کارت/ }))
    expect(server().sent('GET', `${API}/payment-methods`)[0]?.query.toString()).toBe('for=purchase&amount=120000')

    fireEvent.click(await payButton('پرداخت ۱۲۰٬۰۰۰ تومان'))

    await until(() => expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/pay/52'))
    expect(orders()).toHaveLength(1)
    expect(orders()[0]?.body).toEqual({ plan_id: 3, server_id: 2, method_id: 2, expected_amount: '120000.00' } satisfies StoreOrderRequest)
    expect(orders()[0]?.headers['idempotency-key']).toMatch(UUID)
    // The payment screen finds the card the checkout answered.
    expect(rememberedCheckout(52)?.transfer?.card).toBe('6037997700001119')
  })

  it('asks the customer to confirm a price changed since, and sends it with a new key', async () => {
    let price = '120000.00'
    const changed = 'قیمت این سفارش تغییر کرده و الان ۱۳۰٬۰۰۰ تومان است؛ اگر هنوز می‌خواهید، دوباره پرداخت کنید.'
    server()
      .on('GET', `${API}/plans/3`, () => json({ plan: storePlan({ price, offer_price: price }) }))
      .on('POST', `${API}/orders`, () => {
        if (orders().length > 1) return json({ checkout: storeCheckout({ outcome: 'settled' }) })
        // A sale ended while the page was open.
        price = '130000.00'
        return refusal(409, changed, { expected_amount: [changed] })
      })
    open()

    fireEvent.click(await payButton('پرداخت ۱۲۰٬۰۰۰ تومان'))

    expect((await screen.findByRole('alert')).textContent).toBe(changed)
    // Read again, the plan says the new price; the customer confirms it.
    fireEvent.click(await payButton('پرداخت ۱۳۰٬۰۰۰ تومان'))

    await until(() => expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/pay/52'))
    expect(orders().map((order) => (order.body as StoreOrderRequest).expected_amount)).toEqual(['120000.00', '130000.00'])
    const [first, second] = orders().map((order) => order.headers['idempotency-key'])
    expect(second).toMatch(UUID)
    expect(second).not.toBe(first)
  })

  it('checks a discount code as it is applied, and sends the order at the price it leaves', async () => {
    server()
      .on('GET', `${API}/plans/3`, json({ plan: storePlan() }))
      .on('POST', `${API}/discount-codes/check`, (request) =>
        (request.body as { code: string }).code === 'nowruz'
          ? json({ quote: { base: '120000.00', discounts: [{ source: 'code', label: 'کد NOWRUZ', amount: '12000.00', percent: 10 }], amount: '108000.00' } })
          : refusal(422, 'این کد تخفیف معتبر نیست.', { code: ['این کد تخفیف معتبر نیست.'] }),
      )
      .on('POST', `${API}/orders`, json({ checkout: storeCheckout() }))
    open()

    await payButton('پرداخت ۱۲۰٬۰۰۰ تومان')
    fireEvent.click(screen.getByRole('button', { name: 'کد تخفیف دارید؟' }))
    const field = screen.getByRole('textbox', { name: 'کد تخفیف' })

    fireEvent.change(field, { target: { value: 'oldcode' } })
    fireEvent.click(screen.getByRole('button', { name: 'اعمال' }))
    expect((await screen.findByRole('alert')).textContent).toBe('این کد تخفیف معتبر نیست.')

    fireEvent.change(field, { target: { value: 'nowruz' } })
    fireEvent.click(screen.getByRole('button', { name: 'اعمال' }))
    expect((await screen.findByText('nowruz')).parentElement?.textContent).toBe('کد nowruz اعمال شد')
    // What it saves, on the code and on the bill.
    expect(screen.getAllByText('۱۲٬۰۰۰ تومان تخفیف')).toHaveLength(2)
    expect(server().sent('POST', `${API}/discount-codes/check`).at(-1)?.body).toEqual({ code: 'nowruz', for: 'purchase', plan_id: 3, server_id: 1 })
    // The bill: the price before struck through beside what is left.
    const bill = within(screen.getByRole('heading', { name: 'صورتحساب' }).closest('section') as HTMLElement)
    expect(bill.getByText('۱۲۰٬۰۰۰').tagName).toBe('S')
    expect(bill.getByText('۱۰۸٬۰۰۰')).toBeTruthy()

    fireEvent.click(await payButton('پرداخت ۱۰۸٬۰۰۰ تومان'))

    await until(() => expect(orders()).toHaveLength(1))
    expect(orders()[0]?.body).toEqual({ plan_id: 3, server_id: 1, method_id: 1, discount_code: 'nowruz', expected_amount: '108000.00' } satisfies StoreOrderRequest)
    expect(server().sent('GET', `${API}/payment-methods`).at(-1)?.query.get('amount')).toBe('108000')
  })
})
