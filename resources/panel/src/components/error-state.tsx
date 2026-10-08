import { Fragment, useId, useState, useSyncExternalStore, type ReactNode } from 'react'
import {
  ArrowRight,
  Ban,
  Bug,
  ChevronDown,
  CircleAlert,
  CloudOff,
  Construction,
  Copy,
  FileWarning,
  Hourglass,
  KeyRound,
  LayoutDashboard,
  LogIn,
  RefreshCw,
  RotateCw,
  SearchX,
  ServerCrash,
  ShieldX,
  Timer,
  Unplug,
  WifiOff,
  type LucideIcon,
} from 'lucide-react'
import { Link, useInRouterContext } from 'react-router'
import { Callout } from '@/components/callout'
import { Button } from '@/components/ui/button'
import { copyText } from '@/lib/clipboard'
import { routerBasename } from '@/lib/config'
import { describeFailure, FAILURE_KINDS, failureFacts, failureReport, notFoundReason, type Failure, type FailureKind } from '@/lib/failure'
import { formatCountdown } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * Every failure the panels show, drawn one way (lib/failure reads it): a whole screen outside the shell (`screen` — the
 * panel could not start, a sign-in page or the installer failed), a page in the shell (`page` — a page that failed to
 * draw, an address no page has), a card in place of what a read would have shown (`card`, the default), a form's or a
 * strip's line (`inline` — FormError). The words are the failure's — the kind's, or the server's where they are the
 * admin's; after a lead that says what was not found, why it may not be —, the ways on are the kind's — ask again, go
 * back, reload, sign in again —, and under «جزئیات فنی», folded, what support needs: the status, the code, the request's
 * id, the moment and its zone, the address, the build, to copy whole.
 */

const ICONS: Record<FailureKind, LucideIcon> = {
  offline: WifiOff,
  unreachable: CloudOff,
  timeout: Hourglass,
  unauthorized: KeyRound,
  forbidden: ShieldX,
  'not-found': SearchX,
  conflict: Ban,
  invalid: CircleAlert,
  'rate-limited': Timer,
  server: ServerCrash,
  'bad-gateway': Unplug,
  unavailable: Construction,
  unreadable: FileWarning,
  'stale-build': RefreshCw,
  crash: Bug,
}

type ErrorStateVariant = 'screen' | 'page' | 'card' | 'inline'

interface ErrorStateProps {
  /** The failure: an ApiError (an answer, or none), anything the panel's code threw. */
  error?: unknown
  /** A state that is no thrown failure — an address no page has, a shop not set up yet —: its kind. */
  kind?: FailureKind
  variant?: ErrorStateVariant
  /** A card's subject, as its line begins: «لیست پلن‌ها» (… بارگذاری نشد / پیدا نشد). */
  what?: string
  /** This screen's own words for its case, over the kind's. */
  title?: string
  description?: ReactNode
  /** Ask again — offered where asking again may help (not for what is not there, or not allowed). */
  onRetry?: () => void
  /** The ask again is under way: its button waits. */
  retrying?: boolean
  /**
   * A screen's way to another page of the panel than its own dashboard, loaded afresh (a whole address): the main
   * shop's, from the address of a shop that is not there.
   */
  home?: { href: string; label: string }
  className?: string
}

