import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { TicketRow, TicketsResponse, UserDetailResponse } from '@/lib/api-types'
import { TicketsPage } from '@/pages/tickets'
import { signedIn, until } from '@/test/render'
import { customerAccount, ticketRow, userRow } from '@/test/rows'
import { json, server, type Answer } from '@/test/server'

/*
 * The tickets screen (pages/tickets): every ticket, the conversation that moved last first — its number, its subject
 * leading to its page, its customer to theirs, its state, its last activity, its messages and its rating —; the queue
 * waiting on an answer counted beside its tab, the tab the dashboard's link opens; a tab, a search and a customer
 * narrowing the list as the server reads it, a page at a time; and nothing to list said in the screen's own words.
 */

const SARA = { id: 11, name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' }

/** The server's answer: `rows`, and the queue as `meta` says (three waiting on an answer). */
function tickets(rows: TicketRow[] = [ticketRow()], meta: Partial<TicketsResponse['meta']> = {}): Answer {
  return json({ tickets: rows, meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, open: 3, ...meta } } satisfies TicketsResponse)
}

/** The screen at `at`, the server answering every read of the list with `answer`. */
function open(at = '/tickets', answer: Answer = tickets()) {
  server().on('GET', '/api/admin/tickets', answer)
  render(<TicketsPage />, { wrapper: signedIn({ at }).wrapper })
}

/** What the list asked the server each time, as its query read. */
const asked = () =>
  server()
    .sent('GET', '/api/admin/tickets')
    .map((request) => Object.fromEntries(request.query))

const table = () => screen.findByRole('table', { name: 'تیکت‌ها' })

describe('the tickets', () => {
  it('list each one — its number, its subject to its page, its customer to theirs, its state, the last activity, its messages, its rating', async () => {
    open('/tickets', tickets([ticketRow(), ticketRow({ id: 9, subject: 'Renewal problem', status: 'closed', subscription: null, customer: SARA, messages_count: 5, rating: 4 })]))

    const [, waiting, closed] = within(await table()).getAllByRole('row')
    if (!waiting || !closed) throw new Error('Two tickets are listed.')
    expect(within(waiting).getByText('#12')).toBeTruthy()
    expect(within(waiting).getByRole('link', { name: 'سرعت سرویس پایین است' }).getAttribute('href')).toBe('/tickets/12')
    expect(within(waiting).getByText('amir_1').getAttribute('dir')).toBe('ltr')
    expect(within(waiting).getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
    expect(within(waiting).getByText('در انتظار پاسخ')).toBeTruthy()
    expect(within(waiting).getByText('۲')).toBeTruthy()

    expect(within(closed).getByRole('link', { name: 'Renewal problem' }).getAttribute('href')).toBe('/tickets/9')
    expect(within(closed).getByText('sara@example.com')).toBeTruthy()
    expect(within(closed).getByText('بسته‌شده')).toBeTruthy()
    expect(within(closed).getByText('۴ از ۵')).toBeTruthy()
  })

  it('lead to what waits on support as the row’s primary way in, each named by its ticket', async () => {
    open('/tickets', tickets([ticketRow(), ticketRow({ id: 9, status: 'answered' })]))

    const [, waiting, answered] = within(await table()).getAllByRole('row')
    if (!waiting || !answered) throw new Error('Two tickets are listed.')
    const answer = within(waiting).getByRole('link', { name: /^پاسخ تیکت/ })
    expect(answer.getAttribute('href')).toBe('/tickets/12')
    expect(answer.getAttribute('data-variant')).toBe('default')
    expect(
      within(answered)
        .getByRole('link', { name: /^مشاهده تیکت/ })
        .getAttribute('data-variant'),
    ).toBe('secondary')
  })
})

describe('the queue waiting on an answer', () => {
  it('is counted beside its tab, whatever the list shows', async () => {
    open('/tickets?status=closed', tickets([ticketRow({ status: 'closed' })], { open: 3 }))

    await table()
    expect(screen.getByRole('tab', { name: /در انتظار پاسخ/ }).textContent).toBe('در انتظار پاسخ۳')
  })

  it('is the tab the dashboard’s link opens the screen on', async () => {
    open('/tickets?status=open')

    await table()
    expect(screen.getByRole('tab', { name: /در انتظار پاسخ/ }).getAttribute('aria-selected')).toBe('true')
    expect(asked()).toEqual([{ status: 'open' }])
  })
})

describe('the list narrowed', () => {
  it('by the tab pressed', async () => {
    open()
    await table()

    fireEvent.click(screen.getByRole('tab', { name: 'بسته‌شده' }))

    await until(() => expect(asked().at(-1)).toEqual({ status: 'closed' }))
  })

  it('by what is searched, once typing pauses — a ticket’s number among the rest', async () => {
    open()
    await table()
    vi.useFakeTimers()

    fireEvent.change(screen.getByRole('searchbox', { name: 'جستجوی تیکت' }), { target: { value: '#12' } })
    await act(() => vi.advanceTimersByTimeAsync(300))

    await until(() => expect(asked().at(-1)).toEqual({ search: '#12' }))
  })

  it('to one customer, as their page links here — said beside the search, and let go of', async () => {
    server().on(
      'GET',
      '/api/admin/users/10',
      json({ user: userRow(), referral: { referrer: null, referrals: 0, earned: '0.00' }, agency: null, account: customerAccount() } satisfies UserDetailResponse),
    )
    open('/tickets?user=10')

    const pill = await screen.findByRole('group', { name: 'فیلتر مشتری' })
    await until(() => expect(within(pill).getByText('امیر')).toBeTruthy())
    expect(asked()).toEqual([{ user: '10' }])

    fireEvent.click(within(pill).getByRole('button', { name: 'برداشتن فیلتر مشتری' }))

    await until(() => expect(asked().at(-1)).toEqual({}))
  })

  it('a page at a time', async () => {
    open('/tickets', tickets([ticketRow()], { total: 30, last_page: 2 }))

    fireEvent.click(await screen.findByRole('button', { name: 'صفحه بعد' }))

    await until(() => expect(asked().at(-1)).toEqual({ page: '2' }))
  })
})

describe('nothing to list', () => {
  it('says no ticket was opened yet', async () => {
    open('/tickets', tickets([]))

    expect(await screen.findByText('هنوز تیکتی باز نشده است')).toBeTruthy()
  })

  it('says the queue is empty in its own words, and another tab in its', async () => {
    open('/tickets?status=open', tickets([]))
    expect(await screen.findByText('فعلا تیکتی در انتظار پاسخ نیست.')).toBeTruthy()

    fireEvent.click(screen.getByRole('tab', { name: 'بسته‌شده' }))
    expect(await screen.findByText('تیکتی در این وضعیت نیست.')).toBeTruthy()
    expect(screen.queryByText(/جستجو یا فیلتر/)).toBeNull()
  })

  it('says a search found nothing', async () => {
    open('/tickets?status=open&search=nobody', tickets([]))

    expect(await screen.findByText('با این جستجو یا فیلتر چیزی نیست.')).toBeTruthy()
  })
})
