import { useLayoutEffect, useRef, type ReactNode } from 'react'
import { Plus, X } from 'lucide-react'
import { FieldGroup } from '@/components/field'
import { IconButton } from '@/components/icon-button'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * A row as typed: an amount under `K` (a price tier's `from`, an amount offered's `quantity`) and its price. `id` is the
 * draft's own, so rows keep their elements and focus when one is removed; it is never sent.
 */
export type PricedRowDraft<K extends string> = { id: number; price: string } & Record<K, string>

/** A row's names, from its place in the list: its own («پله ۱»), its amount field's and its price field's. */
interface RowNames {
  row: string
  amount: string
  price: string
}

interface PricedRowsEditorProps<K extends string> {
  /** The list's name, its group's label: «حجم‌های پیشنهادی», «قیمت هر گیگابایت». */
  label: string
  /** A line under the label about the rows. */
  description: ReactNode
  error?: string
  rows: PricedRowDraft<K>[]
  onChange: (rows: PricedRowDraft<K>[]) => void
  /** The rows' amount field, the server's name for it: `from`, `quantity`. */
  amount: K
  /** The columns' headings over the amounts and the prices. */
  headings: { amount: string; price: string }
  /** The amounts' column is wider, for a heading that says more than a unit («از (گیگابایت)»). */
  wide?: boolean
  /** A row's names by its place, «۱» on. */
  names: (place: string) => RowNames
  /** An amount's placeholder: guidance, in Latin digits. */
  example: string
  /** A row's price placeholder — what its price left blank comes to —; none where blank means nothing. */
  pricePlaceholder?: (row: PricedRowDraft<K>) => string | undefined
  /** What a row is called on the add button and in the «حداکثر» line: «پله», «مقدار». */
  noun: string
  /** How many rows the list holds at most (the server's bound, lib/limits). */
  most: number
  /** Said beside the add button while there is no row; nothing for a list that may well be empty. */
  empty?: string
}

/** Where the focus goes once the rows have changed: a row's part — the new row's amount, the remove beside a removed row — or the add. */
type FocusTarget = { row: number; part: 'amount' | 'remove' } | 'add'

/**
 * A list of amounts and their prices as rows — the extras' amounts offered, the custom builder's price tiers —: each
 * row's amount, price and remove, then an add button that is held, not disabled, once the list is full. The focus never
 * falls to the document as rows come and go: a new row's amount takes it, a removed row hands it to the remove of the
 * row that takes its place (or the one before), the last one gone to the add.
 */
export function PricedRowsEditor<K extends string>({
  label,
  description,
  error,
  rows,
  onChange,
  amount,
  headings,
  wide = false,
  names,
  example,
  pricePlaceholder,
  noun,
  most,
  empty,
}: PricedRowsEditorProps<K>) {
  const full = rows.length >= most
  const columns = cn('grid gap-2', wide ? 'grid-cols-[5.5rem_minmax(0,1fr)_2rem]' : 'grid-cols-[4.5rem_minmax(0,1fr)_2rem]')

  const block = useRef<HTMLDivElement>(null)
  const focus = useRef<FocusTarget | null>(null)
  useLayoutEffect(() => {
    const target = focus.current
    if (target === null) return
    focus.current = null
    const selector = target === 'add' ? '[data-add]' : `[data-row="${target.row}"] [data-part="${target.part}"]`
    block.current?.querySelector<HTMLElement>(selector)?.focus()
  })

  const add = () => {
    if (full) return
    const id = Math.max(-1, ...rows.map((row) => row.id)) + 1
    focus.current = { row: id, part: 'amount' }
    onChange([...rows, { id, price: '', [amount]: '' } as PricedRowDraft<K>])
  }
  const remove = (index: number) => {
    const next = rows.filter((_, i) => i !== index)
    const neighbour = next[index] ?? next[index - 1]
    focus.current = neighbour ? { row: neighbour.id, part: 'remove' } : 'add'
    onChange(next)
  }
  const edit = (index: number, field: K | 'price', value: string) => onChange(rows.map((row, i) => (i === index ? { ...row, [field]: value } : row)))

  return (
    <div ref={block}>
      <FieldGroup label={label} description={description} error={error} className="gap-2">
        {rows.length > 0 && (
          <div className="grid gap-1.5">
            <div aria-hidden className={cn(columns, 'text-caption text-muted-foreground')}>
              <span>{headings.amount}</span>
              <span>{headings.price}</span>
            </div>
            <ul className="grid gap-2">
              {rows.map((row, index) => {
                const name = names(formatNumber(index + 1))
                return (
                  <li key={row.id} data-row={row.id} className={cn(columns, 'items-center')}>
                    <Input
                      data-part="amount"
                      dir="ltr"
                      inputMode="numeric"
                      autoComplete="off"
                      aria-label={name.amount}
                      placeholder={example}
                      value={row[amount]}
                      onChange={(e) => edit(index, amount, e.target.value)}
                    />
                    <Input
                      dir="ltr"
                      inputMode="numeric"
                      autoComplete="off"
                      aria-label={name.price}
                      placeholder={pricePlaceholder?.(row)}
                      value={row.price}
                      onChange={(e) => edit(index, 'price', e.target.value)}
                    />
                    <IconButton data-part="remove" aria-label={`حذف ${name.row}`} onClick={() => remove(index)}>
                      <X className="size-4" aria-hidden />
                    </IconButton>
                  </li>
                )
              })}
            </ul>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          {/* Held, not disabled, once the list is full: just pressed, it keeps the focus. */}
          <Button data-add variant="secondary" size="sm" icon={Plus} aria-disabled={full} onClick={add}>
            {`افزودن ${noun}`}
          </Button>
          {full && <span className="text-footnote text-muted-foreground">{`حداکثر ${formatNumber(most)} ${noun}؛ برای ${noun} تازه یکی را بردارید.`}</span>}
          {rows.length === 0 && empty && <span className="text-footnote text-muted-foreground">{empty}</span>}
        </div>
      </FieldGroup>
    </div>
  )
}
