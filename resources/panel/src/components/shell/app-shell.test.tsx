import { QueryClientProvider, useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { AGENT_NAV } from '@/apps/agent/nav'
import { NotFoundPage } from '@/components/not-found'
import { AppShell } from '@/components/shell/app-shell'
import type { PanelShell } from '@/components/shell/shell-context'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { useTitleSubject } from '@/lib/document-title'
import { FAILURE_KINDS } from '@/lib/failure'
import { ThemeProvider } from '@/lib/theme'
import { testQueryClient, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The shell around a panel's pages (components/shell/app-shell): the tab named after the page and the shop; a page that
 * throws shows what happened in its place while the rest of the panel still leads anywhere; and moving between a page's
 * sections keeps the page — and what was typed in it — mounted.
 */

const PANEL: PanelShell = { nav: AGENT_NAV, role: 'نماینده' }

function Go({ to }: { to: string }) {
  const navigate = useNavigate()
  return (
    <button type="button" onClick={() => void navigate(to)}>
      برو به {to}
    </button>
  )
}

function Broken(): never {
  throw new Error('Cannot read properties of undefined')
}

/** A page that opens one subject, and names it once it knows it. */
function Subject() {
  useTitleSubject('آلمان ۲')
  return <p>صفحه سرور</p>
}

/** A page that reads what it shows. */
function Reading() {
  const { data } = useQuery({ queryKey: ['subscriptions'], queryFn: () => fetch('/api/admin/subscriptions').then((answer) => answer.json()) })
  return <p>{data ? 'اشتراک‌ها خوانده شد' : 'صفحه اشتراک‌ها'}</p>
}

function open(at: string, panel: PanelShell = PANEL) {
  render(
    <QueryClientProvider client={testQueryClient()}>
      <ThemeProvider>
        <TooltipProvider>
          <MemoryRouter initialEntries={[at]}>
            <AuthProvider>
              <Routes>
                <Route element={<RequireAuth />}>
                  <Route element={<AppShell panel={panel} />}>
                    <Route path="users/:section?" element={<input aria-label="جستجوی کاربران" />} />
                    <Route path="plans" element={<Broken />} />
                    <Route path="orders" element={<p>صفحه سفارش‌ها</p>} />
                    <Route path="payments" element={<Subject />} />
                    <Route path="subscriptions" element={<Reading />} />
                    <Route path="*" element={<NotFoundPage />} />
                  </Route>
                </Route>
              </Routes>
              <Go to="/users" />
              <Go to="/users/groups" />
              <Go to="/orders" />
              <Go to="/payments" />
            </AuthProvider>
          </MemoryRouter>
        </TooltipProvider>
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true }))
    .on('GET', '/api/admin/auth/me', json({ session: { name: '@agent_shop_bot', shop: { id: 2, name: 'فروشگاه نماینده', username: 'agent_shop_bot' } } }))
    .on('GET', '/api/admin/changes', json({ versions: {} }))
    .on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0 } }))
})

describe('the shell', () => {
  it('names the tab after the section, the page and the shop it shows — and a subject a page has open, first', async () => {
    open('/users/groups')
    await until(() => expect(document.title).toBe('گروه‌ها · کاربران · فروشگاه نماینده'))

    fireEvent.click(screen.getByText('برو به /users'))
    await until(() => expect(document.title).toBe('کاربران · فروشگاه نماینده'))

    fireEvent.click(screen.getByText('برو به /payments'))
    await until(() => expect(document.title).toBe('آلمان ۲ · پرداخت‌ها · فروشگاه نماینده'))

    fireEvent.click(screen.getByText('برو به /orders'))
    await until(() => expect(document.title).toBe('سفارش‌ها · فروشگاه نماینده'))
  })

  it('names the shop it shows in its brand — not the installation’s own name', async () => {
    open('/orders')

    expect((await screen.findByRole('link', { name: 'فروشگاه نماینده — داشبورد' })).textContent).toBe('فروشگاه نماینده')
    expect(screen.queryByText('فروشگاه امو')).toBeNull()
  })

  it('says so of an address no page has, with the way to the dashboard — the sidebar still there, the page named for what it is', async () => {
    open('/nowhere')

    expect(await screen.findByText('این صفحه پیدا نشد')).toBeTruthy()
    expect(screen.getByText(/لینکش قدیمی است/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'رفتن به داشبورد' }).getAttribute('href')).toBe('/')
    expect(screen.getByRole('navigation', { name: 'منوی اصلی' })).toBeTruthy()
    await until(() => expect(document.title).toBe('صفحه پیدا نشد · فروشگاه نماینده'))
    expect(within(screen.getByRole('banner')).getByText('صفحه پیدا نشد')).toBeTruthy()
    expect(document.body.textContent).not.toContain('مدیریت')
  })

  it('says on the phone’s menu button whether the menu is open, and which it is', async () => {
    open('/orders')

    const menu = await screen.findByRole('button', { name: 'باز کردن منو' })
    expect(menu.getAttribute('aria-expanded')).toBe('false')
    expect(document.getElementById(menu.getAttribute('aria-controls') ?? '')?.getAttribute('aria-label')).toBe('منوی اصلی')
    fireEvent.click(menu)
    expect(menu.getAttribute('aria-expanded')).toBe('true')
  })

  it('shows a page that throws as what happened, and the next address draws afresh', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    open('/plans')

    expect(await screen.findByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeTruthy()
    fireEvent.click(screen.getByText('برو به /orders'))

    expect(await screen.findByText('صفحه سفارش‌ها')).toBeTruthy()
    expect(screen.queryByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeNull()
  })

  it('keeps a page mounted across its sections — what was typed stays', async () => {
    open('/users')
    fireEvent.change(await screen.findByLabelText('جستجوی کاربران'), { target: { value: 'amir' } })

    fireEvent.click(screen.getByText('برو به /users/groups'))
    fireEvent.click(screen.getByText('برو به /users'))

    expect((screen.getByLabelText('جستجوی کاربران') as HTMLInputElement).value).toBe('amir')
  })
})

