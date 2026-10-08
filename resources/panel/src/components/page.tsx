import type { ReactNode } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'

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
