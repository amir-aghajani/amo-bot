import { Component, Suspense, useEffect, useState, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { Send, ShieldAlert } from 'lucide-react'
import { createBrowserRouter, Outlet, ScrollRestoration, useLocation, useRouteError, type RouteObject } from 'react-router'
import { RouterProvider } from 'react-router/dom'
import { routerBasename } from '@/apps/app/config'
import { queryClient } from '@/apps/app/lib/query'
import { SessionProvider, useSession, useShop } from '@/apps/app/lib/session'
import { goFullscreen, setAccent } from '@/apps/app/telegram/theme'
import { openTelegramLink } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { PreviewMark } from '@/apps/app/ui/preview-mark'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { TabBar, TABS } from '@/apps/app/ui/tab-bar'
import { Toaster } from '@/apps/app/ui/toaster'
import { setShopTimeZone } from '@/lib/format'

/*
 * The mini app: the shop's customer side inside Telegram, on the Store API like the shop's website. Its screens are
 * routes under app/{store key}/ (lazy, each its own file); the five tabs' screens show the tab bar. Before any screen,
 * the customer is signed in by Telegram's launch data (lib/session) — opened in a browser, the app sends them to the
 * bot instead —, and the shop's look is applied: its colour, its name, its time zone, full screen when it wants it. A
 * panel's preview (its own session, read only) shows the same app under a small «پیش‌نمایش» pill.
 */

/**
 * A screen's module: its default export is the screen. No module: its file was gone and main.tsx's `vite:preloadError`
 * listener started a reload for the new build — wait for it.
 */
export const screen = (load: () => Promise<{ default: () => ReactNode } | undefined>) => () =>
  load().then((module) => (module === undefined ? new Promise<never>(() => {}) : { Component: module.default }))

const APP_ROUTES: RouteObject[] = [
  { index: true, lazy: screen(() => import('@/apps/app/screens/home/home')) },
  { path: 'notifications', lazy: screen(() => import('@/apps/app/screens/home/notifications')) },

  { path: 'buy', lazy: screen(() => import('@/apps/app/screens/buy/catalog')) },
  { path: 'buy/plans/:id', lazy: screen(() => import('@/apps/app/screens/buy/plan')) },
  { path: 'buy/custom', lazy: screen(() => import('@/apps/app/screens/buy/custom')) },
  { path: 'test', lazy: screen(() => import('@/apps/app/screens/buy/test-account')) },
  { path: 'pay/:orderId', lazy: screen(() => import('@/apps/app/screens/pay/pay')) },

  { path: 'services', lazy: screen(() => import('@/apps/app/screens/services/list')) },
  { path: 'services/claim', lazy: screen(() => import('@/apps/app/screens/services/claim')) },
  { path: 'services/:id', lazy: screen(() => import('@/apps/app/screens/services/detail')) },
  { path: 'services/:id/renew', lazy: screen(() => import('@/apps/app/screens/services/renew')) },
  { path: 'services/:id/extras', lazy: screen(() => import('@/apps/app/screens/services/extras')) },
  { path: 'services/:id/transfer', lazy: screen(() => import('@/apps/app/screens/services/transfer')) },

  { path: 'wallet', lazy: screen(() => import('@/apps/app/screens/wallet/wallet')) },
  { path: 'wallet/top-up', lazy: screen(() => import('@/apps/app/screens/wallet/top-up')) },
  { path: 'wallet/transfer', lazy: screen(() => import('@/apps/app/screens/wallet/transfer')) },
  { path: 'wallet/history', lazy: screen(() => import('@/apps/app/screens/wallet/history')) },
  { path: 'orders/:id', lazy: screen(() => import('@/apps/app/screens/wallet/invoice')) },

  { path: 'club', lazy: screen(() => import('@/apps/app/screens/club/club')) },
  { path: 'club/wheel', lazy: screen(() => import('@/apps/app/screens/club/wheel')) },
  { path: 'club/lottery', lazy: screen(() => import('@/apps/app/screens/club/lottery')) },
  { path: 'club/referral', lazy: screen(() => import('@/apps/app/screens/club/referral')) },

  { path: 'support', lazy: screen(() => import('@/apps/app/screens/support/tickets')) },
  { path: 'support/new', lazy: screen(() => import('@/apps/app/screens/support/new-ticket')) },
  { path: 'support/:id', lazy: screen(() => import('@/apps/app/screens/support/chat')) },
  { path: 'tutorial', lazy: screen(() => import('@/apps/app/screens/support/tutorial')) },
  { path: 'reviews', lazy: screen(() => import('@/apps/app/screens/support/reviews')) },

  { path: 'account', lazy: screen(() => import('@/apps/app/screens/account/account')) },
  { path: 'account/security', lazy: screen(() => import('@/apps/app/screens/account/security')) },
  { path: 'account/email', lazy: screen(() => import('@/apps/app/screens/account/email')) },

  { path: '*', lazy: screen(() => import('@/apps/app/screens/not-found')) },
]

/** Every screen's frame: the screen, and the tab bar under a tab's own screen. */
function Frame() {
  const { pathname } = useLocation()
  const path = pathname.startsWith(routerBasename) ? pathname.slice(routerBasename.length) || '/' : pathname
  const tab = TABS.some(({ to }) => to === path || `${to}/` === path)

  return (
    <>
      <Suspense fallback={<LoadingBlocks />}>
        <Outlet />
      </Suspense>
      {/* A new screen starts at its top; going back finds the last one where it was left. */}
      <ScrollRestoration />
      {tab && <TabBar />}
    </>
  )
}

/** The shop's look on the app once `GET /` answered: its colour, name and zone, and full screen when it wants it. */
function useShopLook(): void {
  const { data } = useShop()
  const app = data?.app ?? null

  useEffect(() => {
    if (app === null) return
    setAccent(app.accent_color)
    setShopTimeZone(app.time_zone)
    document.title = app.title
    if (app.fullscreen) goFullscreen()
  }, [app])
}

/** What stands before the screens: the sign-in, or why there is none. */
function Gate({ children }: { children: ReactNode }) {
  const { session, retry } = useSession()
  const { data: shop } = useShop()
  useShopLook()

  if (session.state === 'outside') {
    const bot = shop?.shop.bot ?? null

    return (
      <main className="mx-auto grid min-h-svh max-w-md place-items-center px-6">
        <Empty
          icon={Send}
          title="این صفحه را از ربات باز کنید"
          text="فروشگاه داخل تلگرام باز می‌شود: در ربات فروشگاه، دکمه منو یا دکمه اپلیکیشن را بزنید."
          action={
            bot !== null && (
              <Button onClick={() => openTelegramLink(bot.url)}>
                {/* The handle reads left to right: never «sample_bot@» in the line. */}
                <span>
                  باز کردن <span className="ltr">@{bot.username}</span>
                </span>
              </Button>
            )
          }
        />
      </main>
    )
  }
  if (session.state === 'failed') {
    return (
      <main className="mx-auto grid min-h-svh max-w-md place-items-center px-6">
        <Empty icon={ShieldAlert} title="ورود انجام نشد" text={session.message} action={session.retry && <Button onClick={retry}>تلاش دوباره</Button>} />
      </main>
    )
  }
  // The first sign-in alone: one again later (a session ended) leaves the screen standing (lib/session).
  if (session.state === 'loading') {
    return (
      <main className="mx-auto flex max-w-xl flex-col gap-5 px-4 pt-[calc(var(--app-top)+1rem)]">
        <LoadingBlocks count={4} />
      </main>
    )
  }

  return children
}

/** A screen the router could not draw — its file not loaded, or it threw: why, and a way to start again. */
export function ScreenFailed() {
  const error = useRouteError()

  return (
    <main className="mx-auto grid min-h-svh max-w-md place-items-center px-6">
      <ErrorView error={error} retry={() => window.location.reload()} />
    </main>
  )
}

/** The last guard: a screen that threw shows why and a way to start again, never a blank page. */
class Crash extends Component<{ children: ReactNode }, { error: unknown }> {
  state = { error: null as unknown }

  static getDerivedStateFromError(error: unknown) {
    return { error }
  }

  render() {
    if (this.state.error === null) return this.props.children

    return (
      <main className="mx-auto grid min-h-svh max-w-md place-items-center px-6">
        <ErrorView error={this.state.error} retry={() => window.location.reload()} />
      </main>
    )
  }
}

export function App() {
  const [router] = useState(() => createBrowserRouter([{ element: <Frame />, ErrorBoundary: ScreenFailed, children: APP_ROUTES }], { basename: routerBasename }))

  return (
    <Crash>
      <QueryClientProvider client={queryClient}>
        <SessionProvider>
          <Gate>
            <RouterProvider router={router} />
          </Gate>
        </SessionProvider>
        <Toaster />
      </QueryClientProvider>
      <PreviewMark />
    </Crash>
  )
}
