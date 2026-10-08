import { QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RecoverPage } from '@/apps/admin/pages/recover'
import type { Session } from '@/lib/api-types'
import { AuthProvider } from '@/lib/auth'
import { testQueryClient, until } from '@/test/render'
import { json, refusal, server, withHeaders } from '@/test/server'

/*
 * The owner's way back in without a shell (apps/admin/pages/recover): the panel writes a one-time key to the host's
 * files and says where and until when; the key and a new login sign the owner in, and the panel opens; a wrong key is
 * said under its field, and the throttle's wait holds the form.
 */

const OWNER: Session = { name: 'boss', shop: { id: 1, name: 'AmoBot', username: 'amobot', status: 'active' } }

const WRONG_KEY = 'کلید بازیابی درست نیست یا وقتش گذشته است؛ متن فایل storage/recovery-key.txt را دوباره کپی کنید، یا کلید تازه بسازید.'

function Address() {
  const { pathname } = useLocation()
  return <p data-testid="address">{pathname}</p>
}

function open() {
  render(
    <QueryClientProvider client={testQueryClient()}>
      <MemoryRouter initialEntries={['/recover']}>
        <AuthProvider>
          <Routes>
            <Route path="/recover" element={<RecoverPage />} />
            <Route path="*" element={<Address />} />
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The form filled in and sent: the key off the host's files and the new login. */
function setLogin(key: string) {
  fireEvent.change(screen.getByLabelText('کلید بازیابی'), { target: { value: key } })
  fireEvent.change(screen.getByLabelText('نام کاربری'), { target: { value: 'boss' } })
  fireEvent.change(screen.getByLabelText('رمز عبور تازه'), { target: { value: 'a long passphrase' } })
  fireEvent.change(screen.getByLabelText('تکرار رمز عبور'), { target: { value: 'a long passphrase' } })
  fireEvent.click(screen.getByRole('button', { name: 'ذخیره و ورود' }))
}

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'AmoBot', installed: true }))
    .on('GET', '/api/admin/auth/me', refusal(401, 'ابتدا وارد شوید.'))
})

describe('the way back in', () => {
  it('has the key written to the host’s files and says where and until when', async () => {
    server().on('POST', '/api/admin/auth/recovery/key', json({ file: 'storage/recovery-key.txt', expires_at: '2026-10-07T12:30:00Z' }))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'ساختن کلید' }))

    await until(() => expect(screen.getByRole('status').textContent).toBe('کلید در فایل storage/recovery-key.txt نوشته شد و تا ساعت ۱۲:۳۰ کار می‌کند.'))
    expect(screen.getByText('storage/recovery-key.txt').getAttribute('dir')).toBe('ltr')
    expect(server().sent('POST', '/api/admin/auth/recovery/key')).toHaveLength(1)
  })

  it('sets the new login with the key and opens the panel', async () => {
    server().on('POST', '/api/admin/auth/recovery', json({ session: OWNER }))
    open()
    await screen.findByLabelText('کلید بازیابی')

    setLogin('ABCD-EFGH-JKMN-PQRS')

    await until(() => expect(screen.getByTestId('address').textContent).toBe('/'))
    expect(server().sent('POST', '/api/admin/auth/recovery')[0]?.body).toEqual({
      key: 'ABCD-EFGH-JKMN-PQRS',
      username: 'boss',
      password: 'a long passphrase',
      password_confirmation: 'a long passphrase',
    })
  })

  it('says a wrong key under its field and stays', async () => {
    server().on('POST', '/api/admin/auth/recovery', refusal(422, WRONG_KEY, { key: [WRONG_KEY] }))
    open()
    await screen.findByLabelText('کلید بازیابی')

    setLogin('AAAA-BBBB-CCCC-DDDD')

    expect(await screen.findByText(WRONG_KEY)).toBeTruthy()
    expect(screen.getByLabelText('کلید بازیابی').getAttribute('aria-invalid')).toBe('true')
    expect(screen.queryByTestId('address')).toBeNull()
  })

  it('holds the form while the throttle’s wait lasts', async () => {
    server().on('POST', '/api/admin/auth/recovery', withHeaders(refusal(429, 'تلاش‌های ناموفق زیاد بود؛ ۲ دقیقه دیگر دوباره امتحان کنید.'), { 'Retry-After': '90' }))
    open()
    await screen.findByLabelText('کلید بازیابی')
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })

    setLogin('AAAA-BBBB-CCCC-DDDD')

    await until(() => expect(screen.getByRole('timer').textContent).toBe('می‌توانید ۱:۳۰ دیگر دوباره امتحان کنید.'))
    const submit = screen.getByRole('button', { name: 'ذخیره و ورود' })
    expect(submit.getAttribute('aria-disabled')).toBe('true')

    await act(() => vi.advanceTimersByTimeAsync(90_000))
    expect(submit.getAttribute('aria-disabled')).toBeNull()
  })

  it('sends an owner who is signed in already to the panel', async () => {
    server().on('GET', '/api/admin/auth/me', json({ session: OWNER }))
    open()

    await until(() => expect(screen.getByTestId('address').textContent).toBe('/'))
  })

  it('leads back to the sign-in', async () => {
    open()

    fireEvent.click(await screen.findByRole('link', { name: 'بازگشت به صفحه ورود' }))

    expect(screen.getByTestId('address').textContent).toBe('/login')
  })
})
