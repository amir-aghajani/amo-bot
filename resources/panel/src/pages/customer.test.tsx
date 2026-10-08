import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { describe, expect, it } from 'vitest'
import type { AgentRow, OrdersResponse, PaymentsResponse, SubscriptionsResponse, TicketsResponse, UserDetailResponse, UserWalletResponse } from '@/lib/api-types'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { idLabel } from '@/lib/direction'
import { queryKeys } from '@/lib/query-keys'
import { CustomerPage, type CustomerPageProps } from '@/pages/customer'
import { OWNER, testQueryClient, until } from '@/test/render'
import { customerAccount, orderRow, paymentRow, subscriptionRow, ticketRow, userRow } from '@/test/rows'
import { json, refusal, server, type Answer } from '@/test/server'

/*
 * A customer's page (pages/customer): who they are — the three identifiers apart —, then a card of each thing of theirs,
 * each card from its own read and in its own state (a failed read says so with a way to ask again, the other cards
 * stand), each row opening its own screen's dialog and each card leading to that screen narrowed to the customer. A
 * customer the shop does not have is not found; another customer's page starts afresh.
 */

const CUSTOMER = userRow()

const DETAIL: UserDetailResponse = {
  user: CUSTOMER,
  referral: { referrer: { id: 4, name: 'رضا', username: 'reza', telegram_id: 55, email: null }, referrals: 2, earned: '10000.00' },
  agency: null,
  account: customerAccount(),
}

const META = { page: 1, per_page: 25, total: 1, last_page: 1 }

const PAYMENTS = json({ payments: [paymentRow()], meta: { ...META, sort: 'created', dir: 'desc', awaiting_review: 1, paid: 0, paid_amount: '0.00' } } satisfies PaymentsResponse)

/** What the server answers each of the page's reads — the customer's own as `detail` says. */
function answers({ detail = json(DETAIL) }: { detail?: Answer } = {}) {
  server()
    .on('GET', '/api/admin/users/10', detail)
    .on('GET', '/api/admin/subscriptions', json({ subscriptions: [subscriptionRow()], meta: { ...META, sort: 'created', dir: 'desc', expiring: 0, expiring_days: 3 } } satisfies SubscriptionsResponse))
    .on('GET', '/api/admin/payments', PAYMENTS)
    .on('GET', '/api/admin/orders', json({ orders: [orderRow()], meta: { ...META, sort: 'created', dir: 'desc', stuck: 0, sold: 0, sold_amount: '0.00' } } satisfies OrdersResponse))
    .on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions: [] } satisfies UserWalletResponse))
    .on('GET', '/api/admin/tickets', json({ tickets: [ticketRow()], meta: { ...META, open: 1 } } satisfies TicketsResponse))
}

/** The page at `at`, in a panel signed in to the main bot's shop that gives it `props` — as the app mounts it, behind RequireAuth. */
function open(at = '/users/10', props: CustomerPageProps = {}) {
  server().on('GET', '/api/admin/auth/me', json({ session: OWNER }))
  const client = testQueryClient()
  const signedIn = (
    <AuthProvider>
      <RequireAuth />
    </AuthProvider>
  )
  const router = createMemoryRouter([{ element: signedIn, children: [{ path: 'users/:id', element: <CustomerPage {...props} /> }] }], { initialEntries: [at] })
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
  return { router, client }
}

/** The card under its title. */
function card(title: string): HTMLElement {
  const heading = screen.getByRole('heading', { level: 2, name: title })
  const box = heading.closest<HTMLElement>('[data-slot="card"]')
  if (!box) throw new Error(`No card «${title}».`)
  return box
}

const linkIn = (title: string, name: string) => within(card(title)).getByRole('link', { name }).getAttribute('href')

/** How often the page has read `path` of the API. */
const reads = (path: string) => server().sent('GET', `/api/admin/${path}`).length

