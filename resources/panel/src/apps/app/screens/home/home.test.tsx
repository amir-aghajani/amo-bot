import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import HomeScreen from '@/apps/app/screens/home/home'
import * as app from '@/apps/app/test/app'
import { API, service } from '@/apps/app/test/rows'
import type { StoreResponse, StoreSale, StoreSalesResponse, StoreSubscription, StoreSubscriptionsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «خانه» (screens/home/home): the hello and the bell, the running services as cards, the wallet with its top-up, the
 * banners — the shop's word, its sales, its programs —, and the shortcuts; each part only while the shop runs it, and
 * the card that leads to buying when nothing runs. The bell is read again every minute, not while minimised in Telegram.
 */

const SALE: StoreSale = { id: 4, name: 'فروش ویژه پاییز', percent: 20, ends_at: new Date(Date.now() + 2 * 86_400_000).toISOString(), plan_ids: null, order_types: null }

function page(services: StoreSubscription[], total = services.length): StoreSubscriptionsResponse {
  return { subscriptions: services, meta: { page: 1, per_page: 25, total, last_page: 1 } }
}

/** The home screen, its services (`active` running, `all` in total) and sales answered. */
function open({
  shop = app.shop(),
  active = [service()],
  all = active.length,
  sales = [SALE],
  unread = 0,
}: { shop?: StoreResponse; active?: StoreSubscription[]; all?: number; sales?: StoreSale[]; unread?: number } = {}) {
  server()
    .on('GET', `${API}/subscriptions`, (request) => json(request.query.get('status') === 'active' ? page(active) : page([], all)))
    .on('GET', `${API}/sales`, json({ sales } satisfies StoreSalesResponse))
  render(app.screen(<HomeScreen />, { shop, account: app.account({ unread_notifications: unread }) }))
}

describe('the home screen', () => {
  it('shows the services, the wallet, the banners and every shortcut while the shop runs everything', async () => {
    const { app: telegram } = app.telegram()
    const announcement = { text: 'سرور آلمان امشب به‌روز می‌شود.', url: 'https://example.com/news' }
    open({
      shop: app.shop({ app: { title: 'فروشگاه نمونه', accent_color: null, logo_url: null, announcement, fullscreen: true, time_zone: 'Asia/Tehran' } }),
      active: [service({ label: 'سرویس خانه' })],
      unread: 3,
    })

    expect(screen.getByRole('heading', { name: 'سلام، علی' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'اعلان‌ها، ۳ خوانده‌نشده' }).getAttribute('href')).toBe('/notifications')

    const card = await screen.findByRole('link', { name: /سرویس خانه/ })
    expect(card.getAttribute('href')).toBe('/services/9')
    expect(within(card).getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    expect(within(card).getByLabelText('زمان: ۱۰ روز مانده')).toBeTruthy()
    expect(server().sent('GET', `${API}/subscriptions`)).toHaveLength(1)

    const wallet = screen.getByRole('region', { name: 'کیف پول' })
    expect(within(wallet).getByText('۱۵۰٬۰۰۰')).toBeTruthy()
    expect(within(wallet).getByRole('link', { name: 'شارژ' }).getAttribute('href')).toBe('/wallet/top-up')

    const banners = await screen.findByRole('region', { name: 'بنرها' })
    expect(within(banners).getByText('سرور آلمان امشب به‌روز می‌شود.')).toBeTruthy()
    expect(within(banners).getByText('فروش ویژه پاییز')).toBeTruthy()
    expect(within(banners).getByText('۲۰٪ تخفیف روی همه خریدها')).toBeTruthy()
    expect(within(banners).getByText('گردونه شانس')).toBeTruthy()
    expect(within(banners).getByText('قرعه‌کشی شبانه')).toBeTruthy()
    expect(within(banners).getByText('دعوت از دوستان')).toBeTruthy()
    expect(within(banners).getAllByRole('button', { name: /^بنر / })).toHaveLength(5)
    fireEvent.click(within(banners).getByText('سرور آلمان امشب به‌روز می‌شود.'))
    expect(telegram.openLink).toHaveBeenCalledWith('https://example.com/news')

    const shortcuts = screen.getByRole('navigation', { name: 'دسترسی سریع' })
    await until(() => expect(within(shortcuts).getByRole('link', { name: 'نظرات' }).getAttribute('href')).toBe('/reviews'))
    expect(
      within(shortcuts)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/buy', '/services', '/wallet', '/test', '/club', '/support', '/tutorial', '/reviews'])
    // The shop's word on its reviews is `GET /`'s: nothing asked to find out.
    expect(server().sent('GET', `${API}/reviews`)).toHaveLength(0)
  })

  it('hides what the shop does not run, and says it takes no orders', async () => {
    const off = app.shop()
    open({
      shop: app.shop({
        shop: {
          ...off.shop,
          taking_orders: false,
          programs: { test_accounts: false, wheel: false, lottery: false, gift_codes: false, loyalty: false, cashback: false, wallet_transfers: false, reviews: false },
        },
        referral: { enabled: false, rate: 10, first_only: false },
      }),
      sales: [],
    })

    expect(screen.getByText(/فروشگاه فعلا سفارش تازه نمی‌گیرد/)).toBeTruthy()
    await until(() => expect(server().sent('GET', `${API}/sales`)).toHaveLength(1))
    expect(server().sent('GET', `${API}/reviews`)).toHaveLength(0)
    expect(screen.queryByRole('region', { name: 'بنرها' })).toBeNull()
    const shortcuts = screen.getByRole('navigation', { name: 'دسترسی سریع' })
    expect(
      within(shortcuts)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/buy', '/services', '/wallet', '/support', '/tutorial'])
  })

  it('with no service at all, leads to buying the first', async () => {
    open({ active: [], all: 0 })

    const first = await screen.findByRole('link', { name: /اولین سرویس خود را بخرید/ })
    expect(first.getAttribute('href')).toBe('/buy')
    expect(
      server()
        .sent('GET', `${API}/subscriptions`)
        .map((request) => request.query.get('status')),
    ).toEqual(['active', null])
  })

  it('with ended services only, leads to them', async () => {
    open({ active: [], all: 2 })

    expect((await screen.findByRole('link', { name: /سرویس فعالی ندارید/ })).getAttribute('href')).toBe('/services')
  })

  it('still shows the rest when the services could not be read, and asks again', async () => {
    let asked = 0
    server()
      .on('GET', `${API}/subscriptions`, () => (++asked === 1 ? refusal(500, 'خطای سرور') : json(page([service()]))))
      .on('GET', `${API}/sales`, json({ sales: [] } satisfies StoreSalesResponse))
    render(app.screen(<HomeScreen />))

    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))
    expect(screen.getByRole('region', { name: 'کیف پول' })).toBeTruthy()
    expect(await screen.findByRole('link', { name: /ali_1/ })).toBeTruthy()
  })

  it('reads the bell again every minute, but not while the app is minimised in Telegram', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const { app: telegram } = app.telegram()
    server().on('GET', `${API}/me`, json(app.account({ unread_notifications: 2 })))
    open()
    const reads = () => server().sent('GET', `${API}/me`).length

    await screen.findByRole('link', { name: /ali_1/ })
    app.tell(telegram, 'deactivated')
    await act(() => vi.advanceTimersByTimeAsync(180_000))
    expect(reads()).toBe(0)

    app.tell(telegram, 'activated')
    await act(() => vi.advanceTimersByTimeAsync(60_000))
    await until(() => expect(reads()).toBe(1))
    expect(await screen.findByRole('link', { name: 'اعلان‌ها، ۲ خوانده‌نشده' })).toBeTruthy()
  })
})
