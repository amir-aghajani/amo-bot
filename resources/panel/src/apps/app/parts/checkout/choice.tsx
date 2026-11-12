import type { ComponentType, CSSProperties, ReactNode } from 'react'
import { Check } from 'lucide-react'
import { selection } from '@/apps/app/telegram/haptics'
import { ACCENT_FILL, IconTile } from '@/apps/app/ui/visuals'
import { cn } from '@/lib/utils'

/*
 * One of a few to pick, as a checkout asks it: as cards (a location, a test account's server) or as the rows of a card
 * (a renewal's method or plan, the way to pay). Each says what it is and a line under it, and the one picked wears a
 * ticked circle — a card the accent's ring too. A row may be out of reach now (the wallet short of the price), with a
 * line under it that says why and the way on.
 */

/** The circle a choice ends with (22px), ticked once picked: the tick (16px) on its centre. */
function Radio({ on }: { on: boolean }) {
  return (
    <span aria-hidden className={cn('grid size-5.5 shrink-0 place-items-center rounded-full border-2 transition-colors', on ? 'border-accent bg-accent text-on-accent' : 'border-hint/45')}>
      {on && <Check className="size-4" />}
    </span>
  )
}

interface ChoiceOption<T> {
  value: T
  title: ReactNode
  /** A line under the title. */
  detail?: ReactNode
  icon?: ComponentType<{ className?: string }>
  /** A row's icon square, in this colour (the accent's by default). */
  tint?: string
  /** At a row's end, before its circle: a price. */
  end?: ReactNode
  /** A row that cannot be picked now; `below` says why. */
  disabled?: boolean
  /** Under a row: why it cannot be picked, and the way on. */
  below?: ReactNode
}

interface ChoicesProps<T> {
  /** What is chosen, for a screen reader: «لوکیشن». */
  label: string
  options: readonly ChoiceOption<T>[]
  value: T | null
  onChange: (value: T) => void
  /** Cards, or the rows of a card the screen's own Section draws. */
  look?: 'cards' | 'rows'
  /** Cards two a row (short names: locations), or one. */
  columns?: 1 | 2
}

/** A row's hairline from where its words start: past its 32px icon and the 12px gap (60px), or at the row's own 16px. */
const ROW_INSET = { icon: { '--row-inset': '3.75rem' } as CSSProperties, none: { '--row-inset': '1rem' } as CSSProperties }

export function Choices<T extends string | number>({ label, options, value, onChange, look = 'cards', columns = 1 }: ChoicesProps<T>) {
  const pick = (option: T) => {
    if (option !== value) selection()
    onChange(option)
  }

  if (look === 'rows') {
    return (
      <div role="radiogroup" aria-label={label}>
        {options.map(({ value: option, title, detail, icon, tint, end, disabled = false, below }) => (
          <div key={option} className="list-row" style={icon === undefined ? ROW_INSET.none : ROW_INSET.icon}>
            <button
              type="button"
              role="radio"
              aria-checked={option === value}
              disabled={disabled}
              onClick={() => pick(option)}
              className="flex min-h-14 w-full items-center gap-3 px-4 text-start transition-colors active:bg-fill disabled:active:bg-transparent"
            >
              {icon !== undefined && <IconTile icon={icon} color={tint ?? ACCENT_FILL} className={cn(disabled && 'opacity-60')} />}
              <span className={cn('flex min-w-0 flex-1 flex-col gap-0.5 py-3', disabled && 'opacity-60')}>
                <span className="text-body font-medium">{title}</span>
                {detail !== undefined && <span className="text-hint text-footnote">{detail}</span>}
              </span>
              {end !== undefined && <span className="shrink-0 text-body">{end}</span>}
              <Radio on={option === value} />
            </button>
            {below}
          </div>
        ))}
      </div>
    )
  }

  return (
    <div role="radiogroup" aria-label={label} className={cn('grid gap-2', columns === 2 && 'grid-cols-2')}>
      {options.map(({ value: option, title, detail, icon }) => {
        const on = option === value

        return (
          <button
            key={option}
            type="button"
            role="radio"
            aria-checked={on}
            onClick={() => pick(option)}
            // Picked, the card's lift and the accent's ring are drawn as one shadow.
            className={cn('card press flex min-h-14 items-center gap-3 p-3 text-start transition-shadow', on && 'inset-ring-accent-text shadow-card inset-ring-2')}
          >
            {icon !== undefined && <IconTile icon={icon} look="soft" round />}
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
              <span className="truncate text-body font-semibold">{title}</span>
              {detail !== undefined && <span className="text-hint text-footnote">{detail}</span>}
            </span>
            <Radio on={on} />
          </button>
        )
      })}
    </div>
  )
}
