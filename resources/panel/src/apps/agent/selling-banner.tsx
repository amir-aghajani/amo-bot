import { useQuery } from '@tanstack/react-query'
import { Ban, CalendarClock } from 'lucide-react'
import { Link, useLocation } from 'react-router'
import { accountQuery } from '@/apps/agent/queries'
import { monthWords, standingConcern } from '@/components/agency/agency-standing'
import { useShellReads } from '@/components/shell/shell-context'
import { formatDate } from '@/lib/format'
import { SELLING_SUSPENSION } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/** The agent's account page, where the strip leads. */
const ACCOUNT = '/account'

/** The pages that say the whole of it themselves (SellingNotice): the account, and the dashboard's attention card. */
const SAID = [ACCOUNT, '/']

/**
 * The agent's strip over every page while their shop sells nothing — or its end, or a month short of its minimum, is
 * near —, in a line leading to their account, where the whole of it is said (SellingNotice) — and so not over a page
 * that says it already: the account, the dashboard. Read after the page's own reads.
 */
export function SellingBanner() {
  const { data } = useQuery({ ...accountQuery, enabled: useShellReads() })
  const { pathname } = useLocation()
  const standing = data?.account.standing
  const concern = standingConcern(standing)
  if (!standing || concern === null || SAID.includes(pathname)) return null

  const suspended = concern === 'suspended'
  const words =
    suspended && standing.suspension
      ? `فروش ربات شما متوقف است: ${SELLING_SUSPENSION[standing.suspension.reason].label}.`
      : concern === 'expiring' && standing.expires_at !== null
        ? `نمایندگی شما ${formatDate(standing.expires_at)} به پایان می‌رسد.`
        : `خرید حجم ${standing.month.name} (${monthWords(standing)}) هنوز به کف ماهانه نرسیده است.`
  const Icon = suspended ? Ban : CalendarClock

  return (
    <p
      role="status"
      className={cn(
        'flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 border-b px-4 py-1.5 text-center text-footnote',
        suspended ? 'border-danger-line/60 bg-danger-soft text-danger' : 'border-warning-line/60 bg-warning-soft text-warning',
      )}
    >
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {words}
      <Link to={ACCOUNT} className="font-medium underline underline-offset-2">
        حساب نمایندگی
      </Link>
    </p>
  )
}
