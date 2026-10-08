import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { AccountPage } from '@/apps/agent/pages/account'
import type { AccountResponse, AgentBot, TrafficLinesResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, noContent, refusal, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * The agent's «حساب نمایندگی» (apps/agent/pages/account): an account that could not be read says so, its figures and
 * its bot «—» — nothing pulsing for ever; the bot's date is when its token was first handed over, and a bot whose token
 * is gone since is asked for it again — a warning: it stopped —, one never connected for its first; where traffic is
 * bought is said once, by the notice while the bot's traffic sells nothing; and every other browser signed in to the
 * agent's panel is signed out from here, after a second look, this one staying.
 */

const GB = 1024 ** 3

const BOT: AgentBot = { id: 2, username: 'agent_shop_bot', title: 'فروشگاه نماینده', status: 'active', connected: true, problem: null, traffic_balance: 50 * GB, connected_at: '2026-10-03T09:00:00Z' }

/** The account as the server answers it, its bot as `bot` says. */
function account(bot: Partial<AgentBot> = {}, shortage: AccountResponse['traffic_shortage'] = null): AccountResponse {
  return {
    traffic_shortage: shortage,
    account: {
      bot: { ...BOT, ...bot },
      agent: { id: 10, name: 'رضا', username: 'reza', telegram_id: 123456789, email: null },
      level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
      credit_limit: '0.00',
      balance: '25000.00',
    },
  }
}

const skeletons = () => document.querySelectorAll('[data-slot="skeleton"]')

function open() {
  server().on('GET', '/api/agent/account/traffic', json({ lines: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } satisfies TrafficLinesResponse))
  render(<AccountPage />, { wrapper: providers().wrapper })
}

describe('an account that could not be read', () => {
  it('says so, its figures and its bot «—», nothing pulsing', async () => {
    server().on('GET', '/api/agent/account', refusal(500, 'خطای سرور.'))
    open()

    expect(await screen.findByText('حساب نمایندگی بارگذاری نشد.')).toBeTruthy()
    // Three figures and the bot's card.
    expect(screen.getAllByText('—')).toHaveLength(4)
    expect(screen.queryByText('نمایندگی فعال نیست')).toBeNull()
    await until(() => expect(skeletons()).toHaveLength(0))
  })
})

describe('the agent’s bot', () => {
  it('says when its token was first handed over', async () => {
    server().on('GET', '/api/agent/account', json(account()))
    open()

    expect(await screen.findByText('اولین اتصال')).toBeTruthy()
    expect(screen.queryByText('وصل شده')).toBeNull()
    expect(screen.queryByText(/وصل نشده/)).toBeNull()
  })

  it('whose token is gone since is asked for it again, as a warning — the bot stopped', async () => {
    server().on('GET', '/api/agent/account', json(account({ connected: false })))
    open()

    const stopped = await screen.findByText(/ربات شما دیگر وصل نیست/)
    expect(stopped.closest('[class*="border-warning-line"]')).toBeTruthy()
    expect(screen.getByText('اولین اتصال')).toBeTruthy()
    expect(screen.getByText('وصل نشده')).toBeTruthy()
  })

  it('never connected is asked for its first token, as the next step', async () => {
    server().on('GET', '/api/agent/account', json(account({ connected: false, connected_at: null, username: null, title: null })))
    open()

    const first = await screen.findByText(/ربات شما هنوز وصل نشده است/)
    expect(first.closest('[class*="border-info-line"]')).toBeTruthy()
  })
})

describe('where traffic is bought', () => {
  it('is said under the traffic left', async () => {
    server().on('GET', '/api/agent/account', json(account()))
    open()

    expect(await screen.findAllByText(/خرید حجم: ربات اصلی/)).toHaveLength(1)
  })

  it('is said once while the traffic sells nothing: by the notice', async () => {
    server().on('GET', '/api/agent/account', json(account({ traffic_balance: 0 }, { balance: 0, smallest_plan: 20 * GB })))
    open()

    expect(await screen.findByText('حجم ربات تمام شده است.')).toBeTruthy()
    expect(screen.getAllByText(/خرید حجم: ربات اصلی/)).toHaveLength(1)
  })
})

describe('the other browsers signed in to the panel', () => {
  it('are signed out after a second look, this one staying — and a refusal is said in the dialog', async () => {
    vi.spyOn(toast, 'success')
    server().on('GET', '/api/agent/account', json(account()))
    let answer = refusal(422, 'این کار الان انجام نشد.')
    server().on('POST', '/api/agent/auth/sessions/end', () => answer)
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'خروج از مرورگرهای دیگر' }))
    const dialog = await screen.findByRole('dialog', { name: 'خروج از مرورگرهای دیگر' })
    expect(server().sent('POST', '/api/agent/auth/sessions/end')).toEqual([])

    fireEvent.click(within(dialog).getByRole('button', { name: 'خروج از بقیه' }))
    expect(await within(dialog).findByText('این کار الان انجام نشد.')).toBeTruthy()

    answer = noContent
    fireEvent.click(within(dialog).getByRole('button', { name: 'خروج از بقیه' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith('از همه مرورگرهای دیگر خارج شدید'))
    expect(
      server()
        .sent('POST', '/api/agent/auth/sessions/end')
        .map((request) => request.body),
    ).toEqual([undefined, undefined])
  })
})
