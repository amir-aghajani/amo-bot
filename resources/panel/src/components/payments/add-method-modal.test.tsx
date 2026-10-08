import { fireEvent, render, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AddMethodModal } from '@/components/payments/add-method-modal'
import type { DriverDescription, FieldDescription, PaymentMethodResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { cardMethodRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * Adding a payment method (components/payments/add-method-modal): the drivers the API describes to pick from — the
 * wallet listed, but the shop has it already —, then the driver's form drawn from its description and sent as a new
 * method of that driver, a refusal said under its field.
 */

const CARD_REFUSED = 'شماره کارت باید 16 رقم و معتبر باشد.'

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

/** The gateway drivers as the server describes them (Payments\Drivers). */
const DRIVERS: DriverDescription[] = [
  {
    key: 'wallet',
    label: 'کیف پول',
    description: 'پرداخت از موجودی کیف پول مشتری؛ بلافاصله تسویه می‌شود.',
    notes: [],
    traits: { kind: 'instant', builtin: true },
    fields: [],
  },
  {
    key: 'manual',
    label: 'کارت به کارت',
    description: 'مشتری مبلغ را به کارت شما واریز می‌کند و رسید را می‌فرستد.',
    notes: ['بدون کارمزد و بدون نیاز به درگاه'],
    traits: { kind: 'manual', builtin: false },
    fields: [
      field('card_number', { type: 'card', label: 'شماره کارت' }),
      field('card_holder', { label: 'نام صاحب کارت' }),
      field('instructions', { type: 'textarea', label: 'توضیحات برای مشتری', required: false }),
      field('auto_approve_after', { type: 'number', label: 'تایید خودکار', unit: 'دقیقه', required: false, min: 0, max: 10080, default: 0 }),
    ],
  },
]

function open() {
  const onCreated = vi.fn()
  server().on('GET', '/api/admin/payment-methods/drivers', json({ drivers: DRIVERS }))
  render(<AddMethodModal open onClose={vi.fn()} onCreated={onCreated} />, { wrapper: providers().wrapper })
  return onCreated
}

const dialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)
const type = (label: string, value: string) => fireEvent.change(dialog().getByLabelText(label), { target: { value } })

/** The card's form, filled in and sent. */
async function addCard(card: string) {
  fireEvent.click(await dialog().findByRole('button', { name: /کارت به کارت/ }))
  type('شماره کارت', card)
  type('نام صاحب کارت', 'امیر رضایی')
  fireEvent.click(dialog().getByRole('button', { name: 'افزودن روش' }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('adding a payment method', () => {
  it('offers the drivers the API describes, each with how it settles — the wallet the shop has already, not again', async () => {
    open()

    const wallet = await dialog().findByRole('button', { name: /کیف پول/ })
    expect(wallet.hasAttribute('disabled')).toBe(true)
    expect(within(wallet).getByText('داخلی؛ همیشه هست')).toBeTruthy()
    const card = dialog().getByRole('button', { name: /کارت به کارت/ })
    expect(card.hasAttribute('disabled')).toBe(false)
    expect(within(card).getByText('تایید دستی')).toBeTruthy()
  })

  it('draws the driver’s form from its description and sends it as a new method of that driver', async () => {
    server().on('POST', '/api/admin/payment-methods', json({ method: cardMethodRow() } satisfies PaymentMethodResponse, 201))
    const onCreated = open()

    // As it is printed: its digits sent alone.
    await addCard('۶۰۳۷-۹۹۷۷-۰۰۰۰-۱۱۱۹')

    await until(() => expect(onCreated).toHaveBeenCalledWith(cardMethodRow()))
    expect(toast.success).toHaveBeenCalledWith('روش پرداخت اضافه شد')
    expect(server().sent('POST', '/api/admin/payment-methods')[0]?.body).toEqual({
      driver: 'manual',
      label: 'کارت به کارت',
      enabled: true,
      card_number: '6037997700001119',
      card_holder: 'امیر رضایی',
      instructions: '',
      auto_approve_after: '0',
    })
  })

  it('says a refusal under the field it is about', async () => {
    server().on('POST', '/api/admin/payment-methods', refusal(422, CARD_REFUSED, { card_number: [CARD_REFUSED] }))
    const onCreated = open()

    await addCard('6037 9977 0000 1111')

    expect(await dialog().findByText(CARD_REFUSED)).toBeTruthy()
    expect(dialog().getByLabelText('شماره کارت').getAttribute('aria-invalid')).toBe('true')
    expect(onCreated).not.toHaveBeenCalled()
  })
})
