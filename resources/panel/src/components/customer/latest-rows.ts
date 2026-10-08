import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import type { PageMeta } from '@/lib/api-types'

/** How many of a customer's latest rows a card of their page lists; the list narrowed to them has every one. */
const LATEST = 5

interface UseLatestRowsOptions<R, K> {
  /** The list's own key (`queryKeys.orders`): what reloads the list — the live updates, an operation — reloads the card. */
  queryKey: QueryKey
  /** Reads the list's first page, narrowed as `query` says (the customer). */
  read: (query: URLSearchParams) => Promise<R>
  /** Where the answer carries the rows ("orders"). */
  list: K
  /** The customer's id. */
  user: number
}

/**
 * A customer's latest rows of one of the shop's lists — their services, orders or payments — for a card of their page:
 * the list's first page narrowed to them (`user=`), newest first, under the list's own key; with the way an operation's
 * answer changes the card's copy (`replace`, `remove`) as it changes the list's.
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
