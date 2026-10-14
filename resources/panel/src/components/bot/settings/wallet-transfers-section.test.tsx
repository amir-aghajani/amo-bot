import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WalletTransfersSection } from '@/components/bot/settings/wallet-transfers-section'
import { BOT_SETTINGS_SECTIONS, sectionsOfShop } from '@/components/shell/nav'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «انتقال موجودی» in the bot settings (components/bot/settings/wallet-transfers-section): the switch, off until turned
 * on, and the three amounts — the least and the most one transfer moves, a customer's daily cap (0: none) —, each read
 * back under it, saved as the group's PUT; the card says how a transfer goes; a refusal lands under its field. Every
 * shop's, right after the wallet's.
 */

const PATH = '/api/admin/bot/settings/wallet_transfers'
const SWITCH = 'مشتری‌ها بتوانند موجودی کیف پولشان را به مشتری دیگری منتقل کنند'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<WalletTransfersSection settings={settings} />, { wrapper: signedIn().wrapper })
  return screen.findByRole('switch', { name: SWITCH })
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('wallet transfers', () => {
  it('are off until switched on, the amounts waiting, and the card says how a transfer goes', async () => {
    const toggle = await show()

    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect((screen.getByLabelText('کمترین مبلغ انتقال (تومان)') as HTMLInputElement).disabled).toBe(true)
    expect(screen.getByText('= ۱۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText('= ۵٬۰۰۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText('= ۱۰٬۰۰۰٬۰۰۰ تومان در روز برای هر مشتری؛ کمتر از کمترین مبلغ انتقال نمی‌شود.')).toBeTruthy()
    expect(screen.getByText(/فقط موجودی خود کیف پول منتقل می‌شود، نه اعتبار نمایندگی/)).toBeTruthy()
    expect(screen.getByText(/هر دو یک خط ثبت می‌شود که نام دیگری را دارد/)).toBeTruthy()
    expect(screen.getByText(/به ساعت فروشگاه است/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'گروه گزارش‌ها' }).getAttribute('href')).toBe('/bot-settings/reports')
  })

  it('are saved as the group’s PUT, the amounts as typed', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ wallet_transfer_enabled: true, wallet_transfer_min: 20000, wallet_transfer_daily: 0 }) }
    server().on('PUT', PATH, json(saved))
    const toggle = await show()

    fireEvent.click(toggle)
    type('کمترین مبلغ انتقال (تومان)', '۲۰٬۰۰۰')
    type('سقف روزانه هر مشتری (تومان)', '')
    expect(screen.getByText('0 یعنی بدون سقف روزانه؛ جز آن، کمتر از کمترین مبلغ انتقال نمی‌شود.')).toBeTruthy()
    type('سقف روزانه هر مشتری (تومان)', '0')
    expect(screen.getByText('= ۲۰٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText(/بدون سقف روزانه؛ فقط موجودی مشتری/)).toBeTruthy()
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ wallet_transfer_enabled: true, wallet_transfer_min: '۲۰٬۰۰۰', wallet_transfer_max: '5000000', wallet_transfer_daily: '0' })
  })

  it('show a refusal under its field', async () => {
    const refused = 'بیشترین مبلغ انتقال نباید از کمترین مبلغ انتقال کمتر باشد.'
    server().on('PUT', PATH, refusal(422, refused, { wallet_transfer_max: [refused] }))
    await show(botSettingsRow({ wallet_transfer_enabled: true }))

    type('بیشترین مبلغ هر انتقال (تومان)', '5000')
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
  })
})

describe('the section', () => {
  it('is the main shop’s, right after the wallet’s', async () => {
    await openBotSettings('/bot-settings/wallet-transfers')

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(screen.getByRole('region', { name: 'انتقال موجودی' }).hidden).toBe(false)
    const sections = BOT_SETTINGS_SECTIONS.map((section) => section.value)
    expect(sections.indexOf('wallet_transfers')).toBe(sections.indexOf('wallet') + 1)
  })

  it('is an agent’s shop’s too', async () => {
    await openBotSettings('/bot-settings/wallet-transfers', { session: AGENT_SHOP })

    expect(await screen.findByRole('switch', { name: SWITCH })).toBeTruthy()
    expect(sectionsOfShop(BOT_SETTINGS_SECTIONS, false).map((section) => section.value)).toContain('wallet_transfers')
  })
})
