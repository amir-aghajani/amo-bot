import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { DiscountCard } from '@/components/customer/discount-card'
import { providers, until } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «تخفیف اختصاصی» on a customer's page (components/customer/discount-card): their own percent, or none, set or taken
 * away in a dialog, the customer's row as the server answers it put in place.
 */

describe('the customer’s own discount', () => {
  it('says what they have, and saves another', async () => {
    const saved = vi.fn()
    render(<DiscountCard customer={userRow({ discount_percent: 10 })} invalidates={[]} onSaved={saved} />, { wrapper: providers().wrapper })
    server().on('PUT', '/api/admin/users/10/discount', json({ user: userRow({ discount_percent: 15 }) }))

    expect(screen.getByText('۱۰٪')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'تغییر' }))
    fireEvent.change(await screen.findByLabelText('درصد تخفیف'), { target: { value: '۱۵' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/users/10/discount')[0]?.body).toEqual({ percent: '۱۵' }))
    await until(() => expect(saved).toHaveBeenCalledWith(expect.objectContaining({ discount_percent: 15 })))
  })

  it('says none for a customer without one', () => {
    render(<DiscountCard customer={userRow()} invalidates={[]} onSaved={() => {}} />, { wrapper: providers().wrapper })

    expect(screen.getByText('ندارد.')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'تعیین' })).toBeTruthy()
  })
})
