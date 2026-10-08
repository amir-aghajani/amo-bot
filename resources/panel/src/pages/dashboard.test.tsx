import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { DashboardResponse } from '@/lib/api-types'
import { DashboardPage } from '@/pages/dashboard'
import { signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The overview (pages/dashboard): a read that failed with nothing to show says so, and leaves every card at «—» — no
 * zero it does not know, no "all clear", nothing pulsing for ever; another range keeps the last one's figures on screen,
 * dimmed and worded as that range's, until its own arrive.
 */

// The chart draws to its column's measured width, which a page without a layout lacks; its states are its own test's
// (overview-chart.test), its drawing area-chart.test's.
vi.mock('@/components/dashboard/overview-chart', () => ({ OverviewChart: () => null }))

/** The overview of a range of `days` as the server answers it, `orders` placed in it. */
function overview(orders: number, days: 7 | 30 = 30): DashboardResponse {
  return {
    range: { days, from: days === 30 ? '2026-09-07' : '2026-09-30', to: '2026-10-06' },
    generated_at: '2026-10-06T09:00:00Z',
    kpis: {
      revenue: { value: '150000.00', previous: '100000.00' },
      orders: { value: orders, previous: 0 },
      new_users: { value: 4, previous: 2 },
      active_subscriptions: 9,
      users_total: 40,
    },
    series: [],
    attention: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0, pending_reviews: 0, pending_orders: 0, servers_with_errors: 0, expiring_soon: 0 },
    traffic_shortage: null,
    expiring_days: 3,
    recent_orders: [],
    recent_users: [],
  }
}

function open() {
  render(
    <TooltipProvider>
      <DashboardPage />
    </TooltipProvider>,
    { wrapper: signedIn().wrapper },
  )
}

const figures = () => within(screen.getByRole('region', { name: 'شاخص‌های کلیدی' }))
const section = (name: string) => within(screen.getByRole('region', { name }))
/** What holds the range's own figures — the headline cards and the chart. */
const rangeFigures = () => screen.getByRole('region', { name: 'شاخص‌های کلیدی' }).parentElement

describe('a dashboard whose read failed', () => {
  it('says so, every card at «—»: no zero, no "all clear", nothing pulsing', async () => {
    server().on('GET', '/api/admin/dashboard', refusal(500, 'خطای سرور.'))
    open()

    expect(await screen.findByText('داشبورد بارگذاری نشد.')).toBeTruthy()
    expect(figures().getAllByText('—')).toHaveLength(4)
    expect(figures().queryByText('۰')).toBeNull()
    expect(figures().queryByText('بدون مقایسه')).toBeNull()
    expect(screen.queryByText('همه‌چیز مرتب است')).toBeNull()
    expect(section('آخرین سفارش‌ها').getByText('—')).toBeTruthy()
    expect(section('مشتریان جدید').getByText('—')).toBeTruthy()
    // The chart's chunk arrives in a moment; then nothing waits on anything.
    await until(() => expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0))
  })
})

describe('another range', () => {
  it('keeps the last range’s figures on screen, dimmed and worded as that range’s, until its own arrive', async () => {
    const reads = server().hold('GET', '/api/admin/dashboard')
    open()
    await until(() => expect(reads.waiting).toBe(1))
    reads.answer(json(overview(12)))
    await until(() => expect(figures().getByText('۱۲')).toBeTruthy())

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'بازه ۳۰ روز گذشته' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: '۷ روز گذشته' }))

    await until(() => expect(reads.waiting).toBe(1))
    expect(
      server()
        .sent('GET', '/api/admin/dashboard')
        .map((request) => request.query.get('range')),
    ).toEqual(['30', '7'])
    expect(figures().getByText('۱۲')).toBeTruthy()
    expect(rangeFigures()?.getAttribute('aria-busy')).toBe('true')
    expect(rangeFigures()?.className).toContain('opacity-60')
    // The figures on screen are still the 30 days': so are their words — the new range's are not theirs yet.
    expect(figures().getAllByText('نسبت به ۳۰ روز قبل از آن').length).toBeGreaterThan(0)
    expect(figures().queryByText('نسبت به ۷ روز قبل از آن')).toBeNull()
    expect(screen.getByText(/خلاصه وضعیت فروشگاه در ۳۰ روز گذشته/)).toBeTruthy()

    reads.answer(json(overview(3, 7)))

    await until(() => expect(figures().getByText('۳')).toBeTruthy())
    expect(rangeFigures()?.getAttribute('aria-busy')).toBeNull()
    expect(rangeFigures()?.className).not.toContain('opacity-60')
    expect(figures().getAllByText('نسبت به ۷ روز قبل از آن').length).toBeGreaterThan(0)
    expect(screen.getByText(/خلاصه وضعیت فروشگاه در ۷ روز گذشته/)).toBeTruthy()
  })
})
