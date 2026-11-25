import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AgentDashboardPage } from '@/apps/agent/pages/dashboard'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { AccountResponse, AgencyStanding, Session } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { agencyStanding, dashboardResponse } from '@/test/rows'
import { json, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * The agent's overview (apps/agent/pages/dashboard): their terms of selling are said to them, of their own bot, with
 * what lifts a suspension — never as the owner's panel says them of an agent, nor leading to the owner's agents list —;
 * of renewing, by their account: where to ask, or that their request waits — no button promised then, none showing.
 */

// The chart draws to its column's measured width, which a page without a layout lacks.
vi.mock('@/components/dashboard/overview-chart', () => ({ OverviewChart: () => null }))

const AGENT: Session = { name: '@reza_shop_bot', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active', selling_suspended: 'expired' } }

const PASSED = agencyStanding({ expires_at: '2026-10-01T10:00:00Z', suspension: { reason: 'expired', since: '2026-10-01T10:00:00Z' } })

/** The agent's account, its terms `standing`, its renewal and what may be asked as `more` says. */
function account(standing: AgencyStanding, more: Partial<AccountResponse['account']>): AccountResponse {
  return {
    traffic_shortage: null,
    account: {
      bot: { id: 2, username: 'reza_shop_bot', title: 'فروشگاه رضا', status: 'active', connected: true, problem: null, traffic_balance: 0, connected_at: '2026-10-03T09:00:00Z' },
      agent: { id: 10, name: 'رضا', username: 'reza', telegram_id: 123456789, email: null },
      level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
      credit_limit: '0.00',
      balance: '0.00',
      standing,
      renewal: null,
      actions: { request_renewal: false },
      ...more,
    },
  }
}

/** The overview of an agent whose terms are `standing`, their account as `more` says; its selling notice once drawn. */
async function open(standing: AgencyStanding, more: Partial<AccountResponse['account']>) {
  server()
    .on('GET', '/api/agent/auth/me', json({ session: AGENT }))
    .on('GET', '/api/agent/dashboard', json(dashboardResponse({ agency_standing: standing })))
    .on('GET', '/api/agent/account', json(account(standing, more)))
  render(
    <TooltipProvider>
      <AgentDashboardPage />
    </TooltipProvider>,
    { wrapper: signedIn({ session: AGENT }).wrapper },
  )
  await until(() => expect(server().sent('GET', '/api/agent/account')).toHaveLength(1))
  return () => screen.getByText(/فروش ربات شما متوقف است/).closest('li')?.textContent ?? ''
}

describe('the agent’s terms of selling', () => {
  it('say their bot’s selling stopped, and what lifts it, to them', async () => {
    const notice = await open(PASSED, { actions: { request_renewal: true } })

    await until(() => expect(notice()).toContain('فروش ربات شما متوقف است: تاریخ نمایندگی گذشته است.'))
    expect(notice()).toContain('برای تمدید، در «حساب نمایندگی» «درخواست تمدید نمایندگی» را بزنید.')
    expect(notice()).not.toContain('این نماینده')
    expect(screen.queryByRole('link', { name: 'نمایندگان' })).toBeNull()
  })

  it('say a renewal request waits — never send them to a button their account does not show', async () => {
    const notice = await open(PASSED, { renewal: { id: 3, status: 'pending', extended_to: null, reason: null, created_at: '2026-10-05T09:00:00Z', decided_at: null } })

    await until(() => expect(notice()).toContain('درخواست تمدید شما در انتظار بررسی است.'))
    expect(notice()).not.toContain('«درخواست تمدید نمایندگی» را بزنید')
  })
})
