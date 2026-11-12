import { keys, queryClient } from '@/apps/app/lib/query'
import type { Tone } from '@/apps/app/ui/visuals'
import type { StoreTicketDetail, StoreTicketResponse, TicketStatus } from '@/lib/api-types'

/*
 * What the support screens share: where a ticket stands in the customer's words, an id read off the address, where a
 * screen's back button leads, and a ticket an answer gave kept where every screen reads it.
 */

/** Where a ticket stands, as the customer reads it: waiting on support, answered, closed. */
export const TICKET_STATE: Record<TicketStatus, { label: string; tone: Tone }> = {
  open: { label: 'در انتظار پاسخ', tone: 'warning' },
  answered: { label: 'پاسخ داده شد', tone: 'success' },
  closed: { label: 'بسته شده', tone: 'hint' },
}

/** The tickets' pages (`GET /tickets`), kept apart from each ticket's own read (keys.ticket) under the same prefix. */
export const ticketPages = [...keys.tickets, 'pages'] as const

/** A ticket's pictures, fetched as they come into view and kept while its conversation is open (screens/support/chat). */
export const ticketPictures = (ticketId: number) => ['ticket-pictures', ticketId] as const

/** A positive whole number in the address (`/support/:id`, `?subscription=`); null for anything else. */
export function idOf(text: string | null | undefined): number | null {
  return /^[1-9]\d{0,9}$/.test(text ?? '') ? Number(text) : null
}

/**
 * Where a screen's back button leads: back where the customer came from while the app has somewhere to go back to (the
 * router counts its entries in the history's state), else `fallback` — the app was opened right on this screen.
 */
export function backTo(fallback: string): true | string {
  const state: unknown = window.history.state
  const index = typeof state === 'object' && state !== null && 'idx' in state ? state.idx : 0

  return typeof index === 'number' && index > 0 ? true : fallback
}

/** A ticket as an answer gave it: its conversation's read holds it now, and the lists that show it are read again. */
export function keepTicket(ticket: StoreTicketDetail): void {
  queryClient.setQueryData<StoreTicketResponse>(keys.ticket(ticket.id), { ticket })
  void queryClient.invalidateQueries({ queryKey: keys.tickets, predicate: (query) => query.queryKey[1] !== ticket.id })
}

/** A ticket was marked read: the lists that mark it, and the customer's unread notices (read with it), are read again. */
export function readTicket(ticketId: number): void {
  void queryClient.invalidateQueries({ queryKey: keys.tickets, predicate: (query) => query.queryKey[1] !== ticketId })
  void queryClient.invalidateQueries({ queryKey: keys.me })
  void queryClient.invalidateQueries({ queryKey: keys.notifications })
}
