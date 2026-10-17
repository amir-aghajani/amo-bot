import { createElement, lazy, useState, type ComponentType, type ReactElement } from 'react'
import { isStaleBuildError, loadAhead, reloadForNewBuild } from '@/lib/stale-build'

/** A page in a chunk of its own, which can be loaded before it is drawn. */
export interface LazyPage<P extends object> {
  (props: P): ReactElement
  /** Start loading the chunk (lib/prefetch). Silent: a failure is reported when the page is drawn. */
  preload(): void
}

/**
 * A page as its own chunk, loaded when first wanted. A page preloaded by the time it is drawn renders at once — no
 * skeleton, and no Suspense fallback flash. A tab opened before an upgrade asks for the old build's chunk, which is
 * gone: it reloads once for the new build, else the failure goes to the error boundary — and a link to the page about
 * to be followed again (lib/prefetch) asks for its chunk again.
 */
export function lazyPage<M, P extends object>(load: () => Promise<M>, pick: (module: M) => ComponentType<P>): LazyPage<P> {
  let loaded: ComponentType<P> | undefined
  let loading: Promise<ComponentType<P>> | undefined

  const fetchPage = () =>
    (loading ??= load().then(
      // No module: Vite could not fetch the chunk and root.tsx's `vite:preloadError` listener started a reload for the
      // new build — wait for it, draw nothing.
      (module: M | undefined) => (module === undefined ? new Promise<never>(() => {}) : (loaded = pick(module))),
      (error: unknown) => {
        // Not loaded: the next ask tries again.
        loading = undefined
        throw error
      },
    ))

  /** The lazy component's load failed: React keeps that failure for good, for every draw of the page it holds. */
  let failed = false
  const waiting = () =>
    lazy(() =>
      fetchPage().then(
        (component) => ({ default: component }),
        (error: unknown) => {
          failed = true
          return isStaleBuildError(error) && reloadForNewBuild() ? new Promise<never>(() => {}) : Promise.reject(error)
        },
      ),
    )
  let Lazy = waiting()

  function Page(props: P): ReactElement {
    // Chosen once, at mount, so the component never changes under React: the page itself when its chunk is loaded, else
    // the lazy one that waits for it.
    const [Component] = useState<ComponentType<P>>(() => loaded ?? Lazy)
    return createElement(Component, props)
  }

  return Object.assign(Page, {
    preload: () => {
      // Asked again after a failure (a link to the page about to be followed, the connection back): the page drawn next
      // waits for a lazy component of its own, not the one React holds failed. Never from React's own draws, whose
      // retries of a failed page would ask without end.
      if (failed) {
        failed = false
        Lazy = waiting()
      }
      loadAhead(fetchPage)
    },
  })
}
