import fs from 'node:fs'
import path from 'node:path'
import { UNSAFE_ErrorResponseImpl as RouteErrorResponse } from 'react-router'
import { toast } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, type FailureFacts } from '@/lib/api'
import { panelBuild } from '@/lib/config'
import { describeFailure, FAILURE_KINDS, failureFacts, failureReport, messageOf, notFoundReason, toastFailure, type FailureKind } from '@/lib/failure'
import { setShopTimeZone } from '@/lib/format'
import { STORAGE_KEYS } from '@/lib/storage'

/*
 * The one reading of a failure (lib/failure): which kind it is, by the status and what the transport knows of it; the
 * words — the server's own where they are the admin's, the kind's in place of a message that is missing or the
 * server's generic one, and after a lead that says what was not found, why it may not be —; and the details support
 * needs, only those the failure has. Toasts and one-line messages read the same.
 */

afterEach(() => {
  setShopTimeZone(undefined)
})

const failed = (status: number, message: string, facts: FailureFacts = {}, errors = {}) => new ApiError(status, message, errors, facts)

/** [what it is, the failure, the kind it is read as, the words the admin reads] */
const TABLE: [string, unknown, FailureKind, string][] = [
  ['no answer, offline', failed(0, 'No answer', { foreign: true, offline: true }), 'offline', FAILURE_KINDS.offline.description],
  ['no answer', failed(0, 'No answer', { foreign: true }), 'unreachable', FAILURE_KINDS.unreachable.description],
  ['no answer in time', failed(0, 'No answer within 90 seconds', { foreign: true, timedOut: true }), 'timeout', FAILURE_KINDS.timeout.description],
  ['a refused sign-in', failed(401, 'نام کاربری یا رمز عبور اشتباه است.'), 'unauthorized', 'نام کاربری یا رمز عبور اشتباه است.'],
  ['the server’s 403', failed(403, 'درخواست نامعتبر است.'), 'forbidden', 'درخواست نامعتبر است.'],
  ['a firewall’s 403', failed(403, 'HTTP 403 without the API’s answer', { foreign: true }), 'forbidden', FAILURE_KINDS.forbidden.description],
  ['a row that is not there', failed(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.'), 'not-found', 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.'],
  ['a row held by others', failed(409, 'این پلن فروخته شده است.'), 'conflict', 'این پلن فروخته شده است.'],
  ['a refusal', failed(422, 'نام پلن را وارد کنید.'), 'invalid', 'نام پلن را وارد کنید.'],
  ['a body too large', failed(413, 'گزارش خطا بزرگ‌تر از حد مجاز است.'), 'invalid', 'گزارش خطا بزرگ‌تر از حد مجاز است.'],
  ['a wait', failed(429, 'تلاش‌های ناموفق زیاد بود؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.', { retryAfter: 900 }), 'rate-limited', 'تلاش‌های ناموفق زیاد بود؛ ۱۵ دقیقه دیگر دوباره امتحان کنید.'],
  ['the server’s failure', failed(500, 'خطایی در سرور رخ داد. لطفا بعدا دوباره تلاش کنید.', { requestId: '3f9a1c2b7d4e5f60' }), 'server', FAILURE_KINDS.server.description],
  ['a failure with no JSON', failed(500, 'HTTP 500 without the API’s answer', { foreign: true }), 'server', FAILURE_KINDS.server.description],
  ['a panel out of reach', failed(502, 'پنل «آلمان»: پنل جواب نداد.'), 'bad-gateway', 'پنل «آلمان»: پنل جواب نداد.'],
  ['a proxy’s 502: PHP not answering it', failed(502, 'HTTP 502 without the API’s answer', { foreign: true }), 'unreachable', FAILURE_KINDS.unreachable.description],
  ['a proxy’s 504', failed(504, 'HTTP 504 without the API’s answer', { foreign: true }), 'timeout', FAILURE_KINDS.timeout.description],
  ['a shop not installed', failed(503, 'فروشگاه هنوز نصب نشده است.'), 'unavailable', 'فروشگاه هنوز نصب نشده است.'],
  ['a host in maintenance', failed(503, 'HTTP 503 without the API’s answer', { foreign: true }), 'unavailable', FAILURE_KINDS.unavailable.description],
  ['a host’s page for a success', failed(200, 'HTTP 200 that is not JSON', { foreign: true }), 'unreadable', FAILURE_KINDS.unreadable.description],
  ['the panel’s own bug', new TypeError("Cannot read properties of undefined (reading 'id')"), 'crash', FAILURE_KINDS.crash.description],
  ['an older build’s file', new TypeError('Failed to fetch dynamically imported module: https://shop.example/admin/assets/plans-Bx1.js'), 'stale-build', FAILURE_KINDS['stale-build'].description],
  ['an address no route takes', new RouteErrorResponse(404, 'Not Found', null), 'not-found', FAILURE_KINDS['not-found'].description],
  ['something thrown that is no error', 'boom', 'crash', FAILURE_KINDS.crash.description],
]

beforeEach(() => {
  vi.spyOn(toast, 'error')
})

describe('reading a failure', () => {
  it.each(TABLE)('reads %s as its kind, in the words the admin reads', (_what, error, kind, words) => {
    const failure = describeFailure(error)

    expect(failure.kind).toBe(kind)
    expect(failure.message).toBe(words)
  })

  it('says the first message the API put under one of the fields asked about, else its own', () => {
    const refused = failed(422, 'Refused.', {}, { note: ['Too long.'], status: ['Paid meanwhile.'] })

    expect(describeFailure(refused, 'note', 'status').message).toBe('Too long.')
    expect(describeFailure(refused, 'amount', 'status').message).toBe('Paid meanwhile.')
    expect(describeFailure(refused, 'amount').message).toBe('Refused.')
  })

  it('keeps the moment a failure came, however often it is read', () => {
    const bug = new Error('boom')
    const first = describeFailure(bug).at

    expect(describeFailure(bug).at).toBe(first)
    const answer = failed(500, 'x')
    expect(describeFailure(answer).at).toBe(answer.at)
  })

  it('says once the one reload a stale build gets did not help, and what that means', () => {
    sessionStorage.setItem(STORAGE_KEYS.reloadedAt, String(Date.now()))

    expect(describeFailure(new TypeError('Importing a module script failed.')).message).toContain('هنوز پیدا نمی‌شوند')
  })

  it('offers what its words say: an answer the panel cannot read is loaded again, not asked again', () => {
    const kinds = Object.entries(FAILURE_KINDS)
    expect(kinds.filter(([, spec]) => spec.reload).map(([kind]) => kind)).toEqual(['unreadable', 'stale-build', 'crash'])
    for (const [, spec] of kinds) expect(spec.reload && spec.retry, `${spec.title} reloads, and says so`).toBe(false)
    for (const [kind, spec] of kinds) expect(spec.description.includes('دوباره بارگذاری'), kind).toBe(spec.reload)
  })
})

/** The text of a constant of the server's error handler — what it answers a 404 with —, read from its source. */
function serverWords(pattern: RegExp): string {
  const source = fs.readFileSync(path.join(import.meta.dirname, '../../../../app/Core/Http/ErrorHandler.php'), 'utf8')
  const found = pattern.exec(source)?.[1]
  if (found === undefined) throw new Error(`ErrorHandler.php no longer has ${pattern}`)
  return found
}

describe('what follows a lead that says what was not found', () => {
  it('is why it may not be — not the server’s «… پیدا نشد» a second time', () => {
    const row = serverWords(/const NOT_FOUND = '([^']+)'/)
    const route = serverWords(/404 => '([^']+)'/)
    const reason = notFoundReason(null)

    expect(reason).not.toContain('پیدا نشد')
    expect(notFoundReason(describeFailure(failed(404, row)))).toBe(reason)
    expect(notFoundReason(describeFailure(failed(404, route)))).toBe(reason)
    expect(notFoundReason(describeFailure(failed(404, 'HTTP 404 without the API’s answer', { foreign: true })))).toBe(reason)
    expect(notFoundReason(describeFailure(new RouteErrorResponse(404, 'Not Found', null)))).toBe(reason)
  })

  it('is the server’s own words when they say why', () => {
    expect(notFoundReason(describeFailure(failed(404, 'فایل این رسید دیگر در تلگرام نیست.')))).toBe('فایل این رسید دیگر در تلگرام نیست.')
  })
})

