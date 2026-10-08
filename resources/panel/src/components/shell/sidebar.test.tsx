import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AGENT_NAV } from '@/apps/agent/nav'
import { AppShell } from '@/components/shell/app-shell'
import type { PanelShell } from '@/components/shell/shell-context'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { ThemeProvider } from '@/lib/theme'
import { PHONE } from '@/lib/use-media-query'
import { testQueryClient, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The shell's columns (components/shell/sidebar, mobile-drawer): one a size — the sidebar on a desktop, the drawer on a
 * phone, never both —, a group's toggle pointing at its pages whether they show or not, and the toasts in sight while
 * the phone's drawer is open over the page.
 */

const PANEL: PanelShell = { nav: AGENT_NAV, role: 'نماینده' }

async function showPanel() {
  render(
    <QueryClientProvider client={testQueryClient()}>
      <ThemeProvider>
        <TooltipProvider>
          <MemoryRouter initialEntries={['/plans']}>
            <AuthProvider>
              <Routes>
                <Route element={<RequireAuth />}>
                  <Route element={<AppShell panel={PANEL} />}>
                    <Route path="plans" element={<p>صفحه پلن‌ها</p>} />
                  </Route>
                </Route>
              </Routes>
            </AuthProvider>
          </MemoryRouter>
        </TooltipProvider>
        <Toaster />
      </ThemeProvider>
    </QueryClientProvider>,
  )
  await screen.findByText('صفحه پلن‌ها')
}

/** The window is a phone's: the sidebar is a drawer behind the topbar's menu. */
function onAPhone() {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query) =>
      ({
        matches: query === PHONE,
        media: query,
        onchange: null,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => false,
      }) satisfies MediaQueryList,
  )
}

/** The phone's drawer, opened from the topbar. */
function openDrawer(): HTMLElement {
  fireEvent.click(screen.getByRole('button', { name: 'باز کردن منو' }))
  return screen.getByRole('dialog', { name: 'منوی اصلی' })
}

const accountRows = () => screen.queryAllByRole('group', { name: 'حساب کاربری' })

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true }))
    .on('GET', '/api/admin/auth/me', json({ session: { name: '@agent_shop_bot', shop: { id: 2, name: 'فروشگاه نماینده', username: 'agent_shop_bot' } } }))
    .on('GET', '/api/admin/changes', json({ versions: {} }))
    .on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0 } }))
})

describe('the columns', () => {
  it('are the sidebar alone on a desktop', async () => {
    await showPanel()

    expect(document.getElementById('app-sidebar')).not.toBeNull()
    expect(screen.getAllByRole('navigation', { name: 'منوی اصلی' })).toHaveLength(1)
    expect(accountRows()).toHaveLength(1)
  })

  it('are the drawer alone on a phone — one menu, one account row', async () => {
    onAPhone()
    await showPanel()

    expect(document.getElementById('app-sidebar')).toBeNull()
    const drawer = openDrawer()
    expect(within(drawer).getByRole('navigation', { name: 'منوی اصلی' })).toBeTruthy()
    expect(accountRows()).toHaveLength(1)
  })
})

describe('a group of the menu', () => {
  it('points its toggle at its pages, there while it is shut, hidden', async () => {
    await showPanel()
    const toggle = screen.getByRole('button', { name: 'فروش' })
    const pages = document.getElementById(toggle.getAttribute('aria-controls') ?? '')
    expect(within(pages ?? document.createElement('div')).getByRole('link', { name: 'سفارش‌ها' })).toBeTruthy()

    fireEvent.click(toggle)

    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(pages?.hidden).toBe(true)
    expect(document.getElementById(toggle.getAttribute('aria-controls') ?? '')).toBe(pages)
  })
})

describe('the phone’s drawer', () => {
  it('shows the toasts inside it while it is open, above its backdrop', async () => {
    onAPhone()
    await showPanel()
    const drawer = openDrawer()

    act(() => void toast.success('پلن ذخیره شد'))

    await until(() => expect(drawer.contains(screen.getByText('پلن ذخیره شد'))).toBe(true))
  })
})
