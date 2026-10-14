import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { KeyboardsResponse, TestAccountsResponse, TestServerRow } from '@/lib/api-types'
import { keyboardsQuery } from '@/lib/queries'
import { TestsPage } from '@/pages/tests'
import { providers, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, testAccountRow, testServerRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The free test accounts' page (pages/tests): the tests customers took — each one's terms, where it stands with its
 * service, and whether its customer bought after it —; every server's test terms and whether it gives a test now, each
 * leading to its own test card where the panel has server pages; the rules saved as the bot settings' `test_accounts`
 * group; and the page saying so while tests are off, while no server gives one, or while the bot's menu has no
 * «اکانت تست» button.
 */

const SERVERS: TestServerRow[] = [testServerRow(), testServerRow({ id: 2, name: 'هلند', test_enabled: false, available: false })]

/** The bot's saved menu («start»), a row a button: the actions of its buttons. */
const keyboards = (menu: string[]): KeyboardsResponse => ({
  keyboards: [{ name: 'start', title: 'منوی اصلی', is_default: false, type: 'reply', rows: menu.map((action) => [{ action, label: action, style: null, icon: null }]) }],
  actions: [],
  styles: [],
  limits: { rows: 10, per_row: 8, label: 64 },
})

interface Open {
  at?: string
  enabled?: boolean
  servers?: TestServerRow[]
  /** The actions of the bot menu's buttons. */
  menu?: string[]
  serverPage?: (id: number) => string
}

function open({ at = '/tests', enabled = true, servers = SERVERS, menu = ['buy', 'test'], serverPage }: Open = {}) {
  server()
    .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ test_enabled: enabled }))))
    .on('GET', '/api/admin/keyboards', json(keyboards(menu)))
    .on('GET', '/api/admin/tests/servers', json({ servers }))
    .on(
      'GET',
      '/api/admin/tests',
      json({
        tests: [
          testAccountRow({ id: 53, state: 'removed', subscription: null, server: null, customer: { id: 12, name: 'سارا', username: 'sara', telegram_id: 5003, email: null } }),
          testAccountRow({
            id: 52,
            state: 'running',
            on_hold: false,
            bought_after: true,
            customer: { id: 11, name: 'رضا', username: 'reza', telegram_id: 5002, email: null },
            subscription: {
              id: 8,
              name: 'test_reza_1',
              status: 'active',
              traffic: { limit: 500 * 1024 ** 2, used: 100 * 1024 ** 2 },
              starts_at: '2026-10-06T09:00:00Z',
              expires_at: '2026-10-07T09:00:00Z',
            },
          }),
          testAccountRow(),
        ],
        meta: { page: 1, per_page: 25, total: 3, last_page: 1 },
      } satisfies TestAccountsResponse),
    )
  const { client, wrapper } = providers({ at })
  render(<TestsPage serverPage={serverPage} />, { wrapper })
  return client
}

const rowsOf = async (table: string) =>
  within(await screen.findByRole('table', { name: table }))
    .getAllByRole('row')
    .slice(1)

describe('the tests', () => {
  it('list each one’s terms, where it stands, its service and whether its customer bought after it', async () => {
    open()

    const [removed, running, waiting] = await rowsOf('اکانت‌های تست')
    if (!removed || !running || !waiting) throw new Error('Three tests are listed.')
    expect(within(waiting).getByText('در انتظار اولین اتصال')).toBeTruthy()
    expect(within(waiting).getByText('از اولین اتصال')).toBeTruthy()
    expect(within(waiting).getByRole('link', { name: 'test_amir_1' }).getAttribute('href')).toBe('/subscriptions?search=%237')
    expect(within(waiting).queryByText('خرید کرد')).toBeNull()

    expect(within(running).getByText('فعال')).toBeTruthy()
    expect(within(running).getByText('از لحظه ساخت')).toBeTruthy()
    expect(within(running).getByText('خرید کرد')).toBeTruthy()

    expect(within(removed).getByText('حذف‌شده')).toBeTruthy()
    expect(within(removed).getByText('سرور حذف شده')).toBeTruthy()
    expect(within(removed).queryByRole('link', { name: /test_/ })).toBeNull()
  })
})

