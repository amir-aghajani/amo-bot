import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ClientNamesSection, exampleName } from '@/components/bot/settings/client-names-section'
import type { BotSettingsData, BotSettingsResponse, BotSettingsSaved } from '@/lib/api-types'
import { openBotSettings } from '@/test/bot-settings'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «نام کاربری سرویس» in the bot settings (components/bot/settings/client-names-section): the two patterns, each with
 * the name it would give the server's sample customer as it is typed (its default's while blank), the variables the
 * server lists — a tap writes one at the end of the pattern last edited —, and the buyers' two switches, saved as one
 * PUT of the group as typed; a refusal lands under its field. Every shop has it.
 */

const PATH = '/api/admin/bot/settings/client_names'

const VARIABLES: BotSettingsResponse['name_variables'] = [
  { name: 'username', description: 'نام کاربری تلگرام مشتری (بدون @)؛ اگر نداشته باشد USER', example: 'amir' },
  { name: 'seq', description: 'شماره سرویس خود مشتری', example: '3' },
  { name: 'tg_id', description: 'شناسه عددی تلگرام مشتری', example: '123456789' },
  { name: 'n', description: 'شماره سرویس در کل فروشگاه', example: '128' },
  { name: 'random', description: 'شش حرف و رقم تصادفی', example: 'k7m2qx' },
  { name: 'date', description: 'تاریخ ساخت', example: '261013' },
]

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<ClientNamesSection settings={settings} variables={VARIABLES} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText('الگوی نام کاربری سرویس')
}

const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement
const type = (label: string, value: string) => fireEvent.change(field(label), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('the client names', () => {
  it('show each pattern with the name it gives, the variables, and no choice for buyers until switched on', async () => {
    await show()

    expect(field('الگوی نام کاربری سرویس').value).toBe('{username}_{seq}')
    expect(screen.getByText('amir_3')).toBeTruthy()
    expect(screen.getByText('test_amir_3')).toBeTruthy()
    expect(screen.getByRole('button', { name: /\{tg_id\}\s*شناسه عددی تلگرام مشتری/ })).toBeTruthy()
    expect(screen.getByRole('switch', { name: 'نام رندوم' }).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByRole('switch', { name: 'نام دلخواه' }).getAttribute('aria-checked')).toBe('false')
  })

  it('name the sample customer as the pattern is typed: its default while blank, an unknown variable as typed', async () => {
    await show()

    type('الگوی نام کاربری سرویس', 'vpn-{tg_id}-{n}')
    expect(screen.getByText('vpn-123456789-128')).toBeTruthy()
    type('الگوی نام کاربری سرویس', '')
    expect(screen.getByText('amir_3')).toBeTruthy()
    type('الگوی نام کاربری اکانت تست', 'trial.{city}.{seq}')
    expect(screen.getByText('trial.{city}.3')).toBeTruthy()
  })

  it('write a variable tapped at the end of the pattern last edited', async () => {
    await show()

    fireEvent.click(screen.getByRole('button', { name: /\{n\}/ }))
    expect(field('الگوی نام کاربری سرویس').value).toBe('{username}_{seq}{n}')

    fireEvent.focus(field('الگوی نام کاربری اکانت تست'))
    type('الگوی نام کاربری اکانت تست', 'trial_')
    fireEvent.click(screen.getByRole('button', { name: /\{random\}/ }))
    expect(field('الگوی نام کاربری اکانت تست').value).toBe('trial_{random}')
  })

  it('are saved as the group’s PUT, the patterns as typed', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ name_pattern: 'vpn-{n}', name_random: true }) }
    server().on('PUT', PATH, json(saved))
    await show()

    type('الگوی نام کاربری سرویس', 'vpn-{n}')
    fireEvent.click(screen.getByRole('switch', { name: 'نام رندوم' }))
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ name_pattern: 'vpn-{n}', test_name_pattern: 'test_{username}_{seq}', name_random: true, name_custom: false })
  })

  it('show a refusal under its pattern', async () => {
    const refused = 'الگو باید {n} یا {random} را داشته باشد، یا {seq} را کنار {username}، {tg_id} یا {user_id}، تا نام کاربری سرویس‌ها تکراری نشود.'
    server().on('PUT', PATH, refusal(422, refused, { test_name_pattern: [refused] }))
    await show()

    type('الگوی نام کاربری اکانت تست', 'test_{username}')
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(field('الگوی نام کاربری اکانت تست').getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the example name', () => {
  it('fills each variable with the server’s example, one it does not list left as typed', () => {
    expect(exampleName('{username}.{seq}.{tg_id}.{user_id}.{n}.{random}.{date}', '', VARIABLES)).toBe('amir.3.123456789.{user_id}.128.k7m2qx.261013')
    expect(exampleName('  ', 'test_{username}_{seq}', VARIABLES)).toBe('test_amir_3')
  })
})

describe('the section', () => {
  it('is every shop’s, at its own address', async () => {
    await openBotSettings('/bot-settings/client-names', { session: AGENT_SHOP, response: botSettingsResponse(botSettingsRow(), { name_variables: VARIABLES }) })

    expect(await screen.findByRole('region', { name: 'نام کاربری سرویس' })).toBeTruthy()
    expect(screen.getByLabelText('الگوی نام کاربری سرویس')).toBeTruthy()
  })
})
