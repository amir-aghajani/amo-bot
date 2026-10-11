import { isRouteErrorResponse } from 'react-router'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import type { ClientErrorRequest } from '@/lib/api-types'
import { panelBuild } from '@/lib/config'
import { toastFailure } from '@/lib/failure'
import { isStaleBuildError, reloadForNewBuild } from '@/lib/stale-build'

/*
 * The panel's own failures, sent to the shop's log (POST /client-errors), so the owner hears of a page that broke even
 * in an agent's browser: a screen that could not be drawn (`render`: the error boundaries, the router's error screen)
 * and an error no code handled (`unhandled`: a press, a promise, a read's or a save's code).
 *
 * Not reported: the server's answers (a refusal is the screen's to show, a server error is in its log already), a stale
 * build that one reload fixes, a request given up on, and the browser's own noise. Throttled: the same failure once per
 * REPEAT_MS, at most PER_MINUTE a minute, nothing while the server's 429 lasts; a report that fails is dropped.
 * An unhandled failure is also told to the admin — what they did may not have happened — in one toast per burst.
 * Everything also goes to the console.
 */

/** The same failure is sent once in this long. */
const REPEAT_MS = 10 * 60_000

/** Reports a minute, at most. */
const PER_MINUTE = 5

/** Failures this close together are one burst: one toast. */
const BURST_MS = 10_000

/** What a report keeps of each part, in characters (the server cuts again). */
const LIMITS = { message: 500, stack: 4_000, componentStack: 2_000, address: 300 }

/** What the browser says of itself, not of the panel. */
const NOISE = [/^ResizeObserver loop/, /^Script error\.?$/]

type Kind = ClientErrorRequest['kind']

interface Reporter {
  report: (error: unknown, kind: Kind, componentStack?: string | null) => void
  unhandled: (error: unknown) => void
}

let active: Reporter | null = null

/**
 * Start reporting, and listen for what nothing handled (window `error`, `unhandledrejection`). Called once per page by
 * root.tsx; `send` posts a report (tests pass their own). Returns the way to stop.
 */
export function startErrorReporting(send: (report: ClientErrorRequest) => Promise<unknown> = (report) => api.post('/client-errors', report)): () => void {
  const reporter = createReporter(send)
  const onError = (event: ErrorEvent) => reporter.unhandled(event.error ?? event.message)
  const onRejection = (event: PromiseRejectionEvent) => reporter.unhandled(event.reason)
  window.addEventListener('error', onError)
  window.addEventListener('unhandledrejection', onRejection)
  active = reporter

  return () => {
    window.removeEventListener('error', onError)
    window.removeEventListener('unhandledrejection', onRejection)
    if (active === reporter) active = null
  }
}

/** Report a screen that could not be drawn (an error boundary's, the router's), with React's component stack. */
export function reportCrash(error: unknown, componentStack?: string | null): void {
  console.error('The panel failed to draw this screen:', error, componentStack ?? '')
  active?.report(error, 'render', componentStack)
}

/** Report a bug the screen already shows as a failure: a read's or a save's code that threw something not an ApiError. */
export function reportBug(error: unknown): void {
  console.error('The panel failed:', error)
  active?.report(error, 'unhandled')
}

function createReporter(send: (report: ClientErrorRequest) => Promise<unknown>): Reporter {
  /** When each failure was last sent, by what it was. */
  const sent = new Map<string, number>()
  /** When the reports of the last minute went. */
  let recent: number[] = []
  /** The server asked to wait until then. */
  let pausedUntil = 0
  /** When the last unhandled failure came. */
  let lastUnhandled = -Infinity

  const report = (error: unknown, kind: Kind, componentStack?: string | null) => {
    if (!reportable(error)) return
    const now = Date.now()
    const thrown = error instanceof Error ? error : null
    const message = cut(thrown ? `${thrown.name}: ${thrown.message}` : String(error), LIMITS.message)
    const key = `${message}\n${thrown?.stack?.split('\n', 3).join('\n') ?? ''}`
    recent = recent.filter((at) => now - at < 60_000)
    for (const [seen, at] of sent) if (now - at >= REPEAT_MS) sent.delete(seen)
    if (now < pausedUntil || recent.length >= PER_MINUTE || sent.has(key)) return
    sent.set(key, now)
    recent.push(now)

    send({
      kind,
      message,
      stack: thrown?.stack ? cut(thrown.stack, LIMITS.stack) : null,
      component_stack: componentStack ? cut(componentStack, LIMITS.componentStack) : null,
      address: cut(addressOf(window.location), LIMITS.address),
      build: panelBuild,
    }).catch((failure: unknown) => {
      if (failure instanceof ApiError && failure.facts.retryAfter) pausedUntil = Date.now() + failure.facts.retryAfter * 1_000
    })
  }

  return {
    report,
    unhandled: (error) => {
      if (NOISE.some((pattern) => pattern.test(error instanceof Error ? error.message : String(error))) || isAbort(error)) return
      // A file of an older build: one reload brings the new one. If that reload already happened, it is reported as any failure.
      if (isStaleBuildError(error) && reloadForNewBuild()) return
      const now = Date.now()
      const burst = now - lastUnhandled < BURST_MS
      lastUnhandled = now
      if (!(error instanceof ApiError)) {
        console.error('The panel failed:', error)
        report(error, 'unhandled')
      }
      if (burst) return
      if (error instanceof ApiError || isStaleBuildError(error)) {
        toastFailure(error)
      } else {
        toast.error('خطایی در پنل رخ داد', {
          description: 'کاری که انجام می‌دادید شاید کامل نشده باشد؛ اگر صفحه درست کار نمی‌کند، آن را دوباره بارگذاری کنید.',
          action: { label: 'بارگذاری دوباره', onClick: () => window.location.reload() },
        })
      }
    },
  }
}

/**
 * The page's address for a report: its path and its query's parameter names only — never a value (a search may hold a
 * customer's name, phone or email) nor the fragment (an agent's sign-in link carries its code there).
 */
function addressOf(location: Location): string {
  const names = [...new Set(new URLSearchParams(location.search).keys())]

  return names.length === 0 ? location.pathname : `${location.pathname}?${names.join('&')}`
}

/** A failure of the panel's own: not the server's answer, not the router's 404, not a request given up on. */
function reportable(error: unknown): boolean {
  return !(error instanceof ApiError) && !isRouteErrorResponse(error) && !isAbort(error)
}

function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

function cut(text: string, length: number): string {
  return text.slice(0, length)
}
