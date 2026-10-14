import { render, screen } from '@testing-library/react'
import type { BotSettingsResponse, Session } from '@/lib/api-types'
import { BotSettingsPage } from '@/pages/bot-settings'
import { signedIn } from '@/test/render'
import { botSettingsResponse } from '@/test/rows'
import { json, server, silent } from '@/test/server'

/**
 * The bot settings page (pages/bot-settings) at `at`, signed in to the main shop or to `session`'s, its settings read as
 * `response`; the other sections' reads — the channels, the report group, the plans — left unanswered.
 */
export async function openBotSettings(at: string, { session, response = botSettingsResponse() }: { session?: Session; response?: BotSettingsResponse } = {}) {
  server().on('GET', '/api/admin/bot/settings', json(response)).on('GET', '/api/admin/bot/channels', silent).on('GET', '/api/admin/bot/report-group', silent).on('GET', '/api/admin/plans', silent)
  render(<BotSettingsPage />, { wrapper: signedIn({ at, session }).wrapper })
  await screen.findByRole('heading', { name: 'تنظیمات ربات' })
}
