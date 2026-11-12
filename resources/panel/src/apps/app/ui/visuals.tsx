import { useEffect, useState, type ComponentType, type ReactNode } from 'react'
import { formatAmount, formatCountdown, formatNumber, MONEY_UNIT } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * The app's small pictures: an icon in its square or circle (one set of sizes for the whole app), a ring that fills (a
 * service's traffic and days) with its value at its centre, a bar, a badge, a price with what it was before a discount
 * struck through, and a countdown. Every size here is whole pixels, so what sits at a centre is drawn there.
 */

/** Each tone's colour. These are marks on the page, so the accent is its shade that reads there (--app-accent-text). */
const TONES = { accent: 'var(--app-accent-text)', success: 'var(--app-success)', warning: 'var(--app-warning)', danger: 'var(--app-danger)', hint: 'var(--app-hint)' } as const
export type Tone = keyof typeof TONES

/** The tone of what is left of something (traffic, days): calm while there is plenty, warning past three quarters used. */
export function toneLeft(usedFraction: number): Tone {
  if (usedFraction >= 0.9) return 'danger'
  if (usedFraction >= 0.75) return 'warning'
  return 'accent'
}

/** The shop's colour as a fill: a square or tile drawn in it. */
export const ACCENT_FILL = 'var(--app-accent)'

/** The ink of an icon on a square filled `fill`: the shop's colour takes its own (dark on a light one), the app's tints white. */
function inkOn(fill: string): string {
  return fill === ACCENT_FILL ? 'var(--app-on-accent)' : '#ffffff'
}

/** An icon's square, by size: the box, its radius and the icon in it — the box less the icon even, so the icon is centred. */
const TILES = {
  sm: { box: 'size-7 rounded-lg', icon: 'size-4' },
  md: { box: 'size-8 rounded-[10px]', icon: 'size-4.5' },
  lg: { box: 'size-10 rounded-xl', icon: 'size-5' },
  xl: { box: 'size-12 rounded-[14px]', icon: 'size-6' },
  '2xl': { box: 'size-16 rounded-[18px]', icon: 'size-8' },
} as const

type TileSize = keyof typeof TILES

interface IconTileProps {
  icon: ComponentType<{ className?: string; strokeWidth?: number }>
  /** 28, 32, 40, 48 or 64px, the icon 16, 18, 20, 24 or 32px in it. */
  size?: TileSize
  /** A CSS colour: the shop's (ACCENT_FILL, the default) or one of the app's tints. */
  color?: string
  /** `solid`: filled in the colour, the icon in its ink; `soft`: a light wash of it, the icon in the colour. */
  look?: 'solid' | 'soft'
  /** A circle instead of a rounded square. */
  round?: boolean
  className?: string
}

/**
 * An icon in its square (or circle): the one way the app draws an icon on a colour — a list's row, a choice, a tile, an
 * empty screen. The shop's colour as a wash shows its readable shade (--app-accent-text).
 */
export function IconTile({ icon: Icon, size = 'md', color = ACCENT_FILL, look = 'solid', round = false, className }: IconTileProps) {
  const tile = TILES[size]
  const solid = look === 'solid'
  const ink = color === ACCENT_FILL ? 'var(--app-accent-text)' : color

  return (
    <span
      aria-hidden
      className={cn('grid shrink-0 place-items-center', tile.box, round && 'rounded-full', className)}
      style={solid ? { background: color, color: inkOn(color) } : { background: `color-mix(in srgb, ${ink} 14%, transparent)`, color: ink }}
    >
      <Icon className={tile.icon} />
    </span>
  )
}

interface RingProps {
  /** How much of it is filled, 0 to 1 (null: no limit — a full ring, faint). */
  value: number | null
  size?: number
  stroke?: number
  tone?: Tone
  children?: ReactNode
}

