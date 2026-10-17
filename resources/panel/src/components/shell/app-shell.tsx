import { Suspense, useMemo } from 'react'
import { Outlet, useLocation } from 'react-router'
import { ErrorBoundary } from '@/components/error-boundary'
import { PageSkeleton } from '@/components/page'
import { ConnectionBanner } from '@/components/shell/connection-banner'
import { MobileDrawer } from '@/components/shell/mobile-drawer'
import { areaFor, navOfShop, pageTitle, type PanelNav } from '@/components/shell/nav'
import { ShellProvider, type PanelShell } from '@/components/shell/shell-context'
import { Sidebar } from '@/components/shell/sidebar'
import { Topbar } from '@/components/shell/topbar'
import { useMainShop } from '@/lib/auth'
import { pageOf } from '@/lib/config'
import { useDocumentTitle } from '@/lib/document-title'
import { preloadPage } from '@/lib/prefetch'
import { useLiveUpdates } from '@/lib/use-live-updates'

/**
 * The signed-in layout: the sidebar at the inline start and the page beside it under the topbar, which stays at the top;
 * the page owns the scroll and sets its own width (components/page). On a phone the sidebar is a drawer the topbar's menu
 * button opens. Each panel passes its own navigation and extras (`panel`); the navigation is drawn as the shop on screen
 * has it (navOfShop()). Also runs the live updates, and a strip over the page while they cannot reach the server — and
 * the panel's own strip (`banner`), when it has one.
 */
export function AppShell({ panel }: { panel: PanelShell }) {
  const live = useLiveUpdates()
  // The tree of the shop on screen: an agent's has none of the main shop's own sections.
  const main = useMainShop()
  const shell = useMemo(() => ({ ...panel, nav: navOfShop(panel.nav, main) }), [panel, main])

  // The tab and the history name the page — and its section —, not just the panel.
  const { pathname } = useLocation()
  useDocumentTitle(placeTitle(shell.nav, pathname))

  return (
    <ShellProvider panel={shell}>
      {/* The page's <base> is the panel's folder, so a bare `#main` would lead to the dashboard: the link moves the focus itself. */}
      <a
        href="#main"
        onClick={(event) => {
          event.preventDefault()
          document.getElementById('main')?.focus()
        }}
        className="sr-only z-50 rounded-md bg-primary px-3 py-2 text-body text-primary-foreground focus:not-sr-only focus:fixed focus:start-3 focus:top-3"
      >
        پرش به محتوا
      </a>

      <div className="flex min-h-svh">
        <Sidebar />
        <div className="flex min-w-0 flex-1 flex-col">
          <Topbar />
          <ConnectionBanner unreachable={live.unreachable} />
          {shell.banner && <shell.banner />}
          <main id="main" tabIndex={-1} className="flex flex-1 flex-col outline-none">
            {/* A page that throws shows what happened in its place; the sidebar still works. Another page starts afresh;
                another section of the same page draws again and keeps its sections' drafts. Asked again, a page whose
                file did not load asks for it again. */}
            <ErrorBoundary key={pageOf(pathname)} resetKey={pathname} scope="page" onRetry={() => preloadPage(pathname)}>
              <Suspense fallback={<PageSkeleton />}>
                <Outlet />
              </Suspense>
            </ErrorBoundary>
          </main>
        </div>
      </div>

      <MobileDrawer />
    </ShellProvider>
  )
}

/**
 * Where the shell is, as the tab names it: the section on screen — unless it is the page's own name — and the page;
 * nothing at an address no page has, whose not-found page names itself.
 */
function placeTitle(nav: PanelNav, pathname: string): string {
  const page = pageTitle(nav, pathname)
  if (page === null) return ''
  const section = areaFor(nav, pathname)
    ?.groups.flatMap((group) => group.sections)
    .find((entry) => entry.to === pathname)

  return section && section.title !== page ? `${section.title} · ${page}` : page
}
