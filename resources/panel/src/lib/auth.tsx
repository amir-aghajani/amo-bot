import { createContext, useCallback, useContext, useEffect, useMemo, useReducer, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Navigate, Outlet, useLocation } from 'react-router'
import { AppLoading, MissingShop } from '@/components/app-status'
import { ErrorState } from '@/components/error-state'
import { api, ApiError, onUnauthorized, shopMissing } from '@/lib/api'
import type { Session, SessionResponse } from '@/lib/api-types'
import { useAppName } from '@/lib/app-info'
import { MAIN_SHOP } from '@/lib/config'
import { queryKeys } from '@/lib/query-keys'

/*
 * A panel's session, the same in both panels: who is signed in, in the shop the panel shows (`GET /auth/me` of the
 * panel's own API, which answers in the shop the tab's requests name — lib/config). How one signs in is the panel's own
 * — the owner's password, an agent's link — and goes through `enter()`.
 */

interface SessionState {
  /** Who is signed in, in the shop the panel shows; null while nobody is. */
  session: Session | null
  /** True until the first `/auth/me` has answered. */
  loading: boolean
  /** What came instead of a usable answer to that question (the network, the server down) — not "signed out"; else null. */
  failure: unknown
  /** The session ended while the panel was open (a 401 mid-work): the login page says so. */
  expired: boolean
}

type SessionEvent =
  | { type: 'asking' }
  | { type: 'answered'; session: Session | null }
  | { type: 'unreachable'; failure: unknown }
  /** Any 401: the session is gone — expired, when one was open. */
  | { type: 'refused' }
  | { type: 'entered'; session: Session }
  | { type: 'left' }

// Every event but `unreachable` is the server's answer, so whatever went unanswered before is over: a shop installed
// since the first question (its 503 then), a server back up.
function next(state: SessionState, event: SessionEvent): SessionState {
  switch (event.type) {
    case 'asking':
      return { ...state, loading: true, failure: null }
    case 'answered':
      return { ...state, loading: false, failure: null, session: event.session }
    case 'unreachable':
      return { ...state, loading: false, failure: event.failure }
    case 'refused':
      return { ...state, loading: false, failure: null, session: null, expired: state.session !== null }
    case 'entered':
      return { ...state, loading: false, failure: null, session: event.session, expired: false }
    case 'left':
      return { ...state, loading: false, failure: null, session: null, expired: false }
  }
}

interface AuthState extends SessionState {
  /** Ask who is signed in again (after a `failure`). */
  retry: () => void
  /** Open a session — a sign-in: nothing read under the last one stays. */
  enter: (open: () => Promise<SessionResponse>) => Promise<Session>
  /** The open session renewed in its shop (the owner's login changed): who is signed in, as the server answers it now — everything read stays. */
  renew: (change: () => Promise<SessionResponse>) => Promise<Session>
  /** End the session; refused by the server (it could not be reached), it stays open and the failure is thrown. */
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [state, dispatch] = useReducer(next, { session: null, loading: true, failure: null, expired: false })

  /** Who is signed in, asked of the server; only a 401 says nobody — anything else is the server or the network. */
  const ask = useCallback((cancelled: () => boolean = () => false) => {
    dispatch({ type: 'asking' })
    api.get<SessionResponse>('/auth/me').then(
      (data) => {
        if (!cancelled()) dispatch({ type: 'answered', session: data.session })
      },
      (error: unknown) => {
        if (!cancelled()) dispatch(error instanceof ApiError && error.status === 401 ? { type: 'answered', session: null } : { type: 'unreachable', failure: error })
      },
    )
  }, [])

  useEffect(() => {
    let cancelled = false
    ask(() => cancelled)
    return () => {
      cancelled = true
    }
  }, [ask])

  // Any 401 from the API means the session is gone: the login screen comes (saying so, when one was open).
  useEffect(() => onUnauthorized(() => dispatch({ type: 'refused' })), [])

  // Every list, count and form belongs to the session; the shop's name (/api/app) stays. What showed them goes with the
  // session — behind RequireAuth — and another shop is opened by loading the panel afresh (owner.ts), so nothing drawn
  // is left holding what is forgotten here.
  const forget = useCallback(() => queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== queryKeys.app[0] }), [queryClient])

  const enter = useCallback(
    async (open: () => Promise<SessionResponse>) => {
      const data = await open()
      forget()
      dispatch({ type: 'entered', session: data.session })
      return data.session
    },
    [forget],
  )

  const renew = useCallback(async (change: () => Promise<SessionResponse>) => {
    const data = await change()
    dispatch({ type: 'answered', session: data.session })
    return data.session
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch (error) {
      // Already gone (a 401) is signed out all the same.
      if (!(error instanceof ApiError && error.status === 401)) throw error
    }
    forget()
    dispatch({ type: 'left' })
  }, [forget])

  const value = useMemo(() => ({ ...state, retry: () => ask(), enter, renew, logout }), [state, ask, enter, renew, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}

/** The session of a screen behind `RequireAuth`, where there always is one. */
export function useSession(): Session {
  const { session } = useAuth()
  if (!session) throw new Error('useSession must be used behind <RequireAuth>')
  return session
}

/** Whether the shop the panel shows is the main bot's — the owner's own (behind `RequireAuth`). */
export function useMainShop(): boolean {
  return useSession().shop.id === MAIN_SHOP
}

/**
 * The name the panel goes by — its brand, the tab's: the shop it shows. An agent's shop goes by its own name (its bot's
 * title); the main bot's by the name the shop has (/api/app's — the session calls it «فروشگاه اصلی», which is what tells
 * it apart in the owner's shop picker, not its name), and so does the panel before anyone signs in.
 */
export function useShopName(): string {
  const { session } = useAuth()
  const appName = useAppName()
  return session && session.shop.id !== MAIN_SHOP ? session.shop.name : appName
}

/** Route guard: renders child routes for a signed-in panel, otherwise redirects to /login. */
export function RequireAuth() {
  const { session, loading, failure, retry, expired } = useAuth()
  const location = useLocation()

  if (loading) {
    return <AppLoading />
  }

  if (failure !== null) {
    return shopMissing(failure) ? <MissingShop failure={failure} /> : <ErrorState variant="screen" error={failure} onRetry={retry} />
  }

  if (!session) {
    // The whole address — a list's ?status= and ?search= too — so signing in again lands where the panel was.
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search, expired }} />
  }

  return <Outlet />
}
