import { isRouteErrorResponse } from 'react-router'
import { toast } from 'sonner'
import { ApiError } from '@/lib/api'
import { panelBuild } from '@/lib/config'
import { formatDate, shownTimeZone } from '@/lib/format'
import { isStaleBuildError, reloadedLately } from '@/lib/stale-build'

/*
 * The one reading of a failure, whatever shows it — a screen, a page, a card, a form's line, a toast (components/
 * error-state): what kind it was, the words the admin reads, whether asking again may help, and the technical details
 * support needs. The server's own message wins where it is the admin's words (a refusal, a sign-in's answer, a panel
 * out of reach); the kind's words stand in for a message that is missing or the server's generic one.
 */

/** What kind of failure it was: what the admin is told, and what they can do about it. */
export type FailureKind =
  /** No answer, the browser offline. */
  | 'offline'
  /** No answer: the network, or the server down — or a proxy's 502, PHP behind it not answering. */
  | 'unreachable'
  /** No answer in time: the panel's 90 seconds, or a proxy's 504. */
  | 'timeout'
  | 'unauthorized'
  /** A 403: a refusal of the server's, or a host's firewall (ModSecurity) in front of it. */
  | 'forbidden'
  | 'not-found'
  /** A 409: another row still holds on to it. */
  | 'conflict'
  /** A 422, or any other 4xx: refused as sent. */
  | 'invalid'
  | 'rate-limited'
  /** A 500 (or a 5xx no other kind is): the server's own failure, in its log under the request's id. */
  | 'server'
  /** The server's 502: a panel or Telegram it relies on out of reach. */
  | 'bad-gateway'
  | 'unavailable'
  /** An answer that is not the API's — a host's or a proxy's page where JSON was due. */
  | 'unreadable'
  /** A file of an older build of the panel, gone after an upgrade. */
  | 'stale-build'
  /** The panel's own code failed. */
  | 'crash'

interface KindSpec {
  title: string
  /** The kind's own words: what happened and what to do, a sentence or two that stand alone (a form's line, a toast). */
  description: string
  /** The API's message is the admin's words for this kind: it is said instead of `description`. */
  serverWords: boolean
  /** Asking again may help. */
  retry: boolean
  /** Loading the page again is the way on — what its words say to do: the panel's own code, its files, an answer it cannot read. */
  reload: boolean
  tone: 'danger' | 'warning' | 'info'
}

export const FAILURE_KINDS: Record<FailureKind, KindSpec> = {
  offline: {
    title: 'اتصال اینترنت قطع است',
    description: 'این دستگاه به اینترنت وصل نیست؛ با برگشتن اتصال دوباره تلاش کنید.',
    serverWords: false,
    retry: true,
    reload: false,
    tone: 'warning',
  },
  unreachable: {
    title: 'سرور در دسترس نیست',
    description: 'پاسخی از سرور نرسید؛ شبکه یا سرور موقتا در دسترس نیست. کمی بعد دوباره تلاش کنید.',
    serverWords: false,
    retry: true,
    reload: false,
    tone: 'danger',
  },
  timeout: {
    title: 'سرور به‌موقع پاسخ نداد',
    description: 'سرور در زمان مقرر پاسخ نداد؛ دوباره تلاش کنید.',
    serverWords: false,
    retry: true,
    reload: false,
    tone: 'danger',
  },
  unauthorized: {
    title: 'زمان ورود شما تمام شده است',
    description: 'برای ادامه دوباره وارد شوید.',
    serverWords: true,
    retry: false,
    reload: false,
    tone: 'warning',
  },
  forbidden: {
    title: 'دسترسی رد شد',
    description: 'سرور این درخواست را نپذیرفت. اگر تکرار شد، شاید فایروال هاست (مثل ModSecurity) جلوی آن را گرفته است.',
    serverWords: true,
    retry: false,
    reload: false,
    tone: 'danger',
  },
  'not-found': {
    title: 'پیدا نشد',
    description: 'موردی که خواستید پیدا نشد؛ ممکن است حذف شده باشد.',
    serverWords: true,
    retry: false,
    reload: false,
    tone: 'warning',
  },
  conflict: {
    title: 'انجام نشد',
    description: 'این کار با وضعیت فعلی ممکن نیست.',
    serverWords: true,
    retry: false,
    reload: false,
    tone: 'warning',
  },
  invalid: {
    title: 'پذیرفته نشد',
    description: 'سرور این درخواست را نپذیرفت؛ مقدارها را بررسی کنید.',
    serverWords: true,
    retry: false,
    reload: false,
    tone: 'danger',
  },
  'rate-limited': {
    title: 'کمی صبر کنید',
    description: 'تلاش‌ها زیاد بود؛ کمی صبر کنید و دوباره تلاش کنید.',
    serverWords: true,
    retry: true,
    reload: false,
    tone: 'warning',
  },
  server: {
    title: 'خطای سرور',
    description: 'خطایی در سرور رخ داد؛ دوباره تلاش کنید و اگر تکرار شد، کد پیگیری را برای پشتیبانی بفرستید.',
    serverWords: false,
    retry: true,
    reload: false,
    tone: 'danger',
  },
  'bad-gateway': {
    title: 'سرویس بیرونی پاسخ نداد',
    description: 'سرور به پنل یکی از سرورها یا به تلگرام نرسید؛ کمی بعد دوباره تلاش کنید.',
    serverWords: true,
    retry: true,
    reload: false,
    tone: 'danger',
  },
  unavailable: {
    title: 'سرور موقتا در دسترس نیست',
    description: 'سرور فعلا درخواست‌ها را نمی‌پذیرد؛ کمی بعد دوباره تلاش کنید.',
    serverWords: true,
    retry: true,
    reload: false,
    tone: 'warning',
  },
  unreadable: {
    title: 'پاسخ سرور قابل خواندن نبود',
    description: 'چیزی بین پنل و سرور (هاست یا پراکسی) به جای پاسخ برنامه صفحه دیگری فرستاد؛ صفحه را دوباره بارگذاری کنید.',
    serverWords: false,
    retry: false,
    reload: true,
    tone: 'danger',
  },
  'stale-build': {
    title: 'نسخه تازه‌ای از پنل منتشر شده است',
    description: 'پنل به‌روز شده و این صفحه از نسخه قبلی است؛ صفحه را دوباره بارگذاری کنید.',
    serverWords: false,
    retry: false,
    reload: true,
    tone: 'info',
  },
  crash: {
    title: 'خطایی در پنل رخ داد',
    description: 'این بخش به خاطر خطایی در پنل نمایش داده نشد؛ صفحه را دوباره بارگذاری کنید و اگر تکرار شد، جزئیات فنی را برای پشتیبانی بفرستید.',
    serverWords: false,
    retry: false,
    reload: true,
    tone: 'danger',
  },
}

