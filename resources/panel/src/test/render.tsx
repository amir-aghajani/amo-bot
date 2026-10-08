import { setImmediate } from 'node:timers'
import type { ReactNode } from 'react'
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query'
import { act } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import type { Session } from '@/lib/api-types'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import { createQueryClient } from '@/lib/query-client'
import { json, server } from '@/test/server'

/**
 * Waits until `assertion` holds — what a hook or a screen shows once the server has answered —, letting the event loop
 * turn in between (Node's own setImmediate: neither a timer, which ticks every 15 ms on Windows, nor one a test faked).
 * Fails with the assertion's own error after two seconds.
 */
export async function until(assertion: () => void): Promise<void> {
  const deadline = performance.now() + 2_000
  for (;;) {
    try {
      assertion()
      return
    } catch (error) {
      if (performance.now() > deadline) throw error
      await act(() => new Promise<void>((resolve) => setImmediate(resolve)))
    }
  }
}

/**
 * The panels' query client as the app makes it (lib/query-client) — except that a read with no answer is not asked
 * again: a test answers each request once, and lib/query-client's own test covers the retries.
 */
export function testQueryClient(): QueryClient {
  const client = createQueryClient()
  const defaults = client.getDefaultOptions()
  client.setDefaultOptions({ ...defaults, queries: { ...defaults.queries, retry: false } })
  return client
}

interface ProvidersOptions {
  client?: QueryClient
  /** The router's address (in memory: the address bar is left alone). */
  at?: string
}

/** What a screen or a hook of the panel stands on — the query client and a router —, as a `wrapper` for render/renderHook. */
export function providers({ client = testQueryClient(), at = '/' }: ProvidersOptions = {}) {
  function Providers({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[at]}>{children}</MemoryRouter>
      </QueryClientProvider>
    )
  }

  return { client, wrapper: Providers }
}

/** The owner, in the main bot's shop. */
export const OWNER: Session = { name: 'root', shop: { id: 1, name: 'فروشگاه اصلی', username: null, status: 'active' } }

/** The owner in an agent's shop (opened from the agents page): what depends on the shop shown is the agent's. */
export const AGENT_SHOP: Session = { name: 'root', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active' } }

/**
 * What a page of a signed-in panel stands on, as the app mounts it: the providers, and behind RequireAuth the session
 * the panel asks for (`/auth/me`, answered here with `session`). A screen rendered in it appears once that answer lands.
 */
export function signedIn({ session = OWNER, client = testQueryClient(), at = '/' }: ProvidersOptions & { session?: Session } = {}) {
  server().on('GET', '/api/admin/auth/me', json({ session }))

  function SignedIn({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[at]}>
          <AuthProvider>
            <Routes>
              <Route element={<RequireAuth />}>
                <Route path="*" element={children} />
              </Route>
            </Routes>
          </AuthProvider>
        </MemoryRouter>
      </QueryClientProvider>
    )
  }

  return { client, wrapper: SignedIn }
}
