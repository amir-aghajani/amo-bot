import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/agent/pages/settings'
import type { BackupInspectionResponse, BackupsResponse, BackupsStatus, Session } from '@/lib/api-types'
import { ThemeProvider } from '@/lib/theme'
import { signedIn, until } from '@/test/render'
import { json, server } from '@/test/server'

// An agent's panel: lib/config reads the panel off the page's base, which the agent's index.html sets to /agent/.
vi.hoisted(() => window.history.replaceState(null, '', '/agent/'))

/*
 * An agent's «تنظیمات پنل» (apps/agent/pages/settings): their shop's backups first, then the panel's look. A backup
 * there is of their shop alone — said over the section from their session, and on its cards from the server's answer
 *—; how often it goes to their report group is the owner's to set, said without a link to a page their panel lacks;
 * and a restore says it touches their shop alone, its customers signing in again. Every request goes to their panel's API, naming no shop.
 */

const AGENT: Session = { name: '@reza_shop_bot', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active', selling_suspended: null } }

const STATUS: BackupsStatus = {
  scope: 'shop',
  shop: { id: 2, username: 'reza_shop_bot', title: 'فروشگاه رضا' },
  auto: { enabled: false, group: true, every_minutes: 1440, last_sent_at: null, last_problem: null },
  kept: [],
  upload_max_bytes: 32 * 1024 ** 2,
}

function show(at = '/settings/backups') {
  server()
    .on('GET', '/api/agent/auth/me', json({ session: AGENT }))
    .on('GET', '/api/agent/backups', json({ backups: STATUS } satisfies BackupsResponse))
  render(
    <ThemeProvider>
      <SettingsPage />
    </ThemeProvider>,
    { wrapper: signedIn({ session: AGENT, at }).wrapper },
  )
}

describe('an agent’s backups', () => {
  it('are of their shop alone, how often they go being the owner’s to set', async () => {
    show()

    const description = await screen.findByText(/^پشتیبان فروشگاه /)
    expect(description.textContent).toMatch(/^پشتیبان فروشگاه @reza_shop_bot: فقط داده‌های همین فروشگاه\./)
    expect((await screen.findByText(/یک فایل پشتیبان همین حالا ساخته می‌شود/)).textContent).toContain('فروشگاه @reza_shop_bot: فقط داده‌های همین فروشگاه')
    expect(screen.getByText(/فاصله ارسال را مالک فروشگاه اصلی تعیین می‌کند/)).toBeTruthy()
    expect(screen.queryByRole('link', { name: /زمان‌بندی کارها/ })).toBeNull()
    expect(screen.getByRole('switch', { name: 'ارسال خودکار' }).getAttribute('aria-checked')).toBe('false')
  })

  it('are sent to the group and restored through their panel’s API, a restore touching their shop alone', async () => {
    server()
      .on('PUT', '/api/agent/backups/auto', json({ backups: { ...STATUS, auto: { ...STATUS.auto, enabled: true } } } satisfies BackupsResponse))
      .on(
        'POST',
        '/api/agent/backups/inspect',
        json({
          inspection: {
            token: 'a1',
            scope: 'shop',
            shop: STATUS.shop,
            made_at: '2026-11-10T09:30:00Z',
            app_version: '0.8.0',
            tables: [{ table: 'orders', rows: 12 }],
            rows: 12,
            warnings: [],
            expires_at: '2026-11-12T10:30:00Z',
          },
        } satisfies BackupInspectionResponse),
      )
    show()

    fireEvent.click(await screen.findByRole('switch', { name: 'ارسال خودکار' }))
    await until(() => expect(screen.getByRole('switch', { name: 'ارسال خودکار' }).getAttribute('aria-checked')).toBe('true'))
    expect(server().sent('PUT', '/api/agent/backups/auto')[0]?.body).toEqual({ enabled: true })

    fireEvent.change(screen.getByLabelText('انتخاب فایل پشتیبان'), { target: { files: [new File(['gzip'], 'shop.json.gz')] } })
    const picked = await screen.findByRole('group', { name: 'فایل انتخاب‌شده' })
    expect(picked.textContent).toContain('داده‌های فروشگاه @reza_shop_bot به حالت')
    expect(picked.textContent).toContain('مشتری‌هایی که در وب‌سایت و مینی‌اپ این فروشگاه وارد شده‌اند باید دوباره وارد شوند، گفت‌وگوهای نیمه‌کاره با ربات این فروشگاه از اول شروع می‌شود')
    expect(picked.textContent).toContain('فروشگاه‌های دیگر و سرورها دست نمی‌خورند')
    expect(picked.textContent).not.toContain('همه فروشگاه‌ها')
    expect(server().requests.every((request) => request.headers['x-shop'] === undefined)).toBe(true)
  })

  it('come before the panel’s look, which is still there', async () => {
    show('/settings/appearance')

    expect(await screen.findByRole('heading', { name: 'تنظیمات پنل' })).toBeTruthy()
    // Not on screen: not read.
    expect(server().sent('GET', '/api/agent/backups')).toHaveLength(0)
    const sections = screen.getAllByRole('region', { hidden: true }).map((section) => section.getAttribute('aria-label'))
    expect(sections).toEqual(['پشتیبان‌گیری', 'ظاهر'])
  })
})
