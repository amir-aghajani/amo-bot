import { createRef, useImperativeHandle, type Ref } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { describe, expect, it } from 'vitest'
import { api, ApiError } from '@/lib/api'
import type { Session, SessionResponse } from '@/lib/api-types'
import { AuthProvider, RequireAuth, useAuth, useMainShop, useShopName } from '@/lib/auth'
import { queryKeys } from '@/lib/query-keys'
import { providers, testQueryClient, until } from '@/test/render'
import { json, noContent, offline, refusal, server } from '@/test/server'

/*
 * A panel's session (lib/auth): who is signed in is asked of the server once — in the shop the tab shows —; only a 401
 * says nobody — a server that does not answer is not "signed out" but a screen to try again from, and a tab at the
 * address of a shop that is not there is told so, with the way to the main shop —, a session that ends mid-work sends
 * the admin to the login page saying so, and opening a session forgets everything the last one read but the shop's name.
 * Once signed in, the panel goes by the name of the shop it shows.
 */

const OWNER: Session = { name: 'owner', shop: { id: 1, name: 'AmoBot', username: 'amobot', status: 'active' } }

type Auth = ReturnType<typeof useAuth>

/** The session as the panel has it now, for the test to read and to act on. */
function Probe({ handle }: { handle: Ref<Auth> }) {
  const auth = useAuth()
  useImperativeHandle(handle, () => auth, [auth])
  return null
}

/** The login page, showing what the guard left it in the history entry. */
function Login() {
  return <p data-testid="login">{JSON.stringify(useLocation().state)}</p>
}

/** The panel at `at`: a page behind the guard, the login page, the session at hand. */
function panel(at: string) {
  const handle = createRef<Auth>()
  const client = testQueryClient()
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[at]}>
        <AuthProvider>
          <Probe handle={handle} />
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route element={<RequireAuth />}>
              <Route path="/orders" element={<p>سفارش‌ها</p>} />
              <Route path="/shop" element={<Shop />} />
            </Route>
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { client, auth: () => handle.current as Auth }
}

/** What the panel says of the shop it shows, behind the guard. */
function Shop() {
  return (
    <p data-testid="shop">
      {useShopName()} · {useMainShop() ? 'اصلی' : 'نماینده'}
    </p>
  )
}

/** A screen that asks for the session where there is no provider. */
function Lost() {
  useAuth()
  return null
}

const arrival = () => JSON.parse(screen.getByTestId('login').textContent ?? 'null') as unknown

describe('opening the panel', () => {
  it('shows the page to a signed-in session', async () => {
    server().on('GET', '/api/admin/auth/me', json({ session: OWNER } satisfies SessionResponse))
    const { auth } = panel('/orders')

    expect(screen.getByRole('status').textContent).toContain('در حال بارگذاری')
    expect(await screen.findByText('سفارش‌ها')).toBeTruthy()
    expect(auth().session).toEqual(OWNER)
  })

  it('sends a signed-out visitor to the login page with the whole address to come back to', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    const { auth } = panel('/orders?status=failed')

    await until(() => expect(arrival()).toEqual({ from: '/orders?status=failed', expired: false }))
    expect(auth()).toMatchObject({ session: null, failure: null, expired: false })
  })

  it('tells a server that does not answer from a signed-out session, and asks again on «تلاش دوباره»', async () => {
    let answer = offline
    server().on('GET', '/api/admin/auth/me', () => answer)
    panel('/orders')

    expect(await screen.findByRole('heading', { name: 'سرور در دسترس نیست' })).toBeTruthy()
    expect(screen.queryByTestId('login')).toBeNull()

    answer = json({ session: OWNER })
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByText('سفارش‌ها')).toBeTruthy()
    expect(server().sent('GET', '/api/admin/auth/me')).toHaveLength(2)
  })

  it('says the shop the tab’s address names is not there, with the way to the main shop — never the main shop in its place', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(404, 'این فروشگاه پیدا نشد؛ شاید آدرسی که باز کردید درست نیست.', { shop: ['این فروشگاه پیدا نشد؛ شاید آدرسی که باز کردید درست نیست.'] }))
    panel('/orders')

    expect(await screen.findByRole('heading', { name: 'این فروشگاه پیدا نشد' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'رفتن به فروشگاه اصلی' }).getAttribute('href')).toBe('/admin/')
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
    expect(screen.queryByText('سفارش‌ها')).toBeNull()
  })

  it('goes by the name of the shop it shows once signed in — an agent’s by its own —, and knows whether it is the main one', async () => {
    const AGENT_SHOP: Session = { name: 'root', shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active' } }
    server()
      .on('GET', '/api/admin/auth/me', json({ session: AGENT_SHOP } satisfies SessionResponse))
      .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true, timezone: 'UTC' }))
    panel('/shop')

    expect((await screen.findByTestId('shop')).textContent).toBe('فروشگاه رضا · نماینده')
  })

  it('goes by the shop’s own name in the main shop — «فروشگاه اصلی» is what tells it apart in the picker, not its name', async () => {
    const MAIN: Session = { name: 'root', shop: { id: 1, name: 'فروشگاه اصلی', username: 'amobot', status: 'active' } }
    server()
      .on('GET', '/api/admin/auth/me', json({ session: MAIN } satisfies SessionResponse))
      .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true, timezone: 'UTC' }))
    panel('/shop')

    await until(() => expect(screen.getByTestId('shop').textContent).toBe('فروشگاه امو · اصلی'))
  })

  it('counts a server error as unreachable too, not as signed out', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(500, 'خطای سرور'))
    const { auth } = panel('/orders')

    await until(() => expect(auth().failure).toBeInstanceOf(ApiError))
    expect(screen.getByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
  })
})

