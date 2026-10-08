import { Check } from 'lucide-react'
import { isDone, STEPS, type Step } from '@/apps/admin/install/steps'
import type { InstallStatus } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** The steps in a row: the current one marked, the done ones ticked and open to go back to. */
export function StepBar({ current, status, onPick }: { current: Step; status: InstallStatus; onPick: (step: Step) => void }) {
  return (
    <ol className="mb-4 flex flex-wrap items-center justify-center gap-x-1 gap-y-2 text-footnote" aria-label="مراحل نصب">
      {STEPS.map(({ key, label }, index) => {
        const done = isDone(key, status, current)
        const active = key === current
        return (
          <li key={key} className="flex items-center gap-1">
            {index > 0 && <span className="h-px w-4 bg-border" aria-hidden />}
            <button
              type="button"
              disabled={!done || active}
              onClick={() => onPick(key)}
              aria-current={active ? 'step' : undefined}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-2.5 py-1 transition-colors duration-150 outline-none focus-visible:focus-ring',
                active ? 'border-selected bg-info-soft/40 text-foreground' : done ? 'border-border text-foreground hover:bg-fill' : 'border-transparent text-muted-foreground',
              )}
            >
              {done && !active ? <Check className="size-3 text-success" aria-label="انجام‌شده" /> : <span className="tabular">{formatNumber(index + 1)}</span>}
              {label}
            </button>
          </li>
        )
      })}
    </ol>
  )
}
