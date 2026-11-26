import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import ExtrasScreen from '@/apps/app/screens/services/extras'
import { checkout } from '@/apps/app/screens/services/test-rows'
import * as app from '@/apps/app/test/app'
import { API, CARD, service, WALLET, walletRow } from '@/apps/app/test/rows'
import type { StoreExtraOffer, StoreExtrasResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «خرید اضافه» (screens/services/extras): the kinds the service can take now, each one's amounts with the customer's
 * price beside the shop's, an amount typed priced as the shop prices it, and the order sent to its own address with its
 * Idempotency-Key and the price shown — then the payment screen.
 */

const SERVICE = `${API}/subscriptions/9`
const PERSONAL = { source: 'personal', label: 'تخفیف اختصاصی شما ۱۰٪', percent: 10, ends_at: null } as const

const TRAFFIC: StoreExtraOffer = {
  price: '2000.00',
  offer_price: '1800.00',
  discount: PERSONAL,
  options: [
    { quantity: 10, price: '18000.00', offer_price: '16200.00' },
    { quantity: 50, price: '100000.00', offer_price: '90000.00' },
  ],
  custom: { min: 1, max: 100 },
  days: null,
}

const DEVICES: StoreExtraOffer = { price: null, offer_price: null, discount: null, options: [{ quantity: 1, price: '18000.00', offer_price: '18000.00' }], custom: null, days: 18 }

function open(extras: StoreExtrasResponse['extras'] = { traffic: TRAFFIC, time: null, devices: DEVICES }) {
  server()
    .on('GET', SERVICE, json({ subscription: service() }))
    .on('GET', `${SERVICE}/extras`, json({ extras }))
    .on('GET', `${API}/payment-methods`, json({ methods: [WALLET, CARD] }))
    .on('GET', `${API}/wallet`, json(walletRow()))
    .on('GET', `${API}/me`, json(app.account()))
  render(app.screen(<ExtrasScreen />, { at: '/services/9/extras', route: '/services/:id/extras' }))
}

describe('extras for a service', () => {
  it('offers the kinds on sale, and buys an amount at its price: its key, the price shown, then the payment', async () => {
    server().on('POST', `${SERVICE}/extra-traffic`, json(checkout('extra_traffic', '16200.00')))
    open()

    await screen.findByRole('radio', { name: 'حجم' })
    expect(screen.getByRole('radio', { name: 'کاربر' })).toBeTruthy()
    expect(screen.queryByRole('radio', { name: 'زمان' })).toBeNull()
    expect(screen.getByText('تخفیف اختصاصی شما ۱۰٪')).toBeTruthy()

    fireEvent.click(screen.getByRole('radio', { name: /۱۰ گیگابایت/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۱۶٬۲۰۰ تومان' }))

    await until(() => expect(sessionStorage.getItem('amobot-app:checkout:51')).not.toBeNull())
    const [sent] = server().sent('POST', `${SERVICE}/extra-traffic`)
    expect(sent?.body).toEqual({ gb: 10, method_id: 1, expected_amount: '16200.00' })
    expect(sent?.headers['idempotency-key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(server().sent('GET', `${API}/payment-methods`).at(-1)?.query.toString()).toBe('for=extra_traffic&amount=16200')
  })

  it('prices an amount typed as the shop does — the unit price times it, less the discount rounded down', async () => {
    server().on('POST', `${SERVICE}/extra-traffic`, json(checkout('extra_traffic', '45000')))
    open()

    fireEvent.click(await screen.findByRole('radio', { name: /مقدار دلخواه/ }))
    fireEvent.change(screen.getByLabelText('حجم (گیگابایت)'), { target: { value: '۲۵' } })
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۴۵٬۰۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-traffic`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-traffic`)[0]?.body).toEqual({ gb: 25, method_id: 1, expected_amount: '45000' })
  })

  it('prices an amount typed that is one of the options at the price of that option, as the shop does, and holds the order to it', async () => {
    server().on('POST', `${SERVICE}/extra-traffic`, json(checkout('extra_traffic', '16200.00')))
    open()

    fireEvent.click(await screen.findByRole('radio', { name: /مقدار دلخواه/ }))
    fireEvent.change(screen.getByLabelText('حجم (گیگابایت)'), { target: { value: '۱۰' } })
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۱۶٬۲۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-traffic`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-traffic`)[0]?.body).toEqual({ gb: 10, method_id: 1, expected_amount: '16200.00' })
  })

  it('sells an amount typed that is one of the options outside the bounds of a typed amount, as the shop does, and refuses any other there', async () => {
    server().on('POST', `${SERVICE}/extra-traffic`, json(checkout('extra_traffic', '16200.00')))
    open({ traffic: { ...TRAFFIC, custom: { min: 20, max: 40 } }, time: null, devices: DEVICES })

    fireEvent.click(await screen.findByRole('radio', { name: /مقدار دلخواه/ }))
    const field = screen.getByLabelText('حجم (گیگابایت)')
    fireEvent.change(field, { target: { value: '۴۵' } })
    expect(screen.getByText('عددی از ۲۰ تا ۴۰ بنویسید.')).toBeTruthy()
    // Settled, still nothing to pay.
    await act(() => new Promise((resolve) => setTimeout(resolve, 500)))
    expect(screen.queryByRole('button', { name: /^پرداخت/ })).toBeNull()

    fireEvent.change(field, { target: { value: '۱۰' } })
    expect(screen.queryByText('عددی از ۲۰ تا ۴۰ بنویسید.')).toBeNull()
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۱۶٬۲۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-traffic`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-traffic`)[0]?.body).toEqual({ gb: 10, method_id: 1, expected_amount: '16200.00' })
  })

  it('holds a typed count of devices that is one of the options to the price of that option', async () => {
    const devices: StoreExtraOffer = { ...DEVICES, price: '600.00', offer_price: '600.00', options: [{ quantity: 2, price: '1000.00', offer_price: '1000.00' }], custom: { min: 1, max: 5 } }
    server().on('POST', `${SERVICE}/extra-devices`, json(checkout('extra_devices', '1000.00')))
    open({ traffic: TRAFFIC, time: null, devices })

    fireEvent.click(await screen.findByRole('radio', { name: 'کاربر' }))
    fireEvent.click(screen.getByRole('radio', { name: /مقدار دلخواه/ }))
    fireEvent.change(screen.getByLabelText('تعداد کاربر'), { target: { value: '2' } })
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۱٬۰۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-devices`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-devices`)[0]?.body).toEqual({ devices: 2, method_id: 1, expected_amount: '1000.00' })
  })

  it('takes a free extra with one button, naming no card transfer while another way is offered', async () => {
    const free: StoreExtraOffer = {
      ...TRAFFIC,
      offer_price: '0.00',
      discount: { ...PERSONAL, label: 'تخفیف اختصاصی شما ۱۰۰٪', percent: 100 },
      options: [{ quantity: 10, price: '18000.00', offer_price: '0.00' }],
      custom: null,
    }
    server().on('POST', `${SERVICE}/extra-traffic`, json(checkout('extra_traffic', '0.00')))
    open({ traffic: free, time: null, devices: null })
    // The card first: a card-to-card payment of nothing would be the bot's phone rule's `card` moment.
    server().on('GET', `${API}/payment-methods`, json({ methods: [CARD, WALLET] }))

    fireEvent.click(await screen.findByRole('radio', { name: /۱۰ گیگابایت/ }))
    const take = await screen.findByRole('button', { name: 'دریافت رایگان' })
    await until(() => expect((take as HTMLButtonElement).disabled).toBe(false))
    expect(screen.queryByRole('radiogroup', { name: 'روش پرداخت' })).toBeNull()
    fireEvent.click(take)

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-traffic`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-traffic`)[0]?.body).toEqual({ gb: 10, method_id: WALLET.id, expected_amount: '0.00' })
  })

  it('says devices are priced for the days left of the period', async () => {
    server().on('POST', `${SERVICE}/extra-devices`, json(checkout('extra_devices', '18000.00')))
    open()

    fireEvent.click(await screen.findByRole('radio', { name: 'کاربر' }))
    expect(screen.getByText(/۱۸ روز مانده از دوره فعلی/)).toBeTruthy()
    fireEvent.click(screen.getByRole('radio', { name: /۱ کاربر/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'پرداخت ۱۸٬۰۰۰ تومان' }))

    await until(() => expect(server().sent('POST', `${SERVICE}/extra-devices`)).toHaveLength(1))
    expect(server().sent('POST', `${SERVICE}/extra-devices`)[0]?.body).toEqual({ devices: 1, method_id: 1, expected_amount: '18000.00' })
  })

  it('draws no empty line over devices without a discount', async () => {
    open()

    fireEvent.click(await screen.findByRole('radio', { name: 'کاربر' }))
    await screen.findByText(/۱۸ روز مانده از دوره فعلی/)
    expect([...document.querySelectorAll('div.flex-wrap')].filter((line) => line.childElementCount === 0)).toEqual([])
  })

  it('keeps the checkout when a read again fails; an offer not read again holds pay until it is', async () => {
    open()
    fireEvent.click(await screen.findByRole('radio', { name: /۱۰ گیگابایت/ }))
    const pay = () => screen.getByRole('button', { name: 'پرداخت ۱۶٬۲۰۰ تومان' }) as HTMLButtonElement
    await until(() => expect(pay().disabled).toBe(false))

    server().on('GET', SERVICE, refusal(500, 'خطای سرور')).on('GET', `${SERVICE}/extras`, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.subscription(9) }).catch(() => undefined)

    await screen.findByText(/خطای سرور/)
    expect(screen.getByRole('radio', { name: /۱۰ گیگابایت/ }).getAttribute('aria-checked')).toBe('true')
    expect(pay().disabled).toBe(true)

    server().on('GET', `${SERVICE}/extras`, json({ extras: { traffic: TRAFFIC, time: null, devices: DEVICES } }))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    await until(() => expect(pay().disabled).toBe(false))
    expect(screen.queryByText(/خطای سرور/)).toBeNull()
  })

  it('with nothing on sale for it, says so', async () => {
    open({ traffic: null, time: null, devices: null })

    await screen.findByText('الان چیزی برای این سرویس فروخته نمی‌شود')
  })
})
