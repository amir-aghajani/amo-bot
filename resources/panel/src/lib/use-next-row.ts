import { useState } from 'react'
import { useIsFetching, type QueryKey } from '@tanstack/react-query'
import type { PageMeta } from '@/lib/api-types'

/** What a paged list (usePagedList) tells about where it is. */
interface Queue<Row> {
  rows: readonly Row[]
  meta: PageMeta | undefined
  setPage: (page: number) => void
  isPlaceholderData: boolean
}

interface UseNextRowOptions<Row> {
  /** The list's own key: while its pages are read again — a decision just moved a row —, the next waits for them. */
  queryKey: QueryKey
  list: Queue<Row>
  /** The row open in the list's dialog (useOpenRow), and the way to open another. */
  open: Row | null
  onOpen: (row: Row) => void
}

/** «بعدی» as its dialog draws it. */
export interface NextRow {
  /** There is one: a row after the open one, or another page. */
  available: boolean
  /** It is on its way: the list is being read again, or the next page is. */
  busy: boolean
  go: () => void
}

/** What the press waits for: the list read again after a decision, or the page it turned to. */
type Waiting = { for: 'list' } | { for: 'page'; page: number }

/**
 * «بعدی» in a list's detail dialog: the row after the open one, in the list's own order — the next page's first at the
 * end of a page —, so a queue is worked through without closing the dialog (the payments' receipts). When a decision
 * took the open row out of the list (a receipt reviewed leaves the review tab), the next is the row that came to its
 * place. A press while the list is being read again waits for it, so the next is always the list's as it is now.
 */
export function useNextRow<Row extends { id: number }>({ queryKey, list, open, onOpen }: UseNextRowOptions<Row>): NextRow {
  const fetching = useIsFetching({ queryKey }) > 0
  const [waiting, setWaiting] = useState<Waiting | null>(null)
  // Where the open row was last seen in the list: its place, and the row after it (state adjusted during render).
  const [seen, setSeen] = useState<{ id: number; index: number; after: number | null } | null>(null)

  const index = open === null ? -1 : list.rows.findIndex((row) => row.id === open.id)
  if (open !== null && index >= 0) {
    const after = list.rows[index + 1]?.id ?? null
    if (seen?.id !== open.id || seen.index !== index || seen.after !== after) setSeen({ id: open.id, index, after })
  }

  const following = (): Row | undefined => {
    if (open === null) return undefined
    if (index >= 0) return list.rows[index + 1]
    if (seen?.id !== open.id) return undefined
    return list.rows.find((row) => row.id === seen.after) ?? list.rows[seen.index]
  }
  const nextPage = list.meta !== undefined && list.meta.page < list.meta.last_page ? list.meta.page + 1 : null

  /** The next row opened — or the page it is on asked for —, from the list as it stands. */
  const advance = () => {
    const row = following()
    if (row !== undefined) {
      setWaiting(null)
      onOpen(row)
    } else if (nextPage !== null) {
      setWaiting({ for: 'page', page: nextPage })
      list.setPage(nextPage)
    } else {
      setWaiting(null)
    }
  }

  // A press that waited: the list it waited for has come (state adjusted during render). A page the server answered
  // with another (the list shrank meanwhile) opens nothing.
  if (waiting !== null && !fetching && !list.isPlaceholderData) {
    if (waiting.for === 'list') advance()
    else {
      setWaiting(null)
      const [first] = list.rows
      if (list.meta?.page === waiting.page && first !== undefined) onOpen(first)
    }
  }

  return {
    available: open !== null && (following() !== undefined || nextPage !== null),
    busy: waiting !== null,
    go: () => (fetching ? setWaiting({ for: 'list' }) : advance()),
  }
}
