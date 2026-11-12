import { describe, expect, it, vi } from 'vitest'
import { biometrics, keepToken, requestAccess, unlock } from '@/apps/app/telegram/biometric'
import type { BiometricManager } from '@/apps/app/telegram/webapp'
import * as app from '@/apps/app/test/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The fingerprint's calls (telegram/biometric) as Telegram's script makes them: where it will not ask — its permission
 * taken back in Telegram's settings, a prompt already open — the script throws, and each call answers no instead of
 * failing.
 */

/** Telegram's BiometricManager on a phone with a fingerprint, throwing as its script does: no access, or a prompt open. */
function fingerprint({ granted = true, prompting = false } = {}) {
  const refuse = (reason: string): never => {
    throw new Error(reason)
  }
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
      if (prompting) refuse('WebAppBiometricManagerAccessAlreadyRequested')
      callback?.(granted)
      return device
    },
    authenticate: (_params, callback) => {
      if (!granted) refuse('WebAppBiometricManagerBiometricsAccessNotGranted')
      if (prompting) refuse('WebAppBiometricManagerAuthenticationAlreadyRequested')
      callback?.(true, 'secret-1')
      return device
    },
    updateBiometricToken: (_token, callback) => {
      if (!granted) refuse('WebAppBiometricManagerBiometricsAccessNotGranted')
      callback?.(true)
      return device
    },
    openSettings: () => device,
  }

  return device
}

describe("the fingerprint's calls", () => {
  it('hand back the secret, keep a token and are let in while Telegram may ask', async () => {
    const device = fingerprint()

    await expect(unlock(device, 'تایید')).resolves.toBe('secret-1')
    await expect(keepToken(device, '')).resolves.toBe(true)
  })

  it('answer no when its permission was taken back in Telegram, rather than failing', async () => {
    const device = fingerprint({ granted: false })

    await expect(unlock(device, 'تایید')).resolves.toBeNull()
    await expect(keepToken(device, '')).resolves.toBe(false)
  })

  it('answer no while a prompt of its own is open', async () => {
    const device = fingerprint({ prompting: true })

    await expect(unlock(device, 'تایید')).resolves.toBeNull()
    device.isAccessGranted = false
    await expect(requestAccess(device, 'تایید')).resolves.toBe(false)
  })

  it('offer none when Telegram fails to set its manager up', async () => {
    const device = fingerprint()
    device.isInited = false
    device.isBiometricAvailable = false
    device.init = () => {
      throw new Error('WebAppBiometricManagerInitFailed')
    }
    app.telegram({ BiometricManager: device })

    await expect(biometrics()).resolves.toBeNull()
  })
})
