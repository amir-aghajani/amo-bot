import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { PrizeForm } from '@/components/wheel/prize-form'
import { providers, until } from '@/test/render'
import { wheelPrizeRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A lucky wheel slice's form (components/wheel/prize-form): each kind's amount is its own — a number typed for Toman
 * never becomes as many GB once the kind changes — and comes back on a switch back; only the kind's own is sent.
 */

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

/** The slice's kind picked from its list. */
function pick(kind: string) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: 'جایزه' }), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: kind }))
}

function open() {
  render(<PrizeForm prize={null} invalidates={[]} onSaved={() => undefined} onCancel={() => undefined} />, { wrapper: providers().wrapper })
}

describe('a slice’s amount', () => {
  it('is its kind’s own: another kind starts empty, and the first comes back on a switch back', () => {
    open()
    type('مبلغ جایزه (تومان)', '5000')

    pick('حجم')
    expect((screen.getByLabelText('حجم جایزه (گیگابایت)') as HTMLInputElement).value).toBe('')

    type('حجم جایزه (گیگابایت)', '2.5')
    pick('شارژ کیف پول')
    expect((screen.getByLabelText('مبلغ جایزه (تومان)') as HTMLInputElement).value).toBe('5000')
    pick('حجم')
    expect((screen.getByLabelText('حجم جایزه (گیگابایت)') as HTMLInputElement).value).toBe('2.5')
  })

  it('is sent for its kind alone', async () => {
    vi.spyOn(toast, 'success')
    server().on('POST', '/api/admin/wheel/prizes', json({ prize: wheelPrizeRow({ id: 9, label: '۲ گیگ', kind: 'traffic', amount: '2.00' }) }, 201))
    open()
    type('متن روی گردونه', '۲ گیگ')
    type('مبلغ جایزه (تومان)', '5000')
    pick('حجم')
    type('حجم جایزه (گیگابایت)', '2')

    fireEvent.submit(screen.getByLabelText('وزن').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/wheel/prizes')[0]?.body).toEqual({ label: '۲ گیگ', kind: 'traffic', weight: '1', enabled: true, amount: '2' }))
  })
})