describe('a failure in one line', () => {
  it('ends with the code to find it in the log by, when it is the server’s own', () => {
    expect(messageOf(failed(500, 'x', { requestId: '3f9a1c2b7d4e5f60' }))).toBe(`${FAILURE_KINDS.server.description} کد پیگیری: 3f9a1c2b7d4e5f60`)
    expect(messageOf(failed(500, 'x'))).toBe(FAILURE_KINDS.server.description)
    expect(messageOf(failed(422, 'نام پلن را وارد کنید.', { requestId: '3f9a1c2b7d4e5f60' }))).toBe('نام پلن را وارد کنید.')
  })

  it('takes the message under the fields asked about', () => {
    expect(messageOf(failed(422, 'Refused.', {}, { note: ['Too long.'] }), 'note', 'status')).toBe('Too long.')
  })
})

describe('a failure in a toast', () => {
  it('has the server’s words as its title when they are the admin’s', () => {
    toastFailure(failed(409, 'این پلن فروخته شده است.'))

    expect(toast.error).toHaveBeenCalledWith('این پلن فروخته شده است.', { description: undefined })
  })

  it('has the kind’s title over its words, and the code of a failure of the server’s own', () => {
    toastFailure(failed(0, 'No answer', { foreign: true }))
    toastFailure(failed(500, 'x', { requestId: '3f9a1c2b7d4e5f60' }))

    expect(toast.error).toHaveBeenNthCalledWith(1, FAILURE_KINDS.unreachable.title, { description: FAILURE_KINDS.unreachable.description })
    expect(toast.error).toHaveBeenNthCalledWith(2, FAILURE_KINDS.server.title, { description: `${FAILURE_KINDS.server.description} کد پیگیری: 3f9a1c2b7d4e5f60` })
  })

  it('names what failed when the screen says it, the words under it', () => {
    toastFailure(failed(0, 'No answer', { foreign: true }), 'خروج انجام نشد')

    expect(toast.error).toHaveBeenCalledWith('خروج انجام نشد', { description: FAILURE_KINDS.unreachable.description })
  })
})

