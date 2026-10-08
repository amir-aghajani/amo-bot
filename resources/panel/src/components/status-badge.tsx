import type { PageTab } from '@/components/page-tabs'
import { Badge } from '@/components/ui/badge'
import { formatNumber } from '@/lib/format'
import type { Status, Tone } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/** A status (lib/statuses) as a tinted chip with a dot of its colour, so the state reads by more than hue alone. */
export function StatusBadge({ status, className }: { status: Status; className?: string }) {
  return (
    <Badge variant={status.tone} className={cn('gap-1.5', className)}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {status.label}
    </Badge>
  )
}

const DOTS: Record<Tone, string> = {
  neutral: 'bg-faint',
  info: 'bg-info',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
}

/** A state as a dot of its family's colour, beside the words that say it (a server, an inbound, a system row). */
export function StatusDot({ tone, className }: { tone: Tone; className?: string }) {
  return <span aria-hidden className={cn('size-2 shrink-0 rounded-full', DOTS[tone], className)} />
}

/** How many wait somewhere — beside a tab's name, a section's, a title: a small chip in a status family's tint. */
export function CountBadge({ count, tone = 'neutral', label }: { count: number; tone?: Tone; /** What they are, for assistive tech («در انتظار»). */ label?: string }) {
  return (
    <Badge variant={tone} className="h-[18px] min-w-[18px] px-1 tabular">
      {formatNumber(count)}
      {label && <span className="sr-only"> {label}</span>}
    </Badge>
  )
}

/**
 * A status queue's tabs in its vocabulary's words — «همه» first, then each of `order` — with how many wait beside the
 * one that waits on a human (the failed orders, the receipts to review), in its status's tint.
 */
export function statusTabs<S extends string>(vocabulary: Record<S, Status>, order: readonly S[], counts: Partial<Record<S, number>> = {}): PageTab<S | ''>[] {
  return [
    { value: '', label: 'همه' },
    ...order.map((status) => {
      const { label, tone } = vocabulary[status]
      const count = counts[status] ?? 0
      return {
        value: status,
        label:
          count > 0 ? (
            <>
              {label}
              <CountBadge count={count} tone={tone} />
            </>
          ) : (
            label
          ),
      }
    }),
  ]
}
