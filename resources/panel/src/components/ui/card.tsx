import * as React from 'react'
import { cn } from '@/lib/utils'

/*
 * The Console's card: a 12px-cornered hairline box a step above the page. The header is a title (body size, medium) with
 * an optional description and an action at its end; content and footer share its 16px inset. No inner rules unless a
 * card asks for one (a footer that holds a form's buttons). The title is the level under the page's (h2 under its h1).
 */
// A table inside sits on the card's surface (`--table-surface`, under its last column when it sticks — index.css).
function Card({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card" className={cn('flex min-w-0 flex-col rounded-xl border border-border bg-card text-card-foreground shadow-card [--table-surface:var(--surface-2)]', className)} {...props} />
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-header" className={cn('flex min-w-0 items-start gap-3 px-4 pt-4 pb-3', className)} {...props} />
}

/** Title and description stacked, beside the header's action. */
function CardHeading({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-heading" className={cn('grid min-w-0 flex-1 gap-0.5', className)} {...props} />
}

function CardTitle({ className, ...props }: React.ComponentProps<'h2'>) {
  return <h2 data-slot="card-title" className={cn('flex min-w-0 items-center gap-1.5 text-body font-medium text-foreground', className)} {...props} />
}

function CardDescription({ className, ...props }: React.ComponentProps<'p'>) {
  return <p data-slot="card-description" className={cn('text-footnote text-muted-foreground', className)} {...props} />
}

function CardAction({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-action" className={cn('ms-auto flex shrink-0 items-center gap-2', className)} {...props} />
}

function CardContent({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-content" className={cn('px-4 pb-4', className)} {...props} />
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="card-footer" className={cn('flex flex-wrap items-center gap-2 border-t border-border px-4 py-3', className)} {...props} />
}

export { Card, CardHeader, CardHeading, CardFooter, CardTitle, CardAction, CardDescription, CardContent }
