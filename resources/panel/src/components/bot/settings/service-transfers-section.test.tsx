import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServiceTransfersSection } from '@/components/bot/settings/service-transfers-section'
import { BOT_SETTINGS_SECTIONS, sectionsOfShop } from '@/components/shell/nav'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «انتقال سرویس» in the bot settings (components/bot/settings/service-transfers-section): one switch, off until turned
 * on, saved as the group's PUT; the card says how a transfer goes — the service as it is, «تمدید خودکار» off, the link
 * the previous owner keeps until «تغییر لینک», what may not go, the report in «خریدها», and that support transfers
 * whatever it says; a refusal lands under the switch. Every shop's: an agent's has it too.
 */

const PATH = '/api/admin/bot/settings/service_transfers'
const SWITCH = 'مشتری‌ها بتوانند سرویسشان را به مشتری دیگری منتقل کنند'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<ServiceTransfersSection settings={settings} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('switch', { name: SWITCH })
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('service transfers', () => {
  it('are off until switched on, and the card says how a transfer goes', async () => {
    const toggle = await show()

    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText(/با همان لینک، زمان و حجم منتقل می‌شود و «تمدید خودکار» آن خاموش می‌شود/)).toBeTruthy()
    expect(screen.getByText(/دستگاه‌های صاحب قبلی وصل می‌مانند تا گیرنده «تغییر لینک» را بزند/)).toBeTruthy()
    expect(screen.getByText(/اکانت تست، سرویسی که کلاینتش دیگر روی سرور نیست و سرویسی که سفارشی از آن هنوز باز است/)).toBeTruthy()
    expect(screen.getByText(/تاپیک «خریدها»، گزارش می‌شود/)).toBeTruthy()
    expect(screen.getByText(/حتی وقتی این گزینه خاموش است/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'گروه گزارش‌ها' }).getAttribute('href')).toBe('/bot-settings/reports')
    expect(screen.getByRole('link', { name: 'اشتراک‌ها' }).getAttribute('href')).toBe('/subscriptions')
  })

  it('are saved as the group’s PUT', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ transfer_enabled: true }) }
    server().on('PUT', PATH, json(saved))
    const toggle = await show()

    fireEvent.click(toggle)
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ transfer_enabled: true })
  })

  it('show a refusal under the switch', async () => {
    const refused = 'مقدار این گزینه باید روشن یا خاموش باشد.'
    server().on('PUT', PATH, refusal(422, refused, { transfer_enabled: [refused] }))
    const toggle = await show(botSettingsRow({ transfer_enabled: true }))

    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
  })
})

describe('the section', () => {
  it('is the main shop’s, right before the referrals’', async () => {
    await openBotSettings('/bot-settings/service-transfers')

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(screen.getByRole('region', { name: 'انتقال سرویس' }).hidden).toBe(false)
    const sections = BOT_SETTINGS_SECTIONS.map((section) => section.value)
    expect(sections.indexOf('service_transfers')).toBe(sections.indexOf('referral') - 1)
  })

  it('is an agent’s shop’s too', async () => {
    await openBotSettings('/bot-settings/service-transfers', { session: AGENT_SHOP })

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(sectionsOfShop(BOT_SETTINGS_SECTIONS, false).map((section) => section.value)).toContain('service_transfers')
  })
})
