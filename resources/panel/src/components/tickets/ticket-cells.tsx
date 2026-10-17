import { RowTitleLink } from '@/components/list-view'
import type { TicketRow } from '@/lib/api-types'

/* What a ticket's row shows wherever it is listed — the tickets screen, a customer's page — and its own page reuses. */

/** A ticket's own page — both panels have it. */
export function ticketPage(id: number): string {
  return `/tickets/${id}`
}

/** A ticket's subject linking to its page, with the service it is about under it. */
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
