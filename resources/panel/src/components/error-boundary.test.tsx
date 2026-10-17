import { Suspense, type ReactNode } from 'react'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { createMemoryRouter, Outlet, RouterProvider } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { ErrorBoundary, RouteError } from '@/components/error-boundary'
import type { ClientErrorRequest } from '@/lib/api-types'
import { panelBuild } from '@/lib/config'
import { startErrorReporting } from '@/lib/error-reporting'
import { FAILURE_KINDS } from '@/lib/failure'
import { lazyPage } from '@/lib/lazy-page'
import { startPrefetching } from '@/lib/prefetch'
import { STORAGE_KEYS } from '@/lib/storage'

/*
 * Never a blank screen: a page that throws while drawing shows what happened in its place (ErrorState) and is reported
 * to the shop's log (lib/error-reporting), and a page file an upgrade replaced reloads the tab once for the new build
 * (lib/lazy-page, the boundary) — a second failure soon after says the reload did not help, and is reported to the log.
 */

const STALE = 'Failed to fetch dynamically imported module: http://localhost/admin/assets/plans-Bx1.js'

function Thrower({ error }: { error: Error | null }) {
  if (error) throw error
  return <p>صفحه پلن‌ها</p>
}

let reload: MockInstance<() => void>
let sent: ClientErrorRequest[]
let stop: () => void

beforeEach(() => {
  reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
  // React and the reporter tell the console what they caught; the tests read the screen and the reports instead.
  vi.spyOn(console, 'error').mockImplementation(() => undefined)
  sent = []
  stop = startErrorReporting((report) => {
    sent.push(report)
    return Promise.resolve()
  })
})

afterEach(() => stop())

