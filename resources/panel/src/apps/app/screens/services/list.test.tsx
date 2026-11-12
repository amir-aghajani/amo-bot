import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ServicesScreen from '@/apps/app/screens/services/list'
import * as app from '@/apps/app/test/app'
import { API, GB, service } from '@/apps/app/test/rows'
import type { StoreSubscription, StoreSubscriptionsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «سرویس‌ها» (screens/services/list): the customer's services as cards — their name and client name, their state, what
 * is left of their traffic and term, their server, a test's badge — a page at a time; adding one by its link while the
 * shop takes them; with none, the way to buy one; a failed read, a way to ask again.
 */

/** A page of `services`, the `page`th of `last`. */
function page(services: StoreSubscription[], at = 1, last = 1): StoreSubscriptionsResponse {
  return { subscriptions: services, meta: { page: at, per_page: 25, total: services.length, last_page: last } }
}

function open(shop = app.shop()) {
  render(app.screen(<ServicesScreen />, { at: '/services', shop }))
}

describe('the services list', () => {
  it('shows each service as a card: its names, its state, its rings and its server', async () => {
    server().on(
      'GET',
      `${API}/subscriptions`,
      json(
        page([
          service({ label: 'سرویس خانه' }),
          service({ id: 10, name: 'test_ali_1', is_test: true, plan: null, status: 'expired', traffic: { limit_bytes: GB, used_bytes: GB, remaining_bytes: 0 } }),
        ]),
      ),
    )
    open()

    const labelled = await screen.findByRole('link', { name: /سرویس خانه/ })
    expect(labelled.getAttribute('href')).toBe('/services/9')
    expect(within(labelled).getByText('ali_1')).toBeTruthy()
    expect(within(labelled).getByText('فعال')).toBeTruthy()
    expect(within(labelled).getByLabelText('حجم: ۶۰ گیگابایت مانده')).toBeTruthy()
    expect(within(labelled).getByLabelText('زمان: ۱۰ روز مانده')).toBeTruthy()
    expect(within(labelled).getByText('هلند')).toBeTruthy()
    const test = screen.getByRole('link', { name: /test_ali_1/ })
    expect(test.getAttribute('href')).toBe('/services/10')
    expect(within(test).getByText('منقضی‌شده')).toBeTruthy()
    expect(within(test).getByText('اکانت تست')).toBeTruthy()
    expect(within(test).getByText('تست')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'افزودن با لینک' })).toBeTruthy()
  })

  it('reads the next page when asked, and the filter asks the shop for that status', async () => {
    server().on('GET', `${API}/subscriptions`, (request) =>
      json(
        request.query.get('status') === 'active'
          ? page([service({ id: 30, name: 'active_1' })])
          : request.query.get('page') === '2'
            ? page([service({ id: 20, name: 'ali_20' })], 2, 2)
            : page([service()], 1, 2),
      ),
    )
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'سرویس‌های بیشتر' }))
    await screen.findByText('ali_20')
    expect(screen.getByText('ali_1')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'سرویس‌های بیشتر' })).toBeNull()

    fireEvent.click(screen.getByRole('radio', { name: 'فعال' }))
    await screen.findByText('active_1')
    expect(server().sent('GET', `${API}/subscriptions`).at(-1)?.query.get('status')).toBe('active')
  })

  it('keeps the cards when the next page fails, and says why', async () => {
    server().on('GET', `${API}/subscriptions`, (request) => (request.query.get('page') === '2' ? refusal(500, 'خطای سرور') : json(page([service()], 1, 2))))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'سرویس‌های بیشتر' }))

    await screen.findByText(/خطای سرور/)
    expect(screen.getByText('ali_1')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect(screen.getByRole('button', { name: 'سرویس‌های بیشتر' })).toBeTruthy()
  })

  it('with none, offers buying one — and adding one by its link while the shop takes them', async () => {
    server().on('GET', `${API}/subscriptions`, json(page([])))
    open()

    await screen.findByText('هنوز سرویسی ندارید')
    expect(screen.getByRole('button', { name: 'افزودن سرویس با لینک' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'خرید سرویس' }))
    await until(() => expect(screen.queryByText('هنوز سرویسی ندارید')).toBeNull())
  })

  it('offers no adding by link while the shop takes none', async () => {
    server().on('GET', `${API}/subscriptions`, json(page([])))
    open(app.shop({ shop: { ...app.shop().shop, service_claims: false } }))

    await screen.findByText('هنوز سرویسی ندارید')
    expect(screen.queryByRole('button', { name: /افزودن/ })).toBeNull()
  })

  it('a read that failed says why and asks again', async () => {
    let asked = 0
    server().on('GET', `${API}/subscriptions`, () => (++asked === 1 ? refusal(500, 'خطای سرور') : json(page([service()]))))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))
    await screen.findByText('ali_1')
  })
})
