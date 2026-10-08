import { hashKey, useMutation, useQuery, useQueryClient, type QueryKey, type UseQueryOptions } from '@tanstack/react-query'
import { useSectionShown } from '@/components/sectioned-page'

interface UseRowsOptions<R extends Record<K, readonly { id: number }[]>, K extends keyof R & string, Q extends QueryKey, C> {
  /** The list's read (lib/queries): its answer carries the rows under `list`. */
  query: UseQueryOptions<R, Error, R, Q>
  /** Where the list's answer carries the rows ("plans"). */
  list: K
  /** The list's writes, as the screen makes them (lib/api types each by its address): the rows in their new order — answered with the list again —, a row deleted. */
  reorder: (ids: number[]) => Promise<R>
  remove: (row: R[K][number]) => Promise<void>
  /** One field of a row changed in place — its switch —, answered with the row as the server has it now; a list without one has none. */
  patch?: (row: R[K][number], changes: C) => Promise<R[K][number]>
  /**
   * What else shows the list's rows — another list their names are on, a form that offers them —, read again after each
   * of the list's writes (their `meta.invalidates`); handed back for the save of its editor's form, a write of the list too.
   */
  invalidates?: readonly QueryKey[]
}

/**
 * The admin-ordered lists (plans, categories, payment methods, customer groups, agency levels, channels): the read, and
 * the operations every such screen has on its cached copy — a row saved by its form (`upsert`), one field flipped at
 * once (`patch`, put back if the server refuses), a row moved up or down (`move`), a row deleted. Every write goes into
 * the cache as a change of what is there now, so two quick ones never undo each other, and reaches the server after the
 * one before it (one scope per list, its read's key), so the server ends where the screen does; a failed change reads
 * the list again, and the panel's error toast says why — a refused delete, the dialog that asked (RemoveConfirm). What
 * else shows the rows is read again after each (`invalidates`). In a section kept mounted and hidden, the read passes
 * the live updates by until shown.
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
  // The list's writes, one after the other.
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
    // A list given no `patch` has `never` for its changes: nothing can ask it for one.
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
      // The rows in the new order; one the ids do not name (added meanwhile) stays, at the end.
      write((current) => {
        const byId = new Map(current.map((row) => [row.id, row]))
        return [...ids.flatMap((id) => byId.get(id) ?? []), ...current.filter((row) => !ids.includes(row.id))]
      })
    },
    onSuccess: (data) => write(() => [...data[list]]),
    onError: refetch,
  })

  // A refused delete is said by the dialog that asked (components/sortable-list's RemoveConfirm), which stays open.
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

  return { rows, isPending: read.isPending, error: read.error, refetch, upsert, patch, reorder, remove, move, invalidates }
}
