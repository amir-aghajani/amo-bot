import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { APPEARANCE_DESCRIPTION } from '@/components/appearance-card'
import type { ConfigSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { ThemeProvider } from '@/lib/theme'
import { signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The owner's «تنظیمات پنل» (apps/admin/pages/settings): config.php's sections are drawn from the file's read, the
 * file's path named whole over them; the owner's login and the panel's look need none of it — a read that failed is
 * said on the sections it keeps from drawing, not on theirs.
 */

/** The page at `at`, config.php's read refused by the server. */
async function show(at: string) {
  server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
  const { client, wrapper } = signedIn({ at })
  render(
    <ThemeProvider>
      <SettingsPage />
    </ThemeProvider>,
    { wrapper },
  )
  await until(() => expect(client.getQueryState(queryKeys.configSettings)?.status).toBe('error'))
}

/** The skeletons on screen: the hidden sections' (the update's, read on its own) are none of it. */
const loading = () => [...document.querySelectorAll('[data-slot="skeleton"]')].filter((skeleton) => skeleton.closest('[hidden]') === null)

/** config.php as a cPanel host keeps it, deep in the account's folders. */
const CONFIG: ConfigSettingsResponse = {
  file: { path: '/home/u123456789/domains/shop.example.com/public_html/amobot/config.php', exists: true, writable: true },
  groups: {
    app: { name: 'AmoBot', url: 'https://shop.example.com', debug: false, timezone: 'Asia/Tehran' },
    database: { driver: 'mysql', drivers: [{ key: 'mysql', label: 'MySQL / MariaDB', description: '', notes: [], traits: {}, fields: [] }], values: {} },
    telegram: { token: { set: false, hint: '' }, username: '', api_url: 'https://api.telegram.org', poll_timeout: 25, webhook_secret: { set: false, hint: '' } },
    mail: { transport: 'none', from_address: '', from_name: '', drivers: [], values: {} },
    advanced: { log_level: 'info', session_lifetime: 120, session_secure_cookie: false, http_timeout: 30, cron_token: { set: false, hint: '' } },
  },
  meta: { timezones: [], log_levels: ['info'], cron_url: null, webhook_url: 'https://shop.example.com/webhooks/telegram/…' },
}

describe('the panel’s settings, config.php read', () => {
  it('name the file’s whole path — where the File Manager finds it —, wrapping on a phone rather than running past its edge', async () => {
    server().on('GET', '/api/admin/settings/config', json(CONFIG)).on('GET', '/api/admin/system', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: '/settings/login' }).wrapper },
    )

    const path = await screen.findByText(CONFIG.file.path)
    expect(path.className).toContain('break-all')
    expect(path.parentElement?.className).toContain('whitespace-normal')
    expect(path.parentElement?.className).toContain('max-w-full')
  })
})

describe('the panel’s settings, config.php unread', () => {
  it('say so on a section of the file', async () => {
    await show('/settings/telegram')

    expect(screen.getByText('تنظیمات بارگذاری نشد.')).toBeTruthy()
  })

  it('say so on the shop’s email, a section of the file too — its test send with it', async () => {
    await show('/settings/mail')

    expect(screen.getByText('تنظیمات بارگذاری نشد.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'ارسال' })).toBeNull()
  })

  it('stay said while the file is read again — its button keeping the focus, spinning —, until the answer', async () => {
    await show('/settings/telegram')
    const retry = screen.getByRole('button', { name: 'تلاش دوباره' })
    const again = server().hold('GET', '/api/admin/settings/config')

    retry.focus()
    fireEvent.click(retry)
    await until(() => expect(retry.getAttribute('aria-busy')).toBe('true'))
    expect(document.activeElement).toBe(retry)
    expect(screen.getByText('تنظیمات بارگذاری نشد.')).toBeTruthy()
    expect(loading()).toHaveLength(0)

    // Its sections drawn, the bot's card asks how the bot runs: nothing to say here.
    server().on('GET', '/api/admin/system', refusal(500, 'خطای داخلی'))
    again.answer(json(CONFIG))
    await until(() => expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull())
  })

  it('offer the owner’s login all the same', async () => {
    await show('/settings/login')

    expect(screen.getByLabelText('رمز عبور فعلی')).toBeTruthy()
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
  })

  it('offer the panel’s appearance, noting it is stored in the browser rather than the file', async () => {
    await show('/settings/appearance')

    expect(screen.getByRole('radiogroup', { name: 'تم پنل' })).toBeTruthy()
    expect(screen.getByText(APPEARANCE_DESCRIPTION)).toBeTruthy()
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
  })
})
