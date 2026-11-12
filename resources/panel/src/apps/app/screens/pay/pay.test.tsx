import { act, fireEvent, render, screen } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { rememberCheckout } from '@/apps/app/lib/checkout'
import PayScreen from '@/apps/app/screens/pay/pay'
import * as app from '@/apps/app/test/app'
import { API, GATEWAY, storeCheckout, storeOrder, storePayment, storeSubscription, WALLET } from '@/apps/app/test/rows'
import type { StoreOrder, StorePayLink } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server, withHeaders } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * An order's payment (screens/pay/pay): what the checkout answered and the order as it stands decide it — a card to pay
 * (its number and the sum in Rial copied as a banking app takes them) and the receipt sent from the phone, then waiting
 * for support; a gateway's page opened in Telegram and asked about — opened no more once its time is up —, the order
 * watched until it is delivered; a service delivered at once. Reopened where the checkout's answer is gone, the order
 * alone says what to do. Minimised in Telegram, the screen reads nothing; back in front, it asks the gateway.
 */

/** Where the payment led: the service, or a checkout again. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

/** Order 52's payment, its order read as `order` (a function of how often it was read). */
function open(order: StoreOrder | (() => StoreOrder)) {
  server().on('GET', `${API}/orders/52`, () => json({ order: typeof order === 'function' ? order() : order }))
  render(
    app.screen(
      <Routes>
        <Route path="pay/:orderId" element={<PayScreen />} />
        <Route path="*" element={<Where />} />
      </Routes>,
      { at: '/pay/52', route: '*' },
    ),
  )
}

const where = () => screen.getByRole('status', { name: 'آدرس' }).textContent

describe('paying by card', () => {
  it('shows the card and the sum to copy, and sends the receipt picked on the phone', async () => {
    const copy = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
    const waiting = storeOrder({ payments: [storePayment({ status: 'awaiting_review', receipt: { sent_at: '2026-10-07T12:16:41+00:00' } })] })
    server().on('POST', `${API}/payments/89/receipt`, json({ order: waiting }))
    rememberCheckout(storeCheckout())
    open(storeOrder())

    fireEvent.click(await screen.findByText('6037 9977 0000 1119'))
    expect(copy).toHaveBeenLastCalledWith('6037997700001119')
    expect(screen.getByText('محمد کریمی')).toBeTruthy()
    expect(screen.getByText('ملت')).toBeTruthy()
    expect(screen.getByText('شماره پیگیری را نگه دارید.')).toBeTruthy()
    // Toman shown, Rial copied: the digits a banking app takes.
    expect(screen.getByText('۱٬۲۰۰٬۰۰۰ ریال')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: /کپی به ریال/ }))
    expect(copy).toHaveBeenLastCalledWith('1200000')

    const send = screen.getByRole('button', { name: 'ارسال رسید' }) as HTMLButtonElement
    expect(send.disabled).toBe(true)
    const picture = new File(['receipt'], 'receipt.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('تصویر رسید'), { target: { files: [picture] } })
    // Checked (and a large photo drawn smaller) as it is picked.
    expect(await screen.findByRole('img', { name: 'تصویر رسید' })).toBeTruthy()
    fireEvent.change(screen.getByRole('textbox', { name: /توضیح برای پشتیبانی/ }), { target: { value: 'چهار رقم آخر کارت: ۴۴۲۱' } })
    fireEvent.click(send)

    await screen.findByRole('heading', { name: 'در انتظار بررسی' })
    const form = server().sent('POST', `${API}/payments/89/receipt`)[0]?.body as FormData
    expect(form.get('file')).toBe(picture)
    expect(form.get('note')).toBe('چهار رقم آخر کارت: ۴۴۲۱')
  })

  it('inside Telegram, keeps «ارسال رسید» on its main button inactive until a picture is picked — its spinner never turns it on', async () => {
    const { mainButton } = app.telegram()
    rememberCheckout(storeCheckout())
    open(storeOrder())

    await screen.findByText('6037 9977 0000 1119')
    await until(() => expect(mainButton.params).toMatchObject({ text: 'ارسال رسید', is_visible: true, is_active: false }))
    fireEvent.change(screen.getByLabelText('تصویر رسید'), { target: { files: [new File(['receipt'], 'receipt.png', { type: 'image/png' })] } })
    await screen.findByRole('img', { name: 'تصویر رسید' })
    expect(mainButton.params.is_active).toBe(true)
  })

  it('reopened without the checkout’s answer, sends the customer for the card again — the same order shows it', async () => {
    open(storeOrder())

    await screen.findByText(/کارت این پرداخت در این صفحه نیست/)
    // The sum in Toman: its number large, its unit beside it.
    expect(screen.getByText('۱۲۰٬۰۰۰').parentElement?.textContent).toBe('۱۲۰٬۰۰۰تومان')
    fireEvent.click(screen.getByRole('button', { name: 'نمایش دوباره کارت' }))
    await until(() => expect(where()).toBe('/buy/plans/3'))
  })
})

