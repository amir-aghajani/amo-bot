import { Suspense } from 'react'
import { Outlet, useLocation } from 'react-router'
import { ErrorBoundary } from '@/components/error-boundary'
import { PageSkeleton } from '@/components/page'
import { ConnectionBanner } from '@/components/shell/connection-banner'
import { MobileDrawer } from '@/components/shell/mobile-drawer'
import { areaFor, pageTitle, type PanelNav } from '@/components/shell/nav'
import { ShellProvider, type PanelShell } from '@/components/shell/shell-context'
import { Sidebar } from '@/components/shell/sidebar'
import { Topbar } from '@/components/shell/topbar'
import { pageOf } from '@/lib/config'
import { useDocumentTitle } from '@/lib/document-title'
import { useLiveUpdates } from '@/lib/use-live-updates'

/**
 * Two columns, as the Console: the sidebar on the inline-start edge and the page beside it, which owns the scroll and
 * sets its own width (components/page). On a phone the sidebar becomes a drawer under a slim topbar — one column or the
 * other, never both. Each panel draws it with its own navigation and what only it has (`panel`). Over the page, a strip
 * while the panel cannot follow the shop (offline, or the server not answering).
 */
export function AppShell({ panel }: { panel: PanelShell }) {
  // Signed in, the panel follows the shop: what changes anywhere reloads on the screens that show it.
  const live = useLiveUpdates()

  // The tab and the history name the page — and its section —, not just the panel.
  const { pathname } = useLocation()
  useDocumentTitle(placeTitle(panel.nav, pathname))

  return (
    <ShellProvider panel={panel}>
      <a href="#main" className="sr-only z-50 rounded-md bg-primary px-3 py-2 text-body text-primary-foreground focus:not-sr-only focus:fixed focus:start-3 focus:top-3">
        پرش به محتوا
      </a>

      <div className="flex min-h-svh">
        <Sidebar />
        <div className="flex min-w-0 flex-1 flex-col">
          <Topbar />
          <ConnectionBanner unreachable={live.unreachable} />
          <main id="main" tabIndex={-1} className="flex flex-1 flex-col outline-none">
            {/* A page that throws shows what happened in its place; the sidebar still works. Another page starts afresh;
                another section of the same page draws again and keeps its sections' drafts. */}
            <ErrorBoundary key={pageOf(pathname)} resetKey={pathname} scope="page">
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