describe('the menu’s queues', () => {
  beforeEach(() => {
    server().on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 2, stuck_orders: 1, open_tickets: 0, pending_reviews: 0 } }))
  })

  it('are asked for after the page on screen has asked for what it shows — the server answers one at a time', async () => {
    server().on('GET', '/api/admin/subscriptions', json({}))
    open('/subscriptions')

    await until(() => expect(server().sent('GET', '/api/admin/queues')).toHaveLength(1))
    const order = server().requests.map((request) => request.path)
    expect(order.indexOf('/api/admin/subscriptions')).toBeGreaterThan(-1)
    expect(order.indexOf('/api/admin/subscriptions')).toBeLessThan(order.indexOf('/api/admin/queues'))
    expect(await screen.findByText('اشتراک‌ها خوانده شد')).toBeTruthy()
  })

  it('count what waits beside the payments and the orders, in their queue’s words', async () => {
    open('/orders')

    const payments = (await screen.findAllByRole('link', { name: /پرداخت‌ها/ }))[0]
    await until(() => expect(payments?.textContent).toBe('پرداخت‌ها۲ در انتظار بررسی'))
    expect(screen.getAllByRole('link', { name: /سفارش‌ها/ })[0]?.textContent).toBe('سفارش‌ها۱ نیازمند رسیدگی')
    expect(screen.getAllByRole('link', { name: /اشتراک‌ها/ })[0]?.textContent).toBe('اشتراک‌ها')
  })

  it.each([
    ['an agent’s', PANEL],
    ['the owner’s', { nav: ADMIN_NAV, role: 'مالک فروشگاه' }],
  ] as const)(
    'count the tickets waiting on an answer beside «پشتیبانی», right after the dashboard, and the reviews waiting on support beside «نظرات» after it — in %s panel',
    async (_whose, panel) => {
      server().on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 4, pending_reviews: 3 } }))
      open('/orders', panel)

      const menu = await screen.findByRole('navigation', { name: 'منوی اصلی' })
      const [dashboard, support, reviews] = within(menu).getAllByRole('link')
      expect(dashboard?.textContent).toBe('داشبورد')
      expect(support?.getAttribute('href')).toBe('/tickets')
      expect(reviews?.getAttribute('href')).toBe('/reviews')
      await until(() => expect(support?.textContent).toBe('پشتیبانی۴ در انتظار پاسخ'))
      expect(reviews?.textContent).toBe('نظرات۳ در انتظار بررسی')
    },
  )

  it('mark a folded group that holds them, the most pressing queue first', async () => {
    open('/plans')
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const sales = (await screen.findAllByRole('button', { name: 'فروش' }))[0]
    if (!sales) throw new Error('No «فروش» group.')
    await until(() => expect(screen.getAllByRole('link', { name: /پرداخت‌ها/ })[0]?.textContent).toContain('۲'))

    fireEvent.click(sales)

    expect(sales.getAttribute('aria-expanded')).toBe('false')
    expect(sales.textContent).toBe('فروش (نیازمند رسیدگی)')
    expect(screen.queryAllByRole('link', { name: /پرداخت‌ها/ })).toHaveLength(0)
  })
})
