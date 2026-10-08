import type { Tone } from '@/lib/statuses'
import { cn } from '@/lib/utils'

const FILLS: Record<Tone, string> = {
  neutral: 'bg-faint',
  info: 'bg-selected',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
}

interface ProgressBarProps {
  /** What is measured, for assistive tech («پیشرفت», «مصرف حجم»). */
  label: string
  value: number
  max: number
  /** The bar's colour: the focus blue of work under way by default; amber while it waits, red once spent. */
  tone?: Tone
}

/** How far something got, as a thin bar (a broadcast, a grant, a service's traffic). Nothing to measure (0 of 0) is full. */
export function ProgressBar({ label, value, max, tone = 'info' }: ProgressBarProps) {
  const share = max > 0 ? Math.min(1, Math.max(0, value / max)) : 1

  return (
    <div role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={max} aria-valuenow={Math.min(value, max)} className="h-1.5 w-full overflow-hidden rounded-full bg-fill-hover">
      <div className={cn('h-full rounded-full transition-[width]', FILLS[tone])} style={{ width: `${share * 100}%` }} />
    </div>
  )
}
