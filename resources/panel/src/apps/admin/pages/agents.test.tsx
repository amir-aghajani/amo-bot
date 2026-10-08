import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AgentsPage } from '@/apps/admin/pages/agents'
import type { AgencyLevelsResponse, AgencyRequestsResponse, AgencySettingsResponse, AgentRow, AgentsResponse } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The agency's page (apps/admin/pages/agents): the program's numbers that could not be read say so, with a way to ask
 * again, and stay «—» — never zeros, nothing pulsing for ever — while the lists stand; the requests' status pill lists
 * «همه» first, as every pill does, the list still opening on the requests that wait; an agent's shop is a link of the
 * agents list's, opened here or in a new tab.
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
}

/** The agents list's row menu, open, and its «باز کردن فروشگاه». */
async function shopItem() {
  fireEvent.pointerDown(await screen.findByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  return screen.findByRole('menuitem', { name: 'باز کردن فروشگاه' })
}

function open(at = '/agents') {
  server()
    .on('GET', '/api/admin/agency/settings', json({ settings: { enabled: false, default_credit: '0.00', traffic_presets: [50, 100], traffic_min: 10 } } satisfies AgencySettingsResponse))
    .on('GET', '/api/admin/agency/levels', json({ levels: [] } satisfies AgencyLevelsResponse))
    .on('GET', '/api/admin/agency/requests', json({ requests: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1, sort: 'created', dir: 'desc' } } satisfies AgencyRequestsResponse))
    .on('GET', '/api/admin/agency/agents', json({ agents: [REZA], meta: { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'joined', dir: 'desc' } } satisfies AgentsResponse))
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
