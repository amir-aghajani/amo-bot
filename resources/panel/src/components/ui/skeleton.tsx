import { cn } from '@/lib/utils'

/** A placeholder block while something loads: the neutral fill, pulsing. */
function Skeleton({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="skeleton" className={cn('animate-pulse rounded-lg bg-fill-hover', className)} {...props} />
}

export { Skeleton }
