import { keys, queryClient } from '@/apps/app/lib/query'
import { needsRecentSignIn, store } from '@/apps/app/lib/store'
import { biometrics, unlock } from '@/apps/app/telegram/biometric'
import { telegram } from '@/apps/app/telegram/webapp'
import { ApiError } from '@/lib/api'
import type { StoreAccountResponse } from '@/lib/api-types'

/*
 * The recent sign-in what gives a service or money away, or changes how the account signs in, asks of the session (a
 * 403 that says so): the customer proves it is them again without leaving the app — by their fingerprint or face, when
 * this session keeps a secret behind it (`PUT /me/biometric`, set from the account screen), else by the launch data
 * while it is fresh (Telegram made it when the app opened; the shop takes it for 15 minutes). Past both, only opening
 * the app again proves it: RecentSignInNeeded says so in the customer's words. A proof that got no answer, was asked too
 * often, or met the shop's own failure, is that failure: the screen says it in its usual words.
 */

/** No proof could be made from here: the customer opens the app again (or sets up their fingerprint). */
class RecentSignInNeeded extends Error {
  constructor() {
    super('برای امنیت حساب، مینی‌اپ را ببندید و دوباره از ربات باز کنید؛ یا تایید با اثر انگشت را در «حساب من › امنیت» روشن کنید.')
    this.name = 'RecentSignInNeeded'
  }
}

/** A failure no proof is to blame for: no answer came (the network), the shop asks to wait (429), or it failed (5xx). */
function unheard(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 0 || error.status === 429 || error.status >= 500)
}

/** The secret the fingerprint or face hands back; null when there is none, or Telegram would not ask. */
async function fingerprintSecret(reason: string): Promise<string | null> {
  try {
    const manager = await biometrics()
    return manager === null ? null : await unlock(manager, reason)
  } catch {
    return null
  }
}

/** Prove a way in again, the fingerprint first; throws RecentSignInNeeded when neither way can. */
async function proveRecent(reason: string): Promise<void> {
  const account = queryClient.getQueryData<StoreAccountResponse>(keys.me) ?? (await store.get<StoreAccountResponse>('/me'))

  const secret = account.session.biometric ? await fingerprintSecret(reason) : null
  if (secret !== null) {
    try {
      await store.post('/me/reauthenticate', { method: 'biometric', token: secret })
      void queryClient.invalidateQueries({ queryKey: keys.me })
      return
    } catch (error) {
      if (unheard(error)) throw error
      // A secret of another session (the app signed in afresh since): the launch data may still do.
    }
  }

  const app = telegram()
  if (app !== null) {
    try {
      await store.post('/me/reauthenticate', { method: 'mini_app', init_data: app.initData })
      void queryClient.invalidateQueries({ queryKey: keys.me })
      return
    } catch (error) {
      if (unheard(error)) throw error
      // Older than a recent sign-in's minutes.
    }
  }

  throw new RecentSignInNeeded()
}

/**
 * Run a request that may ask a recent sign-in: refused for that, the customer proves it (`reason` is what Telegram's
 * fingerprint prompt says) and it runs once more.
 */
export async function withRecentSignIn<T>(reason: string, run: () => Promise<T>): Promise<T> {
  try {
    return await run()
  } catch (error) {
    if (!needsRecentSignIn(error)) throw error
    await proveRecent(reason)

    return run()
  }
}