describe('a customer’s page', () => {
  it('says who they are — the name, the handle and the Telegram id apart — and their account', async () => {
    answers()
    open()

    const title = await screen.findByRole('heading', { level: 1 })
    expect(title.textContent).toContain('امیر')
    expect(title.textContent).toContain('فعال')
    expect(screen.getByText('#10')).toBeTruthy()
    expect(screen.getByText('@amir')).toBeTruthy()
    expect(screen.getByText('123456789')).toBeTruthy()
    expect(screen.getByText('+989120000001')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'گفتگو در تلگرام' }).getAttribute('href')).toBe('https://t.me/amir')
  })

  it('says of a customer who signed up on the website their email — and offers no chat in Telegram they do not have', async () => {
    answers({ detail: json({ ...DETAIL, user: { ...CUSTOMER, username: null, telegram_id: null, email: 'amir@example.com' } }) })
    open()

    expect((await screen.findByRole('heading', { level: 1 })).textContent).toContain('امیر')
    expect(screen.getByText('amir@example.com').getAttribute('dir')).toBe('ltr')
    expect(screen.getByText('بدون تلگرام')).toBeTruthy()
    expect(screen.queryByText('شناسه تلگرام', { exact: false })).toBeNull()
    expect(screen.queryByRole('link', { name: 'گفتگو در تلگرام' })).toBeNull()
  })

  it('reads each card narrowed to the customer, and leads to each list narrowed the same', async () => {
    answers()
    open()

    await until(() => expect(within(card('سرویس‌ها')).getByText('amir_1')).toBeTruthy())
    await until(() => expect(within(card('پرداخت‌ها')).getByText('#7')).toBeTruthy())
    await until(() => expect(within(card('سفارش‌ها')).getByText('#12')).toBeTruthy())
    await until(() => expect(within(card('تیکت‌ها')).getByText('در انتظار پاسخ')).toBeTruthy())
    for (const list of ['subscriptions', 'payments', 'orders', 'tickets']) {
      expect(
        server()
          .sent('GET', `/api/admin/${list}`)
          .map((request) => request.query.get('user')),
      ).toEqual(['10'])
    }

    expect(linkIn('سرویس‌ها', 'همه سرویس‌ها')).toBe('/subscriptions?user=10')
    expect(linkIn('پرداخت‌ها', 'همه پرداخت‌ها')).toBe('/payments?user=10')
    expect(linkIn('سفارش‌ها', 'همه سفارش‌ها')).toBe('/orders?user=10')
    expect(linkIn('تیکت‌ها', 'همه تیکت‌ها')).toBe('/tickets?user=10')
    expect(linkIn('تیکت‌ها', 'سرعت سرویس پایین است')).toBe('/tickets/12')
    expect(linkIn('زیرمجموعه‌گیری', 'رضا')).toBe('/users/4')
    expect(linkIn('زیرمجموعه‌گیری', '۲ نفر')).toBe('/referrals/invitees?referrer=10')
    expect(within(card('گروه‌ها')).getByText('VIP')).toBeTruthy()
    await until(() => expect(within(card('کیف پول')).getByText('هنوز تراکنشی ندارد')).toBeTruthy())
  })

  it('says of a customer who never opened a ticket so — and offers no list of none', async () => {
    answers()
    server().on('GET', '/api/admin/tickets', json({ tickets: [], meta: { ...META, total: 0, open: 0 } } satisfies TicketsResponse))
    open()

    await until(() => expect(within(card('تیکت‌ها')).getByText('هنوز تیکتی باز نکرده است')).toBeTruthy())
    expect(within(card('تیکت‌ها')).queryByRole('link', { name: 'همه تیکت‌ها' })).toBeNull()
  })

  it('opens a service in the subscriptions screen’s dialog', async () => {
    answers()
    // The dialog follows its row: it reads it again.
    server().on('GET', '/api/admin/subscriptions/1', json({ subscription: subscriptionRow() }))
    open()

    fireEvent.click(await within(await screen.findByRole('table', { name: 'سرویس‌ها' })).findByRole('button', { name: 'amir_1' }))

    const dialog = await screen.findByRole('dialog', { name: 'amir_1' })
    expect(within(dialog).getByText('https://sub.example.com/s/Zx81')).toBeTruthy()
  })

  it('says of a row deleted elsewhere that it is gone, and offers nothing more to do with it', async () => {
    answers()
    server().on('GET', '/api/admin/subscriptions/1', refusal(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.'))
    open()

    fireEvent.click(await within(await screen.findByRole('table', { name: 'سرویس‌ها' })).findByRole('button', { name: 'amir_1' }))

    const dialog = await screen.findByRole('dialog', { name: 'amir_1' })
    await until(() => expect(within(dialog).getByText(/این مورد دیگر وجود ندارد/)).toBeTruthy())
    expect(within(dialog).queryByRole('button', { name: 'غیرفعال کردن' })).toBeNull()
  })

  it('reads the row and its card again when an operation finds the row moved on', async () => {
    const paid = 'فقط سفارش پرداخت‌نشده لغو می‌شود.'
    answers()
    server()
      .on('GET', '/api/admin/orders/12', json({ order: orderRow() }))
      .on('POST', '/api/admin/orders/12/cancel', refusal(422, paid, { status: [paid] }))
    open()

    fireEvent.click(await within(await screen.findByRole('table', { name: 'سفارش‌ها' })).findByRole('button', { name: '#12' }))
    const dialog = await screen.findByRole('dialog', { name: `سفارش ${idLabel(12)}` })
    fireEvent.click(within(dialog).getByRole('button', { name: 'لغو سفارش' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'لغو سفارش' }))

    await until(() => expect(within(dialog).getByText(paid)).toBeTruthy())
    await until(() => expect(server().sent('GET', '/api/admin/orders/12')).toHaveLength(2))
    expect(server().sent('GET', '/api/admin/orders')).toHaveLength(2)
  })

  it('reads again what an operation moves on the page — the customer, their wallet, their payments and services', async () => {
    answers()
    server()
      .on('GET', '/api/admin/orders/12', json({ order: orderRow() }))
      .on('POST', '/api/admin/orders/12/cancel', json({ order: orderRow({ status: 'cancelled', actions: { retry: false, cancel: false } }) }))
    open()
    await until(() => expect(within(card('کیف پول')).getByText('هنوز تراکنشی ندارد')).toBeTruthy())

    fireEvent.click(await within(await screen.findByRole('table', { name: 'سفارش‌ها' })).findByRole('button', { name: '#12' }))
    const dialog = await screen.findByRole('dialog', { name: `سفارش ${idLabel(12)}` })
    fireEvent.click(within(dialog).getByRole('button', { name: 'لغو سفارش' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'لغو سفارش' }))

    await until(() => expect(reads('users/10')).toBe(2))
    await until(() => expect(reads('users/10/wallet')).toBe(2))
    await until(() => expect(reads('payments')).toBe(2))
    await until(() => expect(reads('subscriptions')).toBe(2))
    // The order itself is the dialog's answer, put in its place: its card is not read again for it.
    expect(reads('orders')).toBe(1)
  })

  it('says of a card whose read failed that it did, with a way to ask again — the other cards stand', async () => {
    let failing = true
    answers()
    server().on('GET', '/api/admin/payments', () => (failing ? refusal(500, 'خطای سرور.') : PAYMENTS))
    open()

    await until(() => expect(within(card('پرداخت‌ها')).getByText('لیست پرداخت‌ها بارگذاری نشد.')).toBeTruthy())
    await until(() => expect(within(card('سرویس‌ها')).getByText('amir_1')).toBeTruthy())

    failing = false
    fireEvent.click(within(card('پرداخت‌ها')).getByRole('button', { name: 'تلاش دوباره' }))

    await until(() => expect(within(card('پرداخت‌ها')).getByText('#7')).toBeTruthy())
  })

  it('bans the customer after a second look, and says so at once', async () => {
    answers()
    server().on('PATCH', '/api/admin/users/10', json({ user: { ...CUSTOMER, status: 'banned' } }))
    open()

    const account = await screen.findByRole('button', { name: 'حساب مشتری' })
    fireEvent.pointerDown(account, { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'مسدود کردن' }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'مسدود کردن کاربر' })).getByRole('button', { name: 'مسدود کن' }))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('مسدود'))
    expect(
      server()
        .sent('PATCH', '/api/admin/users/10')
        .map((request) => request.body),
    ).toEqual([{ status: 'banned' }])
  })

  it('makes the customer a bot admin after a second look, by the role’s own address — never the account’s', async () => {
    answers()
    server().on('PUT', '/api/admin/users/10/role', json({ user: { ...CUSTOMER, role: 'admin' } }))
    open()

    const account = await screen.findByRole('button', { name: 'حساب مشتری' })
    fireEvent.pointerDown(account, { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'مدیر ربات کردن' }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'مدیر ربات کردن' })).getByRole('button', { name: 'مدیر ربات کن' }))

    await until(() => expect(screen.getByRole('button', { name: 'مدیر' })).toBeTruthy())
    expect(
      server()
        .sent('PUT', '/api/admin/users/10/role')
        .map((request) => request.body),
    ).toEqual([{ role: 'admin' }])
    expect(server().sent('PATCH', '/api/admin/users/10')).toEqual([])
  })

  it('shows an agent’s agency, and where the panel manages them', async () => {
    const agency: AgentRow = {
      id: 10,
      user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
      status: 'active',
      level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
      credit_limit: '500000.00',
      balance: '25000.00',
      bot: { id: 2, username: 'agent_shop_bot', title: 'فروشگاه نماینده', status: 'active', connected: true, problem: null, traffic_balance: 50 * 1024 ** 3, connected_at: '2026-09-02T10:00:00Z' },
      counts: { customers: 4, sold: 3, active: 2 },
    }
    answers({ detail: json({ ...DETAIL, agency } satisfies UserDetailResponse) })
    open('/users/10', { agentPage: (agent) => `/agents/list?search=${agent.user.telegram_id}` })

    await screen.findByRole('heading', { level: 2, name: 'نمایندگی' })
    expect(within(card('نمایندگی')).getByText('طلایی')).toBeTruthy()
    expect(linkIn('نمایندگی', 'مدیریت نماینده')).toBe('/agents/list?search=123456789')
    expect(within(card('کیف پول')).getByText(/اعتبار نمایندگی ۵۰۰٬۰۰۰ تومان/)).toBeTruthy()
  })
})

