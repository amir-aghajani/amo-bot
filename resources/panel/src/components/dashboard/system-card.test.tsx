import { cleanup, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SystemCard } from '@/components/dashboard/system-card'
import type { BotRuntime, SystemResponse, UpdateResponse, UpdateStatus } from '@/lib/api-types'
import { handleLabel } from '@/lib/direction'
import { agentBotStatus } from '@/lib/statuses'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The owner's system card (components/dashboard/system-card): the bot of the shop on screen, named for which it is — the
 * main one, or this shop's own, an agent's — and by its @username, and what keeps it from getting updates in that shop's
 * terms: the main bot's token is the owner's to set, an agent's bot's the agent's to send. A new version of AmoBot out
 * points at the panel's update.
 */

const QUIET: BotRuntime = { configured: false, enabled: true, username: null, mode: 'offline', last_update_at: null }
const RUNNING: BotRuntime = { configured: true, enabled: true, username: 'amo_bot', mode: 'webhook', last_update_at: null }

/** The update screen of a shop on 1.0.0, nothing newer out. */
const UP_TO_DATE: UpdateStatus = { current: '1.0.0', latest: null, checked_at: '2026-10-08T06:00:00+00:00', available: false, blocker: null, run: null }

function open(bot: SystemResponse['system']['bot'], update: UpdateStatus = UP_TO_DATE) {
  server().on('GET', '/api/admin/system', json({ system: { version: '1.0.0', php: '8.2.12', bot, main_bot: RUNNING, cron: { last_run_at: null, tasks: 9 } } } satisfies SystemResponse))
  server().on('GET', '/api/admin/system/update', json({ update } satisfies UpdateResponse))
  render(<SystemCard />, { wrapper: providers().wrapper })
}

describe('a new version', () => {
  it('points at the panel’s update once it is out', async () => {
    open(
      { id: 1, ...RUNNING },
      {
        ...UP_TO_DATE,
        available: true,
        latest: { version: '1.1.0', published_at: '2026-10-07T09:30:00+00:00', notes: 'Faster.', url: 'https://github.com/amir-aghajani/amo-bot/releases/tag/v1.1.0' },
      },
    )

    const link = await screen.findByRole('link', { name: 'به‌روزرسانی به نسخه 1.1.0' })
    expect(link.getAttribute('href')).toBe('/settings/update')
    expect(within(link.closest('li') as HTMLElement).getByText('آماده نصب')).toBeTruthy()
  })

  it('says nothing while the shop runs the newest', async () => {
    open({ id: 1, ...RUNNING })

    await until(() => expect(server().sent('GET', '/api/admin/system/update')).toHaveLength(1))
    await screen.findByText(`ربات اصلی ${handleLabel('amo_bot')}`)
    expect(screen.queryByText('نسخه تازه')).toBeNull()
  })
})

/** The bot's row, by its label. */
const row = async (label: string) => (await screen.findByText(label)).closest('li') as HTMLElement

describe('the bot’s row', () => {
  it('is the main bot’s in the main shop, named by its @username', async () => {
    open({ id: 1, ...RUNNING })

    expect(within(await row(`ربات اصلی ${handleLabel('amo_bot')}`)).getByText('Webhook')).toBeTruthy()
    expect(screen.queryByText(/ربات این فروشگاه/)).toBeNull()
  })

  it('is this shop’s own in an agent’s, named by its @username', async () => {
    open({ id: 7, ...RUNNING, username: 'reza_shop_bot' })

    expect(within(await row(`ربات این فروشگاه ${handleLabel('reza_shop_bot')}`)).getByText('Webhook')).toBeTruthy()
    expect(screen.queryByText(/^ربات اصلی/)).toBeNull()
  })

  it('is this shop’s own in an agent’s — waiting for the agent to send its token, not for the owner’s settings', async () => {
    open({ id: 7, ...QUIET })

    const bot = await row('ربات این فروشگاه')
    expect(within(bot).getByText('نماینده هنوز توکن رباتش را از ربات اصلی نفرستاده است')).toBeTruthy()
    expect(within(bot).queryByRole('link')).toBeNull()
    expect(screen.queryByText('ربات اصلی')).toBeNull()
  })

  it('says a bot is not working, or has no token yet, in the words the agents list has for an agent’s bot', async () => {
    open({ id: 7, ...RUNNING, username: 'reza_shop_bot', mode: 'offline' })

    const bot = await row(`ربات این فروشگاه ${handleLabel('reza_shop_bot')}`)
    expect(within(bot).getByText('مشکل دارد')).toBeTruthy()
    expect(agentBotStatus({ id: 7, username: 'reza_shop_bot', title: null, status: 'active', connected: true, problem: 'x', traffic_balance: 0, connected_at: null }).label).toBe('مشکل دارد')
    // «ثبت Webhook» is one piece: «Webhook» never on a line of its own.
    expect(within(bot).getByRole('link', { name: 'ثبت Webhook' }).className).toContain('whitespace-nowrap')
    cleanup()

    open({ id: 7, ...QUIET })
    expect(within(await row('ربات این فروشگاه')).getByText('وصل نشده')).toBeTruthy()
    expect(agentBotStatus({ id: 7, username: null, title: null, status: 'active', connected: false, problem: null, traffic_balance: 0, connected_at: null }).label).toBe('وصل نشده')
  })

  it('on a poller says how it gets its updates in words, never by the command an owner has no way to run', async () => {
    open({ id: 1, ...RUNNING, mode: 'polling' })

    expect(within(await row(`ربات اصلی ${handleLabel('amo_bot')}`)).getByText('پولینگ')).toBeTruthy()
    expect(document.body.textContent).not.toContain('bot:poll')
  })
})

describe('the scheduled work’s row', () => {
  it('is named in Persian, and says it has not run yet with the way to set it up', async () => {
    open({ id: 1, ...RUNNING })

    const work = await row('کارهای زمان‌بندی‌شده')
    expect(within(work).getByText('تنظیم نشده')).toBeTruthy()
    expect(within(work).getByRole('link', { name: 'راه‌اندازی Cron' }).getAttribute('href')).toBe('/settings/advanced')
    expect(screen.queryByText('Scheduler')).toBeNull()
  })
})
