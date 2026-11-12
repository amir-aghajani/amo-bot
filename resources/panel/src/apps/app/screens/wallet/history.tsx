import { useEffect, useRef, type ReactNode } from 'react'
import { useInfiniteQuery, type InfiniteData } from '@tanstack/react-query'
import { ChevronLeft, ReceiptText, ShoppingBag } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { problemOf, store } from '@/apps/app/lib/store'
import { byDay } from '@/apps/app/screens/wallet/parts/days'
import { LEDGER_INSET, LedgerLine } from '@/apps/app/screens/wallet/parts/ledger'
import { invoiceNumber, ORDER_ICON, orderStatus, orderTitle } from '@/apps/app/screens/wallet/parts/orders'
import { tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Segmented } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { Badge, IconTile, Price } from '@/apps/app/ui/visuals'
import type { PageMeta, StoreOrder, StoreOrdersResponse, StoreWalletTransactionsResponse } from '@/lib/api-types'
import { formatDate } from '@/lib/format'

/*
 * «تاریخچه»: the wallet's ledger and the customer's orders, a segment each, newest first and set apart by day, read a
 * page at a time as the list is scrolled down (or «بیشتر» is pressed). An order opens its invoice. The segment is kept in
 * the address, so coming back from an invoice finds the orders again.
 */

type Tab = 'transactions' | 'orders'

const TABS = [
  { value: 'transactions', label: 'تراکنش‌ها' },
  { value: 'orders', label: 'سفارش‌ها' },
] as const

/** The page after `meta`'s, while there is one. */
const nextPage = ({ meta }: { meta: PageMeta }): number | undefined => (meta.page < meta.last_page ? meta.page + 1 : undefined)

/** Every row of the pages read so far. */
function rowsOf<P, T>(data: InfiniteData<P> | undefined, rows: (page: P) => T[]): T[] {
  return data?.pages.flatMap(rows) ?? []
}

interface PagedListProps<T> {
  query: {
    /** The pages read so far: undefined before the first. */
    data: unknown
    isError: boolean
    error: unknown
    refetch: () => unknown
    hasNextPage: boolean
    isFetchingNextPage: boolean
    isFetchNextPageError: boolean
    fetchNextPage: () => unknown
  }
  rows: T[]
  at: (row: T) => string | null
  row: (row: T) => ReactNode
  empty: ReactNode
}

/** A paged list by day: its first load, its failure, nothing, or its days — and the next page once its end is in sight. */
function PagedList<T>({ query, rows, at, row, empty }: PagedListProps<T>) {
  const end = useRef<HTMLDivElement>(null)
  const { hasNextPage, isFetchingNextPage, fetchNextPage } = query

  useEffect(() => {
    const sentinel = end.current
    if (sentinel === null || !hasNextPage || typeof IntersectionObserver === 'undefined') return
    const observer = new IntersectionObserver(([entry]) => {
      if (entry?.isIntersecting && !isFetchingNextPage) void fetchNextPage()
    })
    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [hasNextPage, isFetchingNextPage, fetchNextPage])

  // The rows stay while there are some: a read again (or of the next page) that failed takes none of them away.
  if (query.data === undefined) {
    if (query.isError) return <ErrorView error={query.error} retry={() => void query.refetch()} />
    return (
      <div className="flex flex-col gap-2" role="status" aria-label="در حال بارگذاری">
        <Skeleton className="mx-4 h-4 w-24 rounded-full" />
        <Skeleton className="h-48" />
      </div>
    )
  }
  if (rows.length === 0) return empty

  return (
    <>
      {byDay(rows, at).map((day) => (
        <Section key={day.label} title={day.label}>
          {day.rows.map(row)}
        </Section>
      ))}
      {query.isFetchNextPageError && <Notice tone="danger">{problemOf(query.error)}</Notice>}
      {/* Only while there is more: an empty block would still take the screen's gap. */}
      {hasNextPage && (
        <div ref={end} className="flex justify-center">
          <Button variant="secondary" size="sm" busy={isFetchingNextPage} onClick={() => void fetchNextPage()}>
            بیشتر
          </Button>
        </div>
      )}
    </>
  )
}

/**
 * An order in the list: its kind's icon (a square: what was bought, where the ledger's circles are money moved), what it
 * bought beside its amount, its number and time beside where it stands — its invoice a tap away.
 */
function OrderLine({ order }: { order: StoreOrder }) {
  const status = orderStatus(order.status)

  return (
    <Link to={`/orders/${order.id}`} onClick={() => tap()} className="list-row flex items-center gap-3 px-4 py-3 transition-colors active:bg-fill" style={LEDGER_INSET}>
      <IconTile icon={ORDER_ICON[order.type]} size="lg" look="soft" />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex items-center justify-between gap-3">
          <span className="min-w-0 truncate text-body">{orderTitle(order)}</span>
          <Price amount={order.amount} className="shrink-0 text-body" />
        </span>
        <span className="flex items-center justify-between gap-3">
          <span className="text-hint min-w-0 truncate text-footnote">
            <span className="ltr">{invoiceNumber(order)}</span> · {formatDate(order.created_at, { timeStyle: 'short' })}
          </span>
          <Badge tone={status.tone}>{status.label}</Badge>
        </span>
      </span>
      <ChevronLeft aria-hidden className="text-hint size-4.5 shrink-0 opacity-50" />
    </Link>
  )
}

export default function HistoryScreen() {
  const [search, setSearch] = useSearchParams()
  const tab: Tab = search.get('tab') === 'orders' ? 'orders' : 'transactions'

  const transactions = useInfiniteQuery({
    queryKey: [...keys.transactions, 'pages'],
    queryFn: ({ pageParam }) => store.get<StoreWalletTransactionsResponse>('/wallet/transactions', new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    enabled: tab === 'transactions',
  })
  const orders = useInfiniteQuery({
    queryKey: [...keys.orders, 'pages'],
    queryFn: ({ pageParam }) => store.get<StoreOrdersResponse>('/orders', new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    enabled: tab === 'orders',
  })

  return (
    <Screen back title="تاریخچه">
      <Segmented value={tab} options={TABS} onChange={(value) => setSearch(value === 'orders' ? { tab: 'orders' } : {}, { replace: true })} />
      {tab === 'transactions' ? (
        <PagedList
          query={transactions}
          rows={rowsOf(transactions.data, (page) => page.transactions)}
          at={(line) => line.created_at}
          row={(line) => <LedgerLine key={line.id} line={line} time="time" />}
          empty={<Empty icon={ReceiptText} title="هنوز تراکنشی نیست" text="شارژ کیف پول، پرداخت‌ها و برگشت پول اینجا ثبت می‌شود." />}
        />
      ) : (
        <PagedList
          query={orders}
          rows={rowsOf(orders.data, (page) => page.orders)}
          at={(order) => order.created_at}
          row={(order) => <OrderLine key={order.id} order={order} />}
          empty={<Empty icon={ShoppingBag} title="هنوز سفارشی ندارید" text="خریدها، تمدیدها و شارژهای کیف پول شما اینجا می‌آید." />}
        />
      )}
    </Screen>
  )
}
