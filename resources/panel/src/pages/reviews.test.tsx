import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReviewRow, ReviewsResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { ReviewsPage } from '@/pages/reviews'
import { providers, until } from '@/test/render'
import { reviewRow } from '@/test/rows'
import { json, noContent, refusal, server, type Answer } from '@/test/server'

/*
 * The reviews screen (pages/reviews): every review written on the shop's website, the newest first — its number, the
 * name it is signed with and who wrote it (their page, or a guest), its stars, its words with where they use the service
 * from, where it stands and who decided it —; the queue waiting on support counted beside its tab, the tab the dashboard's
 * link opens; a tab and a search narrowing the list as the server reads it; and from a row's menu, what its state allows:
 * approve, reject — a decision another made first told, the list read again —, and delete, asked first.
 */

/** The server's answer: `rows`, and the queue as `meta` says (two waiting on support). */
function reviews(rows: ReviewRow[] = [reviewRow()], meta: Partial<ReviewsResponse['meta']> = {}): Answer {
  return json({ reviews: rows, meta: { page: 1, per_page: 25, total: rows.length, last_page: 1, pending: 2, ...meta } } satisfies ReviewsResponse)
}

/** The screen at `at`, the server answering every read of the list with `answer`. */
function open(at = '/reviews', answer: Answer | (() => Answer) = reviews()) {
  server().on('GET', '/api/admin/reviews', typeof answer === 'function' ? () => answer() : answer)
  render(<ReviewsPage />, { wrapper: providers({ at }).wrapper })
}

/** What the list asked the server each time, as its query read. */
const asked = () =>
  server()
    .sent('GET', '/api/admin/reviews')
    .map((request) => Object.fromEntries(request.query))

const table = () => screen.findByRole('table', { name: 'نظرات' })

/** A review's ⋮ menu — named by its number —, and one of its items chosen. */
async function choose(id: number, item: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات نظر ${idLabel(id)}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: item }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'error')
})

