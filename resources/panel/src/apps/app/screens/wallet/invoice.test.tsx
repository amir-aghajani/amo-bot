import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import InvoiceScreen from '@/apps/app/screens/wallet/invoice'
import { orderRow, paymentRow } from '@/apps/app/screens/wallet/test-rows'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreOrder, StoreOrderResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «فاکتور» (screens/wallet/invoice): an order's invoice — its number and status, what was bought, the amounts with each
 * discount, the payment with its gateway's tracking code when it has one —, «پرداخت» while it awaits one (not while
 * support holds its payment), and «ذخیره تصویر»: the invoice drawn on a canvas and shown as a picture to keep.
 */

/** Order 51 paid online: the gateway's tracking code on its payment. */
const PAID_ONLINE = orderRow({
  payments: [paymentRow({ method: { id: 3, label: 'پرداخت آنلاین', kind: 'online', min_amount: null, max_amount: null }, receipt: null, reference: 'A00000000000000000000000000412345' })],
})

function open(order: StoreOrder = orderRow()) {
  server().on('GET', `${API}/orders/${order.id}`, json({ order } satisfies StoreOrderResponse))
  render(app.screen(<InvoiceScreen />, { at: `/orders/${order.id}`, route: '/orders/:id' }))
}

/** A 2D context that draws nothing and keeps every text drawn. */
function fakeCanvas() {
  const texts: string[] = []
  const context = new Proxy(
    { texts },
    {
      get: (target, name) => {
        if (name === 'fillText') return (text: string) => texts.push(text)
        if (name === 'measureText') return (text: string) => ({ width: text.length * 8 })
        if (name === 'createLinearGradient') return () => ({ addColorStop: () => undefined })
        if (name in target) return target[name as keyof typeof target]
        return () => undefined
      },
      set: () => true,
    },
  )
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => context as unknown as CanvasRenderingContext2D)
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(new Blob(['png'], { type: 'image/png' })))
  vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:invoice-51')
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)

  return texts
}