export function ErrorState({ error, kind, variant = 'card', what, title, description, onRetry, retrying = false, home, className }: ErrorStateProps) {
  const failure = error === undefined || error === null ? null : describeFailure(error)
  const shown = failure?.kind ?? kind ?? 'crash'
  const spec = FAILURE_KINDS[shown]
  // Under a lead or a title that says it was not found, the words say why — not that again.
  const message = shown === 'not-found' && variant !== 'inline' ? notFoundReason(failure) : (failure?.message ?? spec.description)
  const words = { title: title ?? spec.title, message: description ?? message }
  const wait = useRetryWait(error)
  // A whole screen has no other way on: asking again is always offered there.
  const retry = onRetry && (spec.retry || variant === 'screen') ? onRetry : undefined

  if (variant === 'inline') {
    return (
      <Callout tone="danger" icon={CircleAlert} className={className}>
        <span>{words.message}</span>
        <WaitLine failure={failure} wait={wait} />
      </Callout>
    )
  }

  if (variant === 'card') {
    const lead = what === undefined ? `${words.title}.` : `${what} ${shown === 'not-found' ? 'پیدا نشد' : 'بارگذاری نشد'}.`
    return (
      <Callout tone={spec.tone} icon={ICONS[shown]} role="alert" className={className}>
        <div className="grid gap-2">
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <span className="min-w-0">
              <span className="font-medium">{lead}</span> {words.message}
            </span>
            {(retry || spec.reload) && (
              <span className="flex flex-wrap gap-2">
                {retry && <RetryButton onRetry={retry} wait={wait} busy={retrying} size="sm" />}
                {spec.reload && <ReloadButton size="sm" />}
              </span>
            )}
          </div>
          {!retry && <WaitLine failure={failure} wait={wait} />}
          {failure && <TechnicalDetails failure={failure} />}
        </div>
      </Callout>
    )
  }

  const Icon = ICONS[shown]
  const screen = variant === 'screen'
  return (
    <div className={cn(screen ? 'login-backdrop flex min-h-svh items-center justify-center px-4 py-10' : 'flex flex-1 items-center justify-center px-4 py-16', className)}>
      <div role="alert" className="grid w-full max-w-md justify-items-center gap-3 text-center">
        <span
          className={cn(
            'flex size-11 items-center justify-center rounded-full',
            spec.tone === 'danger' ? 'bg-danger-soft text-danger' : spec.tone === 'warning' ? 'bg-warning-soft text-warning' : 'bg-fill-hover text-muted-foreground',
          )}
        >
          <Icon className="size-5" strokeWidth={1.75} aria-hidden />
        </span>
        <h1 className="text-subtitle font-medium">{words.title}</h1>
        <p className="text-body text-muted-foreground">{words.message}</p>
        <WaitLine failure={failure} wait={wait} />
        <Actions kind={shown} variant={variant} retry={retry} retrying={retrying} wait={wait} home={home} />
        {failure && <TechnicalDetails failure={failure} centered />}
      </div>
    </div>
  )
}

/** The ways on from a failure, by its kind: ask again, reload, sign in again, go back, the dashboard — or the screen's own `home`. */
function Actions({
  kind,
  variant,
  retry,
  retrying,
  wait,
  home,
}: {
  kind: FailureKind
  variant: 'screen' | 'page'
  retry?: () => void
  retrying: boolean
  wait: number
  home?: { href: string; label: string }
}) {
  const { reload } = FAILURE_KINDS[kind]
  const lost = kind === 'not-found' || kind === 'forbidden'
  // A state with no way on but waiting (a shop not set up yet) draws no row.
  if (!retry && !reload && !lost && kind !== 'unauthorized' && !home) return null

  return (
    <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
      {retry && <RetryButton onRetry={retry} wait={wait} busy={retrying} />}
      {reload && <ReloadButton />}
      {kind === 'unauthorized' && (
        <PanelLink to="/login" icon={LogIn}>
          ورود دوباره
        </PanelLink>
      )}
      {lost && variant === 'page' && window.history.length > 1 && (
        <Button variant="secondary" icon={ArrowRight} onClick={() => window.history.back()}>
          بازگشت
        </Button>
      )}
      {home ? (
        <Button asChild variant="secondary">
          <a href={home.href}>
            <LayoutDashboard aria-hidden />
            {home.label}
          </a>
        </Button>
      ) : (
        (lost || kind === 'crash') && (
          <PanelLink to="/" icon={LayoutDashboard}>
            رفتن به داشبورد
          </PanelLink>
        )
      )}
    </div>
  )
}

