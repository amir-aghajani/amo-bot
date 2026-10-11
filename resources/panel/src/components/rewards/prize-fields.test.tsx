import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PrizeAmountField } from '@/components/rewards/prize-fields'
import { providers, until } from '@/test/render'
import { planRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The reward pickers (components/rewards/prize-fields): a prize's amount field by its kind, and the plan picker every
 * reward shares — any plan, one off sale too, each saying why it cannot be delivered now, and never a placeholder that
 * waits for ever when the plans could not be read.
 */

const plan = (value = '') => render(<PrizeAmountField id="prize" kind="plan" value={value} onChange={() => undefined} />, { wrapper: providers().wrapper })

describe('a prize', () => {
  it('asks for its amount in its own unit', () => {
    const { rerender } = render(<PrizeAmountField id="prize" kind="wallet" value="" onChange={() => undefined} />)
    expect(screen.getByLabelText('مبلغ جایزه (تومان)')).toBeTruthy()

    rerender(<PrizeAmountField id="prize" kind="traffic" value="" onChange={() => undefined} />)
    expect(screen.getByLabelText('حجم جایزه (گیگابایت)').getAttribute('inputmode')).toBe('numeric')

    rerender(<PrizeAmountField id="prize" kind="traffic" value="" onChange={() => undefined} fraction />)
    expect(screen.getByLabelText('حجم جایزه (گیگابایت)').getAttribute('inputmode')).toBe('decimal')

    rerender(<PrizeAmountField id="prize" kind="days" value="" onChange={() => undefined} />)
    expect(screen.getByLabelText('زمان جایزه (روز)').getAttribute('inputmode')).toBe('numeric')
  })

  it('of a plan offers every plan, saying why one cannot be given now', async () => {
    server().on(
      'GET',
      '/api/admin/plans',
      json({ plans: [planRow({ id: 3, name: 'طلایی' }), planRow({ id: 4, name: 'قدیمی', is_active: false }), planRow({ id: 5, name: 'بی‌سرور', unsellable_reason: 'این پلن روی هیچ سروری نیست.' })] }),
    )
    plan()

    await until(() => expect(screen.getByRole('combobox', { name: 'پلن جایزه' }).textContent).toContain('انتخاب پلن'))
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'پلن جایزه' }), { key: 'Enter' })
    expect((await screen.findByRole('option', { name: /قدیمی/ })).textContent).toContain('غیرفعال؛ فقط برای هدیه')
    expect(screen.getByRole('option', { name: /بی‌سرور/ }).textContent).toContain('این پلن روی هیچ سروری نیست.')
  })

  it('of a plan says when the plans could not be read', async () => {
    server().on('GET', '/api/admin/plans', refusal(500, 'خطای سرور.'))
    plan()

    await until(() => expect(screen.getByRole('combobox', { name: 'پلن جایزه' }).textContent).toContain('لیست پلن‌ها خوانده نشد'))
  })
})