describe('the invoice', () => {
  it('shows the facts of the order: what was bought, each discount, the total and the payment — a card payment with no tracking code', async () => {
    open()

    const invoice = await screen.findByRole('article', { name: 'فاکتور #51' })
    expect(invoice.textContent).toContain('فروشگاه نمونه')
    expect(invoice.textContent).toContain('تکمیل‌شده')
    const bought = within(within(invoice).getByRole('region', { name: 'شرح خرید' }))
    expect(bought.getByText('یک ماهه ۵۰ گیگ')).toBeTruthy()
    expect(bought.getByText('آلمان')).toBeTruthy()
    expect(bought.getByText('ali_2')).toBeTruthy()
    const amounts = within(within(invoice).getByRole('region', { name: 'مبلغ' }))
    expect(amounts.getByText('۱۳۳٬۳۳۴ تومان')).toBeTruthy()
    expect(amounts.getByText('کد NOWRUZ').nextSibling?.textContent).toBe('\u2066−۱۳٬۳۳۴\u2069 تومان')
    expect(amounts.getByText('مبلغ کل').nextSibling?.textContent).toBe('۱۲۰٬۰۰۰ تومان')
    const payment = within(within(invoice).getByRole('region', { name: 'پرداخت' }))
    expect(payment.getByText('کارت به کارت (ملت)')).toBeTruthy()
    expect(payment.getByText('مبلغ پرداخت').nextSibling?.textContent).toBe('۱۲۰٬۰۰۰ تومان')
    // The order's number is the invoice's: a card payment has no tracking code of its own.
    expect(payment.queryByText('کد رهگیری')).toBeNull()
    expect(invoice.textContent).not.toContain('88')
    expect(screen.getByRole('link', { name: /مشاهده سرویس/ }).getAttribute('href')).toBe('/services/17')
    expect(screen.queryByRole('button', { name: 'پرداخت' })).toBeNull()
  })

  it('prints an online payment’s tracking code at its gateway', async () => {
    open(PAID_ONLINE)

    const invoice = await screen.findByRole('article', { name: 'فاکتور #51' })
    const payment = within(within(invoice).getByRole('region', { name: 'پرداخت' }))
    expect(payment.getByText('کد رهگیری').nextSibling?.textContent).toBe('A00000000000000000000000000412345')
  })

  it('offers no «پرداخت» while support holds the order’s online payment, and says why', async () => {
    open(
      orderRow({
        id: 53,
        status: 'pending',
        fulfilled_at: null,
        subscription: null,
        payments: [
          paymentRow({
            id: 93,
            method: { id: 3, label: 'پرداخت آنلاین', kind: 'online', min_amount: null, max_amount: null },
            status: 'pending',
            paid_at: null,
            receipt: null,
            held: true,
            note: 'پرداخت سفارش #53 برای بررسی به پشتیبانی رسیده است.',
          }),
        ],
      }),
    )

    expect(await screen.findByText(/دوباره پرداخت نکنید/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'پرداخت' })).toBeNull()
  })

  it('offers «پرداخت» while the order awaits it, and lists every payment tried with its note', async () => {
    open(
      orderRow({
        id: 52,
        status: 'pending',
        fulfilled_at: null,
        subscription: null,
        payments: [paymentRow({ id: 92, status: 'pending', paid_at: null, receipt: null }), paymentRow({ id: 91, status: 'failed', paid_at: null, note: 'رسید ناخوانا بود.' })],
      }),
    )

    expect(await screen.findByText('رسید ناخوانا بود.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'پرداخت' }))

    await until(() => expect(screen.queryByRole('article')).toBeNull())
  })

  it('says why a failed order failed', async () => {
    open(orderRow({ status: 'failed', failure: 'delivery', fulfilled_at: null, subscription: null }))

    expect(await screen.findByText(/پشتیبانی آن را کامل می‌کند/)).toBeTruthy()
  })

  it('says when there is no such order', async () => {
    server().on('GET', `${API}/orders/77`, refusal(404, 'سفارش پیدا نشد.'))
    render(app.screen(<InvoiceScreen />, { at: '/orders/77', route: '/orders/:id' }))

    expect(await screen.findByText('این سفارش پیدا نشد')).toBeTruthy()
  })

  it('draws the invoice on a canvas and shows it as a picture to keep', async () => {
    const texts = fakeCanvas()
    open(PAID_ONLINE)

    fireEvent.click(await screen.findByRole('button', { name: 'ذخیره تصویر فاکتور' }))

    const sheet = within(await screen.findByRole('dialog'))
    expect(sheet.getByRole('img', { name: 'فاکتور #51' }).getAttribute('src')).toBe('blob:invoice-51')
    expect(sheet.getByText('برای ذخیره، روی تصویر نگه دارید.')).toBeTruthy()
    expect(sheet.getByRole('link', { name: 'دانلود تصویر' }).getAttribute('download')).toBe('invoice-51.png')
    expect(texts).toEqual(
      expect.arrayContaining([
        'فروشگاه نمونه',
        'فاکتور الکترونیکی',
        '#51',
        'تکمیل‌شده',
        'مبلغ کل',
        '۱۲۰٬۰۰۰ تومان',
        'کد رهگیری',
        'A00000000000000000000000000412345',
        'با سپاس از خرید شما',
        '@sample_bot',
      ]),
    )
  })

  it('reads only a whole number as written for the order', () => {
    for (const id of ['0x1F', '1e2', '%207%20', '0']) {
      const { unmount } = render(app.screen(<InvoiceScreen />, { at: `/orders/${id}`, route: '/orders/:id' }))
      expect(screen.getByText('این سفارش پیدا نشد')).toBeTruthy()
      unmount()
    }
    expect(server().requests).toEqual([])
  })

  it('keeps the invoice when the order read again fails', async () => {
    open()
    await screen.findByRole('button', { name: 'ذخیره تصویر فاکتور' })

    server().on('GET', `${API}/orders/51`, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.order(51) }).catch(() => undefined)

    expect(screen.getByRole('button', { name: 'ذخیره تصویر فاکتور' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
