import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import SecurityScreen from '@/apps/app/screens/account/security'
import type { BiometricManager } from '@/apps/app/telegram/webapp'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse, StoreBiometricResponse, StoreMeResponse, StoreReauthenticatedResponse, StoreSessionsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, noContent, refusal, server, withHeaders, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «امنیت» (screens/account/security): the fingerprint switched on — a recent sign-in proven with the launch data first
 * when the shop asks one —, its secret kept by Telegram's BiometricManager; switched off, forgotten by both; refused by
 * Telegram, its settings offered; the password of the account's email; the devices, another one ended.
 */

const SESSIONS: StoreSessionsResponse = {
  sessions: [
    { id: 77, device: 'تلگرام در Android', ip: '203.0.113.7', created_at: '2026-10-27T12:00:00+00:00', last_used_at: '2026-10-27T12:30:00+00:00', current: true },
    { id: 61, device: 'Safari در iOS', ip: '198.51.100.23', created_at: '2026-10-20T18:12:44+00:00', last_used_at: '2026-10-26T21:05:10+00:00', current: false },
  ],
}

/** Telegram's BiometricManager on a phone with a fingerprint: it grants access as `grants` says and keeps one token. */
function fingerprint({ grants = true, token = '' }: { grants?: boolean; token?: string } = {}) {
  const device: BiometricManager & { token: string } = {
    token,
    isInited: true,
    isBiometricAvailable: true,
    biometricType: 'finger',
    isAccessRequested: false,
    isAccessGranted: false,
    isBiometricTokenSaved: token !== '',
    init: (callback) => {
      callback?.()
      return device
    },
    requestAccess: (_params, callback) => {
      device.isAccessRequested = true
      device.isAccessGranted = grants
      callback?.(grants)
      return device
    },
    authenticate: (_params, callback) => {
      callback?.(device.token !== '', device.token)
      return device
    },
    updateBiometricToken: (next, callback) => {
      device.token = next
      device.isBiometricTokenSaved = next !== ''
      callback?.(true)
      return device
    },
    openSettings: vi.fn(() => device),
  }

  return device
}

function open(account: StoreAccountResponse = app.account(), device: BiometricManager | undefined = undefined) {
  const telegram = app.telegram(device === undefined ? {} : { BiometricManager: device })
  server().on('GET', `${API}/me/sessions`, json(SESSIONS))
  render(app.screen(<SecurityScreen />, { at: '/account/security', account }))
  return telegram
}

describe('the security screen', () => {
  it('switches the fingerprint on: proves a recent sign-in with the launch data, then Telegram keeps the secret', async () => {
    const device = fingerprint()
    const answers: Answer[] = [
      withHeaders(refusal(403, 'برای این کار دوباره وارد شوید.'), { 'WWW-Authenticate': 'Bearer error="insufficient_user_authentication", max_age=900' }),
      json({ token: 'secret-1' } satisfies StoreBiometricResponse),
    ]
    server()
      .on('PUT', `${API}/me/biometric`, () => answers.shift() ?? json({ token: 'secret-2' }))
      .on('POST', `${API}/me/reauthenticate`, json({ expires_in: 900 } satisfies StoreReauthenticatedResponse))
      .on('GET', `${API}/me`, json(app.account({ session: { method: 'mini_app', biometric: true, recent_until: null } })))
    const { app: telegram } = open(app.account(), device)

    const toggle = await screen.findByRole('switch', { name: /^تایید با اثر انگشت/ })
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    fireEvent.click(toggle)

    await until(() => expect(device.token).toBe('secret-1'))
    expect(server().sent('PUT', `${API}/me/biometric`)).toHaveLength(2)
    expect(server().sent('POST', `${API}/me/reauthenticate`)[0]?.body).toEqual({ method: 'mini_app', init_data: telegram.initData })
    await until(() => expect(screen.getByRole('switch', { name: /^تایید با اثر انگشت/ }).getAttribute('aria-checked')).toBe('true'))
  })

  it('switches it off: the shop forgets the secret and Telegram lets go of it', async () => {
    const device = fingerprint({ token: 'secret-1' })
    server().on('DELETE', `${API}/me/biometric`, noContent).on('GET', `${API}/me`, json(app.account()))
    open(app.account({ session: { method: 'mini_app', biometric: true, recent_until: null } }), device)

    const toggle = await screen.findByRole('switch', { name: /^تایید با اثر انگشت/ })
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)

    await until(() => expect(device.token).toBe(''))
    expect(server().sent('DELETE', `${API}/me/biometric`)).toHaveLength(1)
    await until(() => expect(screen.getByRole('switch', { name: /^تایید با اثر انگشت/ }).getAttribute('aria-checked')).toBe('false'))
  })

  it("offers Telegram's settings when it may not use the fingerprint, and asks the shop for nothing", async () => {
    const device = fingerprint({ grants: false })
    open(app.account(), device)

    fireEvent.click(await screen.findByRole('switch', { name: /^تایید با اثر انگشت/ }))
    fireEvent.click(await screen.findByRole('button', { name: 'تنظیمات اثر انگشت' }))
    expect(device.openSettings).toHaveBeenCalledTimes(1)
    expect(server().sent('PUT', `${API}/me/biometric`)).toHaveLength(0)
  })

  it('offers no fingerprint on a device without one', async () => {
    open()

    expect(await screen.findByText('Safari در iOS')).toBeTruthy()
    expect(screen.queryByRole('switch')).toBeNull()
  })

  it('ends another device, after asking', async () => {
    const { app: telegram } = open()
    const confirm = vi.spyOn(telegram, 'showConfirm')
    server().on('DELETE', `${API}/me/sessions/61`, noContent)

    const other = (await screen.findByText('Safari در iOS')).closest('li')!
    expect(within(screen.getByText('تلگرام در Android').closest('li')!).getByText('همین دستگاه')).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'خروج' })).toHaveLength(1)
    server().on('GET', `${API}/me/sessions`, json({ sessions: SESSIONS.sessions.slice(0, 1) }))
    fireEvent.click(within(other).getByRole('button', { name: 'خروج' }))

    await until(() => expect(screen.queryByText('Safari در iOS')).toBeNull())
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(server().sent('DELETE', `${API}/me/sessions/61`)).toHaveLength(1)
  })

  it('changes the password of the email, with the current one', async () => {
    const withEmail = app.account()
    const customer = { ...withEmail.customer, email: 'ali@example.com', has_password: true }
    server().on('PUT', `${API}/me/password`, json({ customer } satisfies StoreMeResponse))
    open({ ...withEmail, customer })

    fireEvent.click(screen.getByRole('button', { name: /تغییر رمز عبور/ }))
    fireEvent.change(await screen.findByLabelText('رمز عبور فعلی'), { target: { value: 'old-secret-1' } })
    fireEvent.change(screen.getByLabelText('رمز عبور تازه'), { target: { value: 'new-secret-22' } })
    fireEvent.click(screen.getByRole('button', { name: 'تغییر رمز' }))

    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(server().sent('PUT', `${API}/me/password`)[0]?.body).toEqual({ current_password: 'old-secret-1', password: 'new-secret-22' })
  })

  it('offers adding an email before any password', () => {
    open()

    expect(screen.getByRole('link', { name: /افزودن ایمیل/ }).getAttribute('href')).toBe('/account/email')
    expect(screen.queryByRole('button', { name: /رمز عبور/ })).toBeNull()
  })
})
