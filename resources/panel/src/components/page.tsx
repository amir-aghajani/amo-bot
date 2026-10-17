import { useState, type ReactNode } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

/** What useReadFailure() needs of a read (useQuery's result). */
interface Read {
  error: Error | null
  isFetching: boolean
}

/**
 * The failure a page draws for a read of its own (a ListView, its list's): the read's error, kept while the read is asked
 * again. A read with nothing to show goes back to pending without an error as it is asked again — by
 * «تلاش دوباره» or the live updates —, which would swap the failure, its button and the focus on it for the loading
 * state until the answer. `retrying` spins the button meanwhile, so a second press asks nothing more. `of` names the read
 * when it changes under the page (the dashboard's range): one read's failure is never kept for another.
 */
export function useReadFailure(read: Read, of: unknown = null): { error: Error | null; retrying: boolean } {
  const [last, setLast] = useState<{ error: Error; of: unknown } | null>(null)
  if (read.error !== null && (last?.error !== read.error || last.of !== of)) setLast({ error: read.error, of })
  else if (read.error === null && !read.isFetching && last !== null) setLast(null)
  const error = read.error ?? (last !== null && last.of === of && read.isFetching ? last.error : null)

  return { error, retrying: error !== null && read.isFetching }
}

/**
 * The column a page lives in. `wide` for lists and tables (they use the screen), `default` for pages of cards,
 * `narrow` for a single form or a settings group, `dashboard` for the overview — the Console's widths.
 */
const WIDTHS = {
  wide: 'max-w-[100rem]',
  default: 'max-w-[72rem]',
  narrow: 'max-w-[48rem]',
  dashboard: 'max-w-[62rem]',
}

export type PageWidth = keyof typeof WIDTHS

export function Page({ width = 'wide', children }: { width?: PageWidth; children: ReactNode }) {
  return <div className={cn('mx-auto flex w-full min-w-0 flex-1 flex-col gap-6 px-4 pt-6 pb-12 md:px-8 md:pt-8', WIDTHS[width])}>{children}</div>
}

/** A page's place while its chunk arrives: the header's shape and a block, in the widest column a page takes. */
export function PageSkeleton() {
  return (
    <Page>
      <div className="grid gap-4" aria-busy="true" aria-label="در حال بارگذاری صفحه">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-4 w-80 max-w-full" />
        <Skeleton className="mt-2 h-64 w-full rounded-xl" />
      </div>
    </Page>
  )
}
