import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ExpiredServicesSection } from '@/components/bot/settings/expired-services-section'
import type { BotSettingsData, BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «سرویس‌های منقضی‌شده» in the bot settings (components/bot/settings/expired-services-section): the switch and the hours
 * after a service's end, saved as one PUT of the group as typed; the hint says what the hours come to (0: at once), the
 * hours wait for the switch, a refusal lands under its field, and the free tests' own rule is a link away.
 */

const PATH = '/api/admin/bot/settings/expired_services'

/** The section, once the signed-in panel draws it. */
async function show(settings: BotSettingsData = botSettingsRow()) {
  render(<ExpiredServicesSection settings={settings} />, { wrapper: signedIn().wrapper })
  await screen.findByLabelText('فاصله حذف (ساعت)')
}

const hours = () => screen.getByLabelText('فاصله حذف (ساعت)') as HTMLInputElement
const type = (value: string) => fireEvent.change(hours(), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

describe('the removal of ended services', () => {
  it('is off until switched on, the hours waiting for it, and points to the tests’ own rule', async () => {
    await show()

    expect(screen.getByRole('switch', { name: 'حذف خودکار سرویس‌های منقضی‌شده' }).getAttribute('aria-checked')).toBe('false')
    expect(hours().disabled).toBe(true)
    expect(screen.getByText('۱ روز بعد از پایان حذف می‌شود.')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'اکانت تست › تنظیمات' }).getAttribute('href')).toBe('/tests/settings')
  })

  it('says what the hours typed come to: so long after the end, at once, or the bounds', async () => {
    await show(botSettingsRow({ expired_cleanup: true }))

    type('۱۲')
    expect(screen.getByText('۱۲ ساعت بعد از پایان حذف می‌شود.')).toBeTruthy()
    type('36')
    expect(screen.getByText('۱ روز و ۱۲ ساعت بعد از پایان حذف می‌شود.')).toBeTruthy()
    type('0')
    expect(screen.getByText('همان وقت که تمام شد حذف می‌شود.')).toBeTruthy()
    type('9000')
    expect(screen.getByText('بین 0 تا 8760 ساعت؛ 0 یعنی همان وقت که تمام شد.')).toBeTruthy()
  })

  it('is saved as the group’s PUT: the switch and the hours as typed', async () => {
    const saved: BotSettingsSaved = { settings: botSettingsRow({ expired_cleanup: true, expired_cleanup_hours: 12 }) }
    server().on('PUT', PATH, json(saved))
    await show()

    fireEvent.click(screen.getByRole('switch', { name: 'حذف خودکار سرویس‌های منقضی‌شده' }))
    expect(hours().disabled).toBe(false)
    type('۱۲')
    save()

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ expired_cleanup: true, expired_cleanup_hours: '۱۲' })
  })

  it('shows a refusal under the hours', async () => {
    const refused = 'فاصله حذف باید عددی بین 0 تا 8760 ساعت باشد.'
    server().on('PUT', PATH, refusal(422, refused, { expired_cleanup_hours: [refused] }))
    await show(botSettingsRow({ expired_cleanup: true }))

    type('9000')
    save()

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(hours().getAttribute('aria-invalid')).toBe('true')
  })
})
