import { useCallback, useLayoutEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { isRtl } from '@/lib/direction'
import { cn } from '@/lib/utils'

export interface PageTab<T extends string> {
  value: T
  label: ReactNode
  icon?: LucideIcon
}

type PageTabsProps<T extends string> = {
  value: T
  tabs: PageTab<T>[]
  onChange: (value: T) => void
  'aria-label': string
  /** `sm` for an inline picker (a chart's metric, a form's mode); `md` for a page or form split into views. */
  size?: 'sm' | 'md'
  className?: string
} & (
  | {
      /** Views of something, each in its panel (`tabPanel(id, value)`): a status queue, a form's sections. */
      as?: 'tabs'
      /** The base of the tabs' and their panels' ids (`useId()`). */
      id: string
    }
  | {
      /** A value picked in a form or a card (a mode, a metric): a radio group, there being no panel to go to. */
      as: 'choice'
      id?: never
    }
)

/** What a tab's panel carries, so the tab and its panel point at each other. */
export function tabPanel(id: string, value: string) {
  return { id: `${id}-panel-${value}`, role: 'tabpanel', 'aria-labelledby': `${id}-tab-${value}` } as const
}

/** The ends of a row that scrolls whose tabs are out of sight. */
interface Hidden {
  start: boolean
  end: boolean
}

/** The row's ends that hide tabs, faded — a tab cut there reads as more to see, not as a broken word; nothing while all are in sight. */
function fade({ start, end }: Hidden): string | undefined {
  if (!start && !end) return undefined
  const toEnd = isRtl() ? 'to left' : 'to right'
  return `linear-gradient(${toEnd}, ${start ? 'transparent, black 2rem' : 'black'}, ${end ? 'black calc(100% - 2rem), transparent' : 'black'})`
}

/**
 * The one control for switching in place (by decision — no segmented control): an ink underline that slides to the
 * selected tab (measured from the DOM, so it follows any label width and direction), with arrow-key movement; a pick
 * goes through `onChange`. As tabs it switches views — render each view's panel with `tabPanel()` (keep the others
 * mounted and hidden, so drafts survive); as a choice it is a value of a form. A page's own sections are never tabs —
 * the sidebar lists them. A row wider than its place (five tabs on a phone) scrolls sideways, tabs whole, and keeps the
 * selected one in sight — its ends that hide tabs fading, so the one cut there reads as more to see.
 */
export function PageTabs<T extends string>({ value, tabs, onChange, size = 'md', className, as = 'tabs', id, 'aria-label': ariaLabel }: PageTabsProps<T>) {
  const listRef = useRef<HTMLDivElement>(null)
  const buttons = useRef(new Map<T, HTMLButtonElement>())
  const [marker, setMarker] = useState<{ left: number; width: number } | null>(null)
  const [hidden, setHidden] = useState<Hidden>({ start: false, end: false })
  const choice = as === 'choice'

  // Which ends of a row that scrolls hide tabs: as it is scrolled, and as it is measured.
  const edges = useCallback(() => {
    const list = listRef.current
    if (!list) return
    const scrolled = Math.abs(list.scrollLeft)
    const next = { start: scrolled > 1, end: list.scrollWidth - list.clientWidth - scrolled > 1 }
    setHidden((current) => (current.start === next.start && current.end === next.end ? current : next))
  }, [])

  const measure = useCallback(() => {
    const selected = buttons.current.get(value)
    if (selected) setMarker({ left: selected.offsetLeft, width: selected.offsetWidth })
    edges()
  }, [value, edges])

  // Re-measure when the selection changes, when the list or the selected tab is resized (a label
  // changed, the panel narrowed) and once web fonts have arrived.
  useLayoutEffect(() => {
    measure()
    const list = listRef.current
    if (!list) return
    const observer = new ResizeObserver(measure)
    observer.observe(list)
    const selected = buttons.current.get(value)
    if (selected) observer.observe(selected)
    void document.fonts?.ready.then(measure)
    return () => observer.disconnect()
  }, [measure, value])

  // A row that scrolls (narrow screen): the selected tab in sight, as the page opens and as it changes.
  useLayoutEffect(() => {
    const list = listRef.current
    const selected = buttons.current.get(value)
    if (list && selected && list.scrollWidth > list.clientWidth) {
      selected.scrollIntoView({ block: 'nearest', inline: 'nearest' })
    }
  }, [value])

  // Arrow keys move along the row in its reading direction (Home and End to its ends), picking as they go.
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const index = tabs.findIndex((tab) => tab.value === value)
    if (index === -1) return
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl'
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight'
    const backward = rtl ? 'ArrowRight' : 'ArrowLeft'

    let next = index
    if (event.key === forward) next = (index + 1) % tabs.length
    else if (event.key === backward) next = (index - 1 + tabs.length) % tabs.length
    else if (event.key === 'Home') next = 0
    else if (event.key === 'End') next = tabs.length - 1
    else return

    const target = tabs[next]
    if (!target) return
    event.preventDefault()
    onChange(target.value)
    buttons.current.get(target.value)?.focus()
  }

  return (
    <div
      ref={listRef}
      role={choice ? 'radiogroup' : 'tablist'}
      aria-label={ariaLabel}
      onKeyDown={onKeyDown}
      onScroll={edges}
      // The row clips what reaches past it (it scrolls sideways): its padding is the room a focused tab's ring takes, the
      // negative margin keeps the labels where they stand.
      className={cn('relative -mx-1 scrollbar-none flex items-center gap-5 overflow-x-auto overflow-y-hidden border-b border-border p-1', size === 'sm' && 'gap-4', className)}
      style={{ maskImage: fade(hidden) }}
    >
      {tabs.map(({ value: tabValue, label, icon: Icon }) => {
        const selected = tabValue === value
        return (
          <button
            key={tabValue}
            ref={(element) => {
              if (element) buttons.current.set(tabValue, element)
              else buttons.current.delete(tabValue)
            }}
            type="button"
            // A tab points at its panel while it is there to point at: the selected one's always is; a status queue draws
            // no other.
            {...(choice
              ? { role: 'radio', 'aria-checked': selected }
              : { role: 'tab', 'aria-selected': selected, id: `${id}-tab-${tabValue}`, 'aria-controls': selected ? `${id}-panel-${tabValue}` : undefined })}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(tabValue)}
            className={cn(
              'flex shrink-0 items-center gap-1.5 rounded-sm whitespace-nowrap transition-colors duration-150 outline-none focus-visible:focus-ring',
              size === 'sm' ? 'h-6 text-footnote' : 'h-8 text-body',
              selected ? 'font-medium text-foreground' : 'text-muted-foreground hover:text-foreground',
            )}
          >
            {Icon && <Icon className={size === 'sm' ? 'size-3.5' : 'size-4'} aria-hidden />}
            {label}
          </button>
        )
      })}
      {marker && (
        <span
          aria-hidden
          className="pointer-events-none absolute bottom-0 h-0.5 rounded-full bg-foreground transition-[left,width] duration-200 ease-out motion-reduce:transition-none"
          style={{ left: marker.left, width: marker.width }}
        />
      )}
    </div>
  )
}