describe('the reviews', () => {
  it('list each one — its number, its name and who wrote it, its stars, its words and where they use it from, its state and who decided it', async () => {
    const guests = reviewRow({
      id: 9,
      name: 'Sara',
      rating: 5,
      body: 'عالی\nممنون از پشتیبانی.',
      context: null,
      customer: null,
      status: 'approved',
      reviewer: 'root',
      decided_at: '2026-10-07T10:00:00Z',
      actions: { approve: false, reject: true },
    })
    open('/reviews', reviews([reviewRow(), guests]))

    const [, waiting, approved] = within(await table()).getAllByRole('row')
    if (!waiting || !approved) throw new Error('Two reviews are listed.')
    expect(within(waiting).getByText('#21')).toBeTruthy()
    expect(within(waiting).getByText('امیر ر.')).toBeTruthy()
    expect(within(waiting).getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
    expect(within(waiting).getByRole('img', { name: '۴ از ۵' })).toBeTruthy()
    expect(within(waiting).getByText('سرعت خوب است و قطعی ندارد.')).toBeTruthy()
    expect(within(waiting).getByText('ایرانسل · اندروید · Happ')).toBeTruthy()
    expect(within(waiting).getByText('در انتظار بررسی')).toBeTruthy()

    expect(within(approved).getByText('مهمان')).toBeTruthy()
    expect(within(approved).queryByRole('link')).toBeNull()
    expect(within(approved).getByRole('img', { name: '۵ از ۵' })).toBeTruthy()
    expect(within(approved).getByText('تاییدشده')).toBeTruthy()
    expect(within(approved).getByText('root')).toBeTruthy()
  })

  it('say there is none yet in the screen’s own words', async () => {
    open('/reviews', reviews([], { pending: 0 }))

    expect(await screen.findByText('هنوز نظری نوشته نشده است')).toBeTruthy()
  })
})

describe('the queue waiting on support', () => {
  it('is counted beside its tab, whatever the list shows', async () => {
    open('/reviews?status=approved', reviews([reviewRow({ status: 'approved' })], { pending: 2 }))

    await table()
    expect(screen.getByRole('tab', { name: /در انتظار بررسی/ }).textContent).toBe('در انتظار بررسی۲')
  })

  it('is the tab the dashboard’s link opens the screen on', async () => {
    open('/reviews?status=pending')

    await table()
    expect(screen.getByRole('tab', { name: /در انتظار بررسی/ }).getAttribute('aria-selected')).toBe('true')
    expect(asked()).toEqual([{ status: 'pending' }])
  })
})

describe('the list narrowed', () => {
  it('by the tab pressed', async () => {
    open()
    await table()

    fireEvent.click(screen.getByRole('tab', { name: 'ردشده' }))

    await until(() => expect(asked().at(-1)).toEqual({ status: 'rejected' }))
  })

  it('by what is searched, once typing pauses', async () => {
    open()
    await table()
    vi.useFakeTimers()

    fireEvent.change(screen.getByRole('searchbox', { name: 'جستجوی نظر' }), { target: { value: '#21' } })
    await act(() => vi.advanceTimersByTimeAsync(300))

    await until(() => expect(asked().at(-1)).toEqual({ search: '#21' }))
  })
})

describe('a decision from the row’s menu', () => {
  it('approves it: the review the server answers in its place, said in a toast', async () => {
    open()
    server().on(
      'POST',
      '/api/admin/reviews/21/approve',
      json({ review: reviewRow({ status: 'approved', reviewer: 'root', decided_at: '2026-10-08T12:00:00Z', actions: { approve: false, reject: true } }) }),
    )

    await choose(21, 'تایید')

    await until(() => expect(toast.success).toHaveBeenCalledWith('نظر تایید شد؛ در وب‌سایت نمایش داده می‌شود'))
    expect(within(await table()).getByText('تاییدشده')).toBeTruthy()
  })

  it('offers only what the review’s state allows', async () => {
    open('/reviews', reviews([reviewRow({ status: 'rejected', actions: { approve: true, reject: false } })]))

    fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات نظر ${idLabel(21)}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })

    expect(await screen.findByRole('menuitem', { name: 'تایید' })).toBeTruthy()
    expect(screen.queryByRole('menuitem', { name: 'رد' })).toBeNull()
    expect(screen.getByRole('menuitem', { name: 'حذف' })).toBeTruthy()
  })

  it('refused because another decided it first, says so and reads the list again', async () => {
    open('/reviews', () => reviews())
    server().on('POST', '/api/admin/reviews/21/reject', refusal(422, 'این نظر رد شده است.', { status: ['این نظر رد شده است.'] }))

    await choose(21, 'رد')

    await until(() => expect(toast.error).toHaveBeenCalledWith('این نظر رد شده است.', { description: undefined }))
    await until(() => expect(asked()).toHaveLength(2))
  })
})

describe('deleting a review', () => {
  it('asks first — cancel sends nothing —, then takes it off the list', async () => {
    open()
    server().on('DELETE', '/api/admin/reviews/21', noContent)

    await choose(21, 'حذف')
    const dialog = await screen.findByRole('dialog', { name: 'حذف نظر' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'انصراف' }))
    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(server().sent('DELETE', '/api/admin/reviews/21')).toHaveLength(0)

    await choose(21, 'حذف')
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'حذف نظر' })).getByRole('button', { name: 'حذف' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('نظر حذف شد'))
    expect(server().sent('DELETE', '/api/admin/reviews/21')).toHaveLength(1)
    await until(() => expect(screen.queryByRole('table', { name: 'نظرات' })).toBeNull())
  })

  it('refused, says why in the dialog — and nothing in a toast', async () => {
    open()
    server().on('DELETE', '/api/admin/reviews/21', refusal(404, 'نظر پیدا نشد.'))

    await choose(21, 'حذف')
    const dialog = await screen.findByRole('dialog', { name: 'حذف نظر' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'حذف' }))

    expect(await within(dialog).findByText(/نظر پیدا نشد/)).toBeTruthy()
    expect(toast.error).not.toHaveBeenCalled()
  })
})
