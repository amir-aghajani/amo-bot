import { useState, type ReactNode } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown, EllipsisVertical } from 'lucide-react'
import { Link } from 'react-router'
import { ErrorState } from '@/components/error-state'
import { IconButton } from '@/components/icon-button'
import { Pagination } from '@/components/pagination'
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { TableHead, TableName } from '@/components/ui/table'
import type { PageMeta, SortDirection } from '@/lib/api-types'
import type { ListOrder } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

/** What a list's read gives its view (useRows). */
interface ListState {
  rows: readonly unknown[]
  isPending: boolean
  error: Error | null
  refetch: () => void
}

/** And a paged list's (usePagedList): where it is, the next page loading, whether something narrows it. */
interface PagedListState extends ListState {
  meta: PageMeta | undefined
  isPlaceholderData: boolean
  setPage: (page: number) => void
  filtered: boolean
}

interface ListViewProps {
  list: ListState | PagedListState
  /** What the rows are, for the failure line and the loading state: «پلن‌ها». */
  noun: string
  /** One of them, for a paged list's count: «۱۲۳ کاربر». */
  unit?: string
  /** Nothing to list yet — absent for a list that never is empty (the payment methods: the wallet is always there). */
  empty?: ReactNode
  /** Nothing matches the search, the tab or the filters of a paged list. */
  noMatch?: ReactNode
  /** Rows the skeleton stands in for while loading. */
  skeletonRows?: number
  /** The table. */
  children: ReactNode
}

/**
 * A list page's table in exactly one state: loading, failed (with «تلاش دوباره»), empty, nothing matched, or the rows —
 * named for assistive tech after `noun`. A paged list dims while the next page loads and shows its pagination; rows a
 * later read failed to refresh stay under the failure. After a retry the failure stays (its button spinning, keeping the
 * focus) until the read answers — never the loading state, which would take the button and the focus with it, nor the
 * previous view's rows, which are not this view's.
 */
export function ListView({ list, noun, unit, empty, noMatch, skeletonRows = 4, children }: ListViewProps) {
  const paged = 'meta' in list ? list : null
  // The previous view's rows, standing in while this view's read runs (react-query's placeholder): not an answer.
  const borrowed = paged?.isPlaceholderData === true
  // The failure being retried: while the retry runs, the read is pending with no error (or shows the previous view's
  // rows), so the failure is kept here until the read answers.
  const [retried, setRetried] = useState<Error | null>(null)
  if (retried !== null && !list.isPending && !borrowed && list.error !== retried) setRetried(null)
  const error = list.error ?? retried
  const retry = () => {
    setRetried(list.error)
    list.refetch()
  }
  const failure = error && <ErrorState what={`لیست ${noun}`} error={error} onRetry={retry} retrying={list.error === null} />
  // A later page whose last rows were deleted: the list steps back a page (usePagedList), so show it as loading.
  const emptied = paged?.meta !== undefined && list.rows.length === 0 && paged.meta.total > 0

  if ((list.isPending || emptied) && !failure) {
    return (
      <div className="grid gap-1" aria-busy="true" aria-label={`در حال بارگذاری ${noun}`}>
        <Skeleton className="mb-2 h-4 w-full max-w-md rounded-md" />
        {Array.from({ length: skeletonRows }, (_, i) => (
          <Skeleton key={i} className="h-11 w-full" />
        ))}
      </div>
    )
  }

  // A failure belongs to this view: the previous view's rows are not shown under it.
  if (list.rows.length === 0 || (failure && borrowed)) {
    return failure || (paged?.filtered ? noMatch : empty) || null
  }

  return (
    <div className="grid min-w-0 gap-4">
      {failure}
      <div className={cn('min-w-0 transition-opacity duration-150', paged?.isPlaceholderData && 'opacity-60')}>
        <TableName value={noun}>{children}</TableName>
      </div>
      {paged?.meta && <Pagination page={paged.meta.page} lastPage={paged.meta.last_page} total={paged.meta.total} unit={unit ?? noun} onChange={paged.setPage} disabled={paged.isPlaceholderData} />}
    </div>
  )
}

