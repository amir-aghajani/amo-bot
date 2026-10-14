import { useState } from 'react'

/**
 * The rows ticked in a paged list (the subscriptions' to move, the payments' receipts to dismiss), kept across its pages
 * and cleared by another `scope` (tab, filter, search); `refresh` swaps in a returned row.
 */
export function useSelection<Row extends { id: number }>(scope: string) {
  const [selected, setSelected] = useState<{ scope: string; rows: ReadonlyMap<number, Row> }>({ scope, rows: new Map() })
  const rows = selected.scope === scope ? selected.rows : new Map<number, Row>()
  const change = (next: (current: ReadonlyMap<number, Row>) => ReadonlyMap<number, Row>) => setSelected((current) => ({ scope, rows: next(current.scope === scope ? current.rows : new Map()) }))

  /** Tick or untick rows. */
  const set = (some: readonly Row[], on: boolean) =>
    change((current) => {
      const next = new Map(current)
      for (const row of some) {
        if (on) next.set(row.id, row)
        else next.delete(row.id)
      }
      return next
    })

  return {
    size: rows.size,
    has: (id: number) => rows.has(id),
    rows: () => [...rows.values()],
    set,
    /**
     * The header's box over a page's rows that may be ticked: ticked while all of them are, half while some are, held
     * while there is none; it ticks or unticks them all. Its props, for the Checkbox.
     */
    page: (some: readonly Row[]) => {
      const ticked = some.filter((row) => rows.has(row.id)).length
      return {
        checked: ticked === 0 ? false : ticked === some.length ? true : ('indeterminate' as const),
        disabled: some.length === 0,
        onCheckedChange: (value: boolean | 'indeterminate') => set(some, value === true),
      }
    },
    refresh: (row: Row) => change((current) => (current.has(row.id) ? new Map(current).set(row.id, row) : current)),
    clear: () => change(() => new Map()),
  }
}

/** A list's selection (useSelection()). */
export type Selection<Row extends { id: number }> = ReturnType<typeof useSelection<Row>>
