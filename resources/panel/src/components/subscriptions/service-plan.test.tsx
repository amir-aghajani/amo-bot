import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServicePlan } from '@/components/subscriptions/service-plan'
import { subscriptionRow } from '@/test/rows'

/*
 * The line under a service's name in the lists (components/subscriptions/service-plan): a free test account is marked
 * «تست» — it has no plan —, a sold one names its plan, or says the plan is gone.
 */

describe('a service’s plan line', () => {
  it('marks a free test account', () => {
    render(<ServicePlan subscription={subscriptionRow({ is_test: true, plan: null })} />)

    expect(screen.getByText('تست').getAttribute('title')).toBe('اکانت تست رایگان')
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
  })

  it('names a sold one’s plan, or says it is gone', () => {
    const { rerender } = render(<ServicePlan subscription={subscriptionRow()} />)
    expect(screen.getByText('طلایی')).toBeTruthy()

    rerender(<ServicePlan subscription={subscriptionRow({ plan: null })} />)
    expect(screen.getByText('پلن حذف شده')).toBeTruthy()
  })
})
