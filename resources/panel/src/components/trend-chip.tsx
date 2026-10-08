import { Badge } from '@/components/ui/badge'
import { formatChange } from '@/lib/format'

/** Percent change vs a baseline as a tinted chip; null = there was nothing to compare against. */
export function TrendChip({ change }: { change: number | null }) {
  const trend = change === null ? 'flat' : change > 0.5 ? 'up' : change < -0.5 ? 'down' : 'flat'

  return (
    <Badge variant={trend === 'up' ? 'success' : trend === 'down' ? 'danger' : 'neutral'} className="tabular">
      {change === null ? 'بدون مقایسه' : describeChange(change)}
    </Badge>
  )
}

/** Past ±999٪ the figure says nothing more; past ±100٪ its decimal says nothing either. */
function describeChange(change: number): string {
  if (Math.abs(change) >= 1000) return formatChange(change > 0 ? 999 : -999)
  return formatChange(Math.abs(change) >= 100 ? Math.round(change) : change)
}
