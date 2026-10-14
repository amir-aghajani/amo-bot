import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DriverDescription, FieldDescription, PaymentMethodResponse, PaymentMethodsResponse, UnregisteredMethodRow, WalletMethodRow } from '@/lib/api-types'
import { PaymentMethodsPage } from '@/pages/payment-methods'
import { providers, until } from '@/test/render'
import { cardMethodRow, paymentCardRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The payment methods (pages/payment-methods): each row says what it is — a card method its cards, each left to right
 * with its holder and bank, one switched off or full for today said so —, a card's automatic approval and the amounts
 * a method is offered for under it; a method opens in its driver's form, drawn from the driver's description with its
 * settings as the API describes them, its bounds and a card method's cards beside them, and is saved as the driver's own
 * request; the wallet opens in its form too (its label, bounds and switch); one whose driver is no longer installed
 * says no customer is offered it, a card method no card is shown while card payments go to support's private chat (its
 * caps then beside the point); a method payments were made with is not offered a delete it would be refused; and the
 * page counts its methods once their list was read — never a zero it does not know.
 */

const WALLET: WalletMethodRow = {
  id: 1,
  driver: 'wallet',
  driver_label: 'کیف پول',
  kind: 'instant',
  builtin: true,
  label: 'کیف پول',
  summary: null,
  config: {},
  min_amount: null,
  max_amount: null,
  enabled: true,
  sort: 1,
  counts: { payments: 3 },
  created_at: '2026-09-01T10:00:00Z',
  updated_at: '2026-10-06T09:00:00Z',
}

function field(name: string, overrides: Partial<FieldDescription>): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: true,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: false,
    default: '',
    ...overrides,
  }
}

/** The wallet's driver as the server describes it: nothing to set. */
const WALLET_DRIVER: DriverDescription = { key: 'wallet', label: 'کیف پول', description: 'پرداخت از موجودی کیف پول.', notes: [], traits: { kind: 'instant', builtin: true }, fields: [] }

/** The card-to-card driver as the server describes it (Payments\Drivers\Manual\ManualDriver): its cards are a list of their own. */
const MANUAL: DriverDescription = {
  key: 'manual',
  label: 'کارت به کارت',
  description: 'مشتری مبلغ را به یکی از کارت‌های شما واریز می‌کند و رسید را می‌فرستد.',
  notes: [],
  traits: { kind: 'manual', builtin: false },
  fields: [
    field('instructions', { type: 'textarea', label: 'توضیحات برای مشتری', required: false }),
    field('auto_approve_after', { type: 'number', label: 'تایید خودکار', unit: 'دقیقه', required: false, min: 0, max: 10080, default: 0 }),
  ],
}

function showMethods(methods: PaymentMethodsResponse['methods']) {
  server().on('GET', '/api/admin/payment-methods', json({ methods } satisfies PaymentMethodsResponse))
  render(<PaymentMethodsPage />, { wrapper: providers().wrapper })
}

const openDialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the payment methods', () => {
  it('describe a card method by its cards (left to right), its automatic approval and the amounts it is offered for', async () => {
    showMethods([
      { ...WALLET, max_amount: '2000000.00' },
      cardMethodRow({
        config: { instructions: '', auto_approve_after: 30 },
        min_amount: '10000.00',
        cards: [paymentCardRow({ bank: 'ملت', daily_cap: '500000.00', today: '500000.00' }), paymentCardRow({ id: 12, number: '5892101012345670', holder: 'سارا احمدی', enabled: false })],
      }),
    ])

    const cards = await screen.findAllByText('6037 •••• •••• 1119')
    expect(cards[0]?.getAttribute('dir')).toBe('ltr')
    expect(screen.getAllByText('امیر رضایی').length).toBeGreaterThan(0)
    expect(screen.getAllByText('ملت').length).toBeGreaterThan(0)
    expect(screen.getAllByText(/امروز ۵۰۰٬۰۰۰ تومان از ۵۰۰٬۰۰۰ تومان؛ پر شده/).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/\(خاموش\)/).length).toBeGreaterThan(0)
    expect(screen.getAllByText(/تایید خودکار بعد از ۳۰ دقیقه/).length).toBeGreaterThan(0)
    expect(screen.getAllByText('برای مبلغ‌های از ۱۰٬۰۰۰ تومان به بالا').length).toBeGreaterThan(0)
    expect(screen.getAllByText('برای مبلغ‌های تا ۲٬۰۰۰٬۰۰۰ تومان').length).toBeGreaterThan(0)
  })

  it('open a card method in its driver’s form, with its bounds and its cards', async () => {
    server().on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [MANUAL] }))
    showMethods([WALLET, cardMethodRow({ max_amount: '500000.00' })])

    fireEvent.click(await screen.findByRole('button', { name: 'کارت به کارت (ملت)' }))

    expect((await openDialog().findByLabelText('شماره کارت')).getAttribute('value')).toBe('6037 9977 0000 1119')
    expect(openDialog().getByLabelText('نام صاحب کارت').getAttribute('value')).toBe('امیر رضایی')
    expect(
      openDialog()
        .getByLabelText(/^بیشترین مبلغ/)
        .getAttribute('value'),
    ).toBe('500000')
    expect(
      openDialog()
        .getByLabelText(/^کمترین مبلغ/)
        .getAttribute('value'),
    ).toBe('')
    expect(
      openDialog()
        .getByLabelText(/^تایید خودکار \(دقیقه\)/)
        .getAttribute('value'),
    ).toBe('0')
    expect(openDialog().getByLabelText('نام روش').getAttribute('value')).toBe('کارت به کارت (ملت)')
  })

  it('save a card method’s form as its driver’s own request: label, switch, bounds, settings and its cards in their order', async () => {
    server()
      .on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [MANUAL] }))
      .on('PUT', '/api/admin/payment-methods/5', json({ method: cardMethodRow({ cards: [paymentCardRow({ holder: 'سارا احمدی' })] }) } satisfies PaymentMethodResponse))
    showMethods([WALLET, cardMethodRow()])

    fireEvent.click(await screen.findByRole('button', { name: 'کارت به کارت (ملت)' }))
    fireEvent.change(await openDialog().findByLabelText('نام صاحب کارت'), { target: { value: 'سارا احمدی' } })
    fireEvent.change(openDialog().getByLabelText(/^بیشترین مبلغ/), { target: { value: '500000' } })
    fireEvent.click(openDialog().getByRole('button', { name: 'افزودن کارت' }))
    fireEvent.change(openDialog().getAllByLabelText('شماره کارت')[1] as HTMLElement, { target: { value: '5892-1010-1234-5670' } })
    fireEvent.change(openDialog().getAllByLabelText('نام صاحب کارت')[1] as HTMLElement, { target: { value: 'رضا' } })
    fireEvent.click(openDialog().getByRole('button', { name: 'انتقال کارت ۲ به بالا' }))
    fireEvent.click(openDialog().getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('روش پرداخت ذخیره شد'))
    expect(server().sent('PUT', '/api/admin/payment-methods/5')[0]?.body).toEqual({
      label: 'کارت به کارت (ملت)',
      enabled: true,
      min_amount: '',
      max_amount: '500000',
      instructions: '',
      auto_approve_after: '0',
      cards: [
        { number: '5892101012345670', holder: 'رضا', bank: '', enabled: true, daily_cap: '' },
        { id: 11, number: '6037997700001119', holder: 'سارا احمدی', bank: '', enabled: true, daily_cap: '' },
      ],
    })
  })

  it('keep a card payments were shown: its number as it is, switched off rather than removed', async () => {
    server().on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [MANUAL] }))
    showMethods([WALLET, cardMethodRow({ cards: [paymentCardRow({ payments: 4 }), paymentCardRow({ id: 12, number: '5892101012345670' })] })])

    fireEvent.click(await screen.findByRole('button', { name: 'کارت به کارت (ملت)' }))

    const [paid] = await openDialog().findAllByLabelText('شماره کارت')
    expect(paid?.hasAttribute('readonly')).toBe(true)
    expect(openDialog().getByText('با این کارت پرداخت شده؛ شماره‌اش عوض نمی‌شود.')).toBeTruthy()
    expect(openDialog().queryByRole('button', { name: 'حذف کارت ۱' })).toBeNull()
    expect(openDialog().getByRole('button', { name: 'حذف کارت ۲' })).toBeTruthy()
  })

  it('open the wallet in its form — its label, bounds and switch — and save them', async () => {
    server()
      .on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [WALLET_DRIVER, MANUAL] }))
      .on('PUT', '/api/admin/payment-methods/1', json({ method: { ...WALLET, max_amount: '2000000.00' } } satisfies PaymentMethodResponse))
    showMethods([WALLET])

    fireEvent.click(await screen.findByRole('button', { name: 'کیف پول' }))
    fireEvent.change(await openDialog().findByLabelText(/^بیشترین مبلغ/), { target: { value: '2000000' } })
    fireEvent.click(openDialog().getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('روش پرداخت ذخیره شد'))
    expect(server().sent('PUT', '/api/admin/payment-methods/1')[0]?.body).toEqual({ label: 'کیف پول', enabled: true, min_amount: '', max_amount: '2000000' })
  })

  it('say of one whose driver is no longer installed that no customer is offered it', async () => {
    const gone: UnregisteredMethodRow = { ...WALLET, id: 9, driver: 'paypal', driver_label: 'paypal', kind: null, builtin: false, label: 'درگاه قدیمی' }
    showMethods([WALLET, gone])

    expect((await screen.findAllByText('به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.')).length).toBeGreaterThan(0)
  })

  it('say of a card method with no card left to show that no customer is offered it, and why', async () => {
    showMethods([
      WALLET,
      cardMethodRow({ id: 5, cards: [] }),
      cardMethodRow({ id: 6, label: 'کارت ملی', cards: [paymentCardRow({ enabled: false })] }),
      cardMethodRow({ id: 7, label: 'کارت سپه', cards: [paymentCardRow({ daily_cap: '500000.00', today: '500000.00' }), paymentCardRow({ id: 12, enabled: false })] }),
      cardMethodRow({ id: 8, label: 'کارت تجارت', cards: [paymentCardRow({ daily_cap: '500000.00', today: '500000.00' }), paymentCardRow({ id: 12 })] }),
    ])

    expect((await screen.findAllByText('کارتی ندارد؛ به مشتری پیشنهاد نمی‌شود.')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('همه کارت‌ها خاموش‌اند؛ به مشتری پیشنهاد نمی‌شود.').length).toBeGreaterThan(0)
    // Two lines a method (narrow and wide screens): one method each says so — the last still has a card with room.
    expect(screen.getAllByText('سقف روزانه کارت‌های روشن پر شده؛ تا فردا به مشتری پیشنهاد نمی‌شود.')).toHaveLength(2)
  })

  it('say of a card method, while card payments go to support’s private chat, that no card is shown: no warning of its switches and caps', async () => {
    showMethods([WALLET, cardMethodRow({ cards: [paymentCardRow({ enabled: false }), paymentCardRow({ id: 12, daily_cap: '500000.00', today: '500000.00' })], to_support: true })])

    expect((await screen.findAllByText(/پرداخت کارت به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند/)).length).toBeGreaterThan(0)
    expect(screen.queryByText(/به مشتری پیشنهاد نمی‌شود/)).toBeNull()
    expect(screen.queryByText(/امروز/)).toBeNull()
  })

  it('offer no delete for a method payments were made with: it stays, switched off if need be', async () => {
    showMethods([WALLET, cardMethodRow({ counts: { payments: 9 } })])

    fireEvent.pointerDown(await screen.findByRole('button', { name: 'عملیات کارت به کارت (ملت)' }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))

    expect(await openDialog().findByText(/۹ پرداخت ثبت شده و برای حفظ تاریخچه حذف نمی‌شود/)).toBeTruthy()
    expect(openDialog().queryByRole('button', { name: 'حذف' })).toBeNull()
  })
})

describe('the page’s count', () => {
  it('is shown once the list was read, never as an unknown zero', async () => {
    server().on('GET', '/api/admin/payment-methods', refusal(500, 'خطای سرور.'))
    render(<PaymentMethodsPage />, { wrapper: providers().wrapper })

    expect(await screen.findByText('لیست روش‌های پرداخت بارگذاری نشد.')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('روش‌های پرداخت')
  })

  it('counts what the list read', async () => {
    showMethods([WALLET, cardMethodRow()])

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('روش‌های پرداخت۲'))
  })
})
