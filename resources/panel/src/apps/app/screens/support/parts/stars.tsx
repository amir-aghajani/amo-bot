import { Star } from 'lucide-react'
import { selection } from '@/apps/app/telegram/haptics'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * Stars: five to pick a rating with (a ticket's, a review's) — each choice a step the phone feels —, and a rating shown,
 * an average's last star filled in part. The app reads right to left: the first star is the rightmost, and a part-filled
 * one fills from the right.
 */

/** 14px in a line of facts, 20px where they stand alone. */
const SIZES = { sm: 'size-3.5', md: 'size-5' } as const

/**
 * A star filled, and one not: the empty one faded as a whole (its opacity, not its colour's), so where its outline
 * crosses its fill it draws no darker ring.
 */
const FULL = 'fill-current text-warning'
const EMPTY = 'fill-current text-hint opacity-30'

/** A rating shown: `value` of five stars (a fraction fills the last in part). */
export function Stars({ value, size = 'sm', className }: { value: number; size?: keyof typeof SIZES; className?: string }) {
  return (
    <span className={cn('inline-flex items-center gap-0.5', className)} role="img" aria-label={`${formatNumber(Math.round(value * 10) / 10)} از ۵ ستاره`}>
      {[1, 2, 3, 4, 5].map((star) => {
        const fill = Math.min(1, Math.max(0, value - star + 1))
        return (
          <span key={star} className="relative inline-grid">
            <Star className={cn(SIZES[size], EMPTY)} aria-hidden />
            {fill > 0 && <Star className={cn(SIZES[size], FULL, 'absolute inset-0')} style={{ clipPath: `inset(0 0 0 ${(1 - fill) * 100}%)` }} aria-hidden />}
          </span>
        )
      })}
    </span>
  )
}

/** Five stars to pick from — 32px, each tapped over 44px; 0 is none picked yet. */
export function StarPicker({ value, onChange, label = 'امتیاز' }: { value: number; onChange: (value: number) => void; label?: string }) {
  return (
    <div className="flex items-center justify-center gap-1" role="radiogroup" aria-label={label}>
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          role="radio"
          aria-checked={value === star}
          aria-label={`${formatNumber(star)} ستاره`}
          onClick={() => {
            if (star !== value) selection()
            onChange(star)
          }}
          className="press grid size-11 place-items-center rounded-full"
        >
          <Star className={cn('size-8 transition-[color,opacity] duration-200', star <= value ? FULL : EMPTY)} aria-hidden />
        </button>
      ))}
    </div>
  )
}
