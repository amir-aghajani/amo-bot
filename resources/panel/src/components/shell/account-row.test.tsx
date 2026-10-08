import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AGENT_NAV } from '@/apps/agent/nav'
import { LeaveQuestion } from '@/components/leave-question'
import { AppShell } from '@/components/shell/app-shell'
import type { PanelShell } from '@/components/shell/shell-context'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { STORAGE_KEYS } from '@/lib/storage'
import { ThemeProvider } from '@/lib/theme'
import { PHONE } from '@/lib/use-media-query'
import { useUnsavedGuard } from '@/lib/use-unsaved-guard'
import { testQueryClient, until } from '@/test/render'
import { json, noContent, offline, server } from '@/test/server'

/*
 * The account row at the foot of the sidebar (components/shell/account-row): who is signed in — the mark, the name, the
 * role — and «خروج از حساب», which signs out in one press: no menu. On the rail the button alone; on a phone it is in
 * the drawer. Signing out drops every unsaved draft: it asks first.
 */

const PANEL: PanelShell = { nav: AGENT_NAV, role: 'نماینده' }
const SIGN_OUT = { name: 'خروج از حساب' }
const LOGOUT = '/api/admin/auth/logout'

/** A page with a draft nobody saved. */
function Unsaved() {
  useUnsavedGuard(true)
  return null
}

async function showPanel({ unsaved = false }: { unsaved?: boolean } = {}) {
  render(
    <QueryClientProvider client={testQueryClient()}>
      <ThemeProvider>
        <TooltipProvider>
          <MemoryRouter>
            <AuthProvider>
              <LeaveQuestion />
              <Routes>
                <Route path="login" element={<p>صفحه ورود</p>} />
                <Route element={<RequireAuth />}>
                  <Route element={<AppShell panel={PANEL} />}>
                    <Route
                      index
                      element={
                        <>
                          <p>صفحه داشبورد</p>
                          {unsaved && <Unsaved />}
                        </>
                      }
                    />
                  </Route>
                </Route>
              </Routes>
            </AuthProvider>
          </MemoryRouter>
        </TooltipProvider>
      </ThemeProvider>
    </QueryClientProvider>,
  )
  await screen.findByText('صفحه داشبورد')
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

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true }))
    .on('GET', '/api/admin/auth/me', json({ session: { name: '@agent_shop_bot', shop: { id: 2, name: 'فروشگاه نماینده', username: 'agent_shop_bot' } } }))
    .on('GET', '/api/admin/changes', json({ versions: {} }))
    .on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0 } }))
})

describe('the account row', () => {
  it('says who is signed in — an agent’s «@» in front, the mark’s letter apart — beside the red-outlined button that signs out', async () => {
    await showPanel()

    const row = screen.getByRole('group', { name: 'حساب کاربری' })

    expect(row.querySelector('bdi')?.textContent).toBe('@agent_shop_bot')
    expect(within(row).getByText('نماینده')).toBeTruthy()
    // The mark is the bot's first letter, not its «@» — and hidden from assistive tech, which has the name.
    expect(row.querySelector('[aria-hidden="true"]')?.textContent).toBe('A')
    expect(
      within(row)
        .getAllByRole('button')
        .map((button) => button.getAttribute('aria-label')),
    ).toEqual([SIGN_OUT.name])
    expect(within(row).getByRole('button', SIGN_OUT).getAttribute('data-variant')).toBe('danger-outline')
    expect(screen.queryByRole('menu')).toBeNull()
  })

  it('is the button alone on the rail', async () => {
    localStorage.setItem(STORAGE_KEYS.sidebar, 'collapsed')
    await showPanel()

    expect(screen.queryByRole('group', { name: 'حساب کاربری' })).toBeNull()
    expect(screen.queryByText('@agent_shop_bot')).toBeNull()
    expect(screen.getByRole('button', SIGN_OUT)).toBeTruthy()
  })

  it('is in the phone’s drawer', async () => {
    onAPhone()
    await showPanel()

    fireEvent.click(screen.getByRole('button', { name: 'باز کردن منو' }))
    const drawer = screen.getByRole('dialog', { name: 'منوی اصلی' })

    expect(within(drawer).getByRole('button', SIGN_OUT)).toBeTruthy()
  })
})

describe('signing out', () => {
  it('takes one press — a second one while it runs sends nothing more — and goes to the sign-in', async () => {
    const out = server().hold('POST', LOGOUT)
    await showPanel()
    const button = screen.getByRole('button', SIGN_OUT)

    fireEvent.click(button)
    fireEvent.click(button)

    await until(() => expect(out.waiting).toBe(1))
    expect(button.getAttribute('aria-busy')).toBe('true')
    out.answer(noContent)
    expect(await screen.findByText('صفحه ورود')).toBeTruthy()
    expect(server().sent('POST', LOGOUT)).toHaveLength(1)
  })

  it('stays signed in, and says so, when the server could not be reached', async () => {
    vi.spyOn(toast, 'error')
    server().on('POST', LOGOUT, offline)
    await showPanel()
    const button = screen.getByRole('button', SIGN_OUT)

    fireEvent.click(button)

    await until(() => expect(toast.error).toHaveBeenCalled())
    expect(screen.getByText('صفحه داشبورد')).toBeTruthy()
    expect(button.getAttribute('aria-busy')).toBeNull()
  })

  it('asks first while a draft is unsaved, and stays signed in when the admin would rather stay', async () => {
    await showPanel({ unsaved: true })

    fireEvent.click(screen.getByRole('button', SIGN_OUT))
    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(question).getByRole('button', { name: 'ماندن' }))

    await until(() => expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull())
    expect(server().sent('POST', LOGOUT)).toEqual([])
    expect(screen.getByText('صفحه داشبورد')).toBeTruthy()
  })

  it('goes to the sign-in once the admin agreed, asking nothing more', async () => {
    server().on('POST', LOGOUT, noContent)
    await showPanel({ unsaved: true })

    fireEvent.click(screen.getByRole('button', SIGN_OUT))
    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(question).getByRole('button', { name: 'رها کردن تغییرات' }))

    expect(await screen.findByText('صفحه ورود')).toBeTruthy()
    expect(server().sent('POST', LOGOUT)).toHaveLength(1)
  })
})
