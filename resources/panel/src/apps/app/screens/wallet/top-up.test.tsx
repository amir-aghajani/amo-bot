import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { rememberedCheckout } from '@/apps/app/lib/checkout'
import { keys, queryClient } from '@/apps/app/lib/query'
import { orderRow, paymentRow } from '@/apps/app/screens/wallet/test-rows'
import TopUpScreen from '@/apps/app/screens/wallet/top-up'
import * as app from '@/apps/app/test/app'
import { API, CARD, GATEWAY, walletRow } from '@/apps/app/test/rows'
import type { StoreCheckoutResponse, StorePaymentMethodsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, offline, refusal, server, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «شارژ کیف پول» (screens/wallet/top-up): the shop's amounts and a typed one read as the shop reads it, the ways to pay
 * that amount, the top-up sent with its attempt's key — the same key again after no answer, a new one after a refusal —,
 * and the payment screen opened with the checkout kept for it.
 */

/** The card checkout of top-up 52. */
const CHECKOUT: StoreCheckoutResponse = {
  checkout: {
    outcome: 'transfer',
    order: orderRow({
      id: 52,
      type: 'wallet_topup',
      status: 'pending',
      amount: '100000.00',
      discount_amount: '0.00',
      discounts: [],
      discount_code: null,
      fulfilled_at: null,
      plan: null,
      package_name: null,
      server: null,
      subscription: null,
      payments: [paymentRow({ id: 90, status: 'pending', amount: '100037.00', paid_at: null, receipt: null })],
    }),
    transfer: { payment_id: 90, amount: '100037.00', amount_rial: '1000370', card: '6037997700001119', holder: 'محمد کریمی', bank: 'ملت', instructions: null, contact: null },
    link: null,
    subscription: null,
  },
}

function open() {
  server().on('GET', `${API}/wallet`, json(walletRow({ top_up: { min: '10000.00', max: '50000000.00', presets: ['50000.00', '100000.00', '200000.00'], random: ['73000.00'] } })))
  server().on('GET', `${API}/payment-methods`, json({ methods: [CARD, GATEWAY] } satisfies StorePaymentMethodsResponse))
  render(app.screen(<TopUpScreen />, { at: '/wallet/top-up' }))
}

/** The amounts the ways to pay were read for, in order (none: read for every amount). */
const methodsReadFor = () =>
  server()
    .sent('GET', `${API}/payment-methods`)
    .map((request) => `${request.query.get('for')}:${request.query.get('amount') ?? '-'}`)

describe('a wallet top-up', () => {
  it("offers the shop's amounts, reads the ways to pay the one picked, tops up with them and opens the payment", async () => {
    open()

    fireEvent.click(await screen.findByRole('button', { name: /۱۰۰٬۰۰۰/ }))
    expect(screen.getByRole('button', { name: /۷۳٬۰۰۰/ })).toBeTruthy()
    await until(() => expect(methodsReadFor()).toContain('wallet_topup:100000'))
    fireEvent.click(await screen.findByRole('radio', { name: /پرداخت آنلاین/ }))
    server().on('POST', `${API}/wallet/top-up`, json(CHECKOUT))
    fireEvent.click(screen.getByRole('button', { name: 'پرداخت ۱۰۰٬۰۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${API}/wallet/top-up`)).toHaveLength(1))
    const [sent] = server().sent('POST', `${API}/wallet/top-up`)
    expect(sent?.body).toEqual({ amount: '100000.00', method_id: 3 })
    expect(sent?.headers['idempotency-key']).toMatch(/^[0-9a-f-]{36}$/)
    await until(() => expect(rememberedCheckout(52)?.transfer?.amount).toBe('100037.00'))
    expect(screen.queryByText('مبلغ شارژ')).toBeNull()
  })

  it('keeps a random amount picked, highlighted, when the wallet read again draws others — and sends that one', async () => {
    open()

    fireEvent.click(await screen.findByRole('button', { name: /۷۳٬۰۰۰/ }))
    await until(() => expect(methodsReadFor()).toContain('wallet_topup:73000'))
    // The customer checks their banking app and comes back: the wallet is read again, other amounts drawn.
    server().on('GET', `${API}/wallet`, json(walletRow({ top_up: { min: '10000.00', max: '50000000.00', presets: ['50000.00', '100000.00', '200000.00'], random: ['81000.00'] } })))
    await act(() => queryClient.invalidateQueries({ queryKey: keys.wallet }))

    expect(await screen.findByRole('button', { name: /۸۱٬۰۰۰/ })).toBeTruthy()
    expect(
      within(screen.getByRole('group', { name: 'مبلغ‌های آماده' }))
        .getByRole('button', { name: /۷۳٬۰۰۰/ })
        .getAttribute('aria-pressed'),
    ).toBe('true')
    server().on('POST', `${API}/wallet/top-up`, json(CHECKOUT))
    const pay = screen.getByRole('button', { name: 'پرداخت ۷۳٬۰۰۰ تومان' })
    await until(() => expect((pay as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(pay)
    await until(() => expect(server().sent('POST', `${API}/wallet/top-up`)[0]?.body).toEqual({ amount: '73000.00', method_id: 2 }))
  })

  it('reads a typed amount as the shop does, and says when one is outside its bounds', async () => {
    open()

    const field = await screen.findByLabelText('یا مبلغ دلخواه')
    fireEvent.change(field, { target: { value: '۷۵ هزار' } })
    expect(screen.getByText('۷۵٬۰۰۰ تومان شارژ می‌شود')).toBeTruthy()
    await until(() => expect(methodsReadFor()).toContain('wallet_topup:75000'))
    server().on('POST', `${API}/wallet/top-up`, json(CHECKOUT))
    fireEvent.click(screen.getByRole('button', { name: 'پرداخت ۷۵٬۰۰۰ تومان' }))
    await until(() => expect(server().sent('POST', `${API}/wallet/top-up`)[0]?.body).toEqual({ amount: '۷۵ هزار', method_id: 2 }))
  })

  it('refuses an amount outside the bounds before asking the shop', async () => {
    open()

    fireEvent.change(await screen.findByLabelText('یا مبلغ دلخواه'), { target: { value: '500' } })

    expect(screen.getByRole('alert').textContent).toBe('مبلغ شارژ باید بین ۱۰٬۰۰۰ تومان و ۵۰٬۰۰۰٬۰۰۰ تومان باشد.')
    expect((screen.getByRole('button', { name: 'پرداخت' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('sends the same key again after no answer came, and a new one after a refusal', async () => {
    open()
    const answers: Answer[] = [offline, refusal(422, 'روش پرداخت این مبلغ را نمی‌پذیرد.', { method_id: ['روش پرداخت این مبلغ را نمی‌پذیرد.'] }), json(CHECKOUT)]
    server().on('POST', `${API}/wallet/top-up`, () => answers.shift() ?? offline)

    fireEvent.click(await screen.findByRole('button', { name: /۵۰٬۰۰۰/ }))
    const press = async () => {
      const button = await screen.findByRole('button', { name: 'پرداخت ۵۰٬۰۰۰ تومان' })
      await until(() => expect((button as HTMLButtonElement).disabled).toBe(false))
      fireEvent.click(button)
    }
    await press()
    await until(() => expect(server().sent('POST', `${API}/wallet/top-up`)).toHaveLength(1))
    await press()
    expect(await screen.findByText('روش پرداخت این مبلغ را نمی‌پذیرد.')).toBeTruthy()
    await press()
    await until(() => expect(server().sent('POST', `${API}/wallet/top-up`)).toHaveLength(3))

    const [first, again, fresh] = server()
      .sent('POST', `${API}/wallet/top-up`)
      .map((request) => request.headers['idempotency-key'])
    expect(again).toBe(first)
    expect(fresh).not.toBe(again)
  })

  it('keeps the form and the amount picked when the wallet read again fails', async () => {
    open()
    fireEvent.click(await screen.findByRole('button', { name: /۷۳٬۰۰۰/ }))

    server().on('GET', `${API}/wallet`, refusal(500, 'خطای سرور'))
    await act(() => queryClient.refetchQueries({ queryKey: keys.wallet }).catch(() => undefined))

    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect(
      within(screen.getByRole('group', { name: 'مبلغ‌های آماده' }))
        .getByRole('button', { name: /۷۳٬۰۰۰/ })
        .getAttribute('aria-pressed'),
    ).toBe('true')
  })
})
