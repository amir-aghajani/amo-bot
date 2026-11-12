import { fireEvent, render, screen, within } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import TestAccountScreen from '@/apps/app/screens/buy/test-account'
import * as app from '@/apps/app/test/app'
import { API, storeSubscription } from '@/apps/app/test/rows'
import type { StoreTestAccountResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The free test account (screens/buy/test-account): the servers giving one, with their traffic and hours; taking one
 * makes the service and offers it to open — or the screen says why none can be taken, or that the shop gives none.
 */

/** Where «مشاهده سرویس» went. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

function open() {
  render(
    app.screen(
      <Routes>
        <Route path="test" element={<TestAccountScreen />} />
        <Route path="services/:id" element={<Where />} />
      </Routes>,
      { at: '/test', route: '*' },
    ),
  )
}

const OFFERS: StoreTestAccountResponse = {
  offers: [
    { server: { id: 1, name: 'آلمان' }, traffic_mb: 500, duration_hours: 24, on_hold: true },
    { server: { id: 2, name: 'هلند' }, traffic_mb: 1024, duration_hours: 12, on_hold: false },
  ],
  refusal: null,
}

describe('the free test account', () => {
  it('takes one on the server picked, and opens the service it made', async () => {
    server()
      .on('GET', `${API}/test-account`, json(OFFERS))
      .on(
        'POST',
        `${API}/test-account`,
        json(
          {
            subscription: storeSubscription({
              id: 18,
              name: 'test_42',
              is_test: true,
              plan: null,
              server: { id: 2, name: 'هلند' },
              traffic: { limit_bytes: 1024 ** 3, used_bytes: 0, remaining_bytes: 1024 ** 3 },
            }),
          },
          201,
        ),
      )
    open()

    const servers = within(await screen.findByRole('radiogroup', { name: 'سرور' }))
    expect(servers.getByRole('radio', { name: /آلمان/ }).textContent).toContain('۵۰۰ مگابایت · ۱ روز (از اولین اتصال)')
    expect(servers.getByRole('radio', { name: /هلند/ }).textContent).toContain('۱ گیگابایت · ۱۲ ساعت')
    fireEvent.click(servers.getByRole('radio', { name: /هلند/ }))
    fireEvent.click(screen.getByRole('button', { name: 'دریافت اکانت تست' }))

    await screen.findByRole('heading', { name: 'اکانت تست شما آماده است' })
    expect(server().sent('POST', `${API}/test-account`)[0]?.body).toEqual({ server_id: 2 })
    expect(screen.getByText('test_42')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'مشاهده سرویس' }))
    await until(() => expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/services/18'))
  })

  it('says why the customer can take none now, in the shop’s words', async () => {
    server().on('GET', `${API}/test-account`, json({ offers: OFFERS.offers, refusal: 'اکانت تست بعدی شما از ۲۷ آبان ۱۴۰۵، ۱۸:۳۰ ممکن است.' }))
    open()

    expect(await screen.findByText('اکانت تست بعدی شما از ۲۷ آبان ۱۴۰۵، ۱۸:۳۰ ممکن است.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'دریافت اکانت تست' })).toBeNull()
  })

  it('says so while the shop gives none', async () => {
    server().on('GET', `${API}/test-account`, refusal(404, 'اکانت تست در این فروشگاه فعال نیست.'))
    open()

    expect(await screen.findByText('اکانت تست در این فروشگاه فعال نیست')).toBeTruthy()
  })
})
