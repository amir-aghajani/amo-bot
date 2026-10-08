import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { ErrorState } from '@/components/error-state'
import { ApiError } from '@/lib/api'
import { FAILURE_KINDS, notFoundReason } from '@/lib/failure'

/*
 * Every failure the panels show, one way (components/error-state): a card in place of what a read would have shown, a
 * page in the shell, a whole screen outside it, a form's line — the failure's words, the ways on its kind has, and its
 * technical details folded under «جزئیات فنی», to copy whole for support. A wait the server asked for counts down.
 */

const SERVER_FAILURE = new ApiError(500, 'خطایی در سرور رخ داد.', {}, { requestId: '3f9a1c2b7d4e5f60', endpoint: 'GET /api/admin/plans' })

let reload: MockInstance<() => void>

beforeEach(() => {
  reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
})

describe('a card', () => {
  it('says what did not load and why, with a way to ask again', () => {
    const retry = vi.fn()
    render(<ErrorState what="لیست پلن‌ها" error={new ApiError(0, 'No answer', {}, { foreign: true })} onRetry={retry} />)

    expect(screen.getByRole('alert').textContent).toContain(`لیست پلن‌ها بارگذاری نشد. ${FAILURE_KINDS.unreachable.description}`)
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(retry).toHaveBeenCalledTimes(1)
  })

  it('says once that what is not there was not found, then why it may be — and offers no retry', () => {
    render(<ErrorState what="سرور" error={new ApiError(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.')} onRetry={vi.fn()} />)

    const card = screen.getByRole('alert').textContent ?? ''
    expect(card).toContain(`سرور پیدا نشد. ${notFoundReason(null)}`)
    expect(card.split('پیدا نشد')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })

  it('says why in the server’s words when they say it', () => {
    render(<ErrorState what="رسید" error={new ApiError(404, 'فایل این رسید دیگر در تلگرام نیست.')} />)

    expect(screen.getByRole('alert').textContent).toContain('رسید پیدا نشد. فایل این رسید دیگر در تلگرام نیست.')
  })

  it('offers to load the page again — not to ask again — for an answer the panel cannot read, as its words say', () => {
    const retry = vi.fn()
    render(<ErrorState what="لیست پلن‌ها" error={new ApiError(200, 'HTTP 200 that is not JSON', {}, { foreign: true })} onRetry={retry} />)

    expect(screen.getByRole('alert').textContent).toContain('صفحه را دوباره بارگذاری کنید')
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'بارگذاری دوباره' }))
    expect(reload).toHaveBeenCalledTimes(1)
    expect(retry).not.toHaveBeenCalled()
  })

  it('folds its technical details away, and copies them whole', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    vi.spyOn(toast, 'success')
    render(<ErrorState what="لیست پلن‌ها" error={SERVER_FAILURE} onRetry={vi.fn()} />)

    const toggle = screen.getByRole('button', { name: 'جزئیات فنی' })
    const details = document.getElementById(toggle.getAttribute('aria-controls') ?? '')
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    expect(details?.hidden).toBe(true)

    fireEvent.click(toggle)
    expect(details?.hidden).toBe(false)
    expect(within(details as HTMLElement).getByText('3f9a1c2b7d4e5f60')).toBeTruthy()
    expect(within(details as HTMLElement).getByText('HTTP 500')).toBeTruthy()

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'کپی جزئیات' })))
    expect(write).toHaveBeenCalledWith(expect.stringContaining('کد پیگیری: 3f9a1c2b7d4e5f60'))
    expect(toast.success).toHaveBeenCalledWith('جزئیات خطا کپی شد')
  })
})

describe('a page in the shell', () => {
  it('shows a crash with the ways on: reload, or the dashboard', () => {
    render(
      <MemoryRouter>
        <ErrorState variant="page" error={new TypeError('x is undefined')} />
      </MemoryRouter>,
    )

    expect(screen.getByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'بارگذاری دوباره' }))
    expect(reload).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('link', { name: 'رفتن به داشبورد' }).getAttribute('href')).toBe('/')
  })

  it('words an address no page has in its own words, the way back and to the dashboard', () => {
    vi.spyOn(window.history, 'length', 'get').mockReturnValue(3)
    const back = vi.spyOn(window.history, 'back').mockImplementation(() => undefined)
    render(
      <MemoryRouter>
        <ErrorState variant="page" kind="not-found" title="این صفحه پیدا نشد" description="آدرسی که باز کردید در پنل نیست." />
      </MemoryRouter>,
    )

    expect(screen.getByRole('heading', { name: 'این صفحه پیدا نشد' })).toBeTruthy()
    expect(screen.getByText('آدرسی که باز کردید در پنل نیست.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'جزئیات فنی' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'بازگشت' }))
    expect(back).toHaveBeenCalledTimes(1)
  })
})

describe('a whole screen', () => {
  it('outside the router leads to the panel’s pages as whole pages', () => {
    render(<ErrorState variant="screen" error={new TypeError('x is undefined')} />)

    expect(screen.getByRole('link', { name: 'رفتن به داشبورد' }).getAttribute('href')).toBe('/admin/')
  })

  it('always offers to ask again — there is no other way on', () => {
    const retry = vi.fn()
    render(<ErrorState variant="screen" error={new ApiError(403, 'HTTP 403 without the API’s answer', {}, { foreign: true })} onRetry={retry} />)

    expect(screen.getByRole('heading', { name: FAILURE_KINDS.forbidden.title })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(retry).toHaveBeenCalledTimes(1)
  })
})

describe('a wait the server asked for', () => {
  it('counts down on a form’s line, and says when it is over', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    render(<ErrorState variant="inline" error={new ApiError(429, 'تلاش‌ها زیاد بود.', {}, { retryAfter: 90 })} description="تلاش‌ها زیاد بود." />)

    expect(screen.getByRole('timer').textContent).toBe('می‌توانید ۱:۳۰ دیگر دوباره امتحان کنید.')
    await act(() => vi.advanceTimersByTimeAsync(31_000))
    expect(screen.getByRole('timer').textContent).toBe('می‌توانید ۰:۵۹ دیگر دوباره امتحان کنید.')
    await act(() => vi.advanceTimersByTimeAsync(59_000))
    expect(screen.getByRole('timer').textContent).toBe('حالا می‌توانید دوباره امتحان کنید.')
  })

  it('holds a card’s «تلاش دوباره» until it is over', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    const retry = vi.fn()
    render(<ErrorState what="گزارش" error={new ApiError(429, 'کمی صبر کنید.', {}, { retryAfter: 2 })} onRetry={retry} />)

    const button = screen.getByRole('button', { name: /تلاش دوباره/ })
    expect(button.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(button)
    expect(retry).not.toHaveBeenCalled()

    await act(() => vi.advanceTimersByTimeAsync(2_000))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(retry).toHaveBeenCalledTimes(1)
  })
})
