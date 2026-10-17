import { hashKey, useMutation, useQuery, useQueryClient, type QueryKey, type UseQueryOptions } from '@tanstack/react-query'
import { useSectionShown } from '@/components/sectioned-page'

interface UseRowsOptions<R extends Record<K, readonly { id: number }[]>, K extends keyof R & string, Q extends QueryKey, C> {
  /** The list's read (lib/queries); its answer carries the rows under `list`. */
  query: UseQueryOptions<R, Error, R, Q>
  /** Where the answer carries the rows ("plans"). */
  list: K
  /** Save the rows' new order; answered with the list. None for a list ordered by something else (loyalty tiers). */
  reorder?: (ids: number[]) => Promise<R>
  remove: (row: R[K][number]) => Promise<void>
  /** Change one field of a row in place (its switch); answered with the row. */
  patch?: (row: R[K][number], changes: C) => Promise<R[K][number]>
  /**
   * Other reads that show these rows (a form that offers them), refetched after each of the list's writes — and handed
   * back for the editor form's save, which is a write of the list too.
   */
  invalidates?: readonly QueryKey[]
}

/**
 * An admin-ordered list (plans, categories, payment methods, customer groups, agency levels, channels): its read, and
 * the writes every such screen makes on its cached copy — a row saved by its form (`upsert`), a field flipped at once
 * (`patch`, put back if refused), a row moved up or down (`move`), a row deleted.
 *
 * Each write changes the cache from what it holds now, so two quick ones never undo each other, and the requests go one
 * after another (one mutation scope per list), so the server ends where the screen does. A failed change refetches the
 * list and the error toast says why (a refused delete is said by its dialog instead). In a hidden section the read
 * ignores live updates until shown.
 */
export function useRows<R extends Record<K, readonly { id: number }[]>, K extends keyof R & string, Q extends QueryKey, C extends Partial<R[K][number]> = never>({
  query,
  list,
  reorder: sendOrder,
  remove: sendRemoval,
  patch: sendChanges,
  invalidates = [],
}: UseRowsOptions<R, K, Q, C>) {
  type Row = R[K][number]
  const queryClient = useQueryClient()
  const { queryKey } = query
  const shown = useSectionShown()
  const read = useQuery({ ...query, subscribed: shown })
  // The list's writes run one after the other.
  const scope = { id: hashKey(queryKey) }
  const rows: Row[] = read.data ? [...read.data[list]] : []

  /** Change the cached rows from what they are now, not from what this render saw. */
  const write = (change: (rows: Row[]) => Row[]) => queryClient.setQueryData<R>(queryKey, (current) => (current ? ({ ...current, [list]: change([...current[list]]) } as R) : current))
  const upsert = (row: Row) => write((current) => (current.some((r) => r.id === row.id) ? current.map((r) => (r.id === row.id ? row : r)) : [...current, row]))
  const refetch = () => void queryClient.invalidateQueries({ queryKey })
  // A read in flight would land over the change about to be shown.
  const settle = () => queryClient.cancelQueries({ queryKey })

  const patch = useMutation({
    scope,
    meta: { invalidates },
    // Without `patch`, C is `never`: nothing can ask for one.
    mutationFn: sendChanges && (({ row, changes }: { row: Row; changes: C }) => sendChanges(row, changes)),
    onMutate: async ({ row, changes }) => {
      await settle()
      write((current) => current.map((r) => (r.id === row.id ? { ...r, ...changes } : r)))
    },
    onSuccess: upsert,
    onError: (_error, { row }) => {
      write((current) => current.map((r) => (r.id === row.id ? row : r)))
      refetch()
    },
  })

  const reorder = useMutation({
    scope,
    meta: { invalidates },
    mutationFn: sendOrder,
    onMutate: async (ids) => {
      await settle()
      // The rows in the new order; a row the ids do not name (added meanwhile) stays, at the end.
      write((current) => {
        const byId = new Map(current.map((row) => [row.id, row]))
        return [...ids.flatMap((id) => byId.get(id) ?? []), ...current.filter((row) => !ids.includes(row.id))]
      })
    },
    onSuccess: (data) => write(() => [...data[list]]),
    onError: refetch,
  })

  // Quiet: a refused delete is said by the dialog that asked (RemoveConfirm), which stays open.
  const remove = useMutation({
    scope,
    mutationFn: sendRemoval,
    onSuccess: (_, row) => write((current) => current.filter((r) => r.id !== row.id)),
    meta: { quiet: true, invalidates },
  })

  /** Swap a row with its neighbour. */
  const move = (index: number, delta: -1 | 1) => {
    const ids = rows.map((r) => r.id)
    const from = ids[index]
    const to = ids[index + delta]
    if (from === undefined || to === undefined) return
    ids[index] = to
    ids[index + delta] = from
    reorder.mutate(ids)
  }

  return { rows, isPending: read.isPending, isFetching: read.isFetching, error: read.error, refetch, upsert, patch, reorder, remove, move, invalidates }
}
