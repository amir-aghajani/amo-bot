import { useState } from 'react'
import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { ApiError } from '@/lib/api'

interface UseOpenRowOptions<Row> {
  /** The row's own query key (`queryKeys.payment`), which the live updates reload with its kind. */
  queryKey: (id: number) => QueryKey
  /** Reads the row again. */
  read: (id: number) => Promise<Row>
  /** The list it is opened from, which shows a row an operation handed back as well, and is read again with the row. */
  list: { replace: (row: Row) => void; refetch: () => void }
}

/**
 * The row a list page has open in its modal, kept fresh while it is open: the copy the page handed in (from the
 * list, or an operation's answer) until the row's own query has read it again since — and the live updates reload
 * that query whenever its kind changes, so the modal follows the row even after it left the list (another tab, a
 * filter it no longer matches). A row deleted meanwhile (its read answers 404 — a service deleted elsewhere, an unpaid
 * order that expired) is `gone`: the modal keeps what it last showed, says so and offers nothing more.
 */
export function useOpenRow<Row extends { id: number }>({ queryKey, read, list }: UseOpenRowOptions<Row>) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState<{ row: Row; at: number } | null>(null)
  const id = open?.row.id ?? 0
  const fresh = useQuery({ queryKey: queryKey(id), queryFn: () => read(id), enabled: open !== null })

  const row = open === null ? null : fresh.data && fresh.data.id === open.row.id && fresh.dataUpdatedAt >= open.at ? fresh.data : open.row
  const gone = open !== null && fresh.error instanceof ApiError && fresh.error.status === 404 && fresh.errorUpdatedAt >= open.at

  return {
    row,
    gone,
    /** Open a row (a list's copy) — or close the modal with null. */
    open: (next: Row | null) => setOpen(next && { row: next, at: Date.now() }),
    /** A row an operation handed back: into the list, and into the modal at once when it is the open one. */
    apply: (next: Row) => {
      list.replace(next)
      setOpen((current) => (current?.row.id === next.id ? { row: next, at: Date.now() } : current))
    },
    /** The server refused because the row moved on (decided, cancelled, deleted meanwhile): the row and its list are read again. */
    refresh: () => {
      if (open !== null) void queryClient.invalidateQueries({ queryKey: queryKey(open.row.id) })
      list.refetch()
    },
  }
}