describe('the servers', () => {
  it('say each one’s terms and whether it gives a test now, each leading to its own test card', async () => {
    open({ at: '/tests/servers', serverPage: (id) => `/servers/${id}#test-account` })

    const [germany, holland] = await rowsOf('سرورها')
    if (!germany || !holland) throw new Error('Two servers are listed.')
    expect(within(germany).getByRole('link', { name: 'آلمان' }).getAttribute('href')).toBe('/servers/1#test-account')
    expect(within(germany).getByText('اکانت تست می‌دهد')).toBeTruthy()
    expect(within(germany).getByText(/۵۰۰ مگابایت · ۱ روز/)).toBeTruthy()
    expect(within(holland).getByText('خاموش')).toBeTruthy()
  })

  it('only read where the panel has no server pages (an agent’s)', async () => {
    open({ at: '/tests/servers', servers: [testServerRow({ available: false })] })

    const [germany] = await rowsOf('سرورها')
    if (!germany) throw new Error('A server is listed.')
    expect(within(germany).getByText('آلمان')).toBeTruthy()
    expect(within(germany).queryByRole('link')).toBeNull()
    expect(within(germany).getByText('فعلا نمی‌دهد')).toBeTruthy()
  })
})

describe('the rules', () => {
  it('are saved as the bot settings’ `test_accounts` group, the hints saying what the numbers mean', async () => {
    open({ at: '/tests/settings' })
    server().on('PUT', '/api/admin/bot/settings/test_accounts', json({ settings: botSettingsRow({ test_enabled: true, test_per_user: 2, test_period_days: 30 }) }))

    await screen.findByLabelText('دوره سهمیه (روز)')
    expect(screen.getByText('0 یعنی برای همیشه: هر مشتری روی هم همین تعداد می‌گیرد.')).toBeTruthy()
    expect(screen.getByText('0 یعنی بدون سقف.')).toBeTruthy()
    expect(screen.getByText('۱ روز بعد از پایان حذف می‌شود.')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('تعداد اکانت تست هر مشتری'), { target: { value: '2' } })
    fireEvent.change(screen.getByLabelText('دوره سهمیه (روز)'), { target: { value: '۳۰' } })
    expect(screen.getByText('هر مشتری در هر ۳۰ روز ۲ اکانت تست می‌گیرد.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() =>
      expect(server().sent('PUT', '/api/admin/bot/settings/test_accounts')[0]?.body).toEqual({
        test_enabled: true,
        test_per_user: '2',
        test_period_days: '۳۰',
        test_daily_limit: '0',
        test_newcomers_only: false,
        test_cleanup: true,
        test_cleanup_hours: '24',
        test_unused_days: '7',
      }),
    )
  })

  it('say so while the tests are off', async () => {
    open({ enabled: false })

    expect(await screen.findByText(/اکانت تست خاموش است/)).toBeTruthy()
  })

  it('say so while no server gives a test', async () => {
    open({ servers: [testServerRow({ available: false })] })

    expect(await screen.findByText(/الان هیچ سروری اکانت تست نمی‌دهد/)).toBeTruthy()
    expect(screen.queryByText(/اکانت تست خاموش است/)).toBeNull()
  })

  it('say so while the bot’s menu has no «اکانت تست» button, leading to the keyboards', async () => {
    open({ menu: ['buy'] })

    const notice = await screen.findByText(/دکمه «اکانت تست» در منوی ربات نیست/)
    expect(notice.textContent).toContain('مشتری‌ها به گرفتن اکانت تست در ربات راهی ندارند')
    expect(screen.getByRole('link', { name: 'افزودن دکمه در صفحه کیبوردها' }).getAttribute('href')).toBe('/keyboards')
  })

  it('say nothing of the button while the menu has it', async () => {
    const client = open()

    await until(() => expect(client.getQueryState(keyboardsQuery.queryKey)?.status).toBe('success'))
    expect(screen.queryByText(/در منوی ربات نیست/)).toBeNull()
  })
})