describe('a failure’s technical details', () => {
  it('list what support needs, and copy whole with the moment in UTC', () => {
    window.history.replaceState(null, '', '/admin/servers/2')
    const failure = describeFailure(failed(500, 'x', { requestId: '3f9a1c2b7d4e5f60', endpoint: 'GET /api/admin/servers/2', debug: 'RuntimeException: disk on fire' }))

    const facts = Object.fromEntries(failureFacts(failure).map((fact) => [fact.label, fact.value]))
    expect(facts).toMatchObject({
      وضعیت: 'HTTP 500',
      'کد خطا': 'server',
      'کد پیگیری': '3f9a1c2b7d4e5f60',
      درخواست: 'GET /api/admin/servers/2',
      آدرس: '/admin/servers/2',
      'نسخه پنل': panelBuild,
      'متن فنی': 'RuntimeException: disk on fire',
    })
    expect(failureReport(failure)).toContain(`UTC: ${failure.at.toISOString()}`)
  })

  it('leave out what a failure does not have — a status, of the panel’s own code, which made no request', () => {
    const labels = failureFacts(describeFailure(new TypeError('x is undefined'))).map((fact) => fact.label)

    expect(labels).not.toContain('وضعیت')
    expect(labels).not.toContain('کد پیگیری')
    expect(labels).not.toContain('درخواست')
    expect(labels).toContain('متن فنی')
    expect(failureFacts(describeFailure(new RouteErrorResponse(404, 'Not Found', null))).map((fact) => fact.label)).not.toContain('وضعیت')
  })

  it('say a request that got no answer had none', () => {
    const facts = failureFacts(describeFailure(failed(0, 'No answer', { foreign: true, endpoint: 'GET /api/admin/plans' })))

    expect(facts).toContainEqual({ label: 'وضعیت', value: 'بدون پاسخ' })
  })

  it('name the zone of the moment they show: the shop’s, or the browser’s while the shop’s is not known', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T21:09:12Z'))
    const failure = describeFailure(failed(500, 'x'))

    expect(failureFacts(failure)).toContainEqual({ label: 'منطقه زمانی مرورگر', value: 'UTC' })

    setShopTimeZone('Asia/Tehran')
    const facts = failureFacts(failure)
    expect(facts).toContainEqual({ label: 'منطقه زمانی', value: 'Asia/Tehran' })
    expect(facts.find((fact) => fact.label === 'زمان')?.value).toContain('۰:۳۹:۱۲')
    expect(failureReport(failure)).toContain('UTC: 2026-10-06T21:09:12.000Z')
  })
})
