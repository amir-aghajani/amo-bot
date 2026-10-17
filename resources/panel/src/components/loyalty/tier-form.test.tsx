import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TierForm } from '@/components/loyalty/tier-form'
import type { LoyaltyTierRow } from '@/lib/api-types'
import { providers } from '@/test/render'

/*
 * A loyalty tier's form (components/loyalty/tier-form): its threshold is compared as the request sends it, so one typed
 * back with its separators, or in Persian digits, is no change to save or to ask about.
 */

const GOLD: LoyaltyTierRow = { id: 3, name: 'طلایی', min_spent: '500000.00', discount_percent: 5, cashback_percent: 2, customers: 12 }

const save = () => screen.getByRole('button', { name: 'ذخیره تغییرات' })

describe('a tier’s threshold', () => {
  it('typed back as it was, in any digits and separators, is no change', () => {
    render(<TierForm tier={GOLD} invalidates={[]} onSaved={() => undefined} onCancel={() => undefined} />, { wrapper: providers().wrapper })
    const threshold = screen.getByLabelText('حد خرید (تومان)')

    for (const typed of ['500,000', '۵۰۰٬۰۰۰', ' 500000 ']) {
      fireEvent.change(threshold, { target: { value: typed } })
      expect(save().getAttribute('aria-disabled')).toBe('true')
    }

    fireEvent.change(threshold, { target: { value: '600,000' } })
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })
})