/** The stale build's words once the one reload it gets did not help (lib/stale-build): the files are not there at all. */
const STALE_GAVE_UP =
  'پنل یک بار برای نسخه تازه دوباره بارگذاری شد ولی فایل‌های این صفحه هنوز پیدا نمی‌شوند. اگر پنل همین الان به‌روز شده، چند دقیقه بعد دوباره امتحان کنید؛ وگرنه فایل‌های build روی هاست کامل آپلود نشده است.'

/** A failure as every surface shows it. */
export interface Failure {
  kind: FailureKind
  title: string
  /** What the admin reads: the server's words where they win, else the kind's. */
  message: string
  /** `message` is the server's own words. */
  worded: boolean
  /** A request of the panel's failed (an ApiError) — not the panel's own code: what it got back, or that nothing came, is a fact of it. */
  requested: boolean
  /** The answer's HTTP status; null when none came, or for no request at all. */
  status: number | null
  /** A short name of what it was, for support: the kind, or the name of what the panel's code threw. */
  code: string
  /** The server's id of the request («کد پیگیری»): its log's lines carry it. */
  requestId: string | null
  /** A 429's wait, in seconds, counted from `at`. */
  retryAfter: number | null
  /** «GET /api/admin/servers». */
  endpoint: string | null
  /** What a developer reads: the thrown error's message, the server's exception (APP_DEBUG), what came instead of JSON. */
  technical: string | null
  /** When it happened. */
  at: Date
}

/** When each failure the panel's code threw was first described: a crash on screen keeps its moment. */
const seenAt = new WeakMap<object, Date>()

/**
 * Read a failure: an `ApiError` (an answer, or none), anything else the panel's code threw. `fields` are request fields
 * whose message says it best (an operation's note or state): the first the API put under one of them is the message.
 */
export function describeFailure(error: unknown, ...fields: string[]): Failure {
  const kind = kindOf(error)
  const spec = FAILURE_KINDS[kind]
  const fieldWords = error instanceof ApiError ? fields.map((field) => error.field(field)).find((message) => message !== undefined) : undefined
  const worded = error instanceof ApiError && !error.facts.foreign && spec.serverWords && error.message !== ''
  const message = fieldWords ?? (worded ? error.message : kind === 'stale-build' && reloadedLately() ? STALE_GAVE_UP : spec.description)

  if (error instanceof ApiError) {
    return {
      kind,
      title: spec.title,
      message,
      worded: fieldWords !== undefined || worded,
      requested: true,
      status: error.status === 0 ? null : error.status,
      code: kind,
      requestId: error.facts.requestId ?? null,
      retryAfter: error.facts.retryAfter ?? null,
      endpoint: error.facts.endpoint ?? null,
      technical: error.facts.debug ?? (error.facts.foreign ? error.message : null),
      at: error.at,
    }
  }

  const thrown = error instanceof Error ? error : null
  const routed = isRouteErrorResponse(error) ? error : null
  let at = typeof error === 'object' && error !== null ? seenAt.get(error) : undefined
  if (at === undefined) {
    at = new Date()
    if (typeof error === 'object' && error !== null) seenAt.set(error, at)
  }
  return {
    kind,
    title: spec.title,
    message,
    worded: false,
    requested: false,
    // The router's answer about an address is no request's: its status is in the technical line.
    status: null,
    code: thrown?.name ?? kind,
    requestId: null,
    retryAfter: null,
    endpoint: null,
    technical: thrown ? `${thrown.name}: ${thrown.message}` : routed ? `${routed.status} ${routed.statusText}` : String(error),
    at,
  }
}

