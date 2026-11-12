import type { ComponentType, CSSProperties, ReactNode } from 'react'
import { Minus, Plus } from 'lucide-react'
import { selection } from '@/apps/app/telegram/haptics'
import { Chip } from '@/apps/app/ui/fields'
import { Section } from '@/apps/app/ui/section'
import { IconTile } from '@/apps/app/ui/visuals'

/*
 * A quantity the customer chooses between its bounds (a built service's GB and days): its value large, a slider and a
 * step either way, and the amounts the shop offers as chips. Every step is felt (Telegram's selection touch). The
 * slider reads right to left as the page does: the least at the right.
 */

interface QuantityProps {
  /** What it is: «حجم», «مدت». */
  label: string
  value: number
  min: number
  max: number
  /** The amounts offered as buttons. */
  options: readonly number[]
  /** It in words: «۵۰ گیگابایت». */
  format: (value: number) => string
  onChange: (value: number) => void
  /** Under the card: its price, its rate. */
  footer?: ReactNode
}

/**
 * The slider: a 24px round thumb on a 6px track filled with the accent up to it (from the right) — the thumb raised half
 * its height less half the track's (9px), so it sits on the track's centre line.
 */
const SLIDER =
  'h-8 w-full cursor-pointer appearance-none bg-transparent outline-none ' +
  '[&::-webkit-slider-runnable-track]:h-1.5 [&::-webkit-slider-runnable-track]:rounded-full [&::-webkit-slider-runnable-track]:[background:var(--track)] ' +
  '[&::-webkit-slider-thumb]:-mt-[9px] [&::-webkit-slider-thumb]:size-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-[3px] [&::-webkit-slider-thumb]:border-[var(--app-accent)] [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:shadow-[0_1px_5px_rgb(0_0_0/0.25)] ' +
  '[&::-moz-range-track]:h-1.5 [&::-moz-range-track]:rounded-full [&::-moz-range-track]:[background:var(--track)] ' +
  '[&::-moz-range-thumb]:size-5 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-[3px] [&::-moz-range-thumb]:border-[var(--app-accent)] [&::-moz-range-thumb]:bg-white'

export function Quantity({ label, value, min, max, options, format, onChange, footer }: QuantityProps) {
  const clamp = (next: number) => Math.min(max, Math.max(min, Math.round(next)))
  const step = (next: number) => {
    const settled = clamp(next)
    if (settled === value) return
    selection()
    onChange(settled)
  }
  const filled = max > min ? ((value - min) / (max - min)) * 100 : 100
  const track = { '--track': `linear-gradient(to left, var(--app-accent) ${filled}%, var(--app-fill-strong) ${filled}%)` } as CSSProperties

  return (
    <Section title={label} footer={footer}>
      <div className="flex flex-col gap-3 p-4">
        <div className="flex items-center justify-between gap-3">
          <StepButton label={`کم کردن ${label}`} icon={Minus} disabled={value <= min} onClick={() => step(value - 1)} />
          <output className="min-w-0 truncate text-center text-title font-extrabold" aria-live="polite">
            {format(value)}
          </output>
          <StepButton label={`افزودن ${label}`} icon={Plus} disabled={value >= max} onClick={() => step(value + 1)} />
        </div>
        <div className="flex flex-col">
          <input type="range" min={min} max={max} step={1} value={value} aria-label={label} onChange={(event) => step(Number(event.target.value))} className={SLIDER} style={track} />
          <div className="text-hint flex justify-between text-caption">
            <span>{format(min)}</span>
            <span>{format(max)}</span>
          </div>
        </div>
        {options.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {options.map((option) => (
              // The chip gives its own touch.
              <Chip key={option} active={option === value} onClick={() => onChange(option)}>
                {format(option)}
              </Chip>
            ))}
          </div>
        )}
      </div>
    </Section>
  )
}

/** A round − or + beside the value: a 40px circle, tapped over 44px. */
function StepButton({ label, icon, disabled, onClick }: { label: string; icon: ComponentType<{ className?: string }>; disabled: boolean; onClick: () => void }) {
  return (
    <button type="button" aria-label={label} disabled={disabled} onClick={onClick} className="press tap-target relative grid shrink-0 place-items-center rounded-full disabled:opacity-35">
      <IconTile icon={icon} size="lg" look="soft" round />
    </button>
  )
}
