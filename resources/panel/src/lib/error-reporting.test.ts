import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api'
import type { ClientErrorRequest } from '@/lib/api-types'
import { reportBug, reportCrash, startErrorReporting } from '@/lib/error-reporting'
import { noContent, refusal, server, withHeaders } from '@/test/server'

/*
 * The panel's own failures told to the shop's log (lib/error-reporting): what nothing handled — a press's error, a
 * promise's rejection — and what a screen could not draw; each failure once in a while, a few a minute at most, none
 * while the server asks to wait, never the server's own answers or the browser's noise. What nothing handled is told to
 * the admin too, once a burst.
 */

let sent: ClientErrorRequest[]
let stop: () => void

/** What nothing in the panel handled, as the browser hands it to the page. */
function unhandled(error: unknown) {
  window.dispatchEvent(Object.assign(new Event('error'), { error, message: error instanceof Error ? error.message : String(error) }))
}

function rejected(reason: unknown) {
  window.dispatchEvent(Object.assign(new Event('unhandledrejection'), { reason }))
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-10-06T10:00:00Z'))
  vi.spyOn(console, 'error').mockImplementation(() => undefined)
  vi.spyOn(toast, 'error')
  sent = []
  stop = startErrorReporting((report) => {
    sent.push(report)
    return Promise.resolve()
  })
})

afterEach(() => stop())

describe('what nothing handled', () => {
  it('is told to the log, and to the admin in a toast with a way to reload', () => {
    window.history.replaceState(null, '', '/agent/login#code=secret-code')

    unhandled(new TypeError('x is undefined'))

    expect(sent).toEqual([
      { kind: 'unhandled', message: 'TypeError: x is undefined', stack: expect.any(String) as string, component_stack: null, address: '/agent/login', build: expect.any(String) as string },
    ])
    expect(toast.error).toHaveBeenCalledWith('خطایی در پنل رخ داد', expect.objectContaining({ action: expect.objectContaining({ label: 'بارگذاری دوباره' }) as object }))
  })

  it('says where the page was by its path and its query’s names alone — never a search’s words nor the fragment', () => {
    window.history.replaceState(null, '', '/admin/s/7/users?search=0912%20sara&status=banned&search=x#code=secret')

    unhandled(new TypeError('x is undefined'))

    expect(sent.map((report) => report.address)).toEqual(['/admin/s/7/users?search&status'])
  })

  it('is told to the admin once a burst, however many come', () => {
    unhandled(new TypeError('one'))
    rejected(new TypeError('two'))
    vi.advanceTimersByTime(9_000)
    unhandled(new TypeError('three'))

    expect(toast.error).toHaveBeenCalledTimes(1)
    expect(sent).toHaveLength(3)

    vi.advanceTimersByTime(10_000)
    unhandled(new TypeError('four'))
    expect(toast.error).toHaveBeenCalledTimes(2)
  })

  it('that is the server’s answer is not the panel’s failure: told to the admin in its words, never to the log', () => {
    rejected(new ApiError(409, 'این پلن فروخته شده است.'))

    expect(sent).toEqual([])
    expect(toast.error).toHaveBeenCalledWith('این پلن فروخته شده است.', { description: undefined })
  })

  it('leaves out what the browser says of itself, and a request given up on', () => {
    unhandled(new Error('ResizeObserver loop completed with undelivered notifications.'))
    unhandled('Script error.')
    rejected(new DOMException('The operation was aborted.', 'AbortError'))

    expect(sent).toEqual([])
    expect(toast.error).not.toHaveBeenCalled()
  })

  it('is heard no more once reporting stops', () => {
    stop()
    unhandled(new TypeError('x is undefined'))

    expect(sent).toEqual([])
  })
})

describe('the reports', () => {
  it('send the same failure once in a while — the same words from another place of the code are another', () => {
    const crash = new TypeError('x is undefined')
    reportCrash(crash, '\n    at PlanRow')
    reportCrash(crash, '\n    at PlanRow')
    reportBug(crash)

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({ kind: 'render', component_stack: '\n    at PlanRow' })

    reportBug(new TypeError('x is undefined'))
    expect(sent).toHaveLength(2)

    vi.advanceTimersByTime(10 * 60_000)
    reportCrash(crash)
    expect(sent).toHaveLength(3)
  })

  it('go a few a minute at most', () => {
    for (let i = 1; i <= 7; i++) reportBug(new Error(`failure ${i}`))
    expect(sent).toHaveLength(5)

    vi.advanceTimersByTime(60_000)
    reportBug(new Error('failure 8'))
    expect(sent).toHaveLength(6)
  })

  it('wait as long as the server asks, through the panel’s API', async () => {
    stop()
    stop = startErrorReporting()
    server().on('POST', '/api/admin/client-errors', withHeaders(refusal(429, 'گزارش خطا زیاد فرستاده شد.'), { 'Retry-After': '120' }))

    reportBug(new Error('first'))
    // The refusal comes back.
    await new Promise((resolve) => setTimeout(resolve, 0))
    reportBug(new Error('second'))
    vi.advanceTimersByTime(119_000)
    reportBug(new Error('third'))
    expect(server().sent('POST', '/api/admin/client-errors')).toHaveLength(1)

    server().on('POST', '/api/admin/client-errors', noContent)
    vi.advanceTimersByTime(1_000)
    reportBug(new Error('fourth'))
    expect(
      server()
        .sent('POST', '/api/admin/client-errors')
        .map((request) => (request.body as ClientErrorRequest).message),
    ).toEqual(['Error: first', 'Error: fourth'])
  })

  it('never tell anyone of the server’s answers', () => {
    reportCrash(new ApiError(500, 'خطایی در سرور رخ داد.'))
    reportBug(new ApiError(0, 'No answer', {}, { foreign: true }))

    expect(sent).toEqual([])
  })
})
