import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { AgentsPage } from '@/apps/admin/pages/agents'
import type { AgencyLevelsResponse, AgencyRequestsResponse, AgencySettingsResponse, AgentHiddenResponse, AgentResponse, AgentRow, AgentsResponse } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { agencyStanding } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The agency's page (apps/admin/pages/agents): the program's numbers that could not be read say so, with a way to ask
 * again, and stay «—» — never zeros, nothing pulsing for ever — while the lists stand; the requests' status pill lists
 * «همه» first, as every pill does, the list still opening on the requests that wait; an agent's shop is a link of the
 * agents list's, opened here or in a new tab; an agent's selling is stopped by hand (with a note) and let go again — never
 * past its end, which is extended instead —, and the servers and plans kept from their shop are ticked in a dialog.
 */

/** Agent 21 «رضا», their bot #7 handed over. */
const REZA: AgentRow = {
  id: 21,
  user: { id: 21, name: 'رضا', username: 'reza', telegram_id: 555_000, email: null },
  status: 'active',
  level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
  credit_limit: '0.00',
  balance: '0.00',
  bot: { id: 7, username: 'reza_shop_bot', title: 'فروشگاه رضا', status: 'active', connected: true, problem: null, traffic_balance: 0, connected_at: '2026-10-01T10:00:00Z' },
  counts: { customers: 0, sold: 0, active: 0 },
  standing: null,
  hidden: { servers: 0, plans: 0 },
}

/** The agents list's row menu, open, and its «باز کردن فروشگاه». */
async function shopItem() {
  fireEvent.pointerDown(await screen.findByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  return screen.findByRole('menuitem', { name: 'باز کردن فروشگاه' })
}

function open(at = '/agents', agents: AgentRow[] = [REZA]) {
  server()
    .on(
      'GET',
      '/api/admin/agency/settings',
      json({ settings: { enabled: false, default_credit: '0.00', traffic_presets: [50, 100], traffic_min: 10, term_days: 0, monthly_minimum: '0.00', warn_days: 3 } } satisfies AgencySettingsResponse),
    )
    .on('GET', '/api/admin/agency/levels', json({ levels: [] } satisfies AgencyLevelsResponse))
    .on('GET', '/api/admin/agency/requests', json({ requests: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1, sort: 'created', dir: 'desc' } } satisfies AgencyRequestsResponse))
    .on('GET', '/api/admin/agency/agents', json({ agents, meta: { page: 1, per_page: 25, total: agents.length, last_page: 1, sort: 'joined', dir: 'desc' } } satisfies AgentsResponse))
  render(<AgentsPage />, { wrapper: signedIn({ at }).wrapper })
}

describe('the program’s numbers', () => {
  it('say their read failed and stay «—», the lists standing', async () => {
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    open()

    expect(await screen.findByText('وضعیت نمایندگی بارگذاری نشد.')).toBeTruthy()
    const numbers = screen.getByRole('region', { name: 'آمار نمایندگی' })
    expect(within(numbers).getAllByText('—')).toHaveLength(4)
    expect(within(numbers).queryByText('۰')).toBeNull()
    expect(numbers.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0)
    // The rules came in: the program's switch is said, and the levels' card says what each agent starts with.
    expect(within(numbers).getByText('بدون اعتبار اولیه')).toBeTruthy()
    await until(() => expect(screen.getByText(/نمایندگی خاموش است/)).toBeTruthy())
  })
})

describe('the requests’ status pill', () => {
  it('lists «همه» first, as every pill does — the list opening on the requests that wait', async () => {
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    open()

    const pill = await screen.findByRole('combobox', { name: 'وضعیت در انتظار بررسی' })
    expect(server().sent('GET', '/api/admin/agency/requests')[0]?.query.get('status')).toBe('pending')
    fireEvent.keyDown(pill, { key: 'Enter' })

    const options = await screen.findAllByRole('option')
    expect(options.map((option) => option.textContent)).toEqual(['همه', 'در انتظار بررسی', 'تاییدشده', 'ردشده'])
  })
})

describe('an agent’s shop', () => {
  it('is a link to its address — a new tab opens it as any link —, a plain press opening it here, the panel loaded afresh', async () => {
    const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => undefined)
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    open('/agents/list')

    const shop = await shopItem()
    expect(shop.getAttribute('href')).toBe('/admin/s/7/')

    fireEvent.click(shop, { ctrlKey: true })
    expect(assign).not.toHaveBeenCalled()

    fireEvent.click(await shopItem())
    await until(() => expect(assign).toHaveBeenCalledWith('/admin/s/7/'))
  })
})