describe('paying online', () => {
  const link: StorePayLink = {
    payment_id: 90,
    url: 'https://pay.example.net/pay?authority=A1b2',
    expires_at: new Date(Date.now() + 20 * 60_000).toISOString(),
    amount: '122037.00',
    amount_rial: '1220370',
    fee: '2000.00',
    card: null,
  }
  const pending = storeOrder({ id: 52, payments: [storePayment({ id: 90, method: GATEWAY, amount: '122037.00', link })] })
  const delivered = storeOrder({ status: 'fulfilled', subscription: { id: 18, name: 'ali_3' }, payments: [storePayment({ id: 90, method: GATEWAY, status: 'paid', amount: '122037.00' })] })

  it('opens the gateway’s page in Telegram, asks the gateway, and shows the service once it is paid', async () => {
    const { app: telegram, mainButton } = app.telegram()
    let checks = 0
    server().on('POST', `${API}/payments/90/check`, () =>
      ++checks === 1
        ? json({ check: { state: 'waiting', order: pending, subscription: null } })
        : json({ check: { state: 'paid', order: delivered, subscription: storeSubscription({ id: 18, name: 'ali_3' }) } }),
    )
    rememberCheckout(storeCheckout({ outcome: 'link', order: pending, transfer: null, link }))
    open(pending)

    await screen.findByText('۱۲۲٬۰۳۷')
    expect(screen.getByText(/شامل ۲٬۰۰۰ تومان کارمزد درگاه/)).toBeTruthy()
    expect(mainButton.params).toMatchObject({ text: 'پرداخت در درگاه', is_visible: true, has_shine_effect: true })
    mainButton.press()
    expect(telegram.openLink).toHaveBeenCalledWith(link.url)

    fireEvent.click(screen.getByRole('button', { name: 'بررسی پرداخت' }))
    expect((await screen.findByRole('alert')).textContent).toMatch(/^هنوز پرداختی ثبت نشده است/)

    fireEvent.click(screen.getByRole('button', { name: 'بررسی پرداخت' }))
    await screen.findByRole('heading', { name: 'سرویس شما آماده است' })
    expect(mainButton.params.text).toBe('مشاهده سرویس')
    mainButton.press()
    await until(() => expect(where()).toBe('/services/18'))
  })

  it('reads the order no more while the app is minimised in Telegram, and asks the gateway as it comes back', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { app: telegram, mainButton } = app.telegram()
    server().on('POST', `${API}/payments/90/check`, json({ check: { state: 'waiting', order: pending, subscription: null } }))
    rememberCheckout(storeCheckout({ outcome: 'link', order: pending, transfer: null, link }))
    open(pending)
    const reads = () => server().sent('GET', `${API}/orders/52`).length

    await screen.findByText('۱۲۲٬۰۳۷')
    await until(() => expect(reads()).toBe(1))
    mainButton.press()
    app.tell(telegram, 'deactivated')
    await act(() => vi.advanceTimersByTimeAsync(30_000))
    expect(reads()).toBe(1)
    expect(server().sent('POST', `${API}/payments/90/check`)).toHaveLength(0)

    app.tell(telegram, 'activated')
    await until(() => expect(server().sent('POST', `${API}/payments/90/check`)).toHaveLength(1))
    await act(() => vi.advanceTimersByTimeAsync(5_000))
    await until(() => expect(reads()).toBe(2))
  })

  it('once its time is up, opens the gateway’s page no more: asks the gateway what came of it, or pays again', async () => {
    const { app: telegram, mainButton } = app.telegram()
    const ending = storeOrder({ id: 52, payments: [storePayment({ id: 90, method: GATEWAY, amount: '122037.00', link: { ...link, expires_at: new Date(Date.now() + 300).toISOString() } })] })
    server().on('POST', `${API}/payments/90/check`, json({ check: { state: 'waiting', order: ending, subscription: null } }))
    open(ending)

    await screen.findByText('۱۲۲٬۰۳۷')
    expect(mainButton.params.text).toBe('پرداخت در درگاه')

    await screen.findByRole('heading', { name: 'مهلت پرداخت تمام شد' })
    expect(mainButton.params.text).toBe('بررسی پرداخت')
    mainButton.press()
    expect(telegram.openLink).not.toHaveBeenCalled()
    expect((await screen.findByRole('alert')).textContent).toMatch(/^هنوز پرداختی ثبت نشده است/)

    fireEvent.click(screen.getByRole('button', { name: 'پرداخت دوباره' }))
    await until(() => expect(where()).toBe('/buy/plans/3'))
  })

  it('holds «بررسی پرداخت» for the Retry-After of a check asked too often', async () => {
    server().on('POST', `${API}/payments/90/check`, withHeaders(refusal(429, 'پرداخت را زیاد بررسی کرده‌اید؛ کمی بعد دوباره امتحان کنید.'), { 'Retry-After': '30' }))
    open(pending)

    fireEvent.click(await screen.findByRole('button', { name: 'بررسی پرداخت' }))
    expect((await screen.findByRole('alert')).textContent).toMatch(/زیاد بررسی کرده‌اید/)
    expect((screen.getByRole('button', { name: 'بررسی پرداخت' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('knows a payment held for support from the order: asks no check, says so in the shop’s words, with its tracking code and support’s door', async () => {
    const held = 'پرداخت سفارش #52 برای بررسی به پشتیبانی رسیده است؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.'
    const { app: telegram, mainButton } = app.telegram()
    open(storeOrder({ payments: [storePayment({ id: 90, method: GATEWAY, amount: '122037.00', note: held, held: true, reference: 'A00000000000000000000000000412345' })] }))

    await screen.findByRole('heading', { name: 'پرداخت نزد پشتیبانی است' })
    expect(screen.getByText(held)).toBeTruthy()
    expect(screen.getByRole('button', { name: /کد رهگیری A00000000000000000000000000412345/ })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'بررسی پرداخت' })).toBeNull()
    expect(mainButton.params.text).toBe('پیام به پشتیبانی')
    mainButton.press()

    expect(telegram.openTelegramLink).toHaveBeenCalledWith('https://t.me/sample_support')
    expect(server().sent('POST', `${API}/payments/90/check`)).toHaveLength(0)
  })

  it('says a payment a check finds held for support in the shop’s words, with support’s door', async () => {
    const held = 'پرداخت سفارش #52 برای بررسی به پشتیبانی رسیده است؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.'
    const { app: telegram, mainButton } = app.telegram()
    server().on('POST', `${API}/payments/90/check`, refusal(422, held, { status: [held] }))
    // Its page gone from the order: what came of it is asked.
    open(storeOrder({ payments: [storePayment({ id: 90, method: GATEWAY, amount: '122037.00', note: held })] }))

    await screen.findByRole('heading', { name: 'در انتظار نتیجه پرداخت' })
    expect(mainButton.params.text).toBe('بررسی پرداخت')
    mainButton.press()

    expect((await screen.findByRole('alert')).textContent).toContain(held)
    fireEvent.click(screen.getByRole('button', { name: 'پیام به پشتیبانی' }))
    expect(telegram.openTelegramLink).toHaveBeenCalledWith('https://t.me/sample_support')
  })
})

describe('an order paid at once', () => {
  it('shows the service delivered, its link to copy, and opens it', async () => {
    const order = storeOrder({ status: 'fulfilled', subscription: { id: 17, name: 'ali_2' }, payments: [storePayment({ id: 88, method: WALLET, status: 'paid' })] })
    rememberCheckout(storeCheckout({ outcome: 'settled', order, transfer: null, subscription: storeSubscription() }))
    open(order)

    await screen.findByRole('heading', { name: 'سرویس شما آماده است' })
    expect(screen.getByRole('button', { name: /https:\/\/sub\.example\.net\/sub\/8f1c2e7a9b0d/ })).toBeTruthy()
    expect(screen.getByText('ali_2')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'مشاهده سرویس' }))
    await until(() => expect(where()).toBe('/services/17'))
  })
})
