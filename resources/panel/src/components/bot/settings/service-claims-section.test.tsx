import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServiceClaimsSection } from '@/components/bot/settings/service-claims-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «افزودن سرویس با لینک» in the bot settings (components/bot/settings/service-claims-section): one switch, off until
 * turned on, saved as the group's PUT; the card says how it goes — the first to send the link gets it, each claim is
 * reported in «خریدها», a wrong one is deleted from the shop alone for the right customer to send again, and no plan
 * until the first renewal; a refusal lands under the switch. The main shop's alone: an agent's shop has no such section.
 */

const PATH = '/api/admin/bot/settings/service_claims'
const SWITCH = 'مشتری‌ها بتوانند سرویس را با لینکش اضافه کنند'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<ServiceClaimsSection settings={settings} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('switch', { name: SWITCH })
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('services added by their link', () => {
  it('are off until switched on, and the card says how a claim goes and where a wrong one is undone', async () => {
    const toggle = await show()

    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText(/اولین کسی که لینک را بفرستد سرویس را می‌گیرد/)).toBeTruthy()
    expect(screen.getByText(/تاپیک «خریدها»، گزارش می‌شود/)).toBeTruthy()
    expect(screen.getByText(/«حذف فقط از فروشگاه \(کلاینت روی پنل می‌ماند\)» را بزنید/)).toBeTruthy()
    expect(screen.getByText(/تا اولین تمدید پلن ندارد/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'گروه گزارش‌ها' }).getAttribute('href')).toBe('/bot-settings/reports')
    expect(screen.getByRole('link', { name: 'اشتراک‌ها › افزوده‌شده با لینک' }).getAttribute('href')).toBe('/subscriptions?kind=claimed')
  })

  it('are saved as the group’s PUT', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ claim_enabled: true }) }
    server().on('PUT', PATH, json(saved))
    const toggle = await show()

    fireEvent.click(toggle)
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ claim_enabled: true })
  })

  it('show a refusal under the switch', async () => {
    const refused = 'مقدار این گزینه باید روشن یا خاموش باشد.'
    server().on('PUT', PATH, refusal(422, refused, { claim_enabled: [refused] }))
    const toggle = await show(botSettingsRow({ claim_enabled: true }))

    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
  })
})

describe('the section', () => {
  it('is the main shop’s', async () => {
    await openBotSettings('/bot-settings/service-claims')

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(screen.getByRole('region', { name: 'افزودن سرویس با لینک' }).hidden).toBe(false)
  })

  it('is no agent’s shop’s: its address goes to the first section, and the page has none of it', async () => {
    await openBotSettings('/bot-settings/service-claims', { session: AGENT_SHOP })

    expect(await screen.findByRole('region', { name: 'عمومی' })).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'افزودن سرویس با لینک', hidden: true })).toBeNull()
    expect(screen.queryByRole('switch', { name: SWITCH, hidden: true })).toBeNull()
  })
})
