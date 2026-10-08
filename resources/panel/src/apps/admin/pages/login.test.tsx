import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, type InitialEntry } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LoginPage } from '@/apps/admin/pages/login'
import type { Session } from '@/lib/api-types'
import { AuthProvider } from '@/lib/auth'
import { testQueryClient, until } from '@/test/render'
import { json, refusal, server, withHeaders } from '@/test/server'

/*
 * The owner's sign-in (apps/admin/pages/login): the login config.php keeps; signed in, the panel goes back to the page
 * the guard sent the owner from — its query string too —, never to the login page itself; a refusal stays on the form,
 * and the throttle's wait counts down there, the form held meanwhile.
 */

const OWNER: Session = { name: 'owner', shop: { id: 1, name: 'AmoBot', username: 'amobot', status: 'active' } }

function Address() {
  const { pathname, search } = useLocation()
  return <p data-testid="address">{pathname + search}</p>
}

function open(at: InitialEntry) {
  render(
    <QueryClientProvider client={testQueryClient()}>
      <MemoryRouter initialEntries={[at]}>
        <AuthProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="*" element={<Address />} />
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The form filled in and sent. */
function signIn(username: string, password: string) {
  fireEvent.change(screen.getByLabelText('نام کاربری'), { target: { value: username } })
  fireEvent.change(screen.getByLabelText('رمز عبور'), { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: 'ورود' }))
}

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'AmoBot', installed: true }))
    .on('GET', '/api/admin/auth/me', refusal(401, 'ابتدا وارد شوید.'))
})

describe('signing in', () => {
  it('goes back to the page the guard came from, its query string too', async () => {
    server().on('POST', '/api/admin/auth/login', json({ session: OWNER }))
    open({ pathname: '/login', state: { from: '/orders?status=failed', expired: false } })
    await screen.findByLabelText('نام کاربری')

    signIn('owner', 'a long passphrase')

    await until(() => expect(screen.getByTestId('address').textContent).toBe('/orders?status=failed'))
    expect(server().sent('POST', '/api/admin/auth/login')[0]?.body).toEqual({ username: 'owner', password: 'a long passphrase' })
  })

  it('names the shop the tab shows: the right login at the address of a shop that is not there opens nothing, and says where to go', async () => {
    const missing = 'این فروشگاه پیدا نشد؛ شاید آدرسی که باز کردید درست نیست.'
    server().on('POST', '/api/admin/auth/login', refusal(404, missing, { shop: [missing] }))
    open({ pathname: '/login', state: { from: '/orders' } })
    await screen.findByLabelText('نام کاربری')

    signIn('owner', 'a long passphrase')

    expect(await screen.findByRole('heading', { name: 'این فروشگاه پیدا نشد' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'رفتن به فروشگاه اصلی' }).getAttribute('href')).toBe('/admin/')
    expect(server().sent('POST', '/api/admin/auth/login')[0]?.headers['x-shop']).toBe('1')
  })

  it('never goes back to the login page itself', async () => {
    server().on('POST', '/api/admin/auth/login', json({ session: OWNER }))
    open({ pathname: '/login', state: { from: '/login' } })
    await screen.findByLabelText('نام کاربری')

    signIn('owner', 'a long passphrase')

    await until(() => expect(screen.getByTestId('address').textContent).toBe('/'))
  })

  it('stays on the form when refused, the API’s words under the field', async () => {
    server().on('POST', '/api/admin/auth/login', refusal(422, 'نام کاربری یا رمز عبور درست نیست.', { password: ['نام کاربری یا رمز عبور درست نیست.'] }))
    open('/login')
    await screen.findByLabelText('نام کاربری')

    signIn('owner', 'wrong')

    expect(await screen.findByText('نام کاربری یا رمز عبور درست نیست.')).toBeTruthy()
    expect(screen.getByLabelText('رمز عبور').getAttribute('aria-invalid')).toBe('true')
    expect(screen.queryByTestId('address')).toBeNull()
  })

  it('says the throttle’s refusal above the form', async () => {
    server().on('POST', '/api/admin/auth/login', refusal(429, 'تلاش‌های ناموفق زیاد شد؛ چند دقیقه دیگر دوباره امتحان کنید.'))
    open('/login')
    await screen.findByLabelText('نام کاربری')

    signIn('owner', 'wrong')

    expect((await screen.findByRole('alert')).textContent).toBe('تلاش‌های ناموفق زیاد شد؛ چند دقیقه دیگر دوباره امتحان کنید.')
  })

  it('counts the throttle’s wait down, the form held until it is over', async () => {
    server().on('POST', '/api/admin/auth/login', withHeaders(refusal(429, 'تلاش‌های ناموفق زیاد بود؛ ۲ دقیقه دیگر دوباره امتحان کنید.'), { 'Retry-After': '90' }))
    open('/login')
    await screen.findByLabelText('نام کاربری')
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })

    signIn('owner', 'wrong')

    await until(() => expect(screen.getByRole('timer').textContent).toBe('می‌توانید ۱:۳۰ دیگر دوباره امتحان کنید.'))
    expect(screen.getByRole('alert').textContent).toContain('تلاش‌های ناموفق زیاد بود؛ ۲ دقیقه دیگر دوباره امتحان کنید.')
    const submit = screen.getByRole('button', { name: 'ورود' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')

    await act(() => vi.advanceTimersByTimeAsync(90_000))
    expect(screen.getByRole('timer').textContent).toBe('حالا می‌توانید دوباره امتحان کنید.')
    expect(submit.getAttribute('aria-disabled')).toBeNull()
  })
})

describe('the login page', () => {
  it('says the session ended when the guard sent the owner here for that', async () => {
    open({ pathname: '/login', state: { from: '/orders', expired: true } })

    expect(await screen.findByText('زمان ورود شما تمام شد؛ دوباره وارد شوید تا به همان صفحه برگردید.')).toBeTruthy()
  })

  it('sends an owner who is signed in already to the panel', async () => {
    server().on('GET', '/api/admin/auth/me', json({ session: OWNER }))
    open('/login')

    await until(() => expect(screen.getByTestId('address').textContent).toBe('/'))
  })

  it('names no file of the host to whoever opens it — a lost password has its way back in, no shell needed', async () => {
    open('/login')
    await screen.findByLabelText('نام کاربری')

    expect(document.body.textContent).not.toContain('config.php')
    expect(document.body.textContent).not.toContain('bin/console')
    fireEvent.click(screen.getByRole('link', { name: 'رمز را فراموش کرده‌اید؟' }))
    expect(screen.getByTestId('address').textContent).toBe('/recover')
  })

  it('tells agents where their own way in is', async () => {
    open('/login')
    await screen.findByLabelText('نام کاربری')

    expect(screen.getByText(/نماینده‌ها از ربات/)).toBeTruthy()
  })
})
