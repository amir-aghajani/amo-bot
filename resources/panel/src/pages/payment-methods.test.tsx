import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DriverDescription, FieldDescription, PaymentMethodResponse, PaymentMethodsResponse, UnregisteredMethodRow, WalletMethodRow } from '@/lib/api-types'
import { PaymentMethodsPage } from '@/pages/payment-methods'
import { providers, until } from '@/test/render'
import { cardMethodRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The payment methods (pages/payment-methods): each row says what it is in its driver's words — the server's summary,
 * the same line a payment shows —, a card's automatic approval under it; a card opens in its driver's form, drawn from
 * the driver's description with its settings as the API describes them, and is saved as the driver's own request; one
 * whose driver is no longer installed says no customer is offered it; a method payments were made with is not offered a
 * delete it would be refused; and the page counts its methods once their list was read — never a zero it does not know.
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

/** The card-to-card driver as the server describes it (Payments\Drivers\Manual\ManualDriver). */
const MANUAL: DriverDescription = {
  key: 'manual',
  label: 'کارت به کارت',
  description: 'مشتری مبلغ را به کارت شما واریز می‌کند و رسید را می‌فرستد.',
  notes: [],
  traits: { kind: 'manual', builtin: false },
  fields: [
    field('card_number', { type: 'card', label: 'شماره کارت' }),
    field('card_holder', { label: 'نام صاحب کارت' }),
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
  it('say what each is in its driver’s words — the card left to right — with a card’s automatic approval under it', async () => {
    showMethods([WALLET, cardMethodRow({ config: { card_number: '6037997700001119', card_holder: 'امیر رضایی', instructions: '', auto_approve_after: 30 } })])

    const cards = await screen.findAllByText('6037 •••• •••• 1119')
    expect(cards[0]?.getAttribute('dir')).toBe('ltr')
    expect(screen.getAllByText('امیر رضایی').length).toBeGreaterThan(0)
    expect(screen.getAllByText(/تایید خودکار بعد از ۳۰ دقیقه/).length).toBeGreaterThan(0)
  })

  it('open a card in its driver’s form, its settings as the API describes them', async () => {
    server().on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [MANUAL] }))
    showMethods([WALLET, cardMethodRow()])

    fireEvent.click(await screen.findByRole('button', { name: 'کارت به کارت (ملت)' }))

    expect((await openDialog().findByLabelText('شماره کارت')).getAttribute('value')).toBe('6037 9977 0000 1119')
    expect(openDialog().getByLabelText('نام صاحب کارت').getAttribute('value')).toBe('امیر رضایی')
    expect(
      openDialog()
        .getByLabelText(/^تایید خودکار \(دقیقه\)/)
        .getAttribute('value'),
    ).toBe('0')
    expect(openDialog().getByLabelText('نام روش').getAttribute('value')).toBe('کارت به کارت (ملت)')
  })

  it('save a card’s form again as its driver’s own request, with its label and its switch', async () => {
    server()
      .on('GET', '/api/admin/payment-methods/drivers', json({ drivers: [MANUAL] }))
      .on(
        'PUT',
        '/api/admin/payment-methods/5',
        json({ method: cardMethodRow({ config: { card_number: '6037997700001119', card_holder: 'سارا احمدی', instructions: '', auto_approve_after: 0 } }) } satisfies PaymentMethodResponse),
      )
    showMethods([WALLET, cardMethodRow()])

    fireEvent.click(await screen.findByRole('button', { name: 'کارت به کارت (ملت)' }))
    fireEvent.change(await openDialog().findByLabelText('نام صاحب کارت'), { target: { value: 'سارا احمدی' } })
    fireEvent.click(openDialog().getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('روش پرداخت ذخیره شد'))
    expect(server().sent('PUT', '/api/admin/payment-methods/5')[0]?.body).toEqual({
      label: 'کارت به کارت (ملت)',
      enabled: true,
      card_number: '6037997700001119',
      card_holder: 'سارا احمدی',
      instructions: '',
      auto_approve_after: '0',
    })
  })

  it('say of one whose driver is no longer installed that no customer is offered it', async () => {
    const gone: UnregisteredMethodRow = { ...WALLET, id: 9, driver: 'paypal', driver_label: 'paypal', kind: null, builtin: false, label: 'درگاه قدیمی' }
    showMethods([WALLET, gone])

    expect((await screen.findAllByText('به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.')).length).toBeGreaterThan(0)
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
  it('is said once the list was read — never a zero it does not know', async () => {
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
