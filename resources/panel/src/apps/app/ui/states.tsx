import type { ComponentType, ReactNode } from 'react'
import { CircleAlert, CircleCheck, Info, RefreshCw, TriangleAlert } from 'lucide-react'
import { problemOf } from '@/apps/app/lib/store'
import { Button } from '@/apps/app/ui/button'
import { IconTile } from '@/apps/app/ui/visuals'
import { cn } from '@/lib/utils'

/* What a screen shows while it loads, when it has nothing, and when its read failed. */

/** A shimmering block where content will be. */
export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden className={cn('rounded-card animate-pulse bg-fill', className)} />
}

/** A screen's first load: a few blocks shaped like what comes. */
export function LoadingBlocks({ count = 3 }: { count?: number }) {
  return (
    <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
      {Array.from({ length: count }, (_, index) => (
        <Skeleton key={index} className="h-20" />
      ))}
    </div>
  )
}

interface EmptyProps {
  icon: ComponentType<{ className?: string }>
  title: ReactNode
  text?: ReactNode
  /** What to do about it (a button). */
  action?: ReactNode
}

/** Nothing here yet: an icon, a line, and the way on. */
export function Empty({ icon: Icon, title, text, action }: EmptyProps) {
  return (
    <div className="animate-rise flex flex-col items-center gap-2 px-6 py-12 text-center">
      <IconTile icon={Icon} size="2xl" look="soft" round className="mb-2" />
      <p className="text-heading font-bold">{title}</p>
      {text !== undefined && <p className="text-hint max-w-xs text-footnote">{text}</p>}
      {action !== undefined && <div className="mt-3">{action}</div>}
    </div>
  )
}

/** A read that failed: why, in the customer's words, and «تلاش دوباره». */
export function ErrorView({ error, retry }: { error: unknown; retry?: () => void }) {
  return (
    <div className="animate-rise flex flex-col items-center gap-3 px-6 py-12 text-center" role="alert">
      <IconTile icon={CircleAlert} size="xl" color="var(--app-danger)" look="soft" round />
      <p className="max-w-xs text-body">{problemOf(error)}</p>
      {retry && (
        <Button variant="secondary" size="sm" icon={<RefreshCw />} onClick={retry} className="mt-1">
          تلاش دوباره
        </Button>
      )}
    </div>
  )
}

const NOTICE = {
  info: { color: 'var(--app-accent-text)', icon: Info },
  success: { color: 'var(--app-success)', icon: CircleCheck },
  warning: { color: 'var(--app-warning)', icon: TriangleAlert },
  danger: { color: 'var(--app-danger)', icon: CircleAlert },
} as const

/** A line of warning or news inside a screen: a card tinted by its tone, its tone's icon at its start. */
export function Notice({ tone = 'info', children }: { tone?: keyof typeof NOTICE; children: ReactNode }) {
  const { color, icon: Icon } = NOTICE[tone]

  return (
    <div
      className="flex items-start gap-3 rounded-2xl px-4 py-3 text-footnote"
      style={{ background: `color-mix(in srgb, ${color} 12%, transparent)`, color: `color-mix(in srgb, ${color} 80%, var(--app-text))` }}
    >
      <Icon aria-hidden className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  )
}
