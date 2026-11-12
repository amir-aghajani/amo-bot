import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ticketRow } from '@/apps/app/screens/support/test-rows'
import TicketsScreen from '@/apps/app/screens/support/tickets'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreTicket, StoreTicketsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «پشتیبانی» (screens/support/tickets): the customer's tickets — each with where it stands, its service, a dot while
 * support's answer is unread, its stars once rated — opening its conversation, a page at a time; «تیکت تازه» on the main
 * button; with none, a word on what tickets are for; a failed read, a way to ask again.
 */

function page(tickets: StoreTicket[], at = 1, last = 1, unread = 0): StoreTicketsResponse {
  return { tickets, meta: { page: at, per_page: 25, total: tickets.length, last_page: last, unread } }
}

function open() {
  render(app.screen(<TicketsScreen />, { at: '/support' }))
}

describe('the tickets list', () => {
  it('shows each ticket with its state, its service and whether an answer waits unread', async () => {
    server().on('GET', `${API}/tickets`, json(page([ticketRow(), ticketRow({ id: 7, subject: 'سوال درباره تمدید', status: 'closed', subscription: null, unread: false, rating: 5 })], 1, 1, 1)))
    open()

    const answered = await screen.findByRole('link', { name: /قطعی اتصال/ })
    expect(answered.getAttribute('href')).toBe('/support/12')
    expect(within(answered).getByText('پاسخ داده شد')).toBeTruthy()
    expect(within(answered).getByText('ali_1')).toBeTruthy()
    expect(within(answered).getByLabelText('پاسخ تازه')).toBeTruthy()
    const closed = screen.getByRole('link', { name: /سوال درباره تمدید/ })
    expect(closed.getAttribute('href')).toBe('/support/7')
    expect(within(closed).getByText('بسته شده')).toBeTruthy()
    expect(within(closed).getByLabelText('امتیاز شما: ۵ از ۵')).toBeTruthy()
    expect(within(closed).queryByLabelText('پاسخ تازه')).toBeNull()
    expect(screen.getByText('۱ تیکت با پاسخ تازه')).toBeTruthy()
  })

  it('reads the next page when asked', async () => {
    server().on('GET', `${API}/tickets`, (request) => json(request.query.get('page') === '2' ? page([ticketRow({ id: 3, subject: 'تیکت قدیمی', unread: false })], 2, 2) : page([ticketRow()], 1, 2)))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'تیکت‌های بیشتر' }))
    await screen.findByText('تیکت قدیمی')
    expect(screen.getByText('قطعی اتصال')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تیکت‌های بیشتر' })).toBeNull()
  })

  it('keeps the tickets when the next page fails, and says why', async () => {
    server().on('GET', `${API}/tickets`, (request) => (request.query.get('page') === '2' ? refusal(500, 'خطای سرور') : json(page([ticketRow()], 1, 2))))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'تیکت‌های بیشتر' }))

    await screen.findByText(/خطای سرور/)
    expect(screen.getByText('قطعی اتصال')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('with none, says what tickets are for — and «تیکت تازه» is Telegram’s main button', async () => {
    const { mainButton } = app.telegram()
    server().on('GET', `${API}/tickets`, json(page([])))
    open()

    await screen.findByText('هنوز تیکتی ندارید')
    expect(mainButton.isVisible).toBe(true)
    expect(mainButton.params.text).toBe('تیکت تازه')
    mainButton.press()
    await until(() => expect(screen.queryByText('هنوز تیکتی ندارید')).toBeNull())
  })

  it('a read that failed says why and asks again', async () => {
    let asked = 0
    server().on('GET', `${API}/tickets`, () => (++asked === 1 ? refusal(500, 'خطای سرور') : json(page([ticketRow()]))))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))
    await screen.findByText('قطعی اتصال')
  })
})
