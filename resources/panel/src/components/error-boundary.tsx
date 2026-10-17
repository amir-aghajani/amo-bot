import { Component, type ErrorInfo, type ReactNode } from 'react'
import { useLocation, useNavigate, useRouteError } from 'react-router'
import { ErrorState } from '@/components/error-state'
import { reportCrash } from '@/lib/error-reporting'
import { describeFailure, FAILURE_KINDS } from '@/lib/failure'
import { preloadPage } from '@/lib/prefetch'
import { isStaleBuildError, reloadForNewBuild } from '@/lib/stale-build'

/** `page` keeps the shell around it (the sidebar still works); `app` is the last net, the whole screen. */
type Scope = 'app' | 'page'

interface ErrorBoundaryProps {
  children: ReactNode
  scope: Scope
  /** The address: another one — another section of the same page too — draws the screen again instead of the failure. */
  resetKey?: string
  /** Before «تلاش دوباره» draws the screen again: ask again for what failed (the page's file, which did not load offline). */
  onRetry?: () => void
}

interface ErrorBoundaryState {
  error: unknown
  /** Whether the screen failed — an `error` of undefined is still a failure. */
  failed: boolean
  /** The address the failure (if any) belongs to. */
  resetKey: string | undefined
}

/**
 * Never a blank screen: when drawing throws, `ErrorState` takes the page's place in the shell (or the whole screen) and
 * the failure is reported to the shop's log; a stale build reloads once instead. Where asking again may help (a page's
 * file that did not load offline), its «تلاش دوباره» draws the screen again. AppShell keys it on the page and resets it
 * by the address, so another page starts afresh while another section of the same page keeps its drafts.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null, failed: false, resetKey: this.props.resetKey }

  static getDerivedStateFromError(error: unknown): Partial<ErrorBoundaryState> {
    return { error, failed: true }
  }

  // Another address draws the screen again: the failure was the last one's.
  static getDerivedStateFromProps(props: ErrorBoundaryProps, state: ErrorBoundaryState): Partial<ErrorBoundaryState> | null {
    return props.resetKey !== state.resetKey ? { error: null, failed: false, resetKey: props.resetKey } : null
  }

  componentDidCatch(error: unknown, info: ErrorInfo): void {
    reportFailure(error, info)
  }

  /** «تلاش دوباره» was pressed, and the screen is drawn again from it. */
  private retried = false

  /** «تلاش دوباره»: what failed asked for again, the screen drawn again — failing again, it shows the failure again. */
  private retry = (): void => {
    this.props.onRetry?.()
    this.retried = true
    this.setState({ error: null, failed: false })
  }

  componentDidUpdate(): void {
    if (!this.retried) return
    this.retried = false
    // Drawn again, the failure went with the button pressed, which had the focus: the page's column takes it rather
    // than the document.
    if (!this.state.failed && (document.activeElement === null || document.activeElement === document.body)) document.getElementById('main')?.focus()
  }

  render(): ReactNode {
    if (!this.state.failed) return this.props.children
    return <ErrorState error={this.state.error ?? new Error(String(this.state.error))} variant={this.props.scope === 'app' ? 'screen' : 'page'} onRetry={this.retry} />
  }
}

/**
 * The router's root error element: a failure no page boundary caught (the sign-in page, the installer, the shell
 * itself), as the whole screen. The router reports it (`onError` → `reportFailure`). Where asking again may help (the
 * sign-in page's file, which did not load offline), «تلاش دوباره» asks for the address's page again and draws it again
 * — the router's boundary lets go of its failure as the address is followed afresh.
 */
export function RouteError() {
  const error = useRouteError()
  const location = useLocation()
  const navigate = useNavigate()
  const retry = () => {
    preloadPage(location.pathname)
    // The same entry, what it carries too (where a sign-in leads back to).
    void navigate(location, { replace: true, state: location.state })
  }

  return <ErrorState error={error} variant="screen" onRetry={FAILURE_KINDS[describeFailure(error).kind].retry ? retry : undefined} />
}

/** A failure caught while drawing: a stale build reloads once; anything else (a reload that did not help too) is reported. */
export function reportFailure(error: unknown, info?: ErrorInfo): void {
  if (isStaleBuildError(error) && reloadForNewBuild()) return
  reportCrash(error, info?.componentStack)
}
