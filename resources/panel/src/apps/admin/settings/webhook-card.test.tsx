import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WebhookCard } from '@/apps/admin/settings/webhook-card'
import type { BotMode, SystemResponse, WebhooksResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * How the bots get their updates, from the owner's settings (apps/admin/settings/webhook-card): every bot put on its
 * webhook — or taken off it — after a second look, each bot's outcome in the server's words, the mode read again; and a
 * shop whose address Telegram would not call says so instead of offering it.
 */

const SYSTEM = '/api/admin/system'
const WEBHOOK = '/api/admin/system/webhook'
const REFUSED = 'تلگرام این توکن را نمی‌پذیرد؛ آن را دوباره از @BotFather بگیرید.'

/**
 * The system as GET /system answers: the main bot — whose way every bot gets its updates — in `mode`, while the shop the
 * tab shows is an agent's whose bot has no token yet (its own row says nothing of the installation's way).
 */
function system(mode: BotMode) {
  return json({
    system: {
      version: '1.0.0',
      php: '8.2.12',
      bot: { id: 7, configured: false, enabled: true, username: null, mode: 'offline', last_update_at: null },
      main_bot: { configured: true, enabled: true, username: 'amo_bot', mode, last_update_at: null },
      cron: { last_run_at: null, tasks: 9 },
    },
  } satisfies SystemResponse)
}

/** The card on the settings page, the shop at `appUrl`. */
async function card(mode: BotMode, appUrl = 'https://shop.example') {
  server().on('GET', SYSTEM, system(mode))
  render(<WebhookCard appUrl={appUrl} webhookUrl="https://shop.example/webhooks/telegram/…" />, { wrapper: providers().wrapper })
  await until(() => expect(server().sent('GET', SYSTEM)).toHaveLength(1))
}

/** The card's own button, then the same in the dialog that asks first. */
async function confirm(name: string) {
  fireEvent.click(screen.getByRole('button', { name }))
  const dialog = await screen.findByRole('dialog')
  fireEvent.click(within(dialog).getByRole('button', { name }))
}

describe('the bots’ webhook', () => {
  it('puts every bot on it after a second look, says what happened to each, and reads the mode again', async () => {
    await card('offline')
    server().on(
      'POST',
      WEBHOOK,
      json({
        bots: [
          { bot: { id: 1, username: 'amo_bot' }, done: false, message: REFUSED },
          { bot: { id: 7, username: 'agent_shop_bot' }, done: true, message: 'Webhook ثبت شد؛ تلگرام پیام‌ها را مستقیم به فروشگاه می‌فرستد.' },
        ],
      } satisfies WebhooksResponse),
    )

    await confirm('ثبت Webhook')

    await until(() => expect(screen.getByText(REFUSED)).toBeTruthy())
    expect(screen.getByText('ربات اصلی')).toBeTruthy()
    expect(screen.getByText('@agent_shop_bot')).toBeTruthy()
    expect(server().sent('POST', WEBHOOK)).toHaveLength(1)
    await until(() => expect(server().sent('GET', SYSTEM)).toHaveLength(2))
  })

  it('takes every bot off it once they are on it', async () => {
    await card('webhook')
    server().on('DELETE', WEBHOOK, json({ bots: [{ bot: { id: 1, username: 'amo_bot' }, done: true, message: 'Webhook برداشته شد.' }] } satisfies WebhooksResponse))

    expect(await screen.findByRole('button', { name: 'ثبت دوباره Webhook' })).toBeTruthy()
    await confirm('برداشتن Webhook')

    await until(() => expect(screen.getByText('Webhook برداشته شد.')).toBeTruthy())
    expect(server().sent('DELETE', WEBHOOK)).toHaveLength(1)
  })

  it('keeps a refusal in the dialog that asked', async () => {
    const words = 'تلگرام Webhook را فقط روی آدرس https می‌پذیرد.'
    await card('offline')
    server().on('POST', WEBHOOK, refusal(422, words))

    await confirm('ثبت Webhook')

    await until(() => expect(within(screen.getByRole('dialog')).getByText(words)).toBeTruthy())
  })

  it('offers nothing while the shop’s address is not https, and says where to set it right', async () => {
    await card('offline', 'http://shop.example')

    expect((screen.getByRole('button', { name: 'ثبت Webhook' }) as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByRole('link', { name: 'برنامه' }).getAttribute('href')).toBe('/settings/app')
  })
})
