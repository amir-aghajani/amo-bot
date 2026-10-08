import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { driverDraft, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { DriverPicker } from '@/components/driver-form/driver-picker'
import type { DriverDescription, DriverValues, FieldDescription } from '@/lib/api-types'
import { providers } from '@/test/render'

/*
 * A driver's form drawn from its description alone (components/driver-form): each field by its kind, Latin content left
 * to right, a number's unit beside its label, a few choices side by side and more in a list, a switch, the rarely needed
 * fields folded under «تنظیمات پیشرفته», a field shown only while another holds a value it names, a secret kept unless
 * typed again or cleared — and said to be left behind, in the server's words, while a field it belongs with moved.
 */

function field(name: string, overrides: Partial<FieldDescription> = {}): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: false,
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

const MOVED = 'سرور عوض شده است؛ رمز را دوباره وارد کنید.'

const DRIVER: DriverDescription = {
  key: 'smtp',
  label: 'SMTP',
  description: 'یک حساب ایمیل.',
  notes: [],
  traits: {},
  fields: [
    field('host', { label: 'سرور', required: true, ltr: true, placeholder: 'mail.example.com' }),
    field('timeout', { type: 'number', label: 'تایم‌اوت', unit: 'ثانیه', default: 15, required: true }),
    field('encryption', {
      type: 'choice',
      label: 'رمزنگاری',
      required: true,
      default: 'tls',
      options: [
        { value: 'tls', label: 'STARTTLS' },
        { value: 'ssl', label: 'SSL' },
        { value: 'none', label: 'بدون رمزنگاری' },
      ],
    }),
    field('region', { type: 'choice', label: 'منطقه', required: true, default: 'eu', options: ['eu', 'us', 'asia', 'africa'].map((value) => ({ value, label: value.toUpperCase() })) }),
    field('auth', { type: 'toggle', label: 'ورود با حساب', default: false }),
    field('username', { label: 'نام کاربری', when: { auth: ['true'] } }),
    field('password', { type: 'secret', label: 'رمز عبور', secret: true, default: null, bound_to: ['host'], moved: MOVED, hint: 'رمز همان حساب.' }),
    field('socket', { type: 'path', label: 'سوکت', advanced: true }),
  ],
}

const KEPT: DriverValues = { host: 'mail.example.com', timeout: 15, encryption: 'tls', region: 'eu', auth: false, username: '', password: { set: true, hint: '••••••••' }, socket: '' }

/** The form as a screen holds it, its draft its own. */
function Harness({ stored, errors = {} }: { stored?: DriverValues; errors?: Record<string, string> }) {
  const [draft, setDraft] = useState<DriverDraft>(() => driverDraft(DRIVER, stored))
  return <DriverFields driver={DRIVER} stored={stored} draft={draft} set={(name, value) => setDraft((current) => ({ ...current, [name]: value }))} error={(name) => errors[name]} idPrefix="t" />
}

function show(stored?: DriverValues, errors?: Record<string, string>) {
  render(<Harness stored={stored} errors={errors} />, { wrapper: providers().wrapper })
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('a driver’s form', () => {
  it('draws each field by its kind, as the description says', () => {
    show(KEPT)

    const host = screen.getByLabelText('سرور') as HTMLInputElement
    expect([host.value, host.dir, host.placeholder]).toEqual(['mail.example.com', 'ltr', 'mail.example.com'])
    expect(screen.getByLabelText('تایم‌اوت (ثانیه)').getAttribute('inputmode')).toBe('numeric')
    expect(screen.getByLabelText('تایم‌اوت (ثانیه)').dir).toBe('ltr')
    expect(screen.getByRole('radiogroup', { name: 'رمزنگاری' })).toBeTruthy()
    expect(screen.getByRole('radio', { name: 'STARTTLS' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByRole('combobox', { name: 'منطقه' }).textContent).toBe('EU')
    expect(screen.getByRole('switch', { name: 'ورود با حساب' })).toBeTruthy()
    expect(screen.getByText('(اختیاری)', { selector: 'label[for="t_password"] *' })).toBeTruthy()
  })

  it('takes a choice by its radio or its list', () => {
    show(KEPT)

    fireEvent.click(screen.getByRole('radio', { name: 'SSL' }))
    expect(screen.getByRole('radio', { name: 'SSL' }).getAttribute('aria-checked')).toBe('true')

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'منطقه' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'ASIA' }))
    expect(screen.getByRole('combobox', { name: 'منطقه' }).textContent).toBe('ASIA')
  })

  it('shows a field only while the one it names holds a value it lists', () => {
    show(KEPT)
    expect(screen.queryByLabelText(/^نام کاربری/)).toBeNull()

    fireEvent.click(screen.getByRole('switch', { name: 'ورود با حساب' }))

    expect(screen.getByLabelText(/^نام کاربری/)).toBeTruthy()
  })

  it('folds the rarely needed fields under «تنظیمات پیشرفته»', () => {
    show(KEPT)
    const socket = screen.getByLabelText(/^سوکت/)
    expect(socket.closest('[hidden]')).not.toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))

    expect(socket.closest('[hidden]')).toBeNull()
    expect(socket.dir).toBe('ltr')
  })

  it('keeps a stored secret unless typed again or cleared, and says so when the address it belongs with moved', () => {
    show(KEPT)
    const password = screen.getByLabelText(/^رمز عبور/) as HTMLInputElement
    expect([password.value, password.placeholder]).toEqual(['', '••••••••'])
    expect(screen.getByText('رمز همان حساب.')).toBeTruthy()

    type('سرور', 'smtp.elsewhere.example')
    expect(screen.getByText(MOVED)).toBeTruthy()

    type(/^رمز عبور/, 'a new one')
    expect(screen.queryByText(MOVED)).toBeNull()
    type(/^رمز عبور/, '')
    fireEvent.click(screen.getByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' }))
    expect(screen.queryByText(MOVED)).toBeNull()
  })

  it('draws a bank card’s number in groups of four, on the number pad, and keeps its digits alone', () => {
    const card: DriverDescription = { ...DRIVER, key: 'manual', fields: [field('card_number', { type: 'card', label: 'شماره کارت', required: true })] }
    const stored: DriverValues = { card_number: '6037997700001119' }
    function Card() {
      const [draft, setDraft] = useState<DriverDraft>(() => driverDraft(card, stored))
      return (
        <>
          <DriverFields driver={card} stored={stored} draft={draft} set={(name, value) => setDraft((current) => ({ ...current, [name]: value }))} error={() => undefined} idPrefix="c" />
          <output>{String(draft.card_number)}</output>
        </>
      )
    }
    render(<Card />, { wrapper: providers().wrapper })

    const number = screen.getByLabelText('شماره کارت') as HTMLInputElement
    expect([number.value, number.dir, number.getAttribute('inputmode')]).toEqual(['6037 9977 0000 1119', 'ltr', 'numeric'])

    type('شماره کارت', '۶۱۰۴-۳۳۸۹ ۰۰۰۰ ۱۲۳۴۵')
    expect(number.value).toBe('6104 3389 0000 1234')
    expect(screen.getByRole('status').textContent).toBe('6104338900001234')
  })

  it('has no secret to keep while nothing is stored, and puts a refusal under its field', () => {
    show(undefined, { host: 'سرور را وارد کنید.' })

    expect((screen.getByLabelText(/^رمز عبور/) as HTMLInputElement).placeholder).toBe('')
    expect(screen.queryByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' })).toBeNull()
    type('سرور', 'elsewhere')
    expect(screen.queryByText(MOVED)).toBeNull()
    expect(screen.getByText('سرور را وارد کنید.')).toBeTruthy()
    expect(screen.getByLabelText('سرور').getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the driver picker', () => {
  const OTHER: DriverDescription = { ...DRIVER, key: 'native', label: 'ایمیل خود هاست', description: 'ایمیل خود هاست.', notes: ['php.ini'] }

  it('offers no driver at all first, then the drivers, the one picked described under it', () => {
    const picked: string[] = []
    render(<DriverPicker id="p" label="روش ارسال" drivers={[DRIVER, OTHER]} value="native" onChange={(key) => picked.push(key)} none={{ value: 'none', label: 'خاموش', hint: 'ایمیلی نمی‌رود.' }} />, {
      wrapper: providers().wrapper,
    })

    expect(screen.getByRole('combobox', { name: 'روش ارسال' }).textContent).toBe('ایمیل خود هاست')
    expect(screen.getByText('ایمیل خود هاست. · php.ini')).toBeTruthy()
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'روش ارسال' }), { key: 'Enter' })
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['خاموش', 'SMTP', 'ایمیل خود هاست'])
    fireEvent.click(screen.getByRole('option', { name: 'خاموش' }))
    expect(picked).toEqual(['none'])
  })

  it('says a single driver with nothing else to pick, and offers nothing', () => {
    render(<DriverPicker id="p" label="نوع دیتابیس" drivers={[OTHER]} value="native" onChange={() => undefined} />, { wrapper: providers().wrapper })

    expect(screen.queryByRole('combobox')).toBeNull()
    expect(screen.getByText(/php\.ini/).textContent).toBe('ایمیل خود هاست · php.ini')
  })
})
