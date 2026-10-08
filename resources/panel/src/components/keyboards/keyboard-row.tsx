import { Fragment, type ComponentProps } from 'react'
import { GripVertical, Pencil, Plus, Trash2 } from 'lucide-react'
import { usePlainEmoji } from '@/components/bot-texts/custom-emojis'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import { IconButton } from '@/components/icon-button'
import { styleOption } from '@/components/keyboards/styles'
import type { useRowDrag } from '@/components/keyboards/use-row-drag'
import { OrderControls } from '@/components/order-controls'
import { Button } from '@/components/ui/button'
import type { KeyboardButtonSpec } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

interface KeyboardRowProps {
  row: KeyboardButtonSpec[]
  /** The row's place on the keyboard. */
  index: number
  /** How many rows the keyboard has. */
  count: number
  /** A button may be added to it: the row has room, and an action is left to place. */
  canAdd: boolean
  /** The save's refusals about this row or one of its buttons. */
  problems: string[]
  drag: ReturnType<typeof useRowDrag>
  onMove: (delta: -1 | 1) => void
  onRemove: () => void
  onAdd: () => void
  /** Open the button at this index of the row. */
  onEdit: (index: number) => void
}

/** One row of the keyboard: its buttons in reading order (the first on the right), each opened by a click and dragged anywhere. */
export function KeyboardRow({ row, index, count, canAdd, problems, drag, onMove, onRemove, onAdd, onEdit }: KeyboardRowProps) {
  const plainOf = usePlainEmoji()
  const insertAt = drag.insertAt(index)
  const name = `ردیف ${formatNumber(index + 1)}`

  return (
    <li className={cn('rounded-xl border bg-card p-3 transition-colors', problems.length > 0 ? 'border-danger-line' : insertAt !== null ? 'border-selected' : 'border-border')}>
      <div className="mb-2 flex items-center gap-2 text-footnote text-muted-foreground">
        <span>{name}</span>
        <span className="ms-auto flex items-center gap-0.5">
          <OrderControls label={name} index={index} count={count} onMove={onMove} />
          <IconButton aria-label={`حذف ${name}`} className="size-6 hover:text-danger" onClick={onRemove}>
            <Trash2 className="size-3.5" aria-hidden />
          </IconButton>
        </span>
      </div>

      <div className="flex min-h-10 flex-wrap items-center gap-2" {...drag.row(index)}>
        {row.map((button, b) => (
          <Fragment key={`${button.action}-${b}`}>
            {insertAt === b && <DropMarker />}
            <ButtonChip
              button={button}
              plain={button.icon ? plainOf(button.icon) : ''}
              lifted={drag.dragging?.row === index && drag.dragging.index === b}
              onClick={() => onEdit(b)}
              {...drag.chip({ row: index, index: b })}
            />
          </Fragment>
        ))}
        {insertAt === row.length && <DropMarker />}
        <Button variant="ghost" size="sm" icon={Plus} className="h-9 border border-dashed border-border-strong" onClick={onAdd} aria-disabled={!canAdd}>
          افزودن دکمه
        </Button>
      </div>

      {problems.length > 0 && (
        <ul className="mt-2 grid gap-0.5 text-footnote text-danger">
          {problems.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
    </li>
  )
}

interface ButtonChipProps extends ComponentProps<'button'> {
  button: KeyboardButtonSpec
  /** The plain emoji behind the button's icon, shown until its picture arrives. */
  plain: string
  /** It is the one being dragged. */
  lifted: boolean
}

/** A button as a chip: its icon, label and colour; a click opens it, a drag moves it. */
function ButtonChip({ button, plain, lifted, ...props }: ButtonChipProps) {
  const option = styleOption(button.style)

  return (
    <button
      type="button"
      data-chip
      className={cn(
        'group inline-flex h-9 cursor-grab items-center gap-2 rounded-lg border bg-card px-2.5 text-body transition-colors outline-none hover:border-foreground/40 focus-visible:focus-ring active:cursor-grabbing',
        lifted && 'opacity-40',
      )}
      style={{ borderColor: button.style ? option.swatch : undefined }}
      {...props}
    >
      <GripVertical className="size-3.5 text-faint" aria-hidden />
      {button.icon && <PremiumEmoji id={button.icon} fallback={plain} className="shrink-0" />}
      <span className="max-w-48 truncate">{button.label}</span>
      <span aria-hidden className="size-2 rounded-full" style={{ background: button.style ? option.swatch : 'var(--fg-3)', opacity: button.style ? 1 : 0.6 }} />
      {button.style && <span className="sr-only">({option.colorName})</span>}
      <Pencil className="size-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" aria-hidden />
    </button>
  )
}

/** Where the dragged button will land. */
function DropMarker() {
  return <span aria-hidden className="h-9 w-0.5 rounded-full bg-selected" />
}
