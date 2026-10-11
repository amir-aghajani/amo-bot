import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { LoyaltyCard } from '@/components/customer/loyalty-card'
import { providers } from '@/test/render'
import { loyaltyStanding } from '@/test/rows'

/*
 * A customer's loyalty tier on their page (components/customer/loyalty-card): the tier with its perks — none said for a
 * percent of nothing —, what they spent, and the next tier with what is left; the first still to reach, the top reached.
 */

function show(loyalty = loyaltyStanding()) {
  render(<LoyaltyCard loyalty={loyalty} />, { wrapper: providers().wrapper })
}

describe('the loyalty card', () => {
  it('says the tier, its perks, what was spent and what is left to the next', () => {
    show(loyaltyStanding({ tier: { id: 2, name: 'نقره‌ای', discount_percent: 3, cashback_percent: 0 }, spent: '120000.00', next: { name: 'طلایی', min_spent: '300000.00', missing: '180000.00' } }))

    expect(screen.getByRole('link', { name: 'نقره‌ای' }).getAttribute('href')).toBe('/loyalty')
    expect(screen.getByText(/۳٪ تخفیف/)).toBeTruthy()
    expect(screen.queryByText(/کش‌بک اضافه/)).toBeNull()
    expect(screen.getByText('۱۲۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText(/۱۸۰٬۰۰۰ تومان مانده/)).toBeTruthy()
  })

  it('says a customer below the first tier has none yet, and one on the top tier has none to reach', () => {
    show(loyaltyStanding({ next: { name: 'برنزی', min_spent: '100000.00', missing: '100000.00' } }))
    expect(screen.getByText('هنوز به اولین سطح نرسیده است')).toBeTruthy()
  })

  it('— the top tier', () => {
    show(loyaltyStanding({ tier: { id: 3, name: 'طلایی', discount_percent: 0, cashback_percent: 0 }, spent: '900000.00' }))
    expect(screen.getByText('بالاترین سطح')).toBeTruthy()
  })
})