/** «تلاش دوباره» — waiting while the ask runs, held while a wait the server asked for runs, the wait counting down on it. */
function RetryButton({ onRetry, wait, busy, size }: { onRetry: () => void; wait: number; busy: boolean; size?: 'sm' }) {
  return (
    <Button variant="secondary" size={size} icon={RotateCw} busy={busy} aria-disabled={wait > 0} onClick={onRetry}>
      تلاش دوباره
      {wait > 0 && <span className="tabular">({formatCountdown(wait)})</span>}
    </Button>
  )
}

/** «بارگذاری دوباره» — the page loaded again: the way on from what the panel cannot fix by asking again. */
function ReloadButton({ size }: { size?: 'sm' }) {
  return (
    <Button variant="secondary" size={size} icon={RefreshCw} onClick={() => window.location.reload()}>
      بارگذاری دوباره
    </Button>
  )
}

/** A wait the server asked for (a 429's Retry-After), counting down — and once it is over, that it is. */
function WaitLine({ failure, wait }: { failure: Failure | null; wait: number }) {
  if (failure?.retryAfter == null) return null

  return (
    <span role="timer" className="block text-footnote">
      {wait > 0 ? (
        <>
          می‌توانید <span className="tabular">{formatCountdown(wait)}</span> دیگر دوباره امتحان کنید.
        </>
      ) : (
        'حالا می‌توانید دوباره امتحان کنید.'
      )}
    </span>
  )
}

/** A link to an address of the panel's: a route inside its router, a whole page outside it (the last net, above the router). */
function PanelLink({ to, icon, children }: { to: string; icon: LucideIcon; children: ReactNode }) {
  const routed = useInRouterContext()
  const Icon = icon

  return (
    <Button asChild variant="secondary">
      {routed ? (
        <Link to={to}>
          <Icon aria-hidden />
          {children}
        </Link>
      ) : (
        <a href={`${routerBasename}${to}`}>
          <Icon aria-hidden />
          {children}
        </a>
      )}
    </Button>
  )
}

/** «جزئیات فنی», folded: what support needs to find the failure, and all of it to copy. */
function TechnicalDetails({ failure, centered = false }: { failure: Failure; centered?: boolean }) {
  const [open, setOpen] = useState(false)
  const id = useId()

  return (
    <div className={cn('grid w-full gap-2', centered && 'justify-items-center')}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen((shown) => !shown)}
        className="-ms-1 flex h-6 w-fit items-center gap-1 rounded-md px-1 text-footnote text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:focus-ring"
      >
        <ChevronDown className={cn('size-3.5 transition-transform duration-150', open && 'rotate-180')} aria-hidden />
        جزئیات فنی
      </button>
      <div id={id} hidden={!open} className="grid w-full gap-3 rounded-lg border border-border bg-fill px-3 py-2.5 text-start">
        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-footnote">
          {failureFacts(failure).map(({ label, value }) => (
            <Fragment key={label}>
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="min-w-0 wrap-anywhere text-foreground">{/^[\x20-\x7e]*$/.test(value) ? <bdi dir="ltr">{value}</bdi> : <bdi>{value}</bdi>}</dd>
            </Fragment>
          ))}
        </dl>
        <Button variant="secondary" size="sm" icon={Copy} className="w-fit" onClick={() => void copyText(failureReport(failure), 'جزئیات خطا کپی شد')}>
          کپی جزئیات
        </Button>
      </div>
    </div>
  )
}

/** A clock read once a second, while a wait is counting: what a countdown subscribes to. */
function everySecond(changed: () => void): () => void {
  const timer = setInterval(changed, 1_000)
  return () => clearInterval(timer)
}

const still = () => () => undefined

/**
 * Seconds left of the wait a failure asked for (a 429's `Retry-After`, from when it came), ticking down; 0 for none — a
 * sign-in's submit is held as long.
 */
export function useRetryWait(error: unknown): number {
  const failure = error === undefined || error === null ? null : describeFailure(error)
  const deadline = failure?.retryAfter == null ? null : failure.at.getTime() + failure.retryAfter * 1_000

  return useSyncExternalStore(deadline === null ? still : everySecond, () => (deadline === null ? 0 : Math.max(0, Math.ceil((deadline - Date.now()) / 1_000))))
}
