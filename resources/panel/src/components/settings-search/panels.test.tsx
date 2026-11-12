import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, describe, expect, it } from 'vitest'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { AGENT_NAV } from '@/apps/agent/nav'
import { AppShell } from '@/components/shell/app-shell'
import { navOfShop } from '@/components/shell/nav'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { buildIndex } from '@/lib/settings-search'
import { ThemeProvider } from '@/lib/theme'
import { AGENT_SHOP, testQueryClient } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The settings search over each panel's own index (lib/settings-search, built from the panel's navigation for the shop
 * on screen): an agent never finds the owner's settings, an agent's shop never the main shop's own sections, and every
 * entry is one the server keeps as a favorite.
 */

/** Pages and sections the owner's panel alone has. */
const OWNERS_ONLY = [
  '/servers',
  '/agents',
  '/custom-services',
  '/broadcasts/gifts',
  '/settings/telegram',
  '/settings/database',
  '/settings/app',
  '/settings/mail',
  '/settings/sms',
  '/settings/login',
  '/settings/update',
  '/settings/schedule',
  '/settings/advanced',
]

/** The main shop's own: an agent's shop — in either panel — has none of them. */
const MAIN_SHOPS_ONLY = ['/custom-services', '/bot-settings/service-claims']

const under = (path: string, places: string[]) => places.some((place) => path === place || path.startsWith(`${place}/`) || path.startsWith(`${place}#`))

describe('a panel’s settings index', () => {
  it('has none of the owner’s own settings in an agent’s panel (whose shop is never the main one)', () => {
    const index = buildIndex(navOfShop(AGENT_NAV, false))

    expect(index.length).toBeGreaterThan(0)
    expect(index.filter((entry) => under(entry.path, [...OWNERS_ONLY, ...MAIN_SHOPS_ONLY])).map((entry) => entry.id)).toEqual([])
  })

  it('has none of the main shop’s own sections while the owner has an agent’s shop open — and has them in the main shop', () => {
    expect(buildIndex(navOfShop(ADMIN_NAV, false)).filter((entry) => under(entry.path, MAIN_SHOPS_ONLY))).toEqual([])
    expect(buildIndex(ADMIN_NAV).some((entry) => entry.id === 'section:/bot-settings/service-claims')).toBe(true)
  })

  it('names every entry once, as the server keeps a favorite', () => {
    for (const nav of [ADMIN_NAV, AGENT_NAV]) {
      const ids = buildIndex(nav).map((entry) => entry.id)
      expect(new Set(ids).size).toBe(ids.length)
      for (const id of ids) expect(id).toMatch(/^(page|section|setting):\/\S{0,190}$/)
    }
  })
})

describe('an agent’s settings search', () => {
  beforeEach(() => {
    server()
      .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true }))
      .on('GET', '/api/admin/auth/me', json({ session: AGENT_SHOP }))
      .on('GET', '/api/admin/changes', json({ versions: {} }))
      .on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0 } }))
      .on('GET', '/api/admin/preferences', json({ preferences: { favorites: [] } }))
  })

  it('maps and finds the agent’s own settings only', async () => {
    render(
      <QueryClientProvider client={testQueryClient()}>
        <ThemeProvider>
          <TooltipProvider>
            <MemoryRouter initialEntries={['/plans']}>
              <AuthProvider>
                <Routes>
                  <Route element={<RequireAuth />}>
                    <Route element={<AppShell panel={{ nav: AGENT_NAV, role: 'نماینده' }} />}>
                      <Route path="plans" element={<p>صفحه پلن‌ها</p>} />
                    </Route>
                  </Route>
                </Routes>
              </AuthProvider>
            </MemoryRouter>
          </TooltipProvider>
        </ThemeProvider>
      </QueryClientProvider>,
    )
    await screen.findByText('صفحه پلن‌ها')

    fireEvent.keyDown(document.body, { key: 'k', code: 'KeyK', ctrlKey: true })
    const dialog = await screen.findByRole('dialog', { name: 'جستجوی تنظیمات' })

    const panel = within(dialog).getByRole('group', { name: 'تنظیمات پنل' })
    expect(
      within(panel)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['پشتیبان‌گیری', 'ظاهر'])
    expect(within(dialog).queryByRole('option', { name: 'افزودن سرویس با لینک' })).toBeNull()

    fireEvent.change(within(dialog).getByRole('combobox'), { target: { value: 'کیف پول' } })

    const found = within(dialog).getAllByRole('option')
    expect(found.some((option) => (option.textContent ?? '').includes('کیف پول'))).toBe(true)
    expect(found.some((option) => [...option.querySelectorAll('mark')].some((mark) => 'کیف پول'.includes(mark.textContent ?? '-')))).toBe(true)
  })
})
