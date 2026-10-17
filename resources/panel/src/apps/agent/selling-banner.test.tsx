import { cleanup, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SellingBanner } from '@/apps/agent/selling-banner'
import type { AccountResponse, AgencyStanding } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { agencyStanding } from '@/test/rows'
import { json, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * The agent's strip over every page (apps/agent/selling-banner): while their shop sells nothing it says why and leads to
 * their account — never over the account itself, which says it in full, nor over the dashboard, whose attention card
 * says it; an end near warns; all well, there is no strip.
 */

/** The agent's account, with `standing` as their terms of selling. */
function account(standing: AgencyStanding): AccountResponse {
  return {
    traffic_shortage: null,
    account: {
      bot: { id: 2, username: 'agent_shop_bot', title: 'فروشگاه نماینده', status: 'active', connected: true, problem: null, traffic_balance: 0, connected_at: null },
      agent: { id: 10, name: 'رضا', username: 'reza', telegram_id: 123456789, email: null },
      level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
      credit_limit: '0.00',
      balance: '0.00',
      standing,
    },
  }
}

function open(standing: AgencyStanding, at = '/plans') {
  server().on('GET', '/api/agent/account', json(account(standing)))
  const { client, wrapper } = providers({ at })
  render(<SellingBanner />, { wrapper })
  return client
}

/** The strip at `at` once the account it is drawn from has been read — whatever it then says, it says. */
async function read(standing: AgencyStanding, at?: string) {
  const client = open(standing, at)
  await until(() => expect(client.getQueryState(queryKeys.account)?.status).toBe('success'))
}

describe('the selling strip', () => {
  it('says why the bot sells nothing and leads to the account', async () => {
    open(agencyStanding({ expires_at: '2026-10-01T10:00:00Z', suspension: { reason: 'expired', since: '2026-10-01T10:00:00Z' } }))

    const strip = await screen.findByText(/فروش ربات شما متوقف است/)
    expect(strip.textContent).toContain('فروش ربات شما متوقف است: تاریخ نمایندگی گذشته است.')
    expect(screen.getByRole('link', { name: 'حساب نمایندگی' }).getAttribute('href')).toBe('/account')
    expect(strip.closest('[role="status"]')).toBeTruthy()
  })

  it('warns of an end within the warned days', async () => {
    open(agencyStanding({ expires_at: new Date(Date.now() + 86_400_000).toISOString() }))

    expect((await screen.findByText(/به پایان می‌رسد/)).textContent).toMatch(/نمایندگی شما .* به پایان می‌رسد\./)
  })

  it('is not over the account, whose notice says the whole of it — at its address with a closing slash too', async () => {
    await read(agencyStanding({ suspension: { reason: 'manual', since: '2026-10-01T10:00:00Z' } }), '/account')
    expect(screen.queryByRole('status')).toBeNull()
    cleanup()

    await read(agencyStanding({ suspension: { reason: 'manual', since: '2026-10-01T10:00:00Z' } }), '/account/')
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('is not over the dashboard, whose attention card says it already', async () => {
    await read(agencyStanding({ suspension: { reason: 'manual', since: '2026-10-01T10:00:00Z' } }), '/')

    expect(screen.queryByRole('status')).toBeNull()
  })

  it('is not there while all is well', async () => {
    await read(agencyStanding())

    expect(screen.queryByRole('status')).toBeNull()
  })
})
