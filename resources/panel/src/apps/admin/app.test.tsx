import { Suspense } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createMemoryRouter, Outlet, RouterProvider } from 'react-router'
import { describe, expect, it } from 'vitest'
import { ADMIN_ROUTES } from '@/apps/admin/app'
import type { AppInfo } from '@/lib/api-types'
import { AuthProvider } from '@/lib/auth'
import { testQueryClient, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The owner's routes (apps/admin/app): before the shop is installed nothing but the installer, from any address; once it
 * is, the installer's address goes to the sign-in, and everything else waits behind it with the address to come back to.
 */

function open(at: string, installed: boolean) {
  server()
    .on('GET', '/api/app', json({ name: 'AmoBot', installed, timezone: 'UTC' } satisfies AppInfo))
    .on('GET', '/api/admin/auth/me', installed ? refusal(401, 'ابتدا وارد شوید.') : refusal(503, 'فروشگاه هنوز نصب نشده است.'))
    .on('GET', '/api/install', refusal(403, 'کلید نصب را وارد کنید.', { key: ['کلید نصب را وارد کنید.'] }))
  const router = createMemoryRouter(
    [
      {
        element: (
          <Suspense>
            <Outlet />
          </Suspense>
        ),
        children: ADMIN_ROUTES,
      },
    ],
    { initialEntries: [at] },
  )
  render(
    <QueryClientProvider client={testQueryClient()}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>,
  )
  return router
}

describe('a shop not installed yet', () => {
  it('opens the installer, whatever the address', async () => {
    const router = open('/orders?status=failed', false)

    await until(() => expect(router.state.location.pathname).toBe('/install'))
  })
})

describe('an installed shop', () => {
  it('sends the installer’s address to the sign-in', async () => {
    const router = open('/install', true)

    await until(() => expect(router.state.location.pathname).toBe('/login'))
  })

  it('keeps the panel behind the sign-in, with the whole address to come back to', async () => {
    const router = open('/orders?status=failed', true)

    await until(() => expect(router.state.location.pathname).toBe('/login'))
    expect(router.state.location.state).toEqual({ from: '/orders?status=failed', expired: false })
  })
})
