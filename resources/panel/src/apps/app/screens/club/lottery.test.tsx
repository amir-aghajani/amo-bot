import { act, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import LotteryScreen from '@/apps/app/screens/club/lottery'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreLotteryResponse } from '@/lib/api-types'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «قرعه‌کشی شبانه» (screens/club/lottery): the time left until the draw, ticking; the prize and how many win; the
 * customer's chances and how one gets them; the last draw's winners with the customer marked; and a shop that runs none.
 */

function lotteryRow(overrides: Partial<StoreLotteryResponse> = {}): StoreLotteryResponse {
  return {
    next_draw_at: '2026-10-27T18:30:00+00:00',
    winners: 3,
    prize: '۵۰٬۰۰۰ تومان شارژ کیف پول',
    per_buyer: false,
    entries: 2,
    last_draw: {
      drawn_at: '2026-10-26T18:30:00+00:00',
      winners: [
        { name: 'رضا م***', me: false },
        { name: 'علی ر***', me: true },
      ],
    },
    ...overrides,
  }
}

describe('the nightly draw', () => {
  it('counts down to the draw, with the prize, the customer’s chances and the last winners', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(new Date('2026-10-27T15:00:00+00:00'))
    server().on('GET', `${API}/lottery`, json(lotteryRow()))
    render(app.screen(<LotteryScreen />, { at: '/club/lottery' }))

    expect(await screen.findByText('۰۳:۳۰:۰۰')).toBeTruthy()
    await act(() => vi.advanceTimersByTimeAsync(1_000))
    expect(screen.getByText('۰۳:۲۹:۵۹')).toBeTruthy()
    expect(screen.getByText('۵۰٬۰۰۰ تومان شارژ کیف پول')).toBeTruthy()
    expect(screen.getByText('۳ برنده')).toBeTruthy()
    expect(screen.getByText('۲ شانس در قرعه‌کشی امشب')).toBeTruthy()
    expect(screen.getByText(/هر خرید یک شانس است/)).toBeTruthy()
    const me = screen.getByText('علی ر***').closest('div')
    expect(me?.textContent).toContain('شما')
    expect(screen.getByText('رضا م***').closest('div')?.textContent).not.toContain('شما')
  })

  it('shows a customer without chances how to get one', async () => {
    server().on('GET', `${API}/lottery`, json(lotteryRow({ entries: 0, per_buyer: true, last_draw: null, prize: null })))
    render(app.screen(<LotteryScreen />, { at: '/club/lottery' }))

    expect(await screen.findByText('هنوز شانسی در قرعه‌کشی امشب ندارید')).toBeTruthy()
    expect(screen.getByText(/هر خریدار یک شانس دارد/)).toBeTruthy()
    expect(screen.getByRole('button', { name: /خرید سرویس و گرفتن شانس/ })).toBeTruthy()
    expect(screen.getByText('جایزه به زودی اعلام می‌شود')).toBeTruthy()
    expect(screen.queryByText('برنده‌های قرعه‌کشی قبل')).toBeNull()
  })

  it('says so while the shop runs no draw', async () => {
    server().on('GET', `${API}/lottery`, refusal(404, 'قرعه‌کشی فعال نیست.'))
    render(app.screen(<LotteryScreen />, { at: '/club/lottery' }))

    expect(await screen.findByText('قرعه‌کشی فعال نیست')).toBeTruthy()
  })
})
