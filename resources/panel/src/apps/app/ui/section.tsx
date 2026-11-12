import type { ComponentType, CSSProperties, ReactNode } from 'react'
import { ChevronLeft } from 'lucide-react'
import { Link } from 'react-router'
import { selection, tap } from '@/apps/app/telegram/haptics'
import { ACCENT_FILL, IconTile } from '@/apps/app/ui/visuals'
import { cn } from '@/lib/utils'

/*
 * Telegram's grouped lists: a section — a card on the page with its title above and a note below — of rows, each an icon
 * in its square (32px, ui/visuals IconTile), a title and a line under it, and what it holds or leads to at its end; a
 * hairline between rows from where their words start (app.css's list-row).
 */

interface SectionProps {
  title?: ReactNode
  /** Beside the title: a small action («همه»). */
  action?: ReactNode
  /** A note under the card. */
  footer?: ReactNode
  /** The card draws nothing of its own around the content (a carousel, a grid of cards). */
  bare?: boolean
  className?: string
  children: ReactNode
}

export function Section({ title, action, footer, bare = false, className, children }: SectionProps) {
  return (
    <section className={cn('flex flex-col gap-2', className)}>
      {(title !== undefined || action !== undefined) && (
        <div className="flex min-h-5 items-center justify-between gap-3 px-4">
          {title !== undefined && <h2 className="text-section-header text-footnote font-semibold">{title}</h2>}
          {action}
        </div>
      )}
      {bare ? children : <div className="card overflow-hidden">{children}</div>}
      {footer !== undefined && <p className="text-hint px-4 text-caption">{footer}</p>}
    </section>
  )
}

interface RowProps {
  icon?: ComponentType<{ className?: string }>
  /** The icon's square's colour: a CSS colour (the accent by default). */
  tint?: string
  title: ReactNode
  subtitle?: ReactNode
  /** At the row's end: what it holds (an amount, a state). */
  value?: ReactNode
  /** Where it leads (a screen of the app) — or what it does. */
  to?: string
  onClick?: () => void
  /** A row that warns (a sign-out, a removal). */
  danger?: boolean
  disabled?: boolean
}

/** A row of a section: a link, a button, or just a line of facts. */
export function Row({ icon: Icon, tint, title, subtitle, value, to, onClick, danger = false, disabled = false }: RowProps) {
  const body = (
    <>
      {Icon && <IconTile icon={Icon} color={danger ? 'var(--app-danger)' : (tint ?? ACCENT_FILL)} />}
      <span className="flex min-w-0 flex-1 flex-col gap-0.5 py-3">
        <span className={cn('truncate text-body', danger && 'text-danger')}>{title}</span>
        {subtitle !== undefined && <span className="text-hint text-footnote">{subtitle}</span>}
      </span>
      {value !== undefined && <span className="text-hint shrink-0 text-body">{value}</span>}
      {(to !== undefined || onClick !== undefined) && <ChevronLeft className="text-hint size-4.5 shrink-0 opacity-50" />}
    </>
  )
  // The hairline starts where the words do: past the 32px icon and its 12px gap, or at the row's own 16px.
  const row = 'list-row flex min-h-12 w-full items-center gap-3 px-4 text-start transition-colors'
  const inset = { '--row-inset': Icon ? '3.75rem' : '1rem' } as CSSProperties

  if (to !== undefined && !disabled) {
    return (
      <Link to={to} onClick={() => tap()} className={cn(row, 'active:bg-fill')} style={inset}>
        {body}
      </Link>
    )
  }
  if (onClick !== undefined) {
    return (
      <button
        type="button"
        disabled={disabled}
        onClick={() => {
          tap()
          onClick()
        }}
        className={cn(row, 'active:bg-fill disabled:opacity-50')}
        style={inset}
      >
        {body}
      </button>
    )
  }

  return (
    <div className={row} style={inset}>
      {body}
    </div>
  )
}

interface SwitchRowProps {
  icon?: ComponentType<{ className?: string }>
  /** The icon's square's colour (the accent by default). */
  tint?: string
  title: ReactNode
  subtitle?: ReactNode
  on: boolean
  /** While its change is under way: it takes no tap. */
  busy?: boolean
  onChange: (on: boolean) => void
}

/**
 * A row that is a switch, as Telegram's settings draw one: the whole row is tapped (role switch), its knob at its end —
 * 48 by 28px, a 24px thumb that moves 20px toward the row's end when on (mirrored right to left).
 */
export function SwitchRow({ icon: Icon, tint, title, subtitle, on, busy = false, onChange }: SwitchRowProps) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      disabled={busy}
      onClick={() => {
        selection()
        onChange(!on)
      }}
      className="list-row flex min-h-12 w-full items-center gap-3 px-4 text-start transition-colors active:bg-fill disabled:opacity-60"
      style={{ '--row-inset': Icon ? '3.75rem' : '1rem' } as CSSProperties}
    >
      {Icon && <IconTile icon={Icon} color={tint ?? ACCENT_FILL} />}
      <span className="flex min-w-0 flex-1 flex-col gap-0.5 py-3">
        <span className="truncate text-body">{title}</span>
        {subtitle !== undefined && <span className="text-hint text-footnote">{subtitle}</span>}
      </span>
      <span aria-hidden className={cn('flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors duration-200', on ? 'bg-success' : 'bg-fill-strong')}>
        <span className={cn('size-6 rounded-full bg-white shadow-[0_1px_3px_rgb(0_0_0/0.25)] transition-transform duration-200', on && '-translate-x-5 ltr:translate-x-5')} />
      </span>
    </button>
  )
}

/** A row of two words — a fact and its value — as a receipt lists them. */
export function Fact({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <div className="list-row flex min-h-11 items-center justify-between gap-4 px-4 py-2.5">
      <span className="text-hint shrink-0 text-footnote">{label}</span>
      <span className="min-w-0 text-end text-body">{children}</span>
    </div>
  )
}
