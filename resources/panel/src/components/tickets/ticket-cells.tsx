import { Star } from 'lucide-react'
import { RowTitleLink } from '@/components/list-view'
import type { TicketRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'

/* What a ticket's row shows wherever it is listed — the tickets screen, a customer's page — and its own page reuses. */

/** A ticket's own page — both panels have it. */
export function ticketPage(id: number): string {
  return `/tickets/${id}`
}

/**
 * The ticket's subject as the link to its page — the customer's words, set apart in whichever direction they are
 * written —, with the service it is about under it, by its name on the panel (set apart left to right).
 */
export function TicketSubject({ ticket }: { ticket: Pick<TicketRow, 'id' | 'subject' | 'subscription'> }) {
  return (
    <div className="grid min-w-40 gap-0.5">
      <RowTitleLink to={ticketPage(ticket.id)}>
        <bdi>{ticket.subject}</bdi>
      </RowTitleLink>
      {ticket.subscription && (
        <span className="truncate text-footnote text-muted-foreground">
          سرویس{' '}
          <bdi dir="ltr" className="text-footnote">
            {ticket.subscription.name}
          </bdi>
        </span>
      )}
    </div>
  )
}

/** The customer's word on a closed ticket, 1 to 5: «۴ از ۵» after a star. */
export function TicketRating({ rating }: { rating: number }) {
  return (
    <span className="inline-flex items-center gap-1 whitespace-nowrap tabular">
      <Star className="size-3.5 fill-current text-warning" aria-hidden />
      {formatNumber(rating)} از {formatNumber(5)}
    </span>
  )
}
