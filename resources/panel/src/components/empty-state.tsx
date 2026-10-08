import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

interface EmptyStateProps {
  icon?: LucideIcon
  title: string
  description?: ReactNode
  action?: ReactNode
  /** Tighter spacing for small cards and table bodies. */
  compact?: boolean
  /** In a hairline box of its own (a list page with nothing to list yet), as the Console's empty lists. */
  framed?: boolean
}

/** The "nothing here" state: a quiet pictogram, a title, a line of explanation and the next step. */
export function EmptyState({ icon: Icon, title, description, action, compact, framed }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center justify-center text-center', compact ? 'gap-1 px-4 py-8' : 'gap-1.5 px-6 py-16', framed && 'rounded-xl border border-border')}>
      {Icon && (
        <span className={cn('mb-2 flex items-center justify-center rounded-full bg-fill-hover text-muted-foreground', compact ? 'size-9' : 'size-11')}>
          <Icon className={compact ? 'size-4' : 'size-5'} strokeWidth={1.75} aria-hidden />
        </span>
      )}
      <p className="text-body font-medium">{title}</p>
      {description && <p className="max-w-sm text-footnote text-muted-foreground">{description}</p>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  )
}
