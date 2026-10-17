import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { OwnerDashboardPage } from '@/apps/admin/pages/dashboard'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { AgencyStanding } from '@/lib/api-types'
import { AGENT_SHOP, signedIn } from '@/test/render'
import { agencyStanding, dashboardResponse } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The owner's overview (apps/admin/pages/dashboard): an agent's shop opened in the owner's panel says the agent's terms
 * of selling of the agent — the agents list's words, leading there —, never as the agent's own panel says them to the
 * agent («… شما», «… با پشتیبانی فروشگاه اصلی تماس بگیرید»).
 */

// The chart draws to its column's measured width, which a page without a layout lacks; the system card has its own test.
vi.mock('@/components/dashboard/overview-chart', () => ({ OverviewChart: () => null }))
vi.mock('@/components/dashboard/system-card', () => ({ SystemCard: () => null }))

/** The agent's shop open in the owner's panel, its terms `standing`; the notice once it is on screen. */
async function open(standing: AgencyStanding) {
  server().on('GET', '/api/admin/dashboard', json(dashboardResponse({ agency_standing: standing })))
  render(
    <TooltipProvider>
      <OwnerDashboardPage />
    </TooltipProvider>,
    { wrapper: signedIn({ session: AGENT_SHOP }).wrapper },
  )
  return (await screen.findByText(/این نماینده/)).closest('li')
}

describe('an agent’s terms of selling, in their shop opened by the owner', () => {
  it('say the agent’s selling stopped, and why, of the agent — leading to the agents list', async () => {
    const notice = await open(agencyStanding({ expires_at: '2026-10-01T10:00:00Z', suspension: { reason: 'expired', since: '2026-10-01T10:00:00Z' } }))

    expect(notice?.textContent).toContain('فروش ربات این نماینده متوقف است: تاریخ نمایندگی گذشته است. ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند.')
    expect(screen.getByRole('link', { name: 'نمایندگان' }).getAttribute('href')).toBe('/agents/list')
    expect(notice?.textContent).not.toMatch(/شما|پشتیبانی فروشگاه اصلی/)
    expect(screen.getByText('۱ مورد منتظر اقدام شماست')).toBeTruthy()
  })

  it('warn of the agent’s end in their row’s words', async () => {
    const notice = await open(agencyStanding({ expires_at: new Date(Date.now() + 2 * 86_400_000).toISOString() }))

    expect(notice?.textContent).toMatch(/پایان نمایندگی این نماینده نزدیک است\. نمایندگی .* به پایان می‌رسد؛ بعد از آن فروش رباتش متوقف می‌شود\./)
    expect(notice?.textContent).not.toMatch(/شما|پشتیبانی فروشگاه اصلی/)
  })
})
