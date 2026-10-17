import { Badge } from '@/components/ui/badge'
import { formatChange } from '@/lib/format'

/** What a figure with no baseline says in its comparison's place: the previous period had none to compare against. */
const NO_BASELINE = 'دوره قبل رقمی برای مقایسه نداشت'

/** Percent change vs a baseline as a tinted chip. */
function TrendChip({ change }: { change: number }) {
  const trend = change > 0.5 ? 'up' : change < -0.5 ? 'down' : 'flat'

  return (
    <Badge variant={trend === 'up' ? 'success' : trend === 'down' ? 'danger' : 'neutral'} className="tabular">
      {describeChange(change)}
    </Badge>
  )
}

/**
 * How a figure moved: its chip and what it is compared with («نسبت به دوره قبل»); null = there was nothing to compare
 * against, said in a line of its own — never a comparison's words beside no comparison.
 */
export function Trend({ change, label, className }: { change: number | null; label: string; className?: string }) {
  if (change === null) return <span className={className}>{NO_BASELINE}</span>

  return (
    <>
      <TrendChip change={change} />
      <span className={className}>{label}</span>
    </>
  )
}

/** Past ±999٪ the figure says nothing more; past ±100٪ its decimal says nothing either. */
function describeChange(change: number): string {
  if (Math.abs(change) >= 1000) return formatChange(change > 0 ? 999 : -999)
  return formatChange(Math.abs(change) >= 100 ? Math.round(change) : change)
}
