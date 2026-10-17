import * as React from 'react'
import { cn } from '@/lib/utils'

/*
 * Tables sit on the page itself, as in the Console: no box, a header of secondary text over one hairline, rows lit on
 * hover with a rounded fill reaching past the column edges. The first and last cells have no outer padding, so text
 * lines up with the page title. Local change to re-apply after a shadcn reinstall: `TableName`, which names a table
 * after what it lists (ListView's noun), and the shadow of the sticky last column (`data-under`).
 *
 * Cell rules: a cell keeps to one line (identifiers, numbers, amounts, dates, badges, controls); a name and free text
 * wrap, or are cut short with the whole a press away — never only in a `title`. The last column (the row's controls)
 * stays in sight: when the table scrolls sideways it sticks to the scroll's end (index.css, over `--table-surface`), its
 * edge shadowed while rows pass under it.
 *
 * On a phone (below `md`) a table keeps to its title column, its amount where it lists money, and its controls: every
 * other column is `hidden md:table-cell`, and what a phone still needs of them — the status first — sits under the
 * title (list-view's `PhoneFacts`, drawn on a phone alone). A tablet (`md` to `xl`) has little more room — the rail or
 * the sidebar takes its share, about 650px are left for a list —, so a list's columns it can do without come back at
 * `xl`. What is still wider than its place scrolls under the sticky column.
 */

/** The accessible name of the tables inside, unless one says its own: what they list. */
const TableNameContext = React.createContext<string | undefined>(undefined)
const TableName = TableNameContext.Provider

function Table({ className, ...props }: React.ComponentProps<'table'>) {
  const name = React.useContext(TableNameContext)
  const container = React.useRef<HTMLDivElement>(null)

  // Whether rows pass under the sticky last column — the table scrolls sideways and is not at its end —, as it scrolls
  // and as it or its place is resized: its edge casts a shadow meanwhile (index.css), so a cell cut there reads as
  // going on under it. Set on the element itself: nothing else is drawn again for it.
  React.useLayoutEffect(() => {
    const node = container.current
    if (!node) return
    const measure = () => node.toggleAttribute('data-under', node.scrollWidth - node.clientWidth - Math.abs(node.scrollLeft) > 1)
    measure()
    node.addEventListener('scroll', measure, { passive: true })
    const observer = new ResizeObserver(measure)
    observer.observe(node)
    if (node.firstElementChild) observer.observe(node.firstElementChild)
    return () => {
      node.removeEventListener('scroll', measure)
      observer.disconnect()
    }
  }, [])

  return (
    <div ref={container} data-slot="table-container" className="relative -mx-2.5 scrollbar-thin overflow-x-auto px-2.5">
      {/* A cell's side padding: 8px on a phone, so a title, an amount and the controls fit its width; 12px from `md`. */}
      <table data-slot="table" aria-label={name} className={cn('w-full caption-bottom border-separate border-spacing-0 text-body [--cell-x:0.5rem] md:[--cell-x:0.75rem]', className)} {...props} />
    </div>
  )
}

function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
  return <thead data-slot="table-header" className={className} {...props} />
}

function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
  return <tbody data-slot="table-body" className={className} {...props} />
}

function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
  return (
    <tr
      data-slot="table-row"
      className={cn(
        // The transform makes the row the containing block of its hover layer (an absolutely placed pseudo-element).
        'relative [transform:translate(0,0)]',
        'after:pointer-events-none after:absolute after:inset-y-0 after:-inset-x-2.5 after:-z-10 after:rounded-lg after:bg-fill after:opacity-0 after:transition-opacity after:duration-100',
        '[tbody>&]:hover:after:opacity-100 has-aria-expanded:after:opacity-100 data-[state=selected]:after:bg-fill-hover data-[state=selected]:after:opacity-100',
        className,
      )}
      {...props}
    />
  )
}

function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
  return (
    <th
      data-slot="table-head"
      className={cn(
        // A header of two words may take two lines on a phone, rather than make its column wider than what it heads.
        'h-9 border-b border-border px-(--cell-x) text-start align-middle text-footnote font-medium text-muted-foreground first:ps-0 last:pe-0 md:whitespace-nowrap [&:has([role=checkbox])]:w-8',
        className,
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
  return <td data-slot="table-cell" className={cn('px-(--cell-x) py-2.5 align-middle whitespace-nowrap first:ps-0 last:pe-0 [&:has([role=checkbox])]:w-8', className)} {...props} />
}

export { Table, TableName, TableHeader, TableBody, TableHead, TableRow, TableCell }
