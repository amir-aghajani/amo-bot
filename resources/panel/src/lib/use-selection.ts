import { useState } from 'react'

/**
 * The rows ticked in a paged list (the subscriptions' to move, the payments' receipts to dismiss), kept across its pages
 * and cleared by another `scope` (tab, filter, search); `refresh` swaps in a returned row, and `follow` keeps the ticks in
 * step with the rows as the list reads them again.
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
    /**
     * Keeps the ticks in step with the list's rows as it reads them again (the live updates, another tab, the bot): a
     * ticked row on screen is held as it is now, and one that may no longer be ticked (`tickable` — a receipt decided
     * meanwhile, whose box is gone) is unticked, so a count and what acts on the ticks are the rows as they stand. Called
     * in the list's own render, where the selection's state lives (state adjusted during render), in one update.
     */
    follow: (shown: readonly Row[], tickable: (row: Row) => boolean = () => true) => {
      if (rows.size === 0) return
      let next: Map<number, Row> | null = null
      for (const row of shown) {
        const held = rows.get(row.id)
        if (held === undefined) continue
        if (!tickable(row)) (next ??= new Map(rows)).delete(row.id)
        else if (held !== row) (next ??= new Map(rows)).set(row.id, row)
      }
      if (next !== null) setSelected({ scope, rows: next })
    },
    clear: () => change(() => new Map()),
  }
}

/** A list's selection (useSelection()). */
export type Selection<Row extends { id: number }> = ReturnType<typeof useSelection<Row>>
