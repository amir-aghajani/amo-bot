import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServicePlan } from '@/components/subscriptions/service-plan'
import { claimedServiceRow, subscriptionRow } from '@/test/rows'

/*
 * The line under a service's name in the lists (components/subscriptions/service-plan): a free test account is marked
 * «تست» — it has no plan —, a sold one names its plan, one its customer built says so, as the bot does; one the customer
 * added by its link is marked «با لینک», and without a plan (not renewed yet) says it has none.
 */

describe('a service’s plan line', () => {
  it('marks a free test account', () => {
    render(<ServicePlan subscription={subscriptionRow({ is_test: true, plan: null })} />)

    expect(screen.getByText('تست').getAttribute('title')).toBe('اکانت تست رایگان')
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
  })

  it('names a sold one’s plan — or, of one its customer built, says so as the bot does: no plan was ever deleted from under it', () => {
    const { rerender } = render(<ServicePlan subscription={subscriptionRow()} />)
    expect(screen.getByText('طلایی')).toBeTruthy()

    rerender(<ServicePlan subscription={subscriptionRow({ plan: null })} />)
    expect(screen.getByText('سرویس دلخواه')).toBeTruthy()
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
  })
})

describe('a service added by its link', () => {
  it('is marked so, and says it has no plan rather than looking broken — not «till its first renewal»: a renewed one’s plan may be deleted too', () => {
    const { rerender } = render(<ServicePlan subscription={claimedServiceRow()} />)

    expect(screen.getByText('با لینک').getAttribute('title')).toBe('افزوده‌شده با لینک')
    expect(screen.getByText('بدون پلن')).toBeTruthy()
    expect(screen.queryByText(/اولین/)).toBeNull()
    expect(screen.queryByText('پلن حذف شده')).toBeNull()

    rerender(<ServicePlan subscription={claimedServiceRow({ plan: { id: 3, name: 'طلایی' } })} />)
    expect(screen.getByText('طلایی')).toBeTruthy()
    expect(screen.getByText('با لینک')).toBeTruthy()
  })

  it('is no mark on any other service', () => {
    render(<ServicePlan subscription={subscriptionRow()} />)

    expect(screen.queryByText('با لینک')).toBeNull()
  })
})