describe('a customer the page cannot show', () => {
  it('is not found when the shop has none of that number — and no card asks for theirs', async () => {
    answers({ detail: refusal(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.') })
    open()

    expect(await screen.findByText('مشتری پیدا نشد.')).toBeTruthy()
    for (const list of ['subscriptions', 'payments', 'orders', 'tickets']) expect(server().sent('GET', `/api/admin/${list}`)).toEqual([])
  })

  it('is the panel’s page not found for an address that names no customer, without asking', async () => {
    open('/users/someone')

    expect(await screen.findByText('این صفحه پیدا نشد')).toBeTruthy()
    // The panel's own question about its session aside.
    expect(server().requests.map((request) => request.path)).toEqual(['/api/admin/auth/me'])
  })

  it('stays as it was when a later read of the customer fails, the failure said above the cards', async () => {
    let failing = false
    answers()
    server().on('GET', '/api/admin/users/10', () => (failing ? refusal(500, 'خطای سرور.') : json(DETAIL)))
    const { client } = open()
    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('امیر'))

    failing = true
    await act(() => client.invalidateQueries({ queryKey: queryKeys.user(10) }))

    await until(() => expect(screen.getByText('مشتری بارگذاری نشد.')).toBeTruthy())
    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('امیر')
    expect(within(card('سرویس‌ها')).getByText('amir_1')).toBeTruthy()
  })
})

describe('another customer’s page', () => {
  it('starts afresh with that customer', async () => {
    const sara = userRow({ id: 11, name: 'سارا', username: 'sara', telegram_id: 777 })
    answers()
    server()
      .on('GET', '/api/admin/users/11', json({ ...DETAIL, user: sara } satisfies UserDetailResponse))
      .on('GET', '/api/admin/users/11/wallet', json({ user: sara, transactions: [] } satisfies UserWalletResponse))
    const { router } = open()
    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('امیر'))

    await act(() => router.navigate('/users/11'))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('سارا'))
    expect(
      server()
        .sent('GET', '/api/admin/subscriptions')
        .map((request) => request.query.get('user')),
    ).toEqual(['10', '11'])
  })
})
