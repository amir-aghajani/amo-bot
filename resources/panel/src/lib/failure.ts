import { isRouteErrorResponse } from 'react-router'
import { toast } from 'sonner'
import { ApiError } from '@/lib/api'
import { panelBuild } from '@/lib/config'
import { formatDate, shownTimeZone } from '@/lib/format'
import { isStaleBuildError, reloadedLately } from '@/lib/stale-build'

/*
 * The one reading of a failure, whatever draws it (components/error-state, a form's line, a toast): its kind, the words
 * the admin reads, whether asking again may help, and the technical details support needs. For the kinds where the
 * server words its refusal for the admin (a 4xx, a 502 about a panel), the server's message is shown; otherwise — or
 * when it sent none — the kind's own words are.
 */

/** What kind of failure it was: decides the words, the icon and the ways on. */
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
  /** What happened and what to do, in a sentence or two that stand alone (a form's line, a toast). */
  description: string
  /** The API's message for this kind is meant for the admin: it replaces `description`. */
  serverWords: boolean
  /** Asking again may help. */
  retry: boolean
  /** Reloading the page is the way on (the panel's own code, its files, an answer it cannot read). */
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

/** A stale build's words once its one reload did not help (lib/stale-build): the build's files are missing on the host. */
const STALE_GAVE_UP =
  'پنل یک بار برای نسخه تازه دوباره بارگذاری شد ولی فایل‌های این صفحه هنوز پیدا نمی‌شوند. اگر پنل همین الان به‌روز شده، چند دقیقه بعد دوباره امتحان کنید؛ وگرنه فایل‌های build روی هاست کامل آپلود نشده است.'

/** A failure as every surface shows it. */
export interface Failure {
  kind: FailureKind
  title: string
  /** What the admin reads: the server's message where it is meant for them, else the kind's description. */
  message: string
  /** `message` is the server's. */
  worded: boolean
  /** A request failed (an ApiError), as opposed to the panel's own code. */
  requested: boolean
  /** The answer's HTTP status; null when none came, or for no request at all. */
  status: number | null
  /** A short name for support: the kind, or the name of the error the panel's code threw. */
  code: string
  /** The server's id of the request («کد پیگیری»): its log lines carry it. */
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

/** When each thrown error was first described, so a crash on screen keeps its moment. */
const seenAt = new WeakMap<object, Date>()

/**
 * Describe a failure: an `ApiError` (an answer, or none) or anything the panel's code threw. When the API refused one of
 * `fields` (an operation's `note` or `status`), that refusal is the message.
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
  // The router's own answer: an address no route takes.
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
 * The server's generic 404 messages (ErrorHandler's: a missing row, an unknown address). They only repeat "not found",
 * which the lead before them already says.
 */
const PLAIN_NOT_FOUND = ['مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.', 'صفحه مورد نظر پیدا نشد.']

/**
 * The sentence after a lead that already says what was not found («سرور پیدا نشد.»): the server's reason when it gives
 * one (a receipt Telegram no longer has), else a generic one — never "not found" twice.
 */
export function notFoundReason(failure: Failure | null): string {
  return failure?.worded && !PLAIN_NOT_FOUND.includes(failure.message) ? failure.message : 'ممکن است حذف شده باشد، یا آدرسش درست نباشد.'
}

/** For a server error (a 500), the code support finds it in the log by: «کد پیگیری: 3f9a…». */
function referenceOf(failure: Failure): string | null {
  return failure.kind === 'server' && failure.requestId !== null ? `کد پیگیری: ${failure.requestId}` : null
}

/** A failure in one line (a form's, an operation strip's, a confirmation's), with the tracking code of a server error. */
export function messageOf(error: unknown, ...fields: string[]): string {
  const failure = describeFailure(error, ...fields)
  const reference = referenceOf(failure)

  return reference === null ? failure.message : `${failure.message} ${reference}`
}

/**
 * A failure in a toast. The title is `what` failed («خروج انجام نشد») if given, else the server's message, else the
 * kind's title; the message (when not already the title) and a server error's tracking code go under it.
 */
export function toastFailure(error: unknown, what?: string): void {
  const failure = describeFailure(error)
  const title = what ?? (failure.worded ? failure.message : failure.title)
  const description = [what !== undefined || !failure.worded ? failure.message : null, referenceOf(failure)].filter((line) => line !== null).join(' ')

  toast.error(title, { description: description === '' ? undefined : description })
}

/**
 * The rows of «جزئیات فنی», what support needs: the status (requests only), codes, the request, the moment and the
 * zone it is shown in (the shop's, or the browser's until /api/app has answered), the address, the build.
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

/** The details as one text to paste to support, with the moment in UTC too (as the server's log keeps it). */
export function failureReport(failure: Failure): string {
  return [`${failure.title}: ${failure.message}`, ...failureFacts(failure).map((fact) => `${fact.label}: ${fact.value}`), `UTC: ${failure.at.toISOString()}`].join('\n')
}