describe('a session that ends mid-work', () => {
  it('sends the admin to the login page saying so, with the page to come back to', async () => {
    server()
      .on('GET', '/api/admin/auth/me', json({ session: OWNER }))
      .on('GET', '/api/admin/plans', refusal(401, 'برای دسترسی باید وارد شوید.'))
    const { auth } = panel('/orders?search=%2312')
    await screen.findByText('سفارش‌ها')

    await act(() => api.get('/plans').catch(() => undefined))

    await until(() => expect(arrival()).toEqual({ from: '/orders?search=%2312', expired: true }))
    expect(auth().session).toBeNull()
  })
})

describe('entering', () => {
  it('shows the pages after a sign-in, though the first question went unanswered — a shop installed since (its 503 then)', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(503, 'فروشگاه هنوز نصب نشده است.'))
    const { auth } = panel('/orders')
    expect(await screen.findByText('فروشگاه هنوز نصب نشده است.')).toBeTruthy()

    await act(() => auth().enter(() => Promise.resolve({ session: OWNER })))

    expect(await screen.findByText('سفارش‌ها')).toBeTruthy()
    expect(auth()).toMatchObject({ session: OWNER, failure: null, loading: false })
  })

  it('opens a session and forgets everything the last one read but the shop’s name', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    const { client, auth } = panel('/login')
    await until(() => expect(auth().loading).toBe(false))
    client.setQueryData(queryKeys.app, { name: 'AmoBot', installed: true })
    client.setQueryData(queryKeys.plans, { plans: [] })

    const AGENT_SHOP: Session = { name: '@agent_shop_bot', shop: { id: 2, name: 'Agent', username: 'agent_shop_bot', status: 'active' } }
    await act(() => auth().enter(() => Promise.resolve({ session: AGENT_SHOP })))

    expect(auth().session).toEqual(AGENT_SHOP)
    expect(client.getQueryData(queryKeys.app)).toEqual({ name: 'AmoBot', installed: true })
    expect(client.getQueryData(queryKeys.plans)).toBeUndefined()
  })

  it('leaves everything as it was when the sign-in is refused', async () => {
    server().on('GET', '/api/admin/auth/me', refusal(401, 'ابتدا وارد شوید.'))
    const { client, auth } = panel('/login')
    await until(() => expect(auth().loading).toBe(false))
    client.setQueryData(queryKeys.plans, { plans: [] })

    const refused = new ApiError(422, 'نام کاربری یا رمز عبور درست نیست.')
    await expect(act(() => auth().enter(() => Promise.reject(refused)))).rejects.toBe(refused)

    expect(auth().session).toBeNull()
    expect(client.getQueryData(queryKeys.plans)).toEqual({ plans: [] })
  })
})

describe('signing out', () => {
  async function signedIn() {
    server().on('GET', '/api/admin/auth/me', json({ session: OWNER }))
    const opened = panel('/orders')
    await screen.findByText('سفارش‌ها')
    opened.client.setQueryData(queryKeys.plans, { plans: [] })
    return opened
  }

  it('ends the session and forgets what it read', async () => {
    const { client, auth } = await signedIn()
    server().on('POST', '/api/admin/auth/logout', noContent)

    await act(() => auth().logout())

    expect(auth()).toMatchObject({ session: null, expired: false })
    expect(client.getQueryData(queryKeys.plans)).toBeUndefined()
    await until(() => expect(arrival()).toEqual({ from: '/orders', expired: false }))
  })

  it('counts a session the server already dropped as signed out', async () => {
    const { auth } = await signedIn()
    server().on('POST', '/api/admin/auth/logout', refusal(401, 'ابتدا وارد شوید.'))

    await act(() => auth().logout())

    expect(auth().session).toBeNull()
  })

  it('stays signed in when the server cannot be reached, and says so', async () => {
    const { auth } = await signedIn()
    server().on('POST', '/api/admin/auth/logout', offline)

    await expect(act(() => auth().logout())).rejects.toMatchObject({ status: 0 })

    expect(auth().session).toEqual(OWNER)
  })
})

describe('the session’s hooks', () => {
  it('refuse to work outside the provider', () => {
    const { wrapper } = providers()
    expect(() => render(<Lost />, { wrapper })).toThrow('useAuth must be used inside <AuthProvider>')
  })
})
