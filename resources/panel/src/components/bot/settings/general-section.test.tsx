import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GeneralSection } from '@/components/bot/settings/general-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «عمومی» in the bot settings (components/bot/settings/general-section): the way to support, which card payments in
 * support's private chat need — with that switch on, a contact emptied (as typed, before it is saved) is warned of, since
 * the bot and the website then show the card again —; saved, what shows the group elsewhere is read again.
 */

const CONTACT = 'راه ارتباط با پشتیبانی'
const WARNING = /«هدایت پرداخت کارت به کارت به پیوی پشتیبانی» روشن است/

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData) {
  render(<GeneralSection settings={settings} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText(CONTACT, { exact: false })
}

describe('the way to support', () => {
  it('warns as it is emptied while card payments go to support’s private chat', async () => {
    await show(botSettingsRow({ card_to_support: true, support_contact: '@amo_support' }))
    expect(screen.queryByText(WARNING)).toBeNull()

    fireEvent.change(screen.getByLabelText(CONTACT, { exact: false }), { target: { value: '  ' } })

    expect(screen.getByText(WARNING)).toBeTruthy()
  })

  it('may be empty without a word while card payments show the card', async () => {
    await show(botSettingsRow({ card_to_support: false, support_contact: '' }))

    expect(screen.queryByText(WARNING)).toBeNull()
  })
})

describe('the group, saved', () => {
  it('has what shows it elsewhere read again: the dashboard’s word on the bot, the card-to-card methods’ «پیوی پشتیبانی»', async () => {
    server().on('PUT', '/api/admin/bot/settings/general', json({ settings: botSettingsRow({ enabled: false }) } satisfies BotSettingsSaved))
    const { client, wrapper } = signedIn()
    client.setQueryData(queryKeys.system, {})
    client.setQueryData(queryKeys.paymentMethods, {})
    render(<GeneralSection settings={botSettingsRow()} />, { wrapper })

    fireEvent.click(await screen.findByRole('switch', { name: 'ربات فعال باشد' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(client.getQueryState(queryKeys.system)?.isInvalidated).toBe(true))
    expect(client.getQueryState(queryKeys.paymentMethods)?.isInvalidated).toBe(true)
  })
})
