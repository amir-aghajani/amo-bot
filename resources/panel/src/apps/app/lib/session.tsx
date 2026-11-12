import { createContext, use, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { hashKey, useQuery } from '@tanstack/react-query'
import { storeKey } from '@/apps/app/config'
import { keys, onSessionEnded, queryClient } from '@/apps/app/lib/query'
import { problemOf, setBearer, signedOut, store } from '@/apps/app/lib/store'
import { previewToken, telegram } from '@/apps/app/telegram/webapp'
import { ApiError } from '@/lib/api'
import type { StoreAccountResponse, StoreResponse } from '@/lib/api-types'

/*
 * Who the customer is. Inside Telegram the launch data Telegram handed the app signs them in (`POST /auth/mini-app`):
 * the bot's own customer, with their services and wallet. The bearer token is kept in this device's storage under the
 * shop's key and the Telegram account's id — a phone signed in to two Telegram accounts keeps one token for each —, so
 * the next launch goes on with the same session (and its fingerprint secret, lib/recent) instead of opening another;
 * a kept token another account's, or ended since, is let go and the launch data signs in afresh. A request answered
 * "your session ended" later does the same. A panel's preview (telegram/webapp previewToken) is signed in already: its
 * token is the session, for this page alone — never kept, never traded for another —, and once it ended (its 30
 * minutes), only the panel opens another.
 */

export type Session =
  /** Signing in. */
  | { state: 'loading' }
  /** Opened outside Telegram (a browser): no launch data to sign in with. */
  | { state: 'outside' }
  /** The sign-in was refused or failed: the customer's words for why, and whether trying again may help. */
  | { state: 'failed'; message: string; retry: boolean }
  /** Signed in — and stays so while a session that ended is traded for a new one beneath the screen. */
  | { state: 'signed-in' }

interface SessionContext {
  session: Session
  /** Sign in again (after a failure). */
  retry: () => void
}

const Context = createContext<SessionContext | null>(null)

/** What the app says when the shop's mini app is switched off (the API's 404 speaks of a website). */
const CLOSED = 'مینی‌اپ این فروشگاه فعلا خاموش است؛ کمی بعد دوباره از ربات باز کنید.'

/** What a panel's preview says once its session ended. */
const PREVIEW_ENDED = 'پیش‌نمایش تمام شده است؛ آن را در پنل دوباره باز کنید.'

/** Where this Telegram account's token is kept on this device for this shop. */
function tokenKey(telegramId: number): string {
  return `amobot-app:${storeKey ?? ''}:${telegramId}`
}

function keptToken(key: string): string | null {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

function keepToken(key: string, token: string | null): void {
  try {
    if (token === null) localStorage.removeItem(key)
    else localStorage.setItem(key, token)
  } catch {
    // Storage refused (a private window): the session lasts as long as the page.
  }
}

/** The invite code a referral link opened the app with (`?startapp=ref_<code>`), as the bot's own links carry it. */
function referralCode(): string | undefined {
  const start = telegram()?.initDataUnsafe.start_param ?? ''

  return start.startsWith('ref_') && start.length > 4 ? start.slice(4) : undefined
}

/** Sign in: a preview's own token; else the kept token while it opens this account still, else the launch data. */
async function signIn(): Promise<void> {
  const preview = previewToken()
  if (preview !== null) {
    setBearer(preview)
    queryClient.setQueryData(keys.me, await store.get<StoreAccountResponse>('/me'))
    return
  }

  const app = telegram()
  const telegramId = app?.initDataUnsafe.user?.id
  if (app === null || telegramId === undefined) throw new Error('outside Telegram')
  const key = tokenKey(telegramId)

  const kept = keptToken(key)
  if (kept !== null) {
    setBearer(kept)
    try {
      const account = await store.get<StoreAccountResponse>('/me')
      if (account.customer.telegram?.id === telegramId) {
        queryClient.setQueryData(keys.me, account)
        return
      }
    } catch (error) {
      if (!signedOut(error)) throw error
    }
    keepToken(key, null)
  }

  setBearer(null)
  const referral = referralCode()
  const { token } = await store.post('/auth/mini-app', { init_data: app.initData, ...(referral === undefined ? {} : { referral_code: referral }) })
  keepToken(key, token)
  setBearer(token)
  queryClient.setQueryData(keys.me, await store.get<StoreAccountResponse>('/me'))
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<Session>(() => (telegram() === null ? { state: 'outside' } : { state: 'loading' }))
  const running = useRef(false)

  const start = useCallback(() => {
    if (telegram() === null || running.current) return
    running.current = true
    // Only the first sign-in is the app's loading view: signed in again under a screen, the screen stays as it is (a draft,
    // a picture picked, a gateway's page watched) and reads again once the new session is in.
    setSession((now) => (now.state === 'signed-in' ? now : { state: 'loading' }))
    signIn()
      .then(() => {
        setSession((now) => (now.state === 'signed-in' ? now : { state: 'signed-in' }))
        // Every read again but the account, just read: its sessions too (`['me', 'sessions']`), which name this one.
        void queryClient.invalidateQueries({ predicate: (query) => query.queryHash !== hashKey(keys.me) })
      })
      .catch((error: unknown) => {
        // A preview's 401 is its session ended: only the panel opens another. Any other refusal is the shop's to word (no
        // launch to send anyone back to); trying again helps only where no answer came, or the shop failed.
        if (previewToken() !== null) {
          const status = error instanceof ApiError ? error.status : 0
          setSession(status === 401 ? { state: 'failed', message: PREVIEW_ENDED, retry: false } : { state: 'failed', message: problemOf(error), retry: status === 0 || status >= 500 })
          return
        }
        // A 401 here is the launch data refused (another bot's, or older than a day): only a new launch helps. A 404 is
        // the shop's key opening nothing now: its mini app switched off.
        const closed = error instanceof ApiError && error.status === 404
        const final = error instanceof ApiError && (error.status === 401 || error.status === 403)
        setSession({ state: 'failed', message: closed ? CLOSED : problemOf(error), retry: !final && !closed })
      })
      .finally(() => {
        running.current = false
      })
  }, [])

  useEffect(() => {
    start()
    return onSessionEnded(start)
  }, [start])

  const value = useMemo(() => ({ session, retry: start }), [session, start])

  return <Context value={value}>{children}</Context>
}

export function useSession(): SessionContext {
  const context = use(Context)
  if (context === null) throw new Error('useSession() outside <SessionProvider>')

  return context
}

/** The signed-in customer's account (`GET /me`): names, ways in, balance, loyalty, this session. */
export function useAccount() {
  return useQuery({ queryKey: keys.me, queryFn: () => store.get<StoreAccountResponse>('/me') })
}

/** The shop as the mini app introduces it (`GET /`): its name, bot, programs, look and announcement. Public. */
export function useShop() {
  return useQuery({ queryKey: keys.shop, queryFn: () => store.get<StoreResponse>(''), staleTime: 5 * 60_000 })
}
