import { useCallback, useEffect, useRef, useState } from 'react'
import { Info } from 'lucide-react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { AppLoading } from '@/components/app-status'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { FormError } from '@/components/form-footer'
import { PublicPanel, PublicScreen } from '@/components/public-screen'
import { TextLink } from '@/components/text-link'
import { api, ApiError } from '@/lib/api'
import { useAppName } from '@/lib/app-info'
import { afterSignIn, useAuth, type LoginArrival } from '@/lib/auth'
import { isolate } from '@/lib/direction'
import { useDocumentTitle } from '@/lib/document-title'
import { messageOf } from '@/lib/failure'
import { preloadPage } from '@/lib/prefetch'

/** The link belongs to another agent than the one signed in here: refused unspent until the agent says to replace them. */
const anotherAgent = (failure: unknown) => failure instanceof ApiError && failure.status === 409 && failure.field('replace') !== undefined

/**
 * An agent's only way in: the one-time link from «نمایندگی» ← «🔐 ورود به پنل» in the main bot. The code rides in the
 * address's fragment (`#code=…`), which no browser sends to a server; the page takes it off the address bar at once and
 * spends it once — after `/auth/me` has answered, so the two answers' session cookies cannot cross. A link opened where
 * another agent is signed in asks before replacing that session. Signed in, the panel returns to the page the session
 * ended on. Without a working link the page says where to get one.
 */
export function LoginPage() {
  const { session, loading, enter } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const appName = useAppName()
  // Where signing in leads. A fresh link opened in this tab carries no history state, so the last `from` is kept.
  const arrival = location.state as LoginArrival | null
  const [from, setFrom] = useState(arrival?.from)
  if (arrival?.from !== undefined && arrival.from !== from) setFrom(arrival.from)
  const destination = afterSignIn(from)
  // The link's code, as it lands — the first, or a fresh link opened while this page is on screen.
  const linked = new URLSearchParams(location.hash.slice(1)).get('code')
  const [code, setCode] = useState(linked)
  // The link's outcome: being checked, or refused (with the failure, whose wait may count down).
  const [link, setLink] = useState<{ checking: boolean; error: string | null; failure?: unknown }>({ checking: linked !== null, error: null })
  // Another agent's link, waiting for the agent's word: replace the session open here, or keep it.
  const [asking, setAsking] = useState<{ code: string; signingIn: boolean; error: string | null } | null>(null)
  if (linked !== null && linked !== code) {
    setCode(linked)
    setLink({ checking: true, error: null })
    setAsking(null)
  }
  // A code works once: StrictMode's second mount must not spend a second request on it.
  const tried = useRef<string | null>(null)
  const expired = arrival?.expired === true

  useDocumentTitle('ورود به پنل نمایندگی')

  // The page signing in leads to loads while the link is checked.
  useEffect(() => preloadPage(destination), [destination])

  // The code leaves the address bar — and the history entry — before anything else.
  useEffect(() => {
    if (location.hash !== '') navigate({ pathname: location.pathname, search: location.search }, { replace: true, state: location.state })
  }, [location, navigate])

  /** Spend the code (in place of another agent's session with `replace`), then go where signing in leads. */
  const signIn = useCallback(
    (linkCode: string, replace?: true) =>
      enter(() => api.post('/auth/link', replace ? { code: linkCode, replace } : { code: linkCode }, { signIn: true })).then(() => navigate(destination, { replace: true })),
    [enter, navigate, destination],
  )

  useEffect(() => {
    if (code === null || loading || tried.current === code) return
    tried.current = code
    // A link that no longer works (a 401) refuses only itself: a session open in this browser stays.
    signIn(code).catch((failure: unknown) => {
      if (anotherAgent(failure)) {
        setLink({ checking: false, error: null })
        setAsking({ code, signingIn: false, error: null })
      } else {
        setLink({ checking: false, error: messageOf(failure), failure })
      }
    })
  }, [code, loading, signIn])

  if (link.checking) {
    return <AppLoading label="در حال ورود به پنل نمایندگی…" />
  }

  if (session && link.error === null && asking === null) {
    return <Navigate to={destination} replace />
  }

  /** The agent agreed to replace the session open here. */
  const replace = (question: { code: string }) => {
    setAsking({ code: question.code, signingIn: true, error: null })
    signIn(question.code, true).catch((failure: unknown) => setAsking({ code: question.code, signingIn: false, error: messageOf(failure) }))
  }
  const open = session ? isolate(session.name) : ''

  return (
    <PublicScreen title={`پنل نمایندگی ${appName}`}>
      <div className="mb-4 grid gap-4 empty:hidden">
        <FormError message={link.error} failure={link.failure} />
        {session && asking === null && (
          <Callout tone="info" icon={Info}>
            هنوز با <bdi dir="ltr">{session.name}</bdi> وارد پنل هستید.{' '}
            <TextLink to="/" inline className="font-medium">
              رفتن به پنل
            </TextLink>
          </Callout>
        )}
        {expired && !link.error && (
          <Callout tone="info" icon={Info}>
            زمان ورود شما تمام شد؛ با لینک تازه‌ای از ربات دوباره وارد شوید.
          </Callout>
        )}
      </div>

      <PublicPanel>
        <div className="grid gap-3 text-body">
          <p>
            برای ورود، در ربات اصلی به «نمایندگی» بروید و <span className="font-medium">«🔐 ورود به پنل»</span> را بزنید.
          </p>
          <p className="text-muted-foreground">لینکی که می‌گیرید فقط یک بار و تا چند دقیقه کار می‌کند؛ آن را به کسی ندهید.</p>
        </div>
      </PublicPanel>

      <ConfirmModal
        open={asking !== null}
        onClose={() => {
          setAsking(null)
          setLink({ checking: false, error: null })
        }}
        title="ورود به پنل نمایندگی دیگر"
        description={`در این مرورگر با ${open} وارد پنل هستید و این لینک ورود مال پنل نمایندگی دیگری است.`}
        confirmLabel="ورود با این لینک"
        cancelLabel={`ماندن در پنل ${open}`}
        pending={asking?.signingIn ?? false}
        error={asking?.error}
        onConfirm={() => asking && replace(asking)}
      >
        با این لینک از پنل {open} خارج می‌شوید و هر چه از این به بعد در این مرورگر ثبت کنید در پنل دیگر ثبت می‌شود. اگر این لینک را کس دیگری برایتان فرستاده، با آن وارد نشوید.
      </ConfirmModal>
    </PublicScreen>
  )
}
