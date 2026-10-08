import { useState } from 'react'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ReviewModal } from '@/components/payments/review-modal'
import type { PaymentRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import type { NextRow } from '@/lib/use-next-row'
import { providers, until } from '@/test/render'
import { paymentRow } from '@/test/rows'
import { json, refusal, server, type Answer } from '@/test/server'

/*
 * A payment as the admin reviews it (components/payments/review-modal): what approving brings in its own words, the
 * method's card and holder each in its own direction, a reminder that says when it could not be sent and why, a
 * receipt the browser did not draw explained in the server's words (a download offered only when there is a file), and
 * «بعدی» — a decision keeps the dialog on the decided payment while a next one waits, the dialog closing after the last.
 * An operation running holds every button, the one pressed keeping the focus; once it is over, the focus is where the
 * payment's new state leads — never on the document —, and a refusal because the payment moved on is said once.
 */

const RECEIPT = '/api/admin/payments/7/receipt'

function show(payment: PaymentRow, next: Partial<NextRow> = {}) {
  const props = { onClose: vi.fn(), onReviewed: vi.fn(), onStale: vi.fn(), next: { available: false, busy: false, go: vi.fn(), ...next } }
  render(<ReviewModal payment={payment} {...props} />, { wrapper: providers().wrapper })
  return props
}

const dialog = () => screen.getByRole('dialog', { name: `پرداخت ${idLabel(7)}` })
const button = (name: string | RegExp) => within(dialog()).getByRole('button', { name })

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'warning')
})

describe('what it says', () => {
  it('words approving by what the payment brings, and as the admin’s own call without a receipt', () => {
    show(paymentRow())
    expect(button('تایید و شارژ کیف پول')).toBeTruthy()
  })

  it('words a purchase approved by hand as a delivery', () => {
    show(paymentRow({ status: 'pending', receipt: null, order: { ...paymentRow().order, type: 'purchase', plan: 'طلایی' } }))
    expect(button('تایید دستی و تحویل')).toBeTruthy()
  })

  it('sets the card apart left to right, the holder’s name as written', () => {
    show(paymentRow())
    const card = within(dialog()).getByText('6037 •••• •••• 1119')
    expect(card.getAttribute('dir')).toBe('ltr')
    const holder = within(dialog()).getByText('امیر رضایی')
    expect([holder.getAttribute('dir'), holder.className]).toEqual([null, ''])
  })

  it('says on the receipt what it opens — never in a title alone —, and opens it at full size, with its download', async () => {
    show(paymentRow())
    const receipt = within(dialog()).getByRole('button', { name: new RegExp(`رسید پرداخت ${idLabel(7)}.*نمایش در اندازه کامل`) })
    expect(receipt.getAttribute('title')).toBeNull()
    expect(within(receipt).getByRole('img').getAttribute('src')).toBe(`${RECEIPT}?shop=1`)

    fireEvent.click(receipt)
    const full = await screen.findByRole('dialog', { name: `رسید پرداخت ${idLabel(7)}` })
    expect(
      within(full)
        .getByRole('link', { name: /دانلود رسید/ })
        .getAttribute('href'),
    ).toBe(`${RECEIPT}?shop=1`)
  })

  it('keeps its status and who decided it two words apart, for assistive tech too', () => {
    show(paymentRow({ status: 'failed', reviewer: '@agent_shop_bot', actions: { ...paymentRow().actions, reject: false } }))

    const status = within(dialog()).getByText('وضعیت').nextElementSibling
    expect(status?.textContent?.replace(/\s+/g, ' ').trim()).toBe('ناموفق توسط @agent_shop_bot')
  })
})

/** The dialog as the payments page holds it: an operation's answer is the payment it shows, and a refusal reads it again — as `refreshed`. */
function Reviewing({ first, refreshed }: { first: PaymentRow; refreshed?: PaymentRow }) {
  const [payment, setPayment] = useState(first)
  return <ReviewModal payment={payment} onClose={vi.fn()} onReviewed={setPayment} onStale={() => refreshed && setPayment(refreshed)} next={{ available: false, busy: false, go: vi.fn() }} />
}

