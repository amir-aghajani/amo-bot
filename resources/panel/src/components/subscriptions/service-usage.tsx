import { Dash } from '@/components/list-view'
import { ProgressBar } from '@/components/progress-bar'
import type { SubscriptionRow } from '@/lib/api-types'
import { formatBytes, formatDate, formatDays, timeLeft, UNLIMITED } from '@/lib/format'
import type { Tone } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/** Traffic used against the quota: the amounts, and a bar when there is a quota — amber from 80٪, red once spent. */
export function UsageMeter({ traffic, className }: { traffic: SubscriptionRow['traffic']; className?: string }) {
  if (traffic.limit <= 0) {
    return (
      <div className={cn('grid gap-0.5', className)}>
        <span className="whitespace-nowrap tabular">{formatBytes(traffic.used)}</span>
        <span className="text-footnote text-muted-foreground">حجم {UNLIMITED}</span>
      </div>
    )
  }

  const share = traffic.used / traffic.limit
  const tone: Tone = share >= 1 ? 'danger' : share >= 0.8 ? 'warning' : 'info'

  return (
    <div className={cn('grid gap-1', className)}>
      <span className="whitespace-nowrap tabular">
        {formatBytes(traffic.used)} <span className="text-footnote text-muted-foreground">از {formatBytes(traffic.limit)}</span>
      </span>
      <ProgressBar label="مصرف حجم" value={traffic.used} max={traffic.limit} tone={tone} />
    </div>
  )
}

/**
 * When the service ends, worded as the bot words it for the customer: the date with the days left (amber once it is
 * within the shop's "ending soon" window — the server's `expiring_soon`), still waiting for the first connection (the
 * panel starts the clock then), or never.
 */
export function ServiceExpiry({ subscription }: { subscription: Pick<SubscriptionRow, 'status' | 'expires_at' | 'duration_days' | 'expiring_soon'> }) {
  // A deleted service has no client left to wait for or to run out: its end is a date on record, nothing more.
  if (subscription.status === 'deleted') {
    return subscription.expires_at === null ? <Dash /> : <span className="whitespace-nowrap">{formatDate(subscription.expires_at, { dateStyle: 'medium' })}</span>
  }

  if (subscription.expires_at === null) {
    return subscription.duration_days > 0 ? (
      <div className="grid gap-0.5">
        <span className="whitespace-nowrap">در انتظار اولین اتصال</span>
        <span className="text-footnote text-muted-foreground">{formatDays(subscription.duration_days)} از اولین اتصال</span>
      </div>
    ) : (
      <span>{UNLIMITED}</span>
    )
  }

  return (
    <div className="grid gap-0.5">
      <span className="whitespace-nowrap">{formatDate(subscription.expires_at, { dateStyle: 'medium' })}</span>
      <span className={cn('text-footnote', subscription.expiring_soon ? 'text-warning' : 'text-muted-foreground')}>{timeLeft(subscription.expires_at)}</span>
    </div>
  )
}
