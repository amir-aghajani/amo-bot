import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { LoyaltyTierResponse, LoyaltyTierRow, LoyaltyTiersResponse } from '@/lib/api-types'
import { LoyaltyPage } from '@/pages/loyalty'
import { providers, until } from '@/test/render'
import { json, noContent, server } from '@/test/server'

/*
 * «سطوح وفاداری» (pages/loyalty, components/loyalty/tier-form): the tiers by their threshold — each its discount, its
 * extra cashback and its customers —, one made with its threshold in whole Toman and read again (it may reorder the list
 * and move customers), and one deleted saying where its customers go.
 */

const tier = (overrides: Partial<LoyaltyTierRow>): LoyaltyTierRow => ({ id: 1, name: 'نقره‌ای', min_spent: '500000.00', discount_percent: 3, cashback_percent: 0, customers: 4, ...overrides })

function open(tiers: LoyaltyTierRow[]) {
  server().on('GET', '/api/admin/loyalty/tiers', json({ tiers } satisfies LoyaltyTiersResponse))
  render(<LoyaltyPage />, { wrapper: providers().wrapper })
}

describe('the loyalty tiers page', () => {
  it('lists each tier with its threshold, its percents and its customers', async () => {
    open([tier({ id: 1, name: 'برنزی', min_spent: '0.00', discount_percent: 0, customers: 12 }), tier({ id: 2 })])

    const silver = (await screen.findByRole('button', { name: 'نقره‌ای' })).closest('tr')
    expect(silver?.textContent).toContain('از ۵۰۰٬۰۰۰ تومان')
    expect(silver?.textContent).toContain('۳٪')
    expect(screen.getByRole('button', { name: 'برنزی' }).closest('tr')?.textContent).toContain('از ابتدا')
  })

  it('says a shop without tiers has no program', async () => {
    open([])

    expect(await screen.findByText('هنوز سطحی تعریف نشده است')).toBeTruthy()
  })

  it('makes a tier and reads the list again', async () => {
    open([])
    server().on('POST', '/api/admin/loyalty/tiers', json({ tier: tier({ id: 5, name: 'طلایی', customers: 0 }) } satisfies LoyaltyTierResponse))

    fireEvent.click((await screen.findAllByRole('button', { name: 'افزودن سطح' }))[0]!)
    const dialog = within(await screen.findByRole('dialog', { name: 'افزودن سطح' }))
    fireEvent.change(dialog.getByLabelText('نام سطح'), { target: { value: 'طلایی' } })
    fireEvent.change(dialog.getByLabelText('حد خرید (تومان)'), { target: { value: '۵۰۰٬۰۰۰' } })
    fireEvent.change(dialog.getByLabelText('درصد تخفیف'), { target: { value: '3' } })
    fireEvent.click(dialog.getByRole('button', { name: 'افزودن سطح' }))

    await until(() => expect(server().sent('POST', '/api/admin/loyalty/tiers')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/loyalty/tiers')[0]?.body).toEqual({ name: 'طلایی', min_spent: '500000', discount_percent: '3', cashback_percent: '0' })
    await until(() => expect(server().sent('GET', '/api/admin/loyalty/tiers').length).toBeGreaterThan(1))
  })

  it('deletes a tier, saying where its customers go', async () => {
    open([tier({})])
    server().on('DELETE', '/api/admin/loyalty/tiers/1', noContent)

    fireEvent.pointerDown(await screen.findByRole('button', { name: 'عملیات نقره‌ای' }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'حذف سطح' }))
    expect(dialog.getByText(/۴ مشتری این سطح به سطح پایین‌تر می‌روند/)).toBeTruthy()
    fireEvent.click(dialog.getByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('DELETE', '/api/admin/loyalty/tiers/1')).toHaveLength(1))
  })
})
