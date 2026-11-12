import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { problemOf, setBearer, store } from '@/apps/app/lib/store'
import type { BiometricManager } from '@/apps/app/telegram/webapp'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreBiometricResponse, StoreReauthenticatedResponse } from '@/lib/api-types'
import { json, offline, refusal, server, withHeaders, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * A change that asks a recent sign-in (lib/recent), here `PUT /me/biometric`: proven by the fingerprint when it matches,
 * else by the launch data — also when Telegram may no longer use the fingerprint —, then run once more; past both, the
 * customer is told to open the app again. A proof that got no answer, or met the shop's failure, says that instead.
 */

const REOPEN = 'برای امنیت حساب، مینی‌اپ را ببندید و دوباره از ربات باز کنید؛ یا تایید با اثر انگشت را در «حساب من › امنیت» روشن کنید.'

/** The shop asks this session for a recent sign-in. */
const STALE = withHeaders(refusal(403, 'برای این کار دوباره وارد شوید.'), { 'WWW-Authenticate': 'Bearer error="insufficient_user_authentication", max_age=900' })

/** A fingerprint that matches — or, with its permission taken back in Telegram, whose script throws as it does then. */
function fingerprint({ granted = true } = {}) {
  const device: BiometricManager = {
    isInited: true,
    isBiometricAvailable: true,
    biometricType: 'finger',
    isAccessRequested: true,
    isAccessGranted: granted,
    isBiometricTokenSaved: true,
    init: (callback) => {
      callback?.()
      return device
    },
    requestAccess: (_params, callback) => {
      callback?.(granted)
      return device
    },
    authenticate: (_params, callback) => {
      if (!granted) throw new Error('WebAppBiometricManagerBiometricsAccessNotGranted')
      callback?.(true, 'secret-1')
      return device
    },
    updateBiometricToken: (_token, callback) => {
      if (!granted) throw new Error('WebAppBiometricManagerBiometricsAccessNotGranted')
      callback?.(true)
      return device
    },
    openSettings: () => device,
  }

  return device
}

/** The customer signed in inside Telegram, their session keeping a fingerprint secret or not; the change refused once as stale. */
function signedIn({ biometric = true, device = fingerprint() }: { biometric?: boolean; device?: BiometricManager } = {}) {
  const telegram = app.telegram({ BiometricManager: device })
  queryClient.clear()
  queryClient.setQueryData(keys.me, app.account({ session: { method: 'mini_app', biometric, recent_until: null } }))
  setBearer('a'.repeat(64))
  const answers: Answer[] = [STALE, json({ token: 'secret-2' } satisfies StoreBiometricResponse)]
  server().on('PUT', `${API}/me/biometric`, () => answers.shift() ?? STALE)

  return telegram
}

const change = () => withRecentSignIn('تایید', () => store.put('/me/biometric'))
const proofs = () =>
  server()
    .sent('POST', `${API}/me/reauthenticate`)
    .map((request) => request.body)

describe('a change that asks a recent sign-in', () => {
  it('is proven by the fingerprint, then runs again', async () => {
    signedIn()
    server().on('POST', `${API}/me/reauthenticate`, json({ expires_in: 900 } satisfies StoreReauthenticatedResponse))

    await expect(change()).resolves.toEqual({ token: 'secret-2' })
    expect(proofs()).toEqual([{ method: 'biometric', token: 'secret-1' }])
  })

  it('is proven by the launch data when Telegram may no longer use the fingerprint', async () => {
    const { app: telegram } = signedIn({ device: fingerprint({ granted: false }) })
    server().on('POST', `${API}/me/reauthenticate`, json({ expires_in: 900 } satisfies StoreReauthenticatedResponse))

    await expect(change()).resolves.toEqual({ token: 'secret-2' })
    expect(proofs()).toEqual([{ method: 'mini_app', init_data: telegram.initData }])
  })

  it('asks the customer to open the app again once the launch data is too old', async () => {
    signedIn({ biometric: false })
    server().on('POST', `${API}/me/reauthenticate`, refusal(422, 'اطلاعات ورود قدیمی است.', { init_data: ['اطلاعات ورود قدیمی است.'] }))

    const error = await change().catch((failure: unknown) => failure)
    expect(problemOf(error)).toBe(REOPEN)
  })

  it('says the connection failed when the proof got no answer, not to open the app again', async () => {
    signedIn({ biometric: false })
    server().on('POST', `${API}/me/reauthenticate`, offline)

    const error = await change().catch((failure: unknown) => failure)
    expect(error).toMatchObject({ status: 0 })
    expect(problemOf(error)).not.toBe(REOPEN)
    expect(server().sent('PUT', `${API}/me/biometric`)).toHaveLength(1)
  })

  it("says the shop failed when the proof met the shop's own failure", async () => {
    signedIn()
    server().on('POST', `${API}/me/reauthenticate`, refusal(500, 'خطای داخلی فروشگاه.'))

    const error = await change().catch((failure: unknown) => failure)
    expect(error).toMatchObject({ status: 500 })
    expect(proofs()).toEqual([{ method: 'biometric', token: 'secret-1' }])
  })
})
