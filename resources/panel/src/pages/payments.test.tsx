import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import type { DismissReceiptsResponse, PaymentRow } from '@/lib/api-types'
import { PaymentsPage } from '@/pages/payments'
import { signedIn, until } from '@/test/render'
import { onlinePaymentRow, paymentRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The payments screen (pages/payments): what each payment paid for — the amount an order of a service's extra traffic or
 * time brings, else the plan, else the order's type — over the order's type and number; an online payment paid after its
 * order closed said to have gone to the wallet, one held for support said to be; and the receipts waiting for review
 * dismissed at once — those ticked, or
 * every one waiting, a hundred a request —, after a confirmation that says what it does, with a note for the customers.
 */

const DISMISS = '/api/admin/payments/dismiss'

/** The screen listing `payments` (at `at`), `waiting` receipts in review in the shop. */
function open(payments: PaymentRow[], { at = '/payments', waiting = 0 }: { at?: string; waiting?: number } = {}) {
  server().on(
    'GET',
    '/api/admin/payments',
    json({ payments, meta: { page: 1, per_page: 25, total: payments.length, last_page: 1, sort: 'created', dir: 'desc', awaiting_review: waiting, paid: 0, paid_amount: '0.00' } }),
  )
  render(<PaymentsPage />, { wrapper: signedIn({ at }).wrapper })
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

describe('an online payment', () => {
  it('that came after its order closed says under its status that it went to the wallet', async () => {
    open([
      onlinePaymentRow({ id: 7, status: 'paid', to_wallet: true, description: 'سفارش پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ به کیف پول مشتری رفت' }),
      onlinePaymentRow({ id: 8, order: { ...paymentRow().order, id: 13 } }),
    ])

    const status = (await screen.findByText('به کیف پول مشتری رفت')).closest('div')
    expect(status?.textContent?.replace(/\s+/g, ' ').trim()).toBe('پرداخت‌شده به کیف پول مشتری رفت')
    expect(screen.getAllByText('به کیف پول مشتری رفت')).toHaveLength(1)
  })

  it('held for support says so under its status — still waiting, or since cancelled by support —, words apart', async () => {
    const note = 'درگاه این پرداخت را با سفارش یا مبلغ دیگری تایید کرد؛ پشتیبانی باید آن را در درگاه بررسی کند'
    open([
      onlinePaymentRow({ id: 7, held: true, description: note, actions: { ...onlinePaymentRow().actions, check: false } }),
      onlinePaymentRow({ id: 8, status: 'cancelled', held: true, reviewer: 'root', description: null, order: { ...paymentRow().order, id: 13 } }),
      onlinePaymentRow({ id: 9, order: { ...paymentRow().order, id: 14 } }),
    ])

    const statuses = (await screen.findAllByText('نگه داشته شده برای پشتیبانی')).map((caption) => caption.closest('div')?.textContent?.replace(/\s+/g, ' ').trim())
    expect(statuses).toEqual(['در انتظار پرداخت نگه داشته شده برای پشتیبانی', 'لغوشده نگه داشته شده برای پشتیبانی توسط root'])
  })
})

/** Two receipts waiting for review, and a payment paid. */
const rows = () => [paymentRow({ id: 7 }), paymentRow({ id: 8 }), paymentRow({ id: 9, status: 'paid' })]

describe('dismissing receipts', () => {
  it('ticks only the receipts waiting for review, and rejects those ticked with a note once confirmed', async () => {
    vi.spyOn(toast, 'success')
    server().on('POST', DISMISS, json({ dismissed: 1, skipped: 1, left: 0, until: null } satisfies DismissReceiptsResponse))
    open(rows(), { waiting: 2 })
    await screen.findByRole('checkbox', { name: 'انتخاب رسید پرداخت #7' })

    expect(screen.queryByRole('checkbox', { name: 'انتخاب رسید پرداخت #9' })).toBeNull()
    fireEvent.click(screen.getByRole('checkbox', { name: 'انتخاب همه رسیدهای در انتظار این صفحه' }))
    const toolbar = screen.getByRole('toolbar', { name: 'رسیدهای انتخاب‌شده' })
    expect(within(toolbar).getByText('۲ رسید انتخاب شده')).toBeTruthy()

    fireEvent.click(within(toolbar).getByRole('button', { name: 'رد رسیدهای انتخاب‌شده' }))
    const dialog = await screen.findByRole('dialog', { name: 'رد ۲ رسید' })
    expect(within(dialog).getByText(/پرداختش ناموفق می‌شود و سفارشش باز می‌ماند/)).toBeTruthy()
    fireEvent.change(within(dialog).getByLabelText('دلیل رد برای مشتری (اختیاری)'), { target: { value: 'رسید تکراری بود' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'رد رسیدها' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('۱ رسید رد شد', { description: '۱ رسید پیش‌تر تعیین تکلیف شده بود.' }))
    expect(
      server()
        .sent('POST', DISMISS)
        .map((request) => request.body),
    ).toEqual([{ ids: [7, 8], note: 'رسید تکراری بود' }])
    await until(() => expect(screen.queryByRole('toolbar', { name: 'رسیدهای انتخاب‌شده' })).toBeNull())
    expect(server().sent('GET', '/api/admin/payments').length).toBeGreaterThan(1)
  })

  it('takes every receipt waiting, a hundred a request until none is left of those that had come when it began', async () => {
    vi.spyOn(toast, 'success')
    const began = '2026-10-14T10:00:00+00:00'
    const answers: DismissReceiptsResponse[] = [
      { dismissed: 100, skipped: 0, left: 30, until: began },
      { dismissed: 30, skipped: 0, left: 0, until: began },
    ]
    server().on('POST', DISMISS, () => json(answers.shift()))
    open(rows(), { at: '/payments?status=awaiting_review', waiting: 130 })

    fireEvent.click(await screen.findByRole('button', { name: 'رد همه رسیدهای در انتظار' }))
    const dialog = await screen.findByRole('dialog', { name: 'رد همه رسیدهای در انتظار' })
    expect(within(dialog).getByText('همه ۱۳۰ رسیدی که در انتظار بررسی است رد می‌شود.')).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'رد رسیدها' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('۱۳۰ رسید رد شد', { description: undefined }))
    expect(
      server()
        .sent('POST', DISMISS)
        .map((request) => request.body),
    ).toEqual([
      { all: true, note: '' },
      { all: true, note: '', until: began },
    ])
  })

  it('is offered for every receipt only on the waiting tab, while one waits', async () => {
    open(rows(), { waiting: 2 })
    await screen.findByRole('checkbox', { name: 'انتخاب رسید پرداخت #7' })

    expect(screen.queryByRole('button', { name: 'رد همه رسیدهای در انتظار' })).toBeNull()
  })

  it('is not offered while a search or a filter shows fewer receipts than it would take', async () => {
    open(rows(), { at: '/payments?status=awaiting_review&search=ali', waiting: 2 })
    await screen.findByRole('checkbox', { name: 'انتخاب رسید پرداخت #7' })

    expect(screen.queryByRole('button', { name: 'رد همه رسیدهای در انتظار' })).toBeNull()
  })

  it('says a refusal in the dialog', async () => {
    const refused = 'هر بار حداکثر ۱۰۰ رسید یکجا رد می‌شود.'
    server().on('POST', DISMISS, refusal(422, refused, { ids: [refused] }))
    open(rows(), { waiting: 2 })

    fireEvent.click(await screen.findByRole('checkbox', { name: 'انتخاب رسید پرداخت #7' }))
    fireEvent.click(screen.getByRole('button', { name: 'رد رسیدهای انتخاب‌شده' }))
    const dialog = await screen.findByRole('dialog', { name: 'رد ۱ رسید' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'رد رسیدها' }))

    expect(await within(dialog).findByText(refused)).toBeTruthy()
  })
})
