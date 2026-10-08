import * as React from 'react'
import { cn } from '@/lib/utils'

/*
 * Tables sit on the page itself, as in the Console: no box around them, a header row of secondary text over one
 * hairline, and rows that light up on hover with a rounded fill reaching a little past the column edges. The first
 * and last cells have no outer padding, so the text lines up with the page title above; the container keeps that
 * overhang visible (and scrolls sideways on a narrow screen). One of the panel's own: a table takes its accessible name
 * from what it lists (`TableName`, ListView's noun) — re-apply it if the component is reinstalled with the shadcn CLI.
 *
 * What a cell holds and how it lays out, by rule: a cell keeps to one line — identifiers, numbers, amounts, dates,
 * badges, the row's controls —, while a name (ListView's RowTitleButton / RowTitleLink) and free text (a note, a
 * description: `whitespace-normal` in a measure of its own) wrap, or are cut short with the whole of them a press away
 * (the row's dialog, the customer's page), never in a `title`. The last column — the row's controls, ⋮ or «جزئیات» —
 * stays in sight however wide the table: when it scrolls sideways, that column sticks to the scroll's end (index.css,
 * `[data-slot='table']`, over `--table-surface`, the surface the table sits on — a card's inside a card).
 */

/** The accessible name of the tables inside, unless one says its own: what they list. */
const TableNameContext = React.createContext<string | undefined>(undefined)
const TableName = TableNameContext.Provider

function Table({ className, ...props }: React.ComponentProps<'table'>) {
  const name = React.useContext(TableNameContext)

  return (
    <div data-slot="table-container" className="relative -mx-2.5 scrollbar-thin overflow-x-auto px-2.5">
      <table data-slot="table" aria-label={name} className={cn('w-full caption-bottom border-separate border-spacing-0 text-body', className)} {...props} />
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
        'h-9 border-b border-border px-3 text-start align-middle text-footnote font-medium whitespace-nowrap text-muted-foreground first:ps-0 last:pe-0 [&:has([role=checkbox])]:w-8',
        className,
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
  return <td data-slot="table-cell" className={cn('px-3 py-2.5 align-middle whitespace-nowrap first:ps-0 last:pe-0 [&:has([role=checkbox])]:w-8', className)} {...props} />
}

export { Table, TableName, TableHeader, TableBody, TableHead, TableRow, TableCell }
