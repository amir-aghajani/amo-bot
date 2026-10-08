import { StrictMode, Suspense } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { Direction } from 'radix-ui'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Outlet, type ClientOnErrorFunction, type RouteObject } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { AppLoading } from '@/components/app-status'
import { ErrorBoundary, reportFailure, RouteError } from '@/components/error-boundary'
import { LeaveQuestion } from '@/components/leave-question'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider } from '@/lib/auth'
import { routerBasename } from '@/lib/config'
import { startErrorReporting } from '@/lib/error-reporting'
import { startPrefetching } from '@/lib/prefetch'
import { createQueryClient } from '@/lib/query-client'
import { loadingAhead, reloadForNewBuild } from '@/lib/stale-build'
import { ThemeProvider } from '@/lib/theme'
import { NavigationGuard } from '@/lib/use-unsaved-guard'
import './fonts/vazirmatn/vazirmatn.css'
import './index.css'

/**
 * What both panels stand on — the owner's (apps/admin) and an agent's (apps/agent) mount their routes here: the theme,
 * right-to-left Radix, react-query, tooltips, the session of the panel's own API, the toasts, the last error boundary,
 * and a data router at the panel's folder (so any sub-folder install, any deep link) whose root route holds what every
 * route stands in.
 */
export function mount(routes: RouteObject[]) {
  const queryClient = createQueryClient()
  const router = createBrowserRouter([{ element: <Root />, errorElement: <RouteError />, children: routes }], { basename: routerBasename })

  // The panel's own failures reach the shop's log, and what nothing handled is told to the admin too.
  startErrorReporting()

  // The page the panel opens on loads beside its first questions to the server, not after them; any other as its link is
  // about to be followed (lib/prefetch).
  startPrefetching(routes, routerBasename)

  // A file a page needs from an older build is gone after an upgrade: one reload brings the new build — or, when the
  // reload did not help, the failure goes on to the screen that says so. A page loaded ahead of need waits for its turn.
  window.addEventListener('vite:preloadError', (event) => {
    if (!loadingAhead() && reloadForNewBuild()) event.preventDefault()
  })

  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <ErrorBoundary scope="app">
        <ThemeProvider>
          {/* The panels are Persian, so Radix primitives (menus, tooltips, keyboard nav) run right-to-left. */}
          <Direction.Provider dir="rtl">
            <QueryClientProvider client={queryClient}>
              <TooltipProvider>
                <AuthProvider>
                  <RouterProvider router={router} onError={failed} />
                </AuthProvider>
              </TooltipProvider>
              <Toaster position="bottom-left" />
            </QueryClientProvider>
          </Direction.Provider>
        </ThemeProvider>
      </ErrorBoundary>
    </StrictMode>,
  )
}

/** A failure the router caught while drawing (its root's error element shows it): told to the log as the boundaries tell theirs. */
const failed: ClientOnErrorFunction = (error, { errorInfo }) => reportFailure(error, errorInfo)

/**
 * The root of every route: the unsaved-changes guard's hold on every navigation (while a draft is unsaved) and its
 * question, and the loading screen while a page outside the shell (the sign-in, the installer) fetches its chunk.
 */
function Root() {
  return (
    <>
      <NavigationGuard />
      <LeaveQuestion />
      <Suspense fallback={<AppLoading />}>
        <Outlet />
      </Suspense>
    </>
  )
}
