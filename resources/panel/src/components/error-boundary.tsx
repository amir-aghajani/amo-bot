import { Component, type ErrorInfo, type ReactNode } from 'react'
import { useRouteError } from 'react-router'
import { ErrorState } from '@/components/error-state'
import { reportCrash } from '@/lib/error-reporting'
import { isStaleBuildError, reloadForNewBuild } from '@/lib/stale-build'

/** `page` keeps the shell around it (the sidebar still works); `app` is the last net, the whole screen. */
type Scope = 'app' | 'page'

interface ErrorBoundaryProps {
  children: ReactNode
  scope: Scope
  /** The address: another one — another section of the same page too — draws the screen again instead of the failure. */
  resetKey?: string
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
 * the failure is reported to the shop's log; a stale build reloads once instead. AppShell keys it on the page and resets
 * it by the address, so another page starts afresh while another section of the same page keeps its drafts.
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

  render(): ReactNode {
    return this.state.failed ? <ErrorState error={this.state.error ?? new Error(String(this.state.error))} variant={this.props.scope === 'app' ? 'screen' : 'page'} /> : this.props.children
  }
}

/**
 * The router's root error element: a failure no page boundary caught (the sign-in page, the installer, the shell
 * itself), as the whole screen. The router reports it (`onError` → `reportFailure`).
 */
export function RouteError() {
  return <ErrorState error={useRouteError()} variant="screen" />
}

/** A failure caught while drawing: a stale build reloads once; anything else (a reload that did not help too) is reported. */
export function reportFailure(error: unknown, info?: ErrorInfo): void {
  if (isStaleBuildError(error) && reloadForNewBuild()) return
  reportCrash(error, info?.componentStack)
}
