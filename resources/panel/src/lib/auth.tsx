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
 * A panel's session, shared by both panels: who is signed in, in the shop the tab's requests name (`GET /auth/me`).
 * How one signs in is each panel's own (the owner's password, an agent's link); both go through `enter()`.
 */

interface SessionState {
  /** Who is signed in, in the shop the panel shows; null while nobody is. */
  session: Session | null
  /** True until the first `/auth/me` has answered. */
  loading: boolean
  /** Why `/auth/me` got no usable answer (the network, the server down) — not "signed out"; else null. */
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

// Every event but `unreachable` is an answer of the server's, so it clears an earlier failure.
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
  /** Ask `/auth/me` again (after a `failure`). */
  retry: () => void
  /** Open a session (a sign-in): everything cached under the last one is dropped. */
  enter: (open: () => Promise<SessionResponse>) => Promise<Session>
  /** Take the renewed session the server answered (the owner's login changed); the cache stays. */
  renew: (change: () => Promise<SessionResponse>) => Promise<Session>
  /** Sign out; when the server cannot be reached the session stays open and the failure is thrown. */
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [state, dispatch] = useReducer(next, { session: null, loading: true, failure: null, expired: false })

  /** Ask who is signed in. Only a 401 means nobody; any other failure is the network's or the server's. */
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

  // Any 401 from the API ends the session: the login screen comes (saying so when one was open).
  useEffect(() => onUnauthorized(() => dispatch({ type: 'refused' })), [])

  // Everything read belongs to the session, except /api/app (the shop's name). The screens that showed it are behind
  // RequireAuth and go with the session, so nothing on screen keeps what is dropped here.
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

/** Whether the shop the panel shows is the main bot's (behind `RequireAuth`). */
export function useMainShop(): boolean {
  return useSession().shop.id === MAIN_SHOP
}

/**
 * The name the panel goes by — the brand and the tab's title: an agent's shop by its own name, the main shop (and the
 * panel before anyone signs in) by the shop's name from /api/app.
 */
export function useShopName(): string {
  const { session } = useAuth()
  const appName = useAppName()
  return session && session.shop.id !== MAIN_SHOP ? session.shop.name : appName
}

/** What `RequireAuth` leaves in the history entry when it sends a visitor to /login. */
export interface LoginArrival {
  /** The address they were on, its query too. */
  from?: string
  /** Their session ended while the panel was open. */
  expired?: boolean
}

/** Where a sign-in leads: back to the page the visitor came from, never to the login page itself. */
export function afterSignIn(from: string | undefined): string {
  return from && from !== '/login' ? from : '/'
}

/** Route guard: the child routes for a signed-in panel, else a redirect to /login (or the failure, with a retry). */
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
    const arrival: LoginArrival = { from: location.pathname + location.search, expired }
    return <Navigate to="/login" replace state={arrival} />
  }

  return <Outlet />
}
