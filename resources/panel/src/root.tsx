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
 * Mount a panel (apps/admin/main.tsx, apps/agent/main.tsx) with its routes: the theme, right-to-left Radix,
 * react-query, tooltips, the session, the toasts, the outermost error boundary, and a data router based at the panel's
 * folder whose root route holds what every route needs.
 */
export function mount(routes: RouteObject[]) {
  const queryClient = createQueryClient()
  const router = createBrowserRouter([{ element: <Root />, errorElement: <RouteError />, children: routes }], { basename: routerBasename })

  startErrorReporting()
  startPrefetching(routes, routerBasename)

  // A chunk of an older build is gone after an upgrade: one reload brings the new build (a reload that did not help lets
  // the failure through to the screen that says so). A chunk loaded ahead of need is left to the load that needs it.
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

/** A failure the router caught while drawing (RouteError shows it): reported as the error boundaries report theirs. */
const failed: ClientOnErrorFunction = (error, { errorInfo }) => reportFailure(error, errorInfo)

/**
 * The root of every route: the unsaved-changes guard and its question, and the loading screen while a page outside the
 * shell (the sign-in, the installer) fetches its chunk.
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