function kindOf(error: unknown): FailureKind {
  // The router's own answer about an address: one no route of it takes.
  if (isRouteErrorResponse(error)) return error.status === 404 ? 'not-found' : 'crash'
  if (!(error instanceof ApiError)) return isStaleBuildError(error) ? 'stale-build' : 'crash'

  const { status, facts } = error
  if (status === 0) return facts.timedOut ? 'timeout' : facts.offline ? 'offline' : 'unreachable'
  // What a proxy in front of PHP answers on its own: a 502 is PHP not answering it, a 504 PHP not answering in time.
  if (facts.foreign && status === 502) return 'unreachable'
  if (facts.foreign && status === 504) return 'timeout'
  if (status >= 200 && status < 400) return 'unreadable'
  switch (status) {
    case 401:
      return 'unauthorized'
    case 403:
      return 'forbidden'
    case 404:
      return 'not-found'
    case 409:
      return 'conflict'
    case 429:
      return 'rate-limited'
    case 502:
    case 504:
      return 'bad-gateway'
    case 503:
      return 'unavailable'
  }
  return status >= 500 ? 'server' : 'invalid'
}

/**
 * The server's words for a 404 that say no more than "not found" — a row the request names, missing or another shop's
 * (ErrorHandler::NOT_FOUND), an address no route has (ErrorHandler::MESSAGES[404]): after a lead that already says what
 * was not found they would say it again.
 */
const PLAIN_NOT_FOUND = ['مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.', 'صفحه مورد نظر پیدا نشد.']

/**
 * What follows a lead that says what was not found — a card's «سرور پیدا نشد.», a «پیدا نشد» heading —: the server's
 * own words when they say why (a receipt Telegram no longer has), else why it may be; never «پیدا نشد» a second time.
 */
export function notFoundReason(failure: Failure | null): string {
  return failure?.worded && !PLAIN_NOT_FOUND.includes(failure.message) ? failure.message : 'ممکن است حذف شده باشد، یا آدرسش درست نباشد.'
}

/** The code support finds the request by, when the failure is the server's own (a 500): «کد پیگیری: 3f9a…». */
function referenceOf(failure: Failure): string | null {
  return failure.kind === 'server' && failure.requestId !== null ? `کد پیگیری: ${failure.requestId}` : null
}

/**
 * A failure in one line — a form's, an operation's strip, a confirmation's: its words and, a failure of the server's
 * own, the code to find it in the log by.
 */
export function messageOf(error: unknown, ...fields: string[]): string {
  const failure = describeFailure(error, ...fields)
  const reference = referenceOf(failure)

  return reference === null ? failure.message : `${failure.message} ${reference}`
}

/**
 * A failure told in a toast: the server's words as the toast's title — or `what` failed («خروج انجام نشد») with the
 * words under it —, a generic failure by its kind's title with its words under it; the code of a server's own failure
 * last.
 */
export function toastFailure(error: unknown, what?: string): void {
  const failure = describeFailure(error)
  const title = what ?? (failure.worded ? failure.message : failure.title)
  const description = [what !== undefined || !failure.worded ? failure.message : null, referenceOf(failure)].filter((line) => line !== null).join(' ')

  toast.error(title, { description: description === '' ? undefined : description })
}

/**
 * The technical details of a failure, as «جزئیات فنی» lists them: what support needs, a row each — the facts it has (a
 * status only for a request's), the moment in the zone the panel's dates read in, and which zone that is: the shop's,
 * or the browser's while the shop's is not known.
 */
export function failureFacts(failure: Failure): { label: string; value: string }[] {
  const { zone, shop } = shownTimeZone()
  const facts = [
    { label: 'وضعیت', value: !failure.requested ? null : failure.status === null ? 'بدون پاسخ' : `HTTP ${failure.status}` },
    { label: 'کد خطا', value: failure.code },
    { label: 'کد پیگیری', value: failure.requestId },
    { label: 'درخواست', value: failure.endpoint },
    { label: 'زمان', value: formatDate(failure.at, { dateStyle: 'medium', timeStyle: 'medium' }) },
    { label: shop ? 'منطقه زمانی' : 'منطقه زمانی مرورگر', value: zone },
    { label: 'آدرس', value: window.location.pathname },
    { label: 'نسخه پنل', value: panelBuild },
    { label: 'متن فنی', value: failure.technical },
  ]

  return facts.filter((fact): fact is { label: string; value: string } => fact.value !== null && fact.value !== '')
}

/** The details as one text to paste to support: the words, then every fact — the moment in UTC too, as the server keeps it. */
export function failureReport(failure: Failure): string {
  return [`${failure.title}: ${failure.message}`, ...failureFacts(failure).map((fact) => `${fact.label}: ${fact.value}`), `UTC: ${failure.at.toISOString()}`].join('\n')
}
