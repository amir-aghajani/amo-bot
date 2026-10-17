import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

const TONES = {
  warning: 'border-warning-line/60 bg-warning-soft text-warning',
  danger: 'border-danger-line/60 bg-danger-soft text-danger',
}

interface PageStripProps {
  tone: keyof typeof TONES
  icon: LucideIcon
  /** Announced as it comes — unless the strip sits in a live region of its owner's, always there. */
  role?: 'status'
  children: ReactNode
}

/**
 * The quiet strip across the top of every page, under the topbar: what holds for the whole panel now (the connection
 * lost, an agent's shop that sells nothing), in a line — a link to where the whole of it is said, after its words.
 */
export function PageStrip({ tone, icon: Icon, role, children }: PageStripProps) {
  return (
    <p role={role} className={cn('flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 border-b px-4 py-1.5 text-center text-footnote', TONES[tone])}>
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {children}
    </p>
  )
}
