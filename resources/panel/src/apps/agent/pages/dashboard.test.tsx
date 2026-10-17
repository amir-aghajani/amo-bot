import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AgentDashboardPage } from '@/apps/agent/pages/dashboard'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { Session } from '@/lib/api-types'
import { signedIn } from '@/test/render'
import { agencyStanding, dashboardResponse } from '@/test/rows'
import { json, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * The agent's overview (apps/agent/pages/dashboard): their terms of selling are said to them, of their own bot, with
 * what lifts a suspension — never as the owner's panel says them of an agent, nor leading to the owner's agents list.
 */

// The chart draws to its column's measured width, which a page without a layout lacks.
vi.mock('@/components/dashboard/overview-chart', () => ({ OverviewChart: () => null }))

const AGENT: Session = { name: '@reza_shop_bot', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active', selling_suspended: 'expired' } }

describe('the agent’s terms of selling', () => {
  it('say their bot’s selling stopped, and what lifts it, to them', async () => {
    const standing = agencyStanding({ expires_at: '2026-10-01T10:00:00Z', suspension: { reason: 'expired', since: '2026-10-01T10:00:00Z' } })
    server()
      .on('GET', '/api/agent/auth/me', json({ session: AGENT }))
      .on('GET', '/api/agent/dashboard', json(dashboardResponse({ agency_standing: standing })))
    render(
      <TooltipProvider>
        <AgentDashboardPage />
      </TooltipProvider>,
      { wrapper: signedIn({ session: AGENT }).wrapper },
    )

    const notice = (await screen.findByText(/فروش ربات شما متوقف است/)).closest('li')
    expect(notice?.textContent).toContain('فروش ربات شما متوقف است: تاریخ نمایندگی گذشته است.')
    expect(notice?.textContent).toContain('برای تمدید نمایندگی با پشتیبانی فروشگاه اصلی تماس بگیرید.')
    expect(notice?.textContent).not.toContain('این نماینده')
    expect(screen.queryByRole('link', { name: 'نمایندگان' })).toBeNull()
  })
})
