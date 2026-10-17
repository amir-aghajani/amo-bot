import { useLayoutEffect, useRef } from 'react'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { formatNumber } from '@/lib/format'

/*
 * A list of rows edited in place — the extras' amounts offered, the builder's price tiers, a card method's cards —: each
 * row keyed by the draft's own number, removed by its button, and an add button after them.
 */

/** A new row's key: one past the list's largest (0 for the first). The draft's own, never sent. */
export function nextKey(keys: readonly number[]): number {
  return Math.max(-1, ...keys) + 1
}

/** Where the focus goes once the rows have changed: a row's part, by the row's key — or the add button. */
type FocusTarget = { row: number; part: string } | 'add'

/**
 * The focus of a list of rows, so it never falls to the document as rows come and go: a new row's part takes it, a
 * removed row hands it to the remove of the row that takes its place (or the one before), the last one gone to the add.
 * In `block`, each row is marked `data-row={key(row)}` and its parts `data-part` (its remove `remove`); AddRow adds.
 */
export function useRowFocus<Row>(key: (row: Row) => number) {
  const block = useRef<HTMLDivElement>(null)
  const focus = useRef<FocusTarget | null>(null)
  useLayoutEffect(() => {
    const target = focus.current
    if (target === null) return
    focus.current = null
    const selector = target === 'add' ? '[data-add]' : `[data-row="${target.row}"] [data-part="${target.part}"]`
    block.current?.querySelector<HTMLElement>(selector)?.focus()
  })

  return {
    block,
    /** `row` is being added: its `part` takes the focus once drawn. */
    added: (row: Row, part: string) => {
      focus.current = { row: key(row), part }
    },
    /** The row at `index` is being removed, `left` the rows that stay. */
    removed: (left: readonly Row[], index: number) => {
      const neighbour = left[index] ?? left[index - 1]
      focus.current = neighbour === undefined ? 'add' : { row: key(neighbour), part: 'remove' }
    },
  }
}

interface AddRowProps {
  /** What a row is called: «کارت», «پله», «مقدار». */
  noun: string
  /** The rows the list holds, and the most it may (the server's bound, lib/limits). */
  count: number
  most: number
  onAdd: () => void
  /** Said beside the button while there is no row; nothing for a list that may well be empty. */
  empty?: string
  /** The whole list waits as it is kept (a program switched off): no row is added. */
  disabled?: boolean
}

/**
 * A list's add button, held — not disabled — once the list is full (just pressed, it keeps the focus) with the most it
 * holds beside it; and what an empty list lacks. Disabled while the list waits (`disabled`).
 */
export function AddRow({ noun, count, most, onAdd, empty, disabled = false }: AddRowProps) {
  const full = count >= most

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
      <Button data-add variant="secondary" size="sm" icon={Plus} disabled={disabled} aria-disabled={full} onClick={full ? undefined : onAdd}>
        {`افزودن ${noun}`}
      </Button>
      {full && <span className="text-footnote text-muted-foreground">{`حداکثر ${formatNumber(most)} ${noun}؛ برای ${noun} تازه یکی را بردارید.`}</span>}
      {count === 0 && empty && <span className="text-footnote text-muted-foreground">{empty}</span>}
    </div>
  )
}
