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
 * What the admin sees instead of a blank screen when a screen throws while drawing: the failure as `ErrorState` draws it
 * — the page's place in the shell, or the whole screen —, and the failure told to the shop's log. A page that failed to
 * load because an upgrade replaced the panel's files reloads once for the new build. Around the pages it is keyed on the
 * page and reset by the address: going to another page starts afresh, going to another section of the same page leaves
 * its sections' drafts as they are.
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
 * The router's last net, its root route's error element: a failure no page's boundary caught — the sign-in page's, the
 * installer's, the shell's own — as the whole screen. Reported by the router (`reportFailure`, its `onError`).
 */
export function RouteError() {
  return <ErrorState error={useRouteError()} variant="screen" />
}

/** A failure caught while drawing: the one reload a stale build gets — anything else, the gave-up reload too, to the log. */
export function reportFailure(error: unknown, info?: ErrorInfo): void {
  if (isStaleBuildError(error) && reloadForNewBuild()) return
  reportCrash(error, info?.componentStack)
}
