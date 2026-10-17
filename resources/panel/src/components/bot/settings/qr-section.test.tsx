import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { QrSection } from '@/components/bot/settings/qr-section'
import type { BotSettingsSaved } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «کد QR» in the bot settings (components/bot/settings/qr-section): the switch, saved as the group's PUT, above the
 * picture the code is drawn on.
 */

describe('the QR code', () => {
  it('is switched as the group’s PUT, the background’s card under it', async () => {
    const PATH = '/api/admin/bot/settings/qr'
    server().on('PUT', PATH, json({ settings: botSettingsRow({ qr_enabled: false }) } satisfies BotSettingsSaved))
    render(<QrSection data={botSettingsResponse(botSettingsRow({ qr_enabled: true }))} />, { wrapper: signedIn().wrapper })
    const toggle = await screen.findByRole('switch', { name: 'ساخت کد QR' })
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    expect(screen.getByRole('img', { name: 'پس‌زمینه فعلی کد QR' })).toBeTruthy()

    fireEvent.click(toggle)
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({ qr_enabled: false })
  })
})
