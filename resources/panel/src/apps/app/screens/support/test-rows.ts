import type { StoreTicket, StoreTicketDetail, StoreTicketMessage } from '@/lib/api-types'

/* What the support screens' tests are answered with: tickets and their messages, as the Store API writes them. */

/** Ticket 12, «قطعی اتصال» about service 9, answered by support and not read yet. */
export function ticketRow(overrides: Partial<StoreTicket> = {}): StoreTicket {
  return {
    id: 12,
    subject: 'قطعی اتصال',
    status: 'answered',
    subscription: { id: 9, name: 'ali_1' },
    last_message_at: '2026-10-08T10:40:00+00:00',
    unread: true,
    rating: null,
    created_at: '2026-10-08T10:15:00+00:00',
    closed_at: null,
    ...overrides,
  }
}

/** A message of the customer's, read by support (`seen`) unless told otherwise. */
export function message(overrides: Partial<StoreTicketMessage> = {}): StoreTicketMessage {
  return { id: 31, author: 'customer', body: 'از دیشب سرویس وصل نمی‌شود.', attachment: null, created_at: '2026-10-08T10:15:00+00:00', seen: true, ...overrides }
}

/**
 * Ticket 12 with its conversation: the customer's first message (seen by support), support's answer, and the customer's
 * reply support has not read yet.
 */
export function ticketDetail(overrides: Partial<StoreTicketDetail> = {}): StoreTicketDetail {
  return {
    ...ticketRow({ unread: false }),
    rating_note: null,
    messages: [
      message(),
      message({ id: 35, author: 'support', body: 'لطفا یک بار اتصال را قطع و دوباره وصل کنید.', created_at: '2026-10-08T10:40:00+00:00', seen: null }),
      message({ id: 36, body: 'درست نشد.', created_at: '2026-10-08T10:45:00+00:00', seen: false }),
    ],
    ...overrides,
  }
}
