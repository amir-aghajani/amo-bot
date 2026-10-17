import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { OverviewChart } from '@/components/dashboard/overview-chart'
import type { DashboardResponse, SeriesPoint } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * The period's chart on the overview (components/dashboard/overview-chart): a skeleton while its read runs, «—» when it
 * has nothing to draw — never a skeleton that pulses for ever, nor a comparison without a figure —, and the period's
 * figure and change the server's, as the cards above it say them.
 */

const skeletons = () => document.querySelectorAll('[data-slot="skeleton"]')

describe('the period’s chart', () => {
  it('is «—» with nothing to draw', () => {
    render(<OverviewChart series={undefined} kpis={undefined} />)

    expect(screen.getAllByText('—')).toHaveLength(2)
    expect(screen.queryByText('بدون مقایسه')).toBeNull()
    expect(skeletons()).toHaveLength(0)
  })

  it('waits under a skeleton while it is read', () => {
    render(<OverviewChart series={undefined} kpis={undefined} loading />)

    expect(skeletons().length).toBeGreaterThan(0)
    expect(screen.queryByText('—')).toBeNull()
  })
})

const KPIS: DashboardResponse['kpis'] = {
  revenue: { value: '150000.00', previous: '100000.00' },
  orders: { value: 1, previous: 2 },
  new_users: { value: 0, previous: 0 },
  active_subscriptions: 9,
  users_total: 40,
}

/** Three days whose revenue adds up to less than the server's figure for the period (a payment on its first moment). */
const SERIES: SeriesPoint[] = [
  { date: '2026-10-01', revenue: '100000.00', orders: 1, users: 0 },
  { date: '2026-10-02', revenue: '0.00', orders: 0, users: 0 },
  { date: '2026-10-03', revenue: '20000.00', orders: 0, users: 0 },
]

describe('the period’s figures', () => {
  it('are the server’s — the cards’ above the chart —, never the days added up here', () => {
    render(<OverviewChart series={SERIES} kpis={KPIS} />)

    expect(screen.getByText(formatMoney(150_000))).toBeTruthy()
    expect(screen.getByText('+۵۰٪')).toBeTruthy()
    expect(screen.queryByText(formatMoney(120_000))).toBeNull()
  })

  it('average a count a day under one as a fraction, not as none', () => {
    render(<OverviewChart series={SERIES} kpis={KPIS} />)

    fireEvent.click(screen.getByRole('radio', { name: 'سفارش‌ها' }))

    expect(screen.getByText('میانگین روزانه').textContent).toBe('میانگین روزانه ۰٫۳')
  })
})
