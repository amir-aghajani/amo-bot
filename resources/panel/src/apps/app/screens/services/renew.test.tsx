import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys as keysOf, queryClient } from '@/apps/app/lib/query'
import RenewScreen from '@/apps/app/screens/services/renew'
import { checkout } from '@/apps/app/screens/services/test-rows'
import * as app from '@/apps/app/test/app'
import { API, CARD, GB, service, WALLET, walletRow } from '@/apps/app/test/rows'
import type { RenewalMethod, StoreRenewalResponse, StoreSubscription } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, offline, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «تمدید سرویس» (screens/services/renew): the preview on the service's own plan, the shop's methods to pick from, a
 * plan picked by category for a service without one, a discount code, and the renewal sent with its attempt's
 * Idempotency-Key and the price shown — then the payment screen; a closed renewal window in the shop's words.
 */

const SERVICE = `${API}/subscriptions/9`

/** The preview of a renewal on «یک ماهه ۱۰۰ گیگ» at 108,000 Toman after the customer's 10%, by `method`. */
function preview(method: RenewalMethod = 'extend', methods: RenewalMethod[] = ['extend']): StoreRenewalResponse {
  return {
    renewal: {
      method,
      methods,
      plan: { id: 3, name: 'یک ماهه ۱۰۰ گیگ', description: null, checkout_note: null, price: '120000.00', traffic_gb: 100, duration_days: 30, duration_hours: 720, devices: 2 },
      custom: null,
      price: '108000.00',
      discounts: [{ source: 'personal', label: 'تخفیف اختصاصی شما ۱۰٪', amount: '12000.00', percent: 10 }],
      after: {
        term: { duration_days: 60, duration_hours: 1440, starts_at: '2026-10-01T10:00:00+00:00', expires_at: '2026-12-10T10:00:00+00:00', awaits_first_use: false },
        traffic: { limit_bytes: 200 * GB, used_bytes: 40 * GB, remaining_bytes: 160 * GB },
        next_period: null,
        next_devices: null,
      },
    },
  }
}

function open(subscription: StoreSubscription = service()) {
  server()
    .on('GET', SERVICE, json({ subscription }))
    .on('GET', `${API}/payment-methods`, json({ methods: [WALLET, CARD] }))
    // The wallet covers the price: it is picked first.
    .on('GET', `${API}/wallet`, json(walletRow()))
    // The payment screen opened: the wallet and the account are read again.
    .on('GET', `${API}/me`, json(app.account()))
  return render(app.screen(<RenewScreen />, { at: '/services/9/renew', route: '/services/:id/renew' }))
}

const pay = (amount: string) => screen.findByRole('button', { name: `پرداخت ${amount} تومان` })