describe('the error boundary', () => {
  it('shows what happened in place of a page that threw, with a way to reload, and tells the log', () => {
    window.history.replaceState(null, '', '/admin/plans?status=active#code=secret')
    render(
      <ErrorBoundary scope="page" resetKey="/plans">
        <Thrower error={new TypeError('Cannot read properties of undefined')} />
      </ErrorBoundary>,
    )

    expect(screen.getByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeTruthy()
    expect(reload).not.toHaveBeenCalled()
    expect(sent).toEqual([
      {
        kind: 'render',
        message: 'TypeError: Cannot read properties of undefined',
        stack: expect.stringContaining('Cannot read properties of undefined') as string,
        component_stack: expect.stringContaining('Thrower') as string,
        address: '/admin/plans?status',
        build: panelBuild,
      },
    ])

    fireEvent.click(screen.getByRole('button', { name: 'بارگذاری دوباره' }))
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('draws the screen again at another address', () => {
    const { rerender } = render(
      <ErrorBoundary scope="page" resetKey="/plans">
        <Thrower error={new Error('boom')} />
      </ErrorBoundary>,
    )
    expect(screen.getByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeTruthy()

    rerender(
      <ErrorBoundary scope="page" resetKey="/plans/other">
        <Thrower error={null} />
      </ErrorBoundary>,
    )

    expect(screen.getByText('صفحه پلن‌ها')).toBeTruthy()
  })

  it('reloads once for a page an upgrade replaced, and tells nobody', () => {
    render(
      <ErrorBoundary scope="app">
        <Thrower error={new TypeError(STALE)} />
      </ErrorBoundary>,
    )

    expect(reload).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('heading', { name: FAILURE_KINDS['stale-build'].title })).toBeTruthy()
    expect(sent).toEqual([])
  })

  it('says so when the reload did not help, and tells the log: the host’s files are not whole', () => {
    sessionStorage.setItem(STORAGE_KEYS.reloadedAt, String(Date.now()))
    render(
      <ErrorBoundary scope="app">
        <Thrower error={new TypeError(STALE)} />
      </ErrorBoundary>,
    )

    expect(reload).not.toHaveBeenCalled()
    expect(screen.getByRole('alert').textContent).toContain('هنوز پیدا نمی‌شوند')
    expect(sent.map((report) => report.message)).toEqual([`TypeError: ${STALE}`])
  })
})

/** A lazy page mounted: its file loaded (or not), and React's pause before it swaps the loading screen out (300 ms) gone by. */
async function mount(load: () => Promise<{ Page: () => ReactNode }>) {
  vi.useFakeTimers()
  const Page = lazyPage(load, (module) => module.Page)
  render(
    <ErrorBoundary scope="page">
      <Suspense fallback={<p role="status">در حال بارگذاری…</p>}>
        <Page />
      </Suspense>
    </ErrorBoundary>,
  )
  await act(() => vi.advanceTimersByTimeAsync(1_000))
}

// How a lazy page loads (and reloads for a new build) is lib/lazy-page.test's; here, what reaches the boundary.
describe('a lazy page that fails to load', () => {
  it('shows the new-build screen when a reload a moment ago did not help', async () => {
    sessionStorage.setItem(STORAGE_KEYS.reloadedAt, String(Date.now()))

    await mount(() => Promise.reject(new TypeError(STALE)))

    expect(screen.getByRole('heading', { name: FAILURE_KINDS['stale-build'].title })).toBeTruthy()
    expect(reload).not.toHaveBeenCalled()
  })

  it('hands any other failure to the error screen', async () => {
    await mount(() => Promise.reject(new Error('Unexpected token')))

    expect(screen.getByRole('heading', { name: FAILURE_KINDS.crash.title })).toBeTruthy()
    expect(reload).not.toHaveBeenCalled()
  })

  it('whose file did not come while the browser is offline says so with a way to ask again, which draws the page once it comes', async () => {
    const offline = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    let loads = 0
    const Page = lazyPage(
      () => (++loads === 1 ? Promise.reject(new TypeError(STALE)) : Promise.resolve({ Page: () => <p>صفحه پلن‌ها</p> })),
      (module) => module.Page,
    )
    render(
      <ErrorBoundary scope="page" onRetry={() => Page.preload()}>
        <Suspense fallback={<p role="status">در حال بارگذاری…</p>}>
          <Page />
        </Suspense>
      </ErrorBoundary>,
    )

    const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
    expect(screen.getByRole('heading', { name: FAILURE_KINDS.offline.title })).toBeTruthy()
    expect(reload).not.toHaveBeenCalled()

    offline.mockReturnValue(true)
    fireEvent.click(retry)

    expect(await screen.findByText('صفحه پلن‌ها')).toBeTruthy()
    expect(loads).toBe(2)
  })

  it('asked again from the keyboard, gives the focus to the page’s column once the page is drawn — never to the document', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    let loads = 0
    const Page = lazyPage(
      () => (++loads === 1 ? Promise.reject(new TypeError(STALE)) : Promise.resolve({ Page: () => <p>صفحه پلن‌ها</p> })),
      (module) => module.Page,
    )
    render(
      <main id="main" tabIndex={-1}>
        <ErrorBoundary scope="page" onRetry={() => Page.preload()}>
          <Suspense fallback={<p role="status">در حال بارگذاری…</p>}>
            <Page />
          </Suspense>
        </ErrorBoundary>
      </main>,
    )

    const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
    retry.focus()
    fireEvent.click(retry)

    expect(await screen.findByText('صفحه پلن‌ها')).toBeTruthy()
    expect(document.activeElement).toBe(screen.getByRole('main'))
  })
})

describe('a failure outside the shell — the router’s own screen', () => {
  it('whose page’s file did not come offline says so with a way to ask again, which draws the page once it comes', async () => {
    const offline = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)
    let loads = 0
    const Login = lazyPage(
      () => (++loads === 1 ? Promise.reject(new TypeError(STALE)) : Promise.resolve({ Page: () => <p>صفحه ورود</p> })),
      (module) => module.Page,
    )
    const routes = [{ path: '/login', element: <Login /> }]
    const router = createMemoryRouter(
      [
        {
          element: (
            <Suspense>
              <Outlet />
            </Suspense>
          ),
          errorElement: <RouteError />,
          children: routes,
        },
      ],
      // Sent to the sign-in from a page: where it leads back to rides on the entry.
      { initialEntries: [{ pathname: '/login', state: { from: '/plans' } }] },
    )
    const stopPrefetching = startPrefetching(routes, '/')
    try {
      render(<RouterProvider router={router} />)

      const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
      expect(screen.getByRole('heading', { name: FAILURE_KINDS.offline.title })).toBeTruthy()
      expect(reload).not.toHaveBeenCalled()

      offline.mockReturnValue(true)
      fireEvent.click(retry)

      expect(await screen.findByText('صفحه ورود')).toBeTruthy()
      expect([loads, router.state.location.pathname, router.state.location.state]).toEqual([2, '/login', { from: '/plans' }])
    } finally {
      stopPrefetching()
    }
  })

  it('offers no asking again where it cannot help — an address no route takes', async () => {
    const router = createMemoryRouter([{ path: '/', element: <p>داشبورد</p>, errorElement: <RouteError /> }], { initialEntries: ['/nowhere'] })
    render(<RouterProvider router={router} />)

    expect(await screen.findByRole('heading', { name: FAILURE_KINDS['not-found'].title })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
