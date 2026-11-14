import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GeneralSection } from '@/components/bot/settings/general-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «عمومی» in the bot settings (components/bot/settings/general-section): the phone rule — where it is asked only while it
 * is on, the moments only while it is asked at some, none ticked said to ask no one —; the way to support, which card
 * payments in support's private chat need — with that switch on, a contact emptied (as typed, before it is saved) is
 * warned of, since the bot and the website then show the card again —; saved, every field of the group is sent (the
 * support message's heading switch with the rest) and what shows the group elsewhere is read again.
 */

const CONTACT = 'راه ارتباط با پشتیبانی'
const WARNING = /«هدایت پرداخت کارت به کارت به پیوی پشتیبانی» روشن است/
const SCOPE = 'کجا شماره موبایل پرسیده شود'
const NO_MOMENT = /هیچ کاری تیک نخورده است/
const MOMENTS = ['خرید سرویس', 'تمدید (و حجم، زمان یا کاربر اضافه)', 'گرفتن اکانت تست', 'پرداخت کارت به کارت']

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData) {
  render(<GeneralSection settings={settings} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText(CONTACT, { exact: false })
}

describe('the phone rule', () => {
  it('asks where only while it is on, and the moments only while it is asked at some', async () => {
    await show(botSettingsRow({ phone_required: false }))
    expect(screen.queryByRole('radiogroup', { name: SCOPE })).toBeNull()

    fireEvent.click(screen.getByRole('switch', { name: 'تایید شماره موبایل' }))

    const scope = screen.getByRole('radiogroup', { name: SCOPE })
    expect(within(scope).getByRole('radio', { name: 'کل ربات' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.queryByRole('checkbox')).toBeNull()

    fireEvent.click(within(scope).getByRole('radio', { name: 'فقط موقع این کارها' }))

    expect(screen.getAllByRole('checkbox')).toHaveLength(MOMENTS.length)
    for (const moment of MOMENTS) expect(screen.getByRole('checkbox', { name: moment }).getAttribute('aria-checked')).toBe('true')
  })

  it('says no one is asked while no moment is ticked', async () => {
    await show(botSettingsRow({ phone_required: true, phone_scope: 'moments', phone_on_purchase: false, phone_on_renewal: false, phone_on_test: false, phone_on_card: true }))
    expect(screen.queryByText(NO_MOMENT)).toBeNull()

    fireEvent.click(screen.getByRole('checkbox', { name: 'پرداخت کارت به کارت' }))

    expect(screen.getByText(NO_MOMENT)).toBeTruthy()
  })
})

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
  it('sends every field of the group: the phone rule’s where and moments with the rest', async () => {
    server().on('PUT', '/api/admin/bot/settings/general', json({ settings: botSettingsRow() } satisfies BotSettingsSaved))
    await show(botSettingsRow({ phone_required: true, support_contact: '@amo_support' }))

    fireEvent.click(screen.getByRole('radio', { name: 'فقط موقع این کارها' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'پرداخت کارت به کارت' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() =>
      expect(server().sent('PUT', '/api/admin/bot/settings/general')[0]?.body).toEqual({
        enabled: true,
        phone_required: true,
        phone_scope: 'moments',
        phone_on_purchase: true,
        phone_on_renewal: true,
        phone_on_test: true,
        phone_on_card: false,
        phone_iran_only: false,
        support_contact: '@amo_support',
        support_header: true,
      }),
    )
  })

  it('takes Iranian numbers alone once switched on, offered only while numbers are asked', async () => {
    server().on('PUT', '/api/admin/bot/settings/general', json({ settings: botSettingsRow({ phone_required: true, phone_iran_only: true }) } satisfies BotSettingsSaved))
    await show(botSettingsRow())
    expect(screen.queryByRole('switch', { name: 'فقط شماره‌های ایران (‎+98)' })).toBeNull()

    fireEvent.click(screen.getByRole('switch', { name: 'تایید شماره موبایل' }))
    fireEvent.click(screen.getByRole('switch', { name: 'فقط شماره‌های ایران (‎+98)' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/general')[0]?.body).toMatchObject({ phone_required: true, phone_iran_only: true }))
  })

  it('sends the support message’s heading switched off with the rest: the words alone', async () => {
    server().on('PUT', '/api/admin/bot/settings/general', json({ settings: botSettingsRow({ support_header: false }) } satisfies BotSettingsSaved))
    await show(botSettingsRow())

    const heading = screen.getByRole('switch', { name: 'عنوان پیام پشتیبانی' })
    expect(heading.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(heading)
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/general')[0]?.body).toMatchObject({ support_contact: '', support_header: false }))
  })

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
