import type { ComponentProps, ReactNode } from 'react'
import { Info } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'

/*
 * What the panel explains behind a press — never behind a hover or a `title` alone, which a phone cannot reach: a tap, a
 * click or Enter opens it beside what it explains, Escape or a press elsewhere closes it, and the focus goes into it and
 * back. The ⓘ beside a title or a label (InfoTip), or a badge whose words say more when pressed (InfoBadge).
 */

/** The explanation's card: a paragraph or two, under what it explains, from its start. */
function Explanation({ children }: { children: ReactNode }) {
  return (
    <PopoverContent side="bottom" align="start" className="w-80 text-footnote leading-relaxed">
      {children}
    </PopoverContent>
  )
}

interface InfoTipProps {
  /** What the ⓘ is about, for assistive tech («درباره درآمد»). */
  label: string
  /** The explanation behind it. */
  children: ReactNode
  /** The smaller ⓘ of a stat card's label or a list's cell; the default sits beside a page's title. */
  small?: boolean
}

/** An ⓘ beside a title or a label, with a longer explanation behind it — 24px either way, the target a finger needs. */
export function InfoTip({ label, children, small = false }: InfoTipProps) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label={label}
          className="flex size-6 shrink-0 items-center justify-center rounded-md text-faint transition-colors outline-none hover:text-foreground focus-visible:focus-ring data-[state=open]:text-foreground"
        >
          <Info className={small ? 'size-3.5' : 'size-4'} aria-hidden />
        </button>
      </PopoverTrigger>
      <Explanation>{children}</Explanation>
    </Popover>
  )
}

interface InfoBadgeProps {
  variant: ComponentProps<typeof Badge>['variant']
  /** What the badge says. */
  children: ReactNode
  /** What a press on it explains (why a server cannot sell, what a role allows). */
  info: ReactNode
  className?: string
}

/** A badge whose words say more when pressed — a state with its reason, a mark with what it means. */
export function InfoBadge({ variant, children, info, className }: InfoBadgeProps) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Badge asChild variant={variant} className={cn('cursor-pointer transition-opacity outline-none hover:opacity-80 focus-visible:focus-ring', className)}>
          <button type="button">{children}</button>
        </Badge>
      </PopoverTrigger>
      <Explanation>{info}</Explanation>
    </Popover>
  )
}
