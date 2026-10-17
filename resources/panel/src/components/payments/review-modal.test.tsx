import { useState } from 'react'
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ReviewModal } from '@/components/payments/review-modal'
import type { PaymentCheckResponse, PaymentRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import type { NextRow } from '@/lib/use-next-row'
import { providers, until } from '@/test/render'
import { onlinePaymentRow, paymentRow } from '@/test/rows'
import { json, refusal, server, type Answer } from '@/test/server'

/*
 * A payment's dialog (components/payments/review-modal): how approving is labelled, the method's summary, a reminder
 * that could not be sent, an online payment asked of its gateway — what it said, money gone to the wallet, and one held
 * for support —, a receipt the browser could not draw, «بعدی» through the list, and the focus while and after an
 * operation runs.
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
  it('labels approval by what the payment brings, and as a manual decision without a receipt', () => {
    show(paymentRow())
    expect(button('تایید و شارژ کیف پول')).toBeTruthy()
  })

  it('labels a purchase approved by hand as a delivery', () => {
    show(paymentRow({ status: 'pending', receipt: null, order: { ...paymentRow().order, type: 'purchase', plan: 'طلایی' } }))
    expect(button('تایید دستی و تحویل')).toBeTruthy()
  })

  it('labels approving a service’s extra traffic as adding it, and says how much it adds', () => {
    show(paymentRow({ order: { ...paymentRow().order, type: 'extra_traffic', plan: 'طلایی', server: 'آلمان', subscription_id: 1, traffic_bytes: 10 * 1024 ** 3 } }))
    expect(button('تایید و افزودن حجم')).toBeTruthy()
    expect(within(dialog()).getByText('حجم اضافه · ۱۰ گیگابایت · طلایی · آلمان')).toBeTruthy()
  })

  it('labels a service’s extra time approved by hand as adding it, and says how much it adds', () => {
    show(paymentRow({ status: 'pending', receipt: null, order: { ...paymentRow().order, type: 'extra_time', plan: 'طلایی', server: null, subscription_id: 1, days: 7 } }))
    expect(button('تایید دستی و افزودن زمان')).toBeTruthy()
    expect(within(dialog()).getByText('زمان اضافه · ۷ روز · طلایی')).toBeTruthy()
  })

  it('labels approving a service’s extra devices as adding them, and says how many', () => {
    show(paymentRow({ order: { ...paymentRow().order, type: 'extra_devices', plan: 'طلایی', server: 'آلمان', subscription_id: 1, devices: 2 } }))
    expect(button('تایید و افزودن کاربر')).toBeTruthy()
    expect(within(dialog()).getByText('کاربر اضافه · ۲ کاربر · طلایی · آلمان')).toBeTruthy()
  })

  it('keeps its decisions at the sheet’s foot on a phone, a finger’s size each — a strip opened scrolls with the rest', () => {
    show(paymentRow())
    const section = within(dialog()).getByRole('region', { name: 'عملیات' })

    expect(section.className.split(' ')).toEqual(expect.arrayContaining(['max-md:in-[.app-modal]:sticky', 'max-md:in-[.app-modal]:-bottom-5', 'max-md:in-[.app-modal]:bg-popover']))
    expect(button('تایید و شارژ کیف پول').className).toContain('max-md:h-11')

    fireEvent.click(button('رد کردن'))
    expect(within(dialog()).getByRole('region', { name: 'عملیات' }).className).not.toContain('sticky')
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

  it('says of money support took outside the shop that it was, never that a wallet paid it', () => {
    const outside = paymentRow({
      status: 'paid',
      gateway: 'outside',
      gateway_label: 'خارج از فروشگاه',
      kind: 'instant',
      method: 'خارج از فروشگاه',
      summary: null,
      receipt: null,
      order: { ...paymentRow().order, type: 'purchase', status: 'fulfilled' },
      actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false },
    })
    show(outside)

    expect(within(dialog()).getByText('پرداخت خارج از فروشگاه که پشتیبانی ثبت کرده؛ رسیدی در کار نیست')).toBeTruthy()
    expect(within(dialog()).queryByText(/کیف پول؛/)).toBeNull()
  })

  it('says of a wallet’s payment that the wallet paid it', () => {
    show(paymentRow({ status: 'paid', gateway: 'wallet', gateway_label: 'کیف پول', kind: 'instant', method: 'کیف پول', summary: null, receipt: null }))
    expect(within(dialog()).getByText('پرداخت از کیف پول؛ رسیدی در کار نیست')).toBeTruthy()
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
      actions: { approve: false, reject: false, cancel: false, remind: false, retry: true, refund: true, check: false },
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

  it('refused because the payment changed shows the server’s message once, even with nothing left to do, with the focus on it', async () => {
    const decided = 'این پرداخت را نمی‌شود تایید کرد؛ یا پرداخت‌شده است، یا سفارشش دیگر باز نیست.'
    const cancelled = paymentRow({ status: 'cancelled', actions: { approve: false, reject: false, cancel: false, remind: false, retry: false, refund: false, check: false } })
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
    expect(within(dialog()).getByText(/سرویسی که با این خرید ساخته شده از پنل و فروشگاه حذف می‌شود/)).toBeTruthy()
  })

  it('of a renewal says what it gave is taken back', () => {
    show(
      paymentRow({
        status: 'paid',
        reviewer: 'root',
        order: { ...paymentRow().order, type: 'renewal', status: 'fulfilled', subscription_id: 1 },
        actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false, refund: true },
      }),
    )
    fireEvent.click(button('بازپرداخت به کیف پول'))

    expect(
      within(dialog()).getByText('مبلغ به کیف پول مشتری برمی‌گردد و زمان و حجمی که این تمدید به سرویس داده پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.'),
    ).toBeTruthy()
  })

  it('of a service’s extra says what was added is taken back', () => {
    show(
      paymentRow({
        status: 'paid',
        reviewer: 'root',
        order: { ...paymentRow().order, type: 'extra_time', status: 'fulfilled', subscription_id: 1 },
        actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false, refund: true },
      }),
    )
    fireEvent.click(button('بازپرداخت به کیف پول'))

    expect(within(dialog()).getByText('مبلغ به کیف پول مشتری برمی‌گردد و زمانی که به سرویس اضافه شده پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.')).toBeTruthy()
  })

  it('of a service’s extra devices says the devices added are taken back', () => {
    show(
      paymentRow({
        status: 'paid',
        reviewer: 'root',
        order: { ...paymentRow().order, type: 'extra_devices', status: 'fulfilled', subscription_id: 1, devices: 2 },
        actions: { ...paymentRow().actions, approve: false, reject: false, cancel: false, refund: true },
      }),
    )
    fireEvent.click(button('بازپرداخت به کیف پول'))

    expect(
      within(dialog()).getByText('مبلغ به کیف پول مشتری برمی‌گردد و کاربرهایی که به سرویس اضافه شده پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.'),
    ).toBeTruthy()
  })
})

describe('a reminder', () => {
  const remind = (answer: Answer) => {
    server().on('POST', '/api/admin/payments/7/remind', answer)
    show(paymentRow({ status: 'pending', receipt: null, actions: { ...paymentRow().actions, approve: true, reject: false, remind: true } }))
    fireEvent.click(button('یادآوری به مشتری'))
  }

  it('says it was sent when the customer was notified', async () => {
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

describe('an online payment', () => {
  const CHECK = '/api/admin/payments/7/check'

  /** Payment #7 on CubePay as the dialog shows it, «استعلام از درگاه» pressed with the server answering `answer`. */
  const asked = (answer: Answer, payment = onlinePaymentRow()) => {
    server().on('POST', CHECK, answer)
    const props = show(payment)
    fireEvent.click(button('استعلام از درگاه'))
    return props
  }

  it('shows its gateway, its id there and its page’s end; no receipt, and nothing to approve or reject by hand', () => {
    show(onlinePaymentRow({ checked_at: '2026-10-06T09:00:00Z' }))

    expect(within(dialog()).getByText('درگاه CubePay')).toBeTruthy()
    expect(within(dialog()).getByText('شناسه در درگاه').nextElementSibling?.textContent).toBe('A1b2C3d4E5f6')
    expect(within(dialog()).getByText('مهلت پرداخت در درگاه')).toBeTruthy()
    expect(within(dialog()).getByText('آخرین استعلام')).toBeTruthy()
    expect(within(dialog()).getByText(/پرداخت آنلاین در صفحه درگاه؛ رسیدی در کار نیست/)).toBeTruthy()
    expect(button('استعلام از درگاه')).toBeTruthy()
    expect(button('لغو پرداخت')).toBeTruthy()
    expect(within(dialog()).queryByRole('button', { name: /تایید/ })).toBeNull()
    expect(within(dialog()).queryByRole('button', { name: 'رد کردن' })).toBeNull()
  })

  it('asks the gateway at one press and, still waiting, says so and stays', async () => {
    vi.spyOn(toast, 'info')
    const { onClose, onReviewed } = asked(json({ payment: onlinePaymentRow({ checked_at: '2026-10-06T09:10:00Z' }), state: 'waiting' } satisfies PaymentCheckResponse))

    await until(() => expect(toast.info).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} هنوز در درگاه پرداخت نشده است`))
    expect(server().sent('POST', CHECK)[0]?.body).toBeUndefined()
    expect(onReviewed).toHaveBeenCalled()
    expect(onClose).not.toHaveBeenCalled()
    expect(button('استعلام از درگاه')).toBeTruthy()
  })

  it('says a payment the gateway took was approved, and what it brought', async () => {
    const paid = onlinePaymentRow({
      status: 'paid',
      paid_at: '2026-10-06T09:05:00Z',
      order: { ...paymentRow().order, status: 'fulfilled' },
      actions: { ...onlinePaymentRow().actions, cancel: false, check: false, refund: true },
    })
    const { onClose } = asked(json({ payment: paid, state: 'paid' } satisfies PaymentCheckResponse))

    await until(() => expect(toast.success).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} در درگاه پرداخت شده بود؛ تایید شد و کیف پول شارژ شد`))
    expect(onClose).toHaveBeenCalled()
  })

  it('says a page that closed unpaid failed', async () => {
    const closed = onlinePaymentRow({ status: 'failed', description: 'مهلت پرداخت در درگاه تمام شد' })
    asked(json({ payment: closed, state: 'closed' } satisfies PaymentCheckResponse))

    await until(() => expect(toast.warning).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} در درگاه پرداخت نشد و مهلت صفحه پرداخت تمام شده است`))
  })

  it('says the money that came after its order closed went to the wallet — in the toast and in the dialog', async () => {
    const credited = onlinePaymentRow({
      status: 'paid',
      to_wallet: true,
      description: 'سفارش پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ به کیف پول مشتری رفت',
      order: { ...paymentRow().order, status: 'cancelled' },
      actions: { approve: false, reject: false, cancel: false, remind: false, retry: false, refund: false, check: false },
    })
    asked(json({ payment: credited, state: 'paid' } satisfies PaymentCheckResponse), onlinePaymentRow({ status: 'cancelled' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith(`پرداخت ${idLabel(7)} در درگاه پرداخت شده بود؛ سفارشش بسته بود و مبلغ به کیف پول مشتری رفت`))
    cleanup()
    show(credited)
    expect(within(dialog()).getByText('کیف پول').nextElementSibling?.textContent).toBe('سفارش پیش از رسیدن این پرداخت بسته شده بود؛ مبلغ به کیف پول مشتری رفت')
    expect(within(dialog()).queryByRole('button', { name: /بازپرداخت/ })).toBeNull()
  })

  it('shows the server’s words when the gateway cannot be asked, nothing changed', async () => {
    const down = 'درگاه پرداخت الان جواب نمی‌دهد و چیزی تغییر نکرد؛ کمی بعد دوباره استعلام کنید.'
    asked(refusal(503, down))

    expect(await within(dialog()).findByText(down)).toBeTruthy()
    expect(toast.success).not.toHaveBeenCalled()
  })

  /** Payment #7 held for support (PaymentService::NOTE_HELD): never asked again, its cancel alone offered. */
  const HELD_NOTE = 'درگاه این پرداخت را با سفارش یا مبلغ دیگری تایید کرد؛ پشتیبانی باید آن را در درگاه بررسی کند'
  const held = () => onlinePaymentRow({ held: true, description: HELD_NOTE, checked_at: '2026-10-06T09:10:00Z', actions: { ...onlinePaymentRow().actions, check: false } })

  it('held for support says so beside its status, and why — with its cancel alone offered, and no page’s end to wait on', () => {
    show(held())

    const status = within(dialog()).getByText('وضعیت').nextElementSibling
    expect(status?.textContent?.replace(/\s+/g, ' ').trim()).toBe('در انتظار پرداخت نگه داشته شده برای پشتیبانی')
    expect(within(dialog()).getByText('بررسی پشتیبانی').nextElementSibling?.textContent).toBe(HELD_NOTE)
    expect(within(dialog()).queryByText('دلیل')).toBeNull()
    expect(within(dialog()).queryByText('مهلت پرداخت در درگاه')).toBeNull()
    expect(within(dialog()).queryByRole('button', { name: 'استعلام از درگاه' })).toBeNull()
    expect(button('لغو پرداخت')).toBeTruthy()
  })

  it('whose ask holds it for support says so twice on purpose — the refusal over its operations, and why among its facts', async () => {
    const words = `${HELD_NOTE}.`
    server().on('POST', CHECK, refusal(422, words, { status: [words] }))
    render(<Reviewing first={onlinePaymentRow()} refreshed={held()} />, { wrapper: providers().wrapper })
    const ask = button('استعلام از درگاه')
    ask.focus()
    fireEvent.click(ask)

    await until(() => expect(within(dialog()).queryByRole('button', { name: 'استعلام از درگاه' })).toBeNull())
    // What this press came to, where the operations are — with the focus on it, as any refusal…
    const operations = within(dialog()).getByRole('region', { name: 'عملیات' })
    expect(within(operations).getAllByText(words)).toHaveLength(1)
    expect(document.activeElement?.textContent).toContain(words)
    // …and why the payment is held, as its dialog shows it for anyone opening it later: the same sentence, both kept.
    expect(within(dialog()).getByText('بررسی پشتیبانی').nextElementSibling?.textContent).toBe(HELD_NOTE)
    expect(within(dialog()).getAllByText(new RegExp(HELD_NOTE))).toHaveLength(2)
    expect(within(operations).getByRole('button', { name: 'لغو پرداخت' })).toBeTruthy()
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('a receipt the browser did not draw', () => {
  const failed = () => fireEvent.error(within(dialog()).getByRole('img'))

  it('shows the server’s explanation, with nothing to download, when Telegram no longer has it', async () => {
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
