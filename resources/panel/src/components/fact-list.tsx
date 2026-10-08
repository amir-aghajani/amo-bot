import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/** Everything known about a dialog's subject, as label / value lines in two columns. */
export function FactList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={cn('grid grid-cols-[minmax(6.5rem,auto)_1fr] gap-x-6 gap-y-2.5 text-body', className)}>{children}</dl>
}

/** One label / value line of a FactList. */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="min-w-0 wrap-anywhere">{children}</dd>
    </>
  )
}
