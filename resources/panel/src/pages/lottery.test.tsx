import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotSettingsResponse, BotSettingsSaved, LotteryDrawRow, LotteryDrawsResponse } from '@/lib/api-types'
import { LotteryPage } from '@/pages/lottery'
import { AGENT_SHOP, OWNER, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «قرعه‌کشی» (pages/lottery, components/lottery): the nightly draw's rules — the prize's own amount alone sent with its
 * kind, what was typed for another kind no change —, and the draws, the newest first, each with its prize and its
 * winners, or why it had none.
 */

const QR_BACKGROUND = { custom: false, mime: 'image/jpeg', width: 1024, height: 1024, size: 1, updated_at: 1, max_bytes: 5_000_000 }

const DRAW: LotteryDrawRow = {
  id: 2,
  day: '2026-10-10',
  entries: 3,
  prize: { kind: 'wallet', amount: '50000.00', plan: null, words: '۵۰٬۰۰۰ تومان شارژ کیف پول' },
  winners: [{ id: 1, customer: { id: 7, name: 'Ali', username: 'ali', telegram_id: 5151, email: null }, outcome: '۵۰٬۰۰۰ تومان شارژ کیف پول', order_id: null, subscription_id: null }],
  created_at: '2026-10-10T18:30:00Z',
}

function open(draws: LotteryDrawRow[] = [DRAW], session = OWNER) {
  server().on('GET', '/api/admin/lottery/draws', json({ draws, meta: { page: 1, per_page: 25, total: draws.length, last_page: 1 } } satisfies LotteryDrawsResponse))
  server().on('GET', '/api/admin/bot/settings', json({ settings: botSettingsRow(), qr_background: QR_BACKGROUND } satisfies BotSettingsResponse))
  render(<LotteryPage />, { wrapper: signedIn({ session, at: '/lottery' }).wrapper })
}

describe('the lottery page', () => {
  it('lists the draws with their prizes and their winners', async () => {
    open([DRAW, { ...DRAW, id: 1, day: '2026-10-09', entries: 0, winners: [] }])

    const winner = await screen.findByRole('link', { name: 'Ali' })
    expect(winner.getAttribute('href')).toBe('/users/7')
    expect(screen.getAllByText('۵۰٬۰۰۰ تومان شارژ کیف پول').length).toBeGreaterThan(0)
    expect(screen.getByText('بدون شرکت‌کننده')).toBeTruthy()
  })

  it("saves the rules with the chosen prize's amount alone", async () => {
    open()
    server().on('PUT', '/api/admin/bot/settings/lottery', json({ settings: botSettingsRow({ lottery_enabled: true, lottery_prize: 'traffic', lottery_traffic_gb: 20 }) } satisfies BotSettingsSaved))

    fireEvent.click(await screen.findByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' }))
    fireEvent.click(screen.getByRole('radio', { name: 'حجم' }))
    fireEvent.change(screen.getByLabelText('حجم جایزه (گیگابایت)'), { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/lottery')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/bot/settings/lottery')[0]?.body).toEqual({
      lottery_enabled: true,
      lottery_hour: '22',
      lottery_winners: '1',
      lottery_prize: 'traffic',
      lottery_traffic_gb: '20',
      lottery_entries: 'order',
      lottery_exclude_agents: true,
    })
  })

  it("offers to leave agents out in the main shop alone: an agent's shop has none among its customers", async () => {
    open()
    expect(await screen.findByRole('switch', { name: 'نماینده‌ها شرکت داده نشوند' })).toBeTruthy()
  })

  it("— and none in an agent's shop", async () => {
    open([DRAW], AGENT_SHOP)

    await screen.findByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' })
    expect(screen.queryByRole('switch', { name: 'نماینده‌ها شرکت داده نشوند' })).toBeNull()
  })

  it('holds the prize while the draw is off — a free plan too — and frees it once switched on', async () => {
    open()
    server().on('GET', '/api/admin/plans', json({ plans: [] }))

    fireEvent.click(await screen.findByRole('radio', { name: 'سرویس رایگان' }))
    const plan = screen.getByRole('combobox', { name: 'پلن جایزه' })
    expect(plan.hasAttribute('disabled')).toBe(true)

    fireEvent.click(screen.getByRole('switch', { name: 'قرعه‌کشی شبانه فعال باشد' }))
    expect(plan.hasAttribute('disabled')).toBe(false)
  })

  it('takes an amount typed for a prize not chosen for no change', async () => {
    open()

    fireEvent.click(await screen.findByRole('radio', { name: 'زمان' }))
    fireEvent.change(screen.getByLabelText('زمان جایزه (روز)'), { target: { value: '30' } })
    fireEvent.click(screen.getByRole('radio', { name: 'شارژ کیف پول' }))

    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
  })
})
