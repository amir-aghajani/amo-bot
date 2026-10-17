import type { ReactNode } from 'react'
import { InfoTip } from '@/components/info-tip'
import { Trend } from '@/components/trend-chip'
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
 * while it is read, «—» when there is none: never a zero it does not know, nor a comparison without a figure. In a
 * narrow card (two to a phone's row) the label wraps, the figure is a size down and its unit goes under it when the two
 * do not fit one line, so a long amount is never cut.
 */
export function StatCard({ label, info, value, unit, change, changeLabel = 'نسبت به دوره قبل', hint, loading }: StatCardProps) {
  return (
    <Card className="@container gap-3 p-4">
      <div className="flex min-h-6 items-center gap-1.5 text-body font-medium">
        <span className="min-w-0">{label}</span>
        {info && (
          <InfoTip small label={`درباره ${label}`}>
            {info}
          </InfoTip>
        )}
      </div>

      {loading ? (
        <Skeleton className="h-9 w-32" />
      ) : value === undefined ? (
        <span className="text-title font-medium text-faint @[11rem]:text-stat">—</span>
      ) : (
        <span className="flex min-w-0 flex-wrap items-baseline gap-x-1.5">
          <span className="truncate text-title font-medium tracking-tight tabular @[11rem]:text-stat">{value}</span>
          {unit && <span className="shrink-0 text-body text-muted-foreground">{unit}</span>}
        </span>
      )}

      <div className="flex min-h-5 flex-wrap items-center gap-x-2 gap-y-1 text-footnote text-muted-foreground">
        {loading ? <Skeleton className="h-4 w-36" /> : hint !== undefined ? <span>{hint}</span> : change !== undefined && value !== undefined ? <Trend change={change} label={changeLabel} /> : null}
      </div>
    </Card>
  )
}

/**
 * A row of stat cards: four across when the page's column has room for them, else two — a phone's too — measured on
 * the column (a container query), not the window, since the sidebar takes its share.
 */
export function StatGrid({ label, children }: { label: string; children: ReactNode }) {
  return (
    <section aria-label={label} className="@container">
      <div className="grid grid-cols-2 gap-3 @4xl:grid-cols-4">{children}</div>
    </section>
  )
}
