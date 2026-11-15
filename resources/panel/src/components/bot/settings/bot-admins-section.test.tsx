import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BotAdminsSection } from '@/components/bot/settings/bot-admins-section'
import { BOT_SETTINGS_SECTIONS, sectionsOfShop } from '@/components/shell/nav'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «مدیران ربات» in the bot settings (components/bot/settings/bot-admins-section): whether the shop's admins manage it
 * from the bot's private chat — on until switched off — and a switch per grant beyond the daily work, off until
 * switched on, the website's «مدیران سایت» list; the whole group saved as its PUT, a refusal under its switch. Every
 * shop's: an agent's has its own.
 */

const PATH = '/api/admin/bot/settings/bot_admins'
const SWITCH = 'کار مدیران از ربات'
const GRANTS = ['پلن‌ها و دسته‌بندی‌ها', 'کیف پول مشتری', 'بازپرداخت', 'رد یکجای رسیدها', 'افزایش زمان و حجم سرویس', 'افزودن سفارش برای مشتری', 'حذف یا انتقال سرویس', 'امنیت حساب مشتری']

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<BotAdminsSection settings={settings} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('switch', { name: SWITCH })
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const checked = (name: string) => screen.getByRole('switch', { name }).getAttribute('aria-checked')

describe('the bot’s admins', () => {
  it('manage the shop from the bot until switched off, with no grant until one is switched on', async () => {
    const toggle = await show()

    expect(toggle.getAttribute('aria-checked')).toBe('true')
    for (const grant of GRANTS) expect(checked(grant)).toBe('false')
    expect(screen.getByText(/با دکمه «⚙️ مدیریت» یا/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'لیست کاربران' }).getAttribute('href')).toBe('/users?role=admin')
    expect(screen.getByText(/دو مدیر این کارها را برای هم انجام می‌دهند/)).toBeTruthy()
  })

  it('are saved as the group’s PUT, every switch with it', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ bot_admins_grant_refunds: true, bot_admins_grant_delete: true }) }
    server().on('PUT', PATH, json(saved))
    await show()

    fireEvent.click(screen.getByRole('switch', { name: 'بازپرداخت' }))
    fireEvent.click(screen.getByRole('switch', { name: 'حذف یا انتقال سرویس' }))
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({
      bot_admins_enabled: true,
      bot_admins_grant_catalog: false,
      bot_admins_grant_wallet: false,
      bot_admins_grant_refunds: true,
      bot_admins_grant_payments: false,
      bot_admins_grant_extend: false,
      bot_admins_grant_manual_orders: false,
      bot_admins_grant_delete: true,
      bot_admins_grant_account_security: false,
    })
  })

  it('are let out at a switch', async () => {
    server().on('PUT', PATH, json({ settings: botSettingsRow({ bot_admins_enabled: false }) }))
    const toggle = await show()

    fireEvent.click(toggle)
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toMatchObject({ bot_admins_enabled: false })
  })

  it('show a refusal under its switch', async () => {
    const refused = 'مقدار این گزینه باید روشن یا خاموش باشد.'
    server().on('PUT', PATH, refusal(422, refused, { bot_admins_grant_wallet: [refused] }))
    await show()

    fireEvent.click(screen.getByRole('switch', { name: 'کیف پول مشتری' }))
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
  })
})

describe('the section', () => {
  it('is the bot settings’ last, in the main shop', async () => {
    await openBotSettings('/bot-settings/bot-admins')

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(screen.getByRole('region', { name: 'مدیران ربات' }).hidden).toBe(false)
    expect(BOT_SETTINGS_SECTIONS.at(-1)?.value).toBe('bot_admins')
  })

  it('is an agent’s shop’s too', async () => {
    await openBotSettings('/bot-settings/bot-admins', { session: AGENT_SHOP })

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(sectionsOfShop(BOT_SETTINGS_SECTIONS, false).map((section) => section.value)).toContain('bot_admins')
  })
})
