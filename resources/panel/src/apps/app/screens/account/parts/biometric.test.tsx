import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useAccount } from '@/apps/app/lib/session'
import { BiometricSection } from '@/apps/app/screens/account/parts/biometric'
import type { BiometricManager } from '@/apps/app/telegram/webapp'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import { until } from '@/test/render'
import { json, noContent, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «تایید با اثر انگشت» (screens/account/parts/biometric) once its permission was taken back in Telegram's settings:
 * switched off, the shop forgets the secret and the switch is off, though Telegram's script throws instead of letting
 * go of its copy.
 */

/** A fingerprint that keeps a secret, its permission taken back: Telegram's script throws at every call but init. */
function revoked() {
  const device: BiometricManager = {
    isInited: true,
    isBiometricAvailable: true,
    biometricType: 'finger',
    isAccessRequested: true,
    isAccessGranted: false,
    isBiometricTokenSaved: true,
    init: (callback) => {
      callback?.()
      return device
    },
    requestAccess: (_params, callback) => {
      callback?.(false)
      return device
    },
    authenticate: () => {
      throw new Error('WebAppBiometricManagerBiometricsAccessNotGranted')
    },
    updateBiometricToken: () => {
      throw new Error('WebAppBiometricManagerBiometricsAccessNotGranted')
    },
    openSettings: () => device,
  }

  return device
}

/** The section as the security screen draws it: on while the account says the session keeps a secret. */
function Section() {
  const { data } = useAccount()

  return data === undefined ? null : <BiometricSection held={data.session.biometric} />
}

describe('the fingerprint switch', () => {
  it('switches off once the shop forgot the secret, though Telegram may not let go of its copy', async () => {
    app.telegram({ BiometricManager: revoked() })
    server().on('DELETE', `${API}/me/biometric`, noContent).on('GET', `${API}/me`, json(app.account()))
    render(app.screen(<Section />, { at: '/account/security', account: app.account({ session: { method: 'mini_app', biometric: true, recent_until: null } }) }))

    const toggle = await screen.findByRole('switch', { name: /^تایید با اثر انگشت/ })
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)

    await until(() => expect(screen.getByRole('switch', { name: /^تایید با اثر انگشت/ }).getAttribute('aria-checked')).toBe('false'))
    expect(server().sent('DELETE', `${API}/me/biometric`)).toHaveLength(1)
    expect(screen.getByText('خاموش')).toBeTruthy()
  })
})