// A name wraps at its spaces when the table is narrower than its rows (an identifier, which has none, keeps to its line).
const ROW_TITLE = 'w-fit max-w-full rounded-sm text-start font-medium whitespace-normal wrap-break-word text-foreground underline-offset-4 outline-none hover:underline focus-visible:focus-ring'

/** The row's name as the button that opens it (its form, its dialog). */
export function RowTitleButton({ onClick, children }: { onClick: () => void; children: ReactNode }) {
  return (
    <button type="button" onClick={onClick} className={ROW_TITLE}>
      {children}
    </button>
  )
}

/** The row's name as the link to its own page (a server). */
export function RowTitleLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} className={ROW_TITLE}>
      {children}
    </Link>
  )
}

/** The ⋮ menu at the end of a row; `children` are its items. */
export function RowMenu({ label, children }: { label: string; children: ReactNode }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <IconButton aria-label={`عملیات ${label}`} className="size-7 rounded-md">
          <EllipsisVertical className="size-4" aria-hidden />
        </IconButton>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">{children}</DropdownMenuContent>
    </DropdownMenu>
  )
}

/** An empty cell. */
export function Dash() {
  return <span className="text-faint">—</span>
}

/** What a sorted paged list (usePagedList with `sorts`) gives its headers. */
export interface SortedList<S extends string> {
  order: ListOrder<S> | null
  ownOrder: ListOrder<S> | null
  setOrder: (order: ListOrder<S>) => void
}

interface SortableHeadProps<S extends string> {
  list: SortedList<S>
  /** The key the column sorts the list by (its API's `sort`). */
  by: S
  /** The first press's direction: descending (newest, largest first) unless soonest-first makes more sense (an end date). */
  first?: SortDirection
  /** What the column sorts by, for assistive tech, when its header's words do not say it («حجم ربات» under «ربات»). */
  label?: string
  /** The header's words. */
  children: string
  className?: string
}

/**
 * A paged list's column header that sorts on the server: the first press sorts by the column, the second reverses it,
 * and the third (on a column that is not the list's own order) restores the list's own order — the way back on a phone,
 * where most columns are hidden. `aria-sort` and an arrow show the state; a faint ⇅ in the same place marks an unsorted
 * column, so nothing shifts. Not for an admin-ordered list (that has OrderCell's ▲▼).
 */
export function SortableHead<S extends string>({ list, by, first = 'desc', label, children, className }: SortableHeadProps<S>) {
  const { order, ownOrder } = list
  const dir = order?.sort === by ? order.dir : null
  const Arrow = dir === 'asc' ? ArrowUp : dir === 'desc' ? ArrowDown : ArrowUpDown

  return (
    <TableHead aria-sort={dir === null ? undefined : dir === 'asc' ? 'ascending' : 'descending'} className={className}>
      <button
        type="button"
        aria-label={`مرتب‌سازی بر اساس ${label ?? children}`}
        onClick={() => order && ownOrder && list.setOrder(nextOrder(order, ownOrder, by, first))}
        className={cn(
          '-mx-1 inline-flex items-center gap-1 rounded-sm px-1 transition-colors duration-100 outline-none hover:text-foreground focus-visible:focus-ring',
          dir !== null && 'text-foreground',
        )}
      >
        {children}
        <Arrow aria-hidden className={cn('size-3.5 shrink-0', dir === null && 'text-faint')} />
      </button>
    </TableHead>
  )
}

/** The order a press on `by`'s header asks for, from the list's order now and its own. */
function nextOrder<S extends string>(order: ListOrder<S>, own: ListOrder<S>, by: S, first: SortDirection): ListOrder<S> {
  if (order.sort !== by) return { sort: by, dir: first }
  if (by !== own.sort && order.dir !== first) return own

  return { sort: by, dir: order.dir === 'asc' ? 'desc' : 'asc' }
}
