import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PaymentsSection } from '@/components/bot/settings/payments-section'
import { BOT_SETTINGS_SECTIONS, sectionsOfShop } from '@/components/shell/nav'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «پرداخت کارت به کارت» in the bot settings (components/bot/settings/payments-section): three switches — the card's
 * message protected, card payments in support's private chat, one receipt at a time —, each with what it does, saved as
 * the group's PUT; the server's refusal (support's private chat with no support contact) lands under its switch, and a
 * contact emptied since is warned of; the card links to the payments page's review queue. Every shop's, right after the
 * wallet's.
 */

const PATH = '/api/admin/bot/settings/payments'
const PROTECT = 'جلوگیری از اسکرین‌شات و فوروارد'
const SUPPORT = 'هدایت پرداخت کارت به کارت به پیوی پشتیبانی'
const SINGLE = 'هر مشتری فقط یک رسید در انتظار بررسی'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<PaymentsSection settings={settings} />, { wrapper: signedIn().wrapper })
  await screen.findByRole('switch', { name: PROTECT })
}

const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const toggle = (name: string) => screen.getByRole('switch', { name })

describe('the card-to-card payments', () => {
  it('protect the card and take one receipt at a time until switched off, and send nobody to support’s chat', async () => {
    await show()

    expect([toggle(PROTECT), toggle(SUPPORT), toggle(SINGLE)].map((element) => element.getAttribute('aria-checked'))).toEqual(['true', 'false', 'true'])
    expect(screen.getByText(/فوروارد، ذخیره و کپی متنش بسته است/)).toBeTruthy()
    expect(screen.getByText(/شماره کارتی نشان داده نمی‌شود/)).toBeTruthy()
    expect(screen.getByText(/تا رسید قبلی مشتری بررسی نشده، رسید دیگری از او پذیرفته نمی‌شود/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'پرداخت‌ها' }).getAttribute('href')).toBe('/payments?status=awaiting_review')
  })

  it('are saved as the group’s PUT, the payment methods read again: whether a card is given in support’s chat is theirs to say', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ card_protect: false, card_to_support: true }) }
    server().on('PUT', PATH, json(saved))
    const { client, wrapper } = signedIn()
    client.setQueryData(queryKeys.paymentMethods, {})
    render(<PaymentsSection settings={botSettingsRow()} />, { wrapper })
    await screen.findByRole('switch', { name: PROTECT })

    fireEvent.click(toggle(PROTECT))
    fireEvent.click(toggle(SUPPORT))
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ card_protect: false, card_to_support: true, receipt_single: true })
    await until(() => expect(client.getQueryState(queryKeys.paymentMethods)?.isInvalidated).toBe(true))
  })

  it('warn while support’s private chat is on but the support contact was emptied since: the card is shown again', async () => {
    await show(botSettingsRow({ card_to_support: true, support_contact: ' ' }))

    expect(screen.getByText(/راه ارتباط با پشتیبانی در بخش «عمومی» خالی است/)).toBeTruthy()
  })

  it('say nothing of the contact while it is set', async () => {
    await show(botSettingsRow({ card_to_support: true, support_contact: '@amo_support' }))

    expect(screen.queryByText(/در بخش «عمومی» خالی است/)).toBeNull()
  })

  it('say why support’s private chat was refused, under its switch', async () => {
    const refused = 'اول راه ارتباط با پشتیبانی را در بخش «عمومی» بنویسید؛ مشتری برای پرداخت کارت به کارت به همان‌جا فرستاده می‌شود.'
    server().on('PUT', PATH, refusal(422, refused, { card_to_support: [refused] }))
    await show()

    fireEvent.click(toggle(SUPPORT))
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(toggle(SUPPORT).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the section', () => {
  it('comes right after the wallet’s (and its transfers’, once there)', async () => {
    await openBotSettings('/bot-settings/payments')

    expect(await screen.findByRole('switch', { name: PROTECT })).toBeTruthy()
    expect(screen.getByRole('region', { name: 'پرداخت کارت به کارت' }).hidden).toBe(false)
    const sections = BOT_SETTINGS_SECTIONS.map((section) => section.value)
    const before = sections.includes('wallet_transfers') ? 'wallet_transfers' : 'wallet'
    expect(sections.indexOf('payments')).toBe(sections.indexOf(before) + 1)
  })

  it('is an agent’s shop’s too', async () => {
    await openBotSettings('/bot-settings/payments', { session: AGENT_SHOP })

    expect(await screen.findByRole('switch', { name: PROTECT })).toBeTruthy()
    expect(sectionsOfShop(BOT_SETTINGS_SECTIONS, false).map((section) => section.value)).toContain('payments')
  })
})
