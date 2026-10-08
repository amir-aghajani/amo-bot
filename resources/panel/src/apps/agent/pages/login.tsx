import { useEffect, useRef, useState } from 'react'
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
import { useAuth } from '@/lib/auth'
import { isolate } from '@/lib/direction'
import { useDocumentTitle } from '@/lib/document-title'
import { messageOf } from '@/lib/failure'
import { preloadPage } from '@/lib/prefetch'

/** What the guard that sent the agent here left in the history entry: the page they were on, whether their session ended. */
type Arrival = { from?: string; expired?: boolean } | null

/** The link is another agent's than the one signed in to this browser: refused unspent until the agent says to replace them (409 on `replace`). */
const anotherAgent = (failure: unknown) => failure instanceof ApiError && failure.status === 409 && failure.field('replace') !== undefined

/**
 * An agent's only way in: the one-time link their account in the main bot gives them («نمایندگی» ← «🔐 ورود به پنل»).
 * The link carries its code in the fragment (`#code=…`), which a browser never sends to a server; the page takes it off
 * the address bar at once and spends it once — after the panel's first question about its session has been answered,
 * so the two answers' session cookies cannot cross —, a fresh link opened in a tab already on this page as well. A link
 * of another agent's, opened where an agent is signed in (one sent them «my panel's link»), asks before it takes that
 * session's place; staying keeps it, and the panel it opens. Signed in, the panel goes back to the page the session ended
 * on — its query string too, as the owner's does —, never to this page. Without a link, or with one that no longer
 * works, the page says where to get a fresh one — and to an agent signed in already, whose session such a link leaves
 * as it was, the way back to their panel.
 */
export function LoginPage() {
  const { session, loading, enter } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const appName = useAppName()
  // Where signing in leads: back where the guard came from — kept while a fresh link lands in this tab, whose own
  // history entry carries nothing (state adjusted during render) —, never to this page.
  const arrival = location.state as Arrival
  const [from, setFrom] = useState(arrival?.from)
  if (arrival?.from !== undefined && arrival.from !== from) setFrom(arrival.from)
  const destination = from && from !== '/login' ? from : '/'
  // The link's code, read as a link lands — the first, or a fresh one opened in this tab while it shows this page (state
  // adjusted during render) —; the address bar loses it right away.
  const linked = new URLSearchParams(location.hash.slice(1)).get('code')
  const [code, setCode] = useState(linked)
  // The link's outcome: being checked, or refused — in words, and the failure they came of (a wait counts down).
  const [link, setLink] = useState<{ checking: boolean; error: string | null; failure?: unknown }>({ checking: linked !== null, error: null })
  // Another agent's link, waiting for the agent's word: replace the session open here, or keep it.
  const [asking, setAsking] = useState<{ code: string; signingIn: boolean; error: string | null } | null>(null)
  if (linked !== null && linked !== code) {
    setCode(linked)
    setLink({ checking: true, error: null })
    setAsking(null)
  }
  // A code works once: a second mount (StrictMode) must not spend a second request on it.
  const tried = useRef<string | null>(null)
  const expired = arrival?.expired === true

  useDocumentTitle('ورود به پنل نمایندگی')

  // The page signing in leads to loads while the link is checked.
  useEffect(() => preloadPage(destination), [destination])

  // The code leaves the address bar — and the history entry — before anything else.
  useEffect(() => {
    if (location.hash !== '') navigate({ pathname: location.pathname, search: location.search }, { replace: true, state: location.state })
  }, [location, navigate])

  useEffect(() => {
    if (code === null || loading || tried.current === code) return
    tried.current = code
    // A link that no longer works (a 401) refuses only itself: a session open in this browser stays.
    enter(() => api.post('/auth/link', { code }, { signIn: true }))
      .then(() => navigate(destination, { replace: true }))
      .catch((failure: unknown) => {
        if (!anotherAgent(failure)) {
          setLink({ checking: false, error: messageOf(failure), failure })
          return
        }
        setLink({ checking: false, error: null })
        setAsking({ code, signingIn: false, error: null })
      })
  }, [code, loading, enter, navigate, destination])

  if (link.checking) {
    return <AppLoading label="در حال ورود به پنل نمایندگی…" />
  }

  if (session && link.error === null && asking === null) {
    return <Navigate to={destination} replace />
  }

  /** The agent said to replace the session open here: the link signs in, in its place. */
  const replace = (question: { code: string }) => {
    setAsking({ code: question.code, signingIn: true, error: null })
    enter(() => api.post('/auth/link', { code: question.code, replace: true }, { signIn: true }))
      .then(() => navigate(destination, { replace: true }))
      .catch((failure: unknown) => setAsking({ code: question.code, signingIn: false, error: messageOf(failure) }))
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
