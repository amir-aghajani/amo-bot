import type { ReactNode } from 'react'
import { InfoTip } from '@/components/info-tip'
import { TrendChip } from '@/components/trend-chip'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

interface StatCardProps {
  label: string
  /** What the number counts, behind an ⓘ beside the label. */
  info?: string
  /**
   * The figure — undefined when there is none to show: «—» (its read failed, said where the page says what failed; or
   * there is no such figure, which the hint says).
   */
  value: string | undefined
  /** What the figure counts in («تومان»), set small beside it so a long amount keeps to one line. */
  unit?: string
  /** Percent change vs the previous period; undefined = no comparison line, null = no baseline to compare with. */
  change?: number | null
  /** The comparison's words, beside its chip. */
  changeLabel?: string
  /** A line of its own when a change does not make sense. */
  hint?: ReactNode
  /** The figure's read has not answered yet. */
  loading?: boolean
}

/**
 * One headline figure, as the Console's stat cards: the label (with its ⓘ), the number, and how it moved — a skeleton
 * while it is read, «—» when there is none: never a zero it does not know, nor a comparison without a figure.
 */
export function StatCard({ label, info, value, unit, change, changeLabel = 'نسبت به دوره قبل', hint, loading }: StatCardProps) {
  return (
    <Card className="gap-3 p-4">
      <div className="flex min-h-6 items-center gap-1.5 text-body font-medium">
        <span className="truncate">{label}</span>
        {info && (
          <InfoTip small label={`درباره ${label}`}>
            {info}
          </InfoTip>
        )}
      </div>

      {loading ? (
        <Skeleton className="h-9 w-32" />
      ) : value === undefined ? (
        <span className="text-stat font-medium text-faint">—</span>
      ) : (
        <span className="flex min-w-0 items-baseline gap-1.5">
          <span className="truncate text-stat font-medium tracking-tight tabular">{value}</span>
          {unit && <span className="shrink-0 text-body text-muted-foreground">{unit}</span>}
        </span>
      )}

      <div className="flex min-h-5 flex-wrap items-center gap-x-2 gap-y-1 text-footnote text-muted-foreground">
        {loading ? (
          <Skeleton className="h-4 w-36" />
        ) : hint !== undefined ? (
          <span>{hint}</span>
        ) : change !== undefined && value !== undefined ? (
          <>
            <TrendChip change={change} />
            <span>{changeLabel}</span>
          </>
        ) : null}
      </div>
    </Card>
  )
}

/**
 * A row of stat cards: four across when the page's column has room for them, two, or one on a phone — measured on the
 * column (a container query), not the window, since the sidebar takes its share.
 */
export function StatGrid({ label, children }: { label: string; children: ReactNode }) {
  return (
    <section aria-label={label} className="@container">
      <div className="grid gap-3 @xl:grid-cols-2 @4xl:grid-cols-4">{children}</div>
    </section>
  )
}
