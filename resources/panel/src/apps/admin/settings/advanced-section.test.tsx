import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { AdvancedSection } from '@/apps/admin/settings/advanced-section'
import type { ConfigAdvancedSettings, ConfigSettingsResponse, CronUrlResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * «تنظیمات پنل › پیشرفته» (apps/admin/settings/advanced-section): with a cron token saved, the cron address — its token
 * masked on screen — and the line cPanel's «Cron Jobs» form takes in its Command field (docs/Installation.md's
 * `curl -fsS …/cron/<token> > /dev/null`), each copied whole, the token asked for as it is copied.
 */

const SETTINGS: ConfigAdvancedSettings = { log_level: 'info', session_lifetime: 120, session_secure_cookie: false, http_timeout: 30, cron_token: { set: true, hint: '••••••3f9a' } }

const META: ConfigSettingsResponse['meta'] = { timezones: [], log_levels: ['info'], cron_url: 'https://shop.example/cron/••••••3f9a', webhook_url: 'https://shop.example/webhooks/telegram/…' }

function show() {
  server().on('GET', '/api/admin/settings/config/cron-url', json({ url: 'https://shop.example/cron/k2J8sQ0x3f9a' } satisfies CronUrlResponse))
  render(<AdvancedSection settings={SETTINGS} meta={META} disabled={false} />, { wrapper: providers().wrapper })
}

describe('the cron job', () => {
  it('is a line for cPanel’s «Cron Jobs» form beside the address, each copied whole', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    vi.spyOn(toast, 'success')
    show()

    expect(screen.getByText('curl -fsS https://shop.example/cron/••••••3f9a > /dev/null')).toBeTruthy()
    expect(screen.getByText(/این خط برای فرم «Cron Jobs» در cPanel است/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'کپی خط Cron' }))
    await until(() => expect(write).toHaveBeenCalledWith('curl -fsS https://shop.example/cron/k2J8sQ0x3f9a > /dev/null'))
    expect(toast.success).toHaveBeenCalledWith('خط Cron کپی شد')

    fireEvent.click(screen.getByRole('button', { name: 'کپی آدرس Cron' }))
    await until(() => expect(write).toHaveBeenLastCalledWith('https://shop.example/cron/k2J8sQ0x3f9a'))
    expect(server().sent('GET', '/api/admin/settings/config/cron-url')).toHaveLength(2)
  })

  it('has no line while no token is saved', () => {
    render(<AdvancedSection settings={{ ...SETTINGS, cron_token: { set: false, hint: '' } }} meta={{ ...META, cron_url: null }} disabled={false} />, { wrapper: providers().wrapper })

    expect(screen.queryByRole('button', { name: 'کپی خط Cron' })).toBeNull()
    expect(screen.queryByText(/curl/)).toBeNull()
  })
})
