import { createElement, lazy, useState, type ComponentType, type ReactElement } from 'react'
import { isStaleBuildError, loadAhead, reloadForNewBuild } from '@/lib/stale-build'

/** A page of its own chunk, which can be asked for before it is drawn. */
export interface LazyPage<P extends object> {
  (props: P): ReactElement
  /**
   * Starts loading the page's chunk — the page the panel opens on, a link about to be followed (lib/prefetch). Quiet:
   * a failure here is the drawing's to report, when the page is drawn.
   */
  preload(): void
}

/**
 * A page as its own chunk, loaded when it is first wanted — a panel's first load carries only its shell. One asked for
 * ahead (`preload`) and loaded by the time it is drawn is drawn at once: no skeleton, and none of the wait React holds a
 * fallback on screen for before the page replaces it. A tab opened before an upgrade asks for the old build's chunk,
 * which is gone: it reloads once for the new build, else the failure goes to the error boundary.
 */
export function lazyPage<M, P extends object>(load: () => Promise<M>, pick: (module: M) => ComponentType<P>): LazyPage<P> {
  let loaded: ComponentType<P> | undefined
  let loading: Promise<ComponentType<P>> | undefined

  const fetchPage = () =>
    (loading ??= load().then(
      // Nothing for a module: Vite could not fetch the chunk, and the panel's `vite:preloadError` listener (root.tsx) took
      // the failure over for the reload it started — the tab is reloading for the new build: wait for it, draw nothing.
      (module: M | undefined) => (module === undefined ? new Promise<never>(() => {}) : (loaded = pick(module))),
      (error: unknown) => {
        // Not loaded: the next ask tries again.
        loading = undefined
        throw error
      },
    ))

  const Lazy = lazy(() =>
    fetchPage().then(
      (component) => ({ default: component }),
      (error: unknown) => (isStaleBuildError(error) && reloadForNewBuild() ? new Promise<never>(() => {}) : Promise.reject(error)),
    ),
  )

  function Page(props: P): ReactElement {
    // Decided as it mounts, so it stays the one component for good: the page itself when its chunk is here, else the
    // lazy one that waits for it.
    const [Component] = useState<ComponentType<P>>(() => loaded ?? Lazy)
    return createElement(Component, props)
  }

  return Object.assign(Page, { preload: () => loadAhead(fetchPage) })
}
