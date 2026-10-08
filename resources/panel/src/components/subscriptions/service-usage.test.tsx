import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServiceExpiry } from '@/components/subscriptions/service-usage'
import type { SubscriptionRow } from '@/lib/api-types'

/*
 * A service's end as the subscriptions screen words it: a date and what is left of it while the service has a client,
 * the term still waiting for the first connection — and, once deleted, only the date on record.
 */

type Expiry = Pick<SubscriptionRow, 'status' | 'expires_at' | 'duration_days' | 'expiring_soon'>

const IN_A_MONTH = new Date(Date.now() + 30 * 86_400_000).toISOString()

describe('a service’s end', () => {
  it('is a date and the time left while the service runs', () => {
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: IN_A_MONTH, duration_days: 90, expiring_soon: false } satisfies Expiry} />)

    expect(screen.getByText(/مانده/)).toBeTruthy()
  })

  it('waits for the first connection while the panel has not started the clock', () => {
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: null, duration_days: 90, expiring_soon: false } satisfies Expiry} />)

    expect(screen.getByText('در انتظار اولین اتصال')).toBeTruthy()
  })

  it('is only the date on record once the service is deleted — nothing is left of it, nothing waits', () => {
    const { container, rerender } = render(<ServiceExpiry subscription={{ status: 'deleted', expires_at: IN_A_MONTH, duration_days: 90, expiring_soon: false } satisfies Expiry} />)
    expect(container.textContent).not.toMatch(/مانده/)
    expect(container.textContent).not.toBe('')

    rerender(<ServiceExpiry subscription={{ status: 'deleted', expires_at: null, duration_days: 90, expiring_soon: false } satisfies Expiry} />)
    expect(screen.queryByText('در انتظار اولین اتصال')).toBeNull()
  })
})
