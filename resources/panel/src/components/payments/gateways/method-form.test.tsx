import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { MethodForm } from '@/components/payments/gateways/method-form'
import type { DriverDescription } from '@/lib/api-types'
import { providers } from '@/test/render'
import { cardDriverRow, cardMethodRow, cubePayDriverRow, cubePayMethodRow, paymentCardRow } from '@/test/rows'

/*
 * A payment method's form (components/payments/gateways/method-form), as an edit opens it: `dirty` reads its bounds and
 * its cards' caps as the server does — typed back with their thousands set apart, or in Persian digits, they are no
 * change —, and an online gateway's form says what its driver says of setting it up — the address it is to call among
 * it, left to right — where a card method's says none.
 */

/** The form over `method` of `driver`. */
function edit(driver: DriverDescription, method: Parameters<typeof MethodForm>[0]['method']) {
  render(<MethodForm driver={driver} method={method} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

const save = () => screen.getByRole('button', { name: 'ذخیره تغییرات' })
const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('a method’s form', () => {
  it('is no change while its bounds and its cards’ caps are typed back as the server reads them', () => {
    edit(cardDriverRow(), cardMethodRow({ min_amount: '50000.00', max_amount: '5000000.00', cards: [paymentCardRow({ daily_cap: '300000.00' })] }))

    type(/^کمترین مبلغ/, '۵۰٬۰۰۰')
    type(/^بیشترین مبلغ/, '5,000,000')
    type(/^سقف روزانه/, '۳۰۰٬۰۰۰ تومان')
    expect(save().getAttribute('aria-disabled')).toBe('true')

    type(/^سقف روزانه/, '250000')
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })

  it('of an online gateway says what its driver says of setting it up, an address in it left to right', () => {
    const webhook = 'https://shop.example/payments/webhooks/variza'
    const driver: DriverDescription = { ...cubePayDriverRow(), notes: [`در پروفایل پنل واریزا، آدرس callback_url را ${webhook} بگذارید`, 'هر صفحه پرداخت ۳۰ دقیقه مهلت دارد'] }
    edit(driver, cubePayMethodRow())

    const notes = within(screen.getByRole('list', { name: 'درباره این درگاه' }))
    expect(notes.getAllByRole('listitem')).toHaveLength(2)
    expect(notes.getByText(webhook).getAttribute('dir')).toBe('ltr')
  })

  it('of a card method says nothing of a gateway', () => {
    edit(cardDriverRow(), cardMethodRow())
    expect(screen.queryByRole('list', { name: 'درباره این درگاه' })).toBeNull()
  })

  it('gives its name field an example of its own kind: a bank beside a card method’s, a gateway’s own name else', () => {
    edit(cardDriverRow(), cardMethodRow())
    expect(screen.getByLabelText('نام روش').getAttribute('placeholder')).toBe('کارت به کارت (ملت)')
    cleanup()

    const gateway = cubePayDriverRow()
    edit(gateway, cubePayMethodRow())
    expect(screen.getByLabelText('نام روش').getAttribute('placeholder')).toBe(gateway.label)
  })
})
