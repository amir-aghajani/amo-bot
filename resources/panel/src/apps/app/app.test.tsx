import { Suspense } from 'react'
import { act, fireEvent, render, screen as view } from '@testing-library/react'
import { createMemoryRouter, Outlet, RouterProvider } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { screen, ScreenFailed } from '@/apps/app/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * A screen whose file does not load (app.tsx): the build before an update is gone, and main.tsx's `vite:preloadError`
 * listener has started a reload — the app waits for it; that reload already behind it, the customer reads why in their
 * own words with a way to start again, never the router's own page.
 */

/** The app's frame around one screen, loaded by `load`. */
function open(load: Parameters<typeof screen>[0]) {
  const router = createMemoryRouter([
    {
      element: (
        <Suspense fallback={<p>در حال بارگذاری</p>}>
          <Outlet />
        </Suspense>
      ),
      ErrorBoundary: ScreenFailed,
      children: [{ index: true, lazy: screen(load) }],
    },
  ])
  render(<RouterProvider router={router} />)
}

describe('a screen whose file did not load', () => {
  it('waits for the reload for the new build, showing no failure', async () => {
    open(() => Promise.resolve(undefined))

    await act(() => new Promise((resolve) => setTimeout(resolve, 20)))
    expect(view.queryByRole('alert')).toBeNull()
  })

  it('says why in the customer’s words once that reload is behind it, and starts again', async () => {
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
    open(() => Promise.reject(new TypeError('Failed to fetch dynamically imported module: https://shop.example/app/assets/tickets-Db1.js')))

    expect((await view.findByRole('alert')).textContent).toMatch(/^این صفحه باز نشد؛ اگر فروشگاه همین الان به‌روز شده/)
    fireEvent.click(view.getByRole('button', { name: 'تلاش دوباره' }))
    expect(reload).toHaveBeenCalledOnce()
    reload.mockRestore()
  })

  it('shows a screen that threw the same way, not the router’s own page', async () => {
    open(() =>
      Promise.resolve({
        default: () => {
          throw new Error('boom')
        },
      }),
    )

    expect((await view.findByRole('alert')).textContent).toMatch(/^خطایی پیش آمد؛ دوباره تلاش کنید/)
    expect(view.queryByText(/Unexpected Application Error/)).toBeNull()
  })
})
