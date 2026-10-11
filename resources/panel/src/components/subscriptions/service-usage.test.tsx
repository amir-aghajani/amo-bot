import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServiceExpiry } from '@/components/subscriptions/service-usage'
import type { SubscriptionRow } from '@/lib/api-types'
import { formatDate } from '@/lib/format'

/*
 * A service's end as the subscriptions screen words it, as the bot does: a date and what is left of it while the service
 * has a client — less than a day away, its hour and the hours left: an hourly plan's —, the term still waiting for the
 * first connection, in its days or its hours — and, once deleted, only the date on record.
 */

type Expiry = Pick<SubscriptionRow, 'status' | 'expires_at' | 'duration_hours' | 'expiring_soon'>

const HOUR = 3_600_000
const IN_A_MONTH = new Date(Date.now() + 30 * 24 * HOUR).toISOString()

describe('a service’s end', () => {
  it('is a date and the time left while the service runs', () => {
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: IN_A_MONTH, duration_hours: 90 * 24, expiring_soon: false } satisfies Expiry} />)

    expect(screen.getByText(formatDate(IN_A_MONTH, { dateStyle: 'medium' }))).toBeTruthy()
    expect(screen.getByText('۳۰ روز مانده')).toBeTruthy()
  })

  it('says its hour and the hours left once it is less than a day away — an hourly plan’s service', () => {
    const inFiveHours = new Date(Date.now() + 5 * HOUR - 60_000).toISOString()
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: inFiveHours, duration_hours: 6, expiring_soon: true } satisfies Expiry} />)

    expect(screen.getByText(formatDate(inFiveHours, { dateStyle: 'medium', timeStyle: 'short' }))).toBeTruthy()
    expect(screen.getByText('۵ ساعت مانده')).toBeTruthy()
  })

  it('waits for the first connection while the panel has not started the clock, its term from then', () => {
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: null, duration_hours: 90 * 24, expiring_soon: false } satisfies Expiry} />)

    expect(screen.getByText('در انتظار اولین اتصال')).toBeTruthy()
    expect(screen.getByText('۹۰ روز از اولین اتصال')).toBeTruthy()
  })

  it('shows an hourly plan’s term waiting for the first connection in hours', () => {
    const { rerender } = render(<ServiceExpiry subscription={{ status: 'active', expires_at: null, duration_hours: 6, expiring_soon: false } satisfies Expiry} />)
    expect(screen.getByText('۶ ساعت از اولین اتصال')).toBeTruthy()

    rerender(<ServiceExpiry subscription={{ status: 'active', expires_at: null, duration_hours: 30, expiring_soon: false } satisfies Expiry} />)
    expect(screen.getByText('۱ روز و ۶ ساعت از اولین اتصال')).toBeTruthy()
  })

  it('never ends without a term', () => {
    render(<ServiceExpiry subscription={{ status: 'active', expires_at: null, duration_hours: 0, expiring_soon: false } satisfies Expiry} />)

    expect(screen.getByText('نامحدود')).toBeTruthy()
    expect(screen.queryByText('در انتظار اولین اتصال')).toBeNull()
  })

  it('is only the date on record once the service is deleted — nothing is left of it, nothing waits', () => {
    const { container, rerender } = render(<ServiceExpiry subscription={{ status: 'deleted', expires_at: IN_A_MONTH, duration_hours: 90 * 24, expiring_soon: false } satisfies Expiry} />)
    expect(container.textContent).not.toMatch(/مانده/)
    expect(container.textContent).not.toBe('')

    rerender(<ServiceExpiry subscription={{ status: 'deleted', expires_at: null, duration_hours: 90 * 24, expiring_soon: false } satisfies Expiry} />)
    expect(screen.queryByText('در انتظار اولین اتصال')).toBeNull()
  })
})