/** A ring that fills from the top, clockwise, to `value` — drawn in once it is shown. */
export function Ring({ value, size = 96, stroke = 8, tone = 'accent', children }: RingProps) {
  const [drawn, setDrawn] = useState(0)
  const radius = (size - stroke) / 2
  const length = 2 * Math.PI * radius
  const target = value === null ? 1 : Math.min(1, Math.max(0, value))
  useEffect(() => {
    const frame = requestAnimationFrame(() => setDrawn(target))
    return () => cancelAnimationFrame(frame)
  }, [target])

  return (
    <div className="relative grid shrink-0 place-items-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" aria-hidden>
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--app-fill-strong)" strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={TONES[tone]}
          strokeOpacity={value === null ? 0.35 : 1}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={length}
          strokeDashoffset={length * (1 - drawn)}
          style={{ transition: 'stroke-dashoffset 0.9s cubic-bezier(0.2, 0.8, 0.2, 1)' }}
        />
      </svg>
      <div className="absolute inset-0 grid place-items-center text-center">{children}</div>
    </div>
  )
}

/** What a ring holds at its centre: its number, large, and its unit under it — one block, centred as one. */
export function RingValue({ value, unit, size = 'md' }: { value: ReactNode; unit?: ReactNode; size?: 'sm' | 'md' | 'lg' }) {
  return (
    <span className="flex flex-col items-center gap-1 leading-none">
      <span className={cn('font-bold', size === 'sm' ? 'text-body' : size === 'md' ? 'text-heading' : 'text-title')}>{value}</span>
      {unit !== undefined && <span className="text-hint text-micro font-medium">{unit}</span>}
    </span>
  )
}

/** A bar filled to `value` (0 to 1). */
export function Bar({ value, tone = 'accent', className }: { value: number; tone?: Tone; className?: string }) {
  return (
    <div className={cn('bg-fill-strong h-1.5 overflow-hidden rounded-full', className)} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(value * 100)}>
      <div className="h-full rounded-full transition-[width] duration-700" style={{ width: `${Math.min(100, Math.max(0, value * 100))}%`, background: TONES[tone] }} />
    </div>
  )
}

/** A small label in a tinted pill, 24px high, its words on its centre line. */
export function Badge({ tone = 'accent', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  const color = TONES[tone]

  return (
    <span
      className={cn('inline-flex h-6 shrink-0 items-center gap-1 rounded-full px-2.5 text-caption leading-none font-semibold whitespace-nowrap [&_svg]:size-3.5', className)}
      style={{ background: `color-mix(in srgb, ${color} 15%, transparent)`, color: `color-mix(in srgb, ${color} 88%, var(--app-text))` }}
    >
      {children}
    </span>
  )
}

/** A price in Toman — and, when a discount made it less, what it was before, struck through. */
export function Price({ amount, before, className }: { amount: string; before?: string | null; className?: string }) {
  const discounted = before !== undefined && before !== null && Number(before) > Number(amount)

  return (
    <span className={cn('inline-flex flex-wrap items-baseline gap-x-1.5', className)}>
      {discounted && <s className="text-hint text-footnote decoration-danger/60">{formatAmount(before)}</s>}
      <span className="font-bold">{Number(amount) === 0 ? 'رایگان' : formatAmount(amount)}</span>
      {Number(amount) !== 0 && <span className="text-hint text-footnote">{MONEY_UNIT}</span>}
    </span>
  )
}

/** Whether `moment` has come (true for none), drawn again the moment it does. */
export function useReached(moment: string | null): boolean {
  const due = moment === null ? 0 : new Date(moment).getTime()
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = setTimeout(() => setNow(Date.now()), Math.max(0, Math.min(due - Date.now() + 200, 2 ** 31 - 1)))
    return () => clearTimeout(timer)
  }, [due])

  return due <= now
}

/** The time left until `to`, ticking each second: «۲ روز ۰۴:۱۲:۰۹», then `done` once it passed. */
export function Countdown({ to, done = 'تمام شد' }: { to: string; done?: ReactNode }) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [])
  const left = Math.floor((new Date(to).getTime() - now) / 1000)
  if (left <= 0) return <>{done}</>
  const days = Math.floor(left / 86_400)
  const hours = Math.floor((left % 86_400) / 3600)

  return (
    <span className="tabular-nums">
      {days > 0 && `${formatNumber(days)} روز `}
      {formatNumber(hours).padStart(2, '۰')}:{formatCountdown(left % 3600).padStart(5, '۰')}
    </span>
  )
}
