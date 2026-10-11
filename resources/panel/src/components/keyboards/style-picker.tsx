import { useId, useRef, type KeyboardEvent } from 'react'
import { Check } from 'lucide-react'
import type { StyleOption } from '@/components/keyboards/styles'
import type { ButtonStyle } from '@/lib/api-types'
import { isRtl } from '@/lib/direction'
import { cn } from '@/lib/utils'

interface StylePickerProps {
  options: StyleOption[]
  value: ButtonStyle | null
  onChange: (style: ButtonStyle | null) => void
  /** What the group is called: a keyboard button's colour, or a plan's button's. */
  label?: string
  /** The choices' grid — side by side where the form has the room. */
  className?: string
}

/** A radio group of Telegram's button colours (a keyboard button, a plan's button): arrows move the pick, Tab leaves. */
export function StylePicker({ options, value, onChange, label = 'رنگ دکمه', className }: StylePickerProps) {
  const id = useId()
  const radios = useRef(new Map<ButtonStyle | null, HTMLButtonElement>())
  const picked = options.some((option) => option.value === value)

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const [forward, backward] = isRtl() ? ['ArrowLeft', 'ArrowRight'] : ['ArrowRight', 'ArrowLeft']
    const step = event.key === 'ArrowDown' || event.key === forward ? 1 : event.key === 'ArrowUp' || event.key === backward ? -1 : 0
    if (step === 0) return
    event.preventDefault()
    const index = options.findIndex((option) => option.value === value)
    const next = options[(index + step + options.length) % options.length]
    if (!next) return
    onChange(next.value)
    radios.current.get(next.value)?.focus()
  }

  return (
    <div className="grid gap-2">
      <p id={id} className="mb-1 text-body font-medium">
        {label}
      </p>
      <div role="radiogroup" aria-labelledby={id} onKeyDown={onKeyDown} className={cn('grid gap-2', className)}>
        {options.map((option, i) => {
          const selected = option.value === value
          return (
            <button
              key={option.value ?? 'default'}
              ref={(element) => {
                if (element) radios.current.set(option.value, element)
                else radios.current.delete(option.value)
              }}
              type="button"
              role="radio"
              aria-checked={selected}
              // One stop for Tab: the picked colour (the first, when none of these is).
              tabIndex={selected || (!picked && i === 0) ? 0 : -1}
              onClick={() => onChange(option.value)}
              className={cn(
                'flex items-center gap-3 rounded-lg border px-3 py-2.5 text-start transition-colors duration-150 outline-none focus-visible:focus-ring',
                selected ? 'border-selected bg-info-soft/40' : 'border-border hover:bg-fill',
              )}
            >
              <span aria-hidden className="size-9 shrink-0 rounded-lg border border-white/10" style={{ background: option.swatch }} />
              <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="text-body font-medium">{option.title}</span>
                <span className="text-footnote text-muted-foreground">
                  {option.value ? (
                    <>
                      <span dir="ltr">{option.value}</span> — {option.colorName}
                    </>
                  ) : (
                    option.colorName
                  )}
                </span>
              </span>
              {selected && <Check className="size-4 text-selected" aria-hidden />}
            </button>
          )
        })}
      </div>
    </div>
  )
}
