import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import type { Tone } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/** A strip says something worth reading: neutral ink is not one of its tones. */
type CalloutTone = Exclude<Tone, 'neutral'>

const TONES: Record<CalloutTone, { box: string; icon: string }> = {
  success: { box: 'border-success-line/70 bg-success-soft/45', icon: 'text-success' },
  warning: { box: 'border-warning-line/70 bg-warning-soft/45', icon: 'text-warning' },
  danger: { box: 'border-danger-line/70 bg-danger-soft/45 text-danger', icon: 'text-danger' },
  info: { box: 'border-info-line/70 bg-info-soft/45', icon: 'text-info' },
}

interface CalloutProps {
  tone: CalloutTone
  icon?: LucideIcon
  /** Announced to assistive tech; a danger callout is an alert unless told otherwise. */
  role?: 'alert' | 'status'
  className?: string
  children: ReactNode
}

/** A tinted strip that reports an outcome or a note in place: a test that passed, a refusal, a hint before a form. */
export function Callout({ tone, icon: Icon, role, className, children }: CalloutProps) {
  return (
    <div role={role ?? (tone === 'danger' ? 'alert' : undefined)} className={cn('flex items-start gap-2.5 rounded-lg border px-3 py-2.5 text-body', TONES[tone].box, className)}>
      {Icon && <Icon className={cn('mt-[3px] size-4 shrink-0', TONES[tone].icon)} aria-hidden />}
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  )
}