/** The agents list's one row's menu, open. */
async function menu() {
  fireEvent.pointerDown(await screen.findByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
}

describe('an agent’s selling', () => {
  /** «رضا» selling, with terms of selling: no end, no minimum. */
  const SELLING: AgentRow = { ...REZA, standing: agencyStanding() }

  it('is stopped by hand with a note the agent reads, and the row says so', async () => {
    vi.spyOn(toast, 'success')
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    const stopped: AgentRow = { ...SELLING, standing: agencyStanding({ suspension: { reason: 'manual', since: '2026-10-14T10:00:00Z' } }) }
    server().on('POST', '/api/admin/agency/agents/21/suspend', json({ agent: stopped } satisfies AgentResponse))
    open('/agents/list', [SELLING])

    await menu()
    expect(screen.queryByRole('menuitem', { name: 'ادامه فروش' })).toBeNull()
    fireEvent.click(await screen.findByRole('menuitem', { name: 'توقف فروش' }))
    const dialog = await screen.findByRole('dialog', { name: 'توقف فروش' })
    fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: 'تسویه نشده' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'توقف فروش' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('فروش نماینده متوقف شد'))
    expect(server().sent('POST', '/api/admin/agency/agents/21/suspend')[0]?.body).toEqual({ note: 'تسویه نشده' })
    expect(await screen.findByRole('button', { name: 'فروش متوقف' })).toBeTruthy()
  })

  it('stopped by hand is let go again; one past its end is extended instead', async () => {
    vi.spyOn(toast, 'success')
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    server().on('POST', '/api/admin/agency/agents/21/resume', json({ agent: SELLING } satisfies AgentResponse))
    open('/agents/list', [{ ...SELLING, standing: agencyStanding({ suspension: { reason: 'manual', since: '2026-10-14T10:00:00Z' } }) }])

    await menu()
    expect(screen.queryByRole('menuitem', { name: 'توقف فروش' })).toBeNull()
    fireEvent.click(await screen.findByRole('menuitem', { name: 'ادامه فروش' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith('فروش نماینده دوباره باز شد'))
    await until(() => expect(screen.queryByRole('button', { name: 'فروش متوقف' })).toBeNull())
  })

  it('past its end offers no «ادامه فروش»', async () => {
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    open('/agents/list', [{ ...SELLING, standing: agencyStanding({ expires_at: '2026-10-01T10:00:00Z', suspension: { reason: 'expired', since: '2026-10-01T10:00:00Z' } }) }])

    await menu()
    expect(await screen.findByRole('menuitem', { name: 'توقف فروش' })).toBeTruthy()
    expect(screen.queryByRole('menuitem', { name: 'ادامه فروش' })).toBeNull()
  })
})

describe('the servers and plans kept from an agent’s shop', () => {
  it('are ticked in a dialog and saved exactly, the row counting them', async () => {
    vi.spyOn(toast, 'success')
    server().on('GET', '/api/admin/agency', refusal(500, 'خطای سرور.'))
    server().on(
      'GET',
      '/api/admin/agency/agents/21/hidden',
      json({
        hidden: {
          servers: [
            { id: 1, name: 'آلمان', is_active: true, hidden: false },
            { id: 2, name: 'هلند', is_active: true, hidden: true },
          ],
          plans: [{ id: 9, name: 'یک‌ماهه', is_active: true, hidden: false }],
        },
      } satisfies AgentHiddenResponse),
    )
    const saved: AgentRow = { ...REZA, standing: agencyStanding(), hidden: { servers: 2, plans: 1 } }
    server().on('PUT', '/api/admin/agency/agents/21/hidden', json({ hidden: { servers: [], plans: [] }, agent: saved }))
    open('/agents/list', [{ ...REZA, standing: agencyStanding(), hidden: { servers: 1, plans: 0 } }])

    expect(await screen.findByText('پنهان: ۱ سرور، ۰ پلن')).toBeTruthy()
    fireEvent.pointerDown(await screen.findByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'سرورها و پلن‌های پنهان' }))
    const dialog = await screen.findByRole('dialog', { name: /سرورها و پلن‌های پنهان/ })
    fireEvent.click(await within(dialog).findByRole('checkbox', { name: 'آلمان' }))
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'یک‌ماهه' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('سرورها و پلن‌های پنهان ذخیره شد'))
    expect(server().sent('PUT', '/api/admin/agency/agents/21/hidden')[0]?.body).toEqual({ server_ids: [1, 2], plan_ids: [9] })
    expect(await screen.findByText('پنهان: ۲ سرور، ۱ پلن')).toBeTruthy()
  })
})