describe('an operation', () => {
  it('running holds every button — the one pressed keeps the focus, turning — and a press meanwhile does nothing', async () => {
    const answer = server().hold('POST', '/api/admin/payments/7/approve')
    show(paymentRow())
    const approve = button('تایید و شارژ کیف پول')

    approve.focus()
    fireEvent.click(approve)
    await until(() => expect(answer.waiting).toBe(1))

    expect(approve.hasAttribute('disabled')).toBe(false)
    expect([approve.getAttribute('aria-disabled'), approve.getAttribute('aria-busy')]).toEqual(['true', 'true'])
    expect(document.activeElement).toBe(approve)
    const reject = button('رد کردن')
    expect([reject.hasAttribute('disabled'), reject.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    fireEvent.click(reject)
    expect(within(dialog()).queryByLabelText('دلیل رد کردن')).toBeNull()

    await act(async () => answer.answer(refusal(500, 'خطای سرور.')))
  })

  it('whose delivery then fails leaves the focus on what is offered now: the retry', async () => {
    const delivered = paymentRow({
      status: 'paid',
      reviewer: 'root',
      order: { ...paymentRow().order, status: 'failed', notes: 'پنل «آلمان»: پاسخ نداد.' },
      actions: { approve: false, reject: false, cancel: false, remind: false, retry: true, refund: true },
    })
    server().on('POST', '/api/admin/payments/7/approve', json({ payment: delivered }))
    vi.spyOn(toast, 'error')
    render(<Reviewing first={paymentRow()} />, { wrapper: providers().wrapper })
    const approve = button('تایید و شارژ کیف پول')

    approve.focus()
    fireEvent.click(approve)

    await until(() => expect(document.activeElement?.textContent).toBe('تلاش دوباره برای تحویل'))
    expect(toast.error).toHaveBeenCalled()
  })

  it('refused because the payment moved on says so once, in the server’s words, though nothing is left to do — the focus on it', async () => {
    const decided = 'این پرداخت را نمی‌شود تایید کرد؛ یا پرداخت‌شده است، یا سفارشش دیگر باز نیست.'
    const cancelled = paymentRow({ status: 'cancelled', actions: { approve: false, reject: false, cancel: false, remind: false, retry: false, refund: false } })
    server().on('POST', '/api/admin/payments/7/approve', refusal(422, decided, { status: [decided] }))
    render(<Reviewing first={paymentRow()} refreshed={cancelled} />, { wrapper: providers().wrapper })
    const approve = button('تایید و شارژ کیف پول')

    approve.focus()
    fireEvent.click(approve)

    await until(() => expect(within(dialog()).queryByRole('button', { name: 'تایید و شارژ کیف پول' })).toBeNull())
    expect(within(dialog()).getAllByText(decided)).toHaveLength(1)
    expect(within(dialog()).queryByText(/همین الان تغییر کرد/)).toBeNull()
    expect(document.activeElement?.textContent).toContain(decided)
  })
})

describe('a refund', () => {
  it('holds its note to the wallet line it is written on', () => {
    show(
      paymentRow({
        status: 'paid',
        reviewer: 'root',
        order: { ...paymentRow().order, type: 'purchase' },
        actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false, refund: true },
      }),
    )
    fireEvent.click(button('بازپرداخت به کیف پول'))

    expect(within(dialog()).getByLabelText('توضیح').getAttribute('maxlength')).toBe('190')
    expect(within(dialog()).getByText('۰ از ۱۹۰ کاراکتر')).toBeTruthy()
  })
})

describe('a reminder', () => {
  const remind = (answer: Answer) => {
    server().on('POST', '/api/admin/payments/7/remind', answer)
    show(paymentRow({ status: 'pending', receipt: null, actions: { ...paymentRow().actions, approve: true, reject: false, remind: true } }))
    fireEvent.click(button('یادآوری به مشتری'))
  }

  it('says it was sent when the customer was told', async () => {
    remind(json({ payment: paymentRow({ status: 'pending', receipt: null }), delivery: 'told' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} یادآوری فرستاده شد`))
  })

  it('says it was not, and why, when the customer was not', async () => {
    remind(json({ payment: paymentRow({ status: 'pending', receipt: null }), delivery: 'unreachable' }))
    await until(() => expect(toast.warning).toHaveBeenCalledWith('یادآوری فرستاده نشد', { description: 'تلگرام در دسترس نبود' }))
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('says it went by email to a customer without Telegram', async () => {
    remind(json({ payment: paymentRow({ status: 'pending', receipt: null }), delivery: 'emailed' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} یادآوری با ایمیل فرستاده شد`))
    expect(toast.warning).not.toHaveBeenCalled()
  })

  it('names the mail server as what was out of reach for a customer without Telegram', async () => {
    const sara = { ...paymentRow().user, name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' }
    remind(json({ payment: paymentRow({ status: 'pending', receipt: null, user: sara }), delivery: 'unreachable' }))
    await until(() => expect(toast.warning).toHaveBeenCalledWith('یادآوری فرستاده نشد', { description: 'سرور ایمیل در دسترس نبود' }))
  })
})

describe('a receipt the browser did not draw', () => {
  const failed = () => fireEvent.error(within(dialog()).getByRole('img'))

  it('is explained in the server’s words, with nothing to download, when Telegram no longer has it', async () => {
    server().on('GET', RECEIPT, refusal(404, 'فایل این رسید دیگر در تلگرام نیست.'))
    show(paymentRow())
    failed()

    expect(await within(dialog()).findByText('فایل این رسید دیگر در تلگرام نیست.')).toBeTruthy()
    expect(within(dialog()).queryByText('دانلود رسید')).toBeNull()
    expect(within(dialog()).queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('can be asked for again while Telegram is out of reach', async () => {
    server().on('GET', RECEIPT, refusal(502, 'تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.'))
    show(paymentRow())
    failed()

    fireEvent.click(await within(dialog()).findByRole('button', { name: 'تلاش دوباره' }))
    expect(within(dialog()).getByRole('img')).toBeTruthy()
  })

  it('is offered as a download when the file is there and the browser just cannot draw it', async () => {
    server().on('GET', RECEIPT, { kind: 'response', status: 200, body: 'heic-bytes', type: 'image/heic' })
    show(paymentRow({ receipt: { name: 'IMG_1.HEIC', note: null, sent_at: '2026-10-06T09:00:00Z' } }))

    const download = await within(dialog()).findByRole('link', { name: /دانلود رسید/ })
    expect(download.getAttribute('download')).toBe('IMG_1.HEIC')
  })
})

/** The server approves payment #7: paid, refundable now. */
const approved = () =>
  server().on(
    'POST',
    '/api/admin/payments/7/approve',
    json({ payment: paymentRow({ status: 'paid', reviewer: 'root', actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false, refund: true } }) }),
  )

describe('«بعدی»', () => {
  it('opens the next payment, and after a decision the dialog stays on the decided one with «بعدی» in focus', async () => {
    approved()
    const { onClose, onReviewed, next } = show(paymentRow(), { available: true })

    fireEvent.click(button('تایید و شارژ کیف پول'))
    await until(() => expect(onReviewed).toHaveBeenCalled())

    expect(onClose).not.toHaveBeenCalled()
    await until(() => expect(document.activeElement?.textContent).toBe('بعدی'))
    fireEvent.click(button('بعدی'))
    expect(next.go).toHaveBeenCalledTimes(1)
  })

  it('closes the dialog after a decision on the last one, and does nothing pressed with none after it', async () => {
    approved()
    const { onClose, next } = show(paymentRow())

    fireEvent.click(button('بعدی'))
    expect(button('بعدی').getAttribute('aria-disabled')).toBe('true')
    expect(next.go).not.toHaveBeenCalled()

    fireEvent.click(button('تایید و شارژ کیف پول'))
    await until(() => expect(onClose).toHaveBeenCalled())
  })
})
