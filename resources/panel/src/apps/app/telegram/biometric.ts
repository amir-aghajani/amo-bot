import { telegram, type BiometricManager } from '@/apps/app/telegram/webapp'

/*
 * The device's fingerprint or face through Telegram's BiometricManager (Bot API 7.2). The shop gives the session a
 * secret (`PUT /me/biometric`), Telegram keeps it in the device's secure storage and hands it back only once the
 * fingerprint or face matches (`authenticate`); the shop takes it back as a recent sign-in (`POST /me/reauthenticate`,
 * `biometric`). Every call answers a promise that never fails: Telegram's script throws where it will not ask (its
 * permission taken back in Telegram's settings, a prompt already open), and that answers as a no. Outside Telegram, or
 * on a device without it, none of it is offered.
 */

/** Telegram's call, answered through its callback — or `no` when the call throws instead. */
function asked<T>(call: (answer: (value: T) => void) => void, no: T): Promise<T> {
  return new Promise((resolve) => {
    try {
      call(resolve)
    } catch {
      resolve(no)
    }
  })
}

/** Telegram's biometric manager, set up — null when this client or device has none. */
export async function biometrics(): Promise<BiometricManager | null> {
  const app = telegram()
  if (app === null || !app.isVersionAtLeast('7.2') || app.BiometricManager === undefined) return null
  const manager = app.BiometricManager
  if (!manager.isInited) await asked<void>((answer) => manager.init(() => answer()), undefined)

  return manager.isBiometricAvailable ? manager : null
}

/** The words for the device's way: «اثر انگشت» or «تشخیص چهره». */
export function biometricName(manager: BiometricManager): string {
  return manager.biometricType === 'face' ? 'تشخیص چهره' : 'اثر انگشت'
}

/** Ask the user once to let the app use their fingerprint or face (Telegram remembers the answer). */
export function requestAccess(manager: BiometricManager, reason: string): Promise<boolean> {
  if (manager.isAccessGranted) return Promise.resolve(true)

  return asked((answer) => manager.requestAccess({ reason }, (granted) => answer(granted)), false)
}

/** Keep `token` behind the fingerprint or face ('' forgets it); whether Telegram kept it (or let it go). */
export function keepToken(manager: BiometricManager, token: string): Promise<boolean> {
  return asked((answer) => manager.updateBiometricToken(token, (updated) => answer(updated)), false)
}

/** The kept secret, once the fingerprint or face matched; null when it did not, none is kept, or Telegram may not ask. */
export function unlock(manager: BiometricManager, reason: string): Promise<string | null> {
  if (!manager.isBiometricTokenSaved) return Promise.resolve(null)

  return asked<string | null>((answer) => manager.authenticate({ reason }, (success, token) => answer(success && token ? token : null)), null)
}
