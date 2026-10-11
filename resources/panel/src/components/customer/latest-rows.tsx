import type { ReactNode } from 'react'
import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { TextLink } from '@/components/text-link'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { PageMeta } from '@/lib/api-types'

/** How many of a customer's latest rows a card of their page lists; the full list narrowed to them has every one. */
const LATEST = 5

interface UseLatestRowsOptions<R, K> {
  /** The list's own key (`queryKeys.orders`): whatever reloads the list reloads the card too. */
  queryKey: QueryKey
  /** Reads the list's first page, narrowed as `query` says (the customer). */
  read: (query: URLSearchParams) => Promise<R>
  /** Where the answer carries the rows ("orders"). */
  list: K
  /** The customer's id. */
  user: number
}

/**
 * A customer's latest rows of one of the shop's lists (services, orders, payments, tickets) for a card on their page:
 * the list's first page narrowed to them (`user=`), cached under the list's own key, with `replace` and `remove` for the
 * answers of the operations the card's dialog runs.
 */
// K comes first and stands alone: it is read off `list` before `read` (an arrow whose parameter is typed by this hook) gives R.
export function useLatestRows<K extends string, R extends { meta: PageMeta } & Record<K, readonly { id: number }[]>>({ queryKey, read, list, user }: UseLatestRowsOptions<R, K>) {
  type Row = R[K][number]
  const queryClient = useQueryClient()
  const key = [...queryKey, { user }]
  const result = useQuery({ queryKey: key, queryFn: () => read(new URLSearchParams({ user: String(user) })) })
  const edit = (change: (current: R) => R) => queryClient.setQueryData<R>(key, (current) => current && change(current))
  const rows: Row[] = result.data ? result.data[list].slice(0, LATEST) : []

  return {
    rows,
    /** How many the customer has in all. */
    total: result.data?.meta.total,
    isPending: result.isPending,
    error: result.error,
    refetch: () => void result.refetch(),
    /** The card's own key, under the list's. */
    queryKey: key,
    /** A row an operation handed back, in its place. */
    replace: (row: Row) => edit((current) => ({ ...current, [list]: current[list].map((r) => (r.id === row.id ? row : r)) }) as R),
    /** A row that is gone (deleted), out of the card and its count. */
    remove: (id: number) => edit((current) => ({ ...current, [list]: current[list].filter((r) => r.id !== id), meta: { ...current.meta, total: current.meta.total - 1 } }) as R),
  }
}

interface LatestCardProps {
  title: string
  /** How many the customer has in all, in words («۳ سفارش»); null while there is none, which hides the link too. */
  count: string | null
  /** The full list narrowed to the customer, and its link's words: «همه سفارش‌ها». */
  all: { to: string; label: string }
  children: ReactNode
}

/** A card of the customer's page listing their latest rows of one list, with the way to all of them. */
export function LatestCard({ title, count, all, children }: LatestCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>{title}</CardTitle>
          {count !== null && <CardDescription>{count}</CardDescription>}
        </CardHeading>
        {count !== null && (
          <CardAction>
            <TextLink to={all.to} className="text-footnote">
              {all.label}
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  )
}