describe('a renewal', () => {
  it('on its own plan: the preview, the wallet that covers it, and the renewal with its key and the price shown', async () => {
    server()
      .on('GET', `${SERVICE}/renewal`, json(preview()))
      .on('POST', `${SERVICE}/renewal`, json(checkout('renewal', '108000.00')))
    open()

    await screen.findByText('تخفیف اختصاصی شما ۱۰٪')
    expect(screen.getByText('۱۲۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByRole('radio', { name: /کیف پول/ }).getAttribute('aria-checked')).toBe('true')
    expect(server().sent('GET', `${API}/payment-methods`)[0]?.query.toString()).toBe('for=renewal&amount=108000')
    fireEvent.click(await pay('۱۰۸٬۰۰۰'))

    await until(() => expect(sessionStorage.getItem('amobot-app:checkout:51')).not.toBeNull())
    const [sent] = server().sent('POST', `${SERVICE}/renewal`)
    expect(sent?.body).toEqual({ method_id: 1, method: 'extend', expected_amount: '108000.00' })
    expect(sent?.headers['idempotency-key']).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('by the method picked among the shop’s, previewed again', async () => {
    server()
      .on('GET', `${SERVICE}/renewal`, (request) => json(preview((request.query.get('method') as RenewalMethod | null) ?? 'extend', ['extend', 'reset_time'])))
      .on('POST', `${SERVICE}/renewal`, json(checkout('renewal', '108000.00')))
    open()

    fireEvent.click(await screen.findByRole('radio', { name: /ریست زمان \+ انتقال حجم/ }))
    await until(() => expect(server().sent('GET', `${SERVICE}/renewal`).at(-1)?.query.get('method')).toBe('reset_time'))
    fireEvent.click(await pay('۱۰۸٬۰۰۰'))

    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/renewal`)[0]?.body).toMatchObject({ method: 'reset_time' })
  })

  it('with a discount code: checked first, then sent with the price it leaves', async () => {
    server()
      .on('GET', `${SERVICE}/renewal`, json(preview()))
      .on(
        'POST',
        `${API}/discount-codes/check`,
        json({
          quote: {
            base: '120000.00',
            discounts: [
              { source: 'personal', label: 'تخفیف اختصاصی شما ۱۰٪', amount: '12000.00', percent: 10 },
              { source: 'code', label: 'کد NOWRUZ', amount: '21600.00', percent: 20 },
            ],
            amount: '86400.00',
          },
        }),
      )
      .on('POST', `${SERVICE}/renewal`, json(checkout('renewal', '86400.00')))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'کد تخفیف دارید؟' }))
    fireEvent.change(screen.getByLabelText('کد تخفیف'), { target: { value: 'nowruz' } })
    fireEvent.click(screen.getByRole('button', { name: 'اعمال' }))

    await screen.findByText('کد NOWRUZ')
    expect(server().sent('POST', `${API}/discount-codes/check`)[0]?.body).toEqual({ for: 'renewal', subscription_id: 9, method: 'extend', code: 'nowruz' })
    fireEvent.click(await pay('۸۶٬۴۰۰'))

    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/renewal`)[0]?.body).toEqual({ method_id: 1, method: 'extend', discount_code: 'nowruz', expected_amount: '86400.00' })
  })

  it('keeps the attempt’s key across a server failure, and takes a new one after the price changed', async () => {
    const answers = [
      refusal(500, 'خطای سرور'),
      refusal(409, 'قیمت این سفارش تغییر کرده و الان ۱۲۰٬۰۰۰ تومان است؛ اگر هنوز می‌خواهید، دوباره پرداخت کنید.', { expected_amount: ['…'] }),
      json(checkout('renewal', '108000.00')),
    ]
    server()
      .on('GET', `${SERVICE}/renewal`, json(preview()))
      .on('POST', `${SERVICE}/renewal`, () => answers.shift() ?? refusal(500, 'خطای سرور'))
    open()

    for (const tries of [1, 2, 3]) {
      fireEvent.click(await pay('۱۰۸٬۰۰۰'))
      await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(tries))
    }

    const keys = server()
      .sent('POST', `${SERVICE}/renewal`)
      .map((request) => request.headers['idempotency-key'])
    expect(keys[1]).toBe(keys[0])
    expect(keys[2]).not.toBe(keys[1])
    expect(server().sent('GET', `${SERVICE}/renewal`).length).toBeGreaterThan(1)
  })

  it('keeps the checkout and its key when the service read again fails after no answer came', async () => {
    server().on('GET', `${SERVICE}/renewal`, json(preview())).on('POST', `${SERVICE}/renewal`, offline)
    open()

    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(1))
    await screen.findByRole('alert')

    // The app back in front: the service is read again, and that read fails.
    server().on('GET', SERVICE, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keysOf.subscription(9), exact: true }).catch(() => undefined)
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()

    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(2))
    const [first, second] = server().sent('POST', `${SERVICE}/renewal`)
    expect(second?.body).toEqual(first?.body)
    expect(second?.headers['idempotency-key']).toBe(first?.headers['idempotency-key'])
  })

  it('sends the renewal that got no answer with the same key from the screen opened again; a new one once it is made', async () => {
    const answers = [offline, json(checkout('renewal', '108000.00'))]
    server()
      .on('GET', `${SERVICE}/renewal`, json(preview()))
      .on('POST', `${SERVICE}/renewal`, () => answers.shift() ?? json(checkout('renewal', '108000.00')))
    const first = open()
    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(1))
    await screen.findByRole('alert')
    first.unmount()

    // A reload: the screen opened afresh, the same renewal pressed again — then made.
    const again = open()
    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(sessionStorage.getItem('amobot-app:checkout:51')).not.toBeNull())
    again.unmount()
    open()
    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(3))

    const sent = server()
      .sent('POST', `${SERVICE}/renewal`)
      .map((request) => request.headers['idempotency-key'])
    expect(sent[1]).toBe(sent[0])
    expect(sent[2]).not.toBe(sent[1])
  })

  it('for a service without a plan: the plans by category, the one picked previewed and sent', async () => {
    server()
      .on(
        'GET',
        `${SERVICE}/renewal-plans`,
        json({
          plans: [
            {
              id: 3,
              name: 'یک ماهه ۱۰۰ گیگ',
              description: null,
              checkout_note: null,
              price: '120000.00',
              renewal_price: '108000.00',
              discounts: [],
              traffic_gb: 100,
              duration_days: 30,
              duration_hours: 720,
              devices: 2,
              category_id: 4,
            },
            {
              id: 5,
              name: 'سه ماهه نامحدود',
              description: null,
              checkout_note: null,
              price: '300000.00',
              renewal_price: '300000.00',
              discounts: [],
              traffic_gb: 0,
              duration_days: 90,
              duration_hours: 2160,
              devices: 0,
              category_id: null,
            },
          ],
          custom_services: false,
        }),
      )
      .on('GET', `${API}/plans`, json({ groups: [{ category: { id: 4, name: 'ماهانه' }, plans: [] }] }))
      .on('GET', `${SERVICE}/renewal`, json(preview()))
      .on('POST', `${SERVICE}/renewal`, json(checkout('renewal', '108000.00')))
    open(service({ plan: null, claimed_at: '2026-10-01T10:00:00+00:00', renewable: false, picks_plan: true }))

    await screen.findByRole('button', { name: 'ماهانه' })
    fireEvent.click(screen.getByRole('button', { name: 'سایر پلن‌ها' }))
    expect(screen.getByRole('radio', { name: /سه ماهه نامحدود/ })).toBeTruthy()
    expect(screen.queryByRole('radio', { name: /یک ماهه/ })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'ماهانه' }))
    fireEvent.click(screen.getByRole('radio', { name: /یک ماهه ۱۰۰ گیگ/ }))

    fireEvent.click(await pay('۱۰۸٬۰۰۰'))
    await until(() => expect(server().sent('POST', `${SERVICE}/renewal`)).toHaveLength(1))
    expect(server().sent('GET', `${SERVICE}/renewal`)[0]?.query.get('plan_id')).toBe('3')
    expect(server().sent('POST', `${SERVICE}/renewal`)[0]?.body).toMatchObject({ plan_id: 3, expected_amount: '108000.00' })
  })

  it('while the shop’s window is closed, says from when in the preview’s words, once, and offers no payment', async () => {
    const words = 'تمدید این سرویس وقتی ۲۰٪ از حجم یا کمتر مانده باشد ممکن است.'
    server().on('GET', `${SERVICE}/renewal`, refusal(422, words, { status: [words] }))
    open(service({ renewal_window: { open: false, opens_at: null, days_before: null, traffic_percent: 20 } }))

    await screen.findByText(words)
    expect(screen.getAllByText(/ممکن است/)).toHaveLength(1)
    expect(screen.queryByRole('button', { name: /پرداخت/ })).toBeNull()
  })
})
