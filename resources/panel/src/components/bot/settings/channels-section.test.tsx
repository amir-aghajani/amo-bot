import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ChannelsSection } from '@/components/bot/settings/channels-section'
import type { BotChannelRow, BotChannelsResponse, BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «کانال‌ها» in the bot settings (components/bot/settings/channels-section): the rule's one switch, saved as the group's
 * PUT — a refusal under it —, above the channels' list, which says while the rule is off that it applies to no one, and
 * of a channel without a link that no one is asked to join it.
 */

const PATH = '/api/admin/bot/settings/channels'
const SWITCH = 'عضویت اجباری در کانال'

async function show(channels: BotChannelRow[] = []) {
  server().on('GET', '/api/admin/bot/channels', json({ channels } satisfies BotChannelsResponse))
  render(<ChannelsSection settings={botSettingsRow()} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('switch', { name: SWITCH })
}

describe('the channels’ rule', () => {
  it('is off until switched on, the list saying it applies to no one meanwhile, and is saved as the group’s PUT', async () => {
    server().on('PUT', PATH, json({ settings: botSettingsRow({ join_required: true }) } satisfies BotSettingsSaved))
    const toggle = await show()
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText(/کلید «عضویت اجباری در کانال» خاموش است و این لیست اعمال نمی‌شود/)).toBeTruthy()

    fireEvent.click(toggle)
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ join_required: true })
  })

  it('shows a refusal under its switch', async () => {
    const refused = 'مقدار این گزینه باید روشن یا خاموش باشد.'
    server().on('PUT', PATH, refusal(422, refused, { join_required: [refused] }))
    fireEvent.click(await show())
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(screen.getByRole('switch', { name: SWITCH }).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('a channel', () => {
  it('links to where customers join it — and without a link, a row edited by hand, says no one is asked to', async () => {
    const channel = (id: number, title: string, username: string | null): BotChannelRow => ({
      id,
      chat_id: -100_000 - id,
      type: 'channel',
      title,
      username,
      link: username && `https://t.me/${username}`,
      bot_is_admin: true,
      checked_at: null,
      sort: id,
    })
    await show([channel(1, 'اخبار', 'amo_news'), channel(2, 'آرشیو', null)])

    const cell = (title: string) => screen.getByText(title).closest('td')
    expect((await screen.findByText('@amo_news')).closest('a')?.getAttribute('href')).toBe('https://t.me/amo_news')
    expect(cell('آرشیو')?.textContent).toContain('لینک عضویت ندارد و از کسی خواسته نمی‌شود')
    expect(cell('آرشیو')?.querySelector('a')).toBeNull()
  })
})
