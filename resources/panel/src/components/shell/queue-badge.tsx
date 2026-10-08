import { queryOptions, useQuery } from '@tanstack/react-query'
import { useShellReads } from '@/components/shell/shell-context'
import { CountBadge } from '@/components/status-badge'
import { api } from '@/lib/api'
import type { QueueCounts, QueuesResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_STUCK, PAYMENT_STATUS, REVIEW_STATUS, TICKET_STATUS, type Status } from '@/lib/statuses'

/** A queue that waits on a human, by the API's name for its count (GET /queues): a nav entry's `badge`. */
export type Queue = keyof QueueCounts

/**
 * Each queue in its vocabulary's words and tint: the receipts to review, the orders paid and not delivered, the tickets
 * waiting on support, the reviews waiting on support.
 */
const QUEUES: Record<Queue, Status> = {
  payments_to_review: PAYMENT_STATUS.awaiting_review,
  stuck_orders: ORDER_STUCK,
  open_tickets: TICKET_STATUS.open,
  pending_reviews: REVIEW_STATUS.pending,
}

/**
 * The shop's queues, read once for the whole menu: again when the orders, the payments, the tickets or the reviews move
 * (lib/use-live-updates), and every minute besides — an order turns stuck with time alone.
 */
const queuesQuery = queryOptions({
  queryKey: queryKeys.queues,
  queryFn: () => api.get<QueuesResponse>('/queues').then((data) => data.queues),
  refetchInterval: 60_000,
})

export function useQueueCounts(): QueueCounts | undefined {
  return useQuery({ ...queuesQuery, enabled: useShellReads() }).data
}

/** Beside a nav entry's name: how many wait in its queue, in the queue's tint — nothing while none do. */
export function QueueBadge({ queue, counts }: { queue: Queue | undefined; counts: QueueCounts | undefined }) {
  const count = queue === undefined ? 0 : (counts?.[queue] ?? 0)
  if (queue === undefined || count === 0) return null
  const { label, tone } = QUEUES[queue]

  return <CountBadge count={count} tone={tone} label={label} />
}

/**
 * Of the queues of some nav entries, the most pressing one with something waiting — a red one before the rest —, for
 * where the counts are out of sight: a folded group, a group or a page on the icon rail (a dot, and its words).
 */
export function pressingQueue(counts: QueueCounts | undefined, queues: readonly (Queue | undefined)[]): Status | undefined {
  const waiting = queues.filter((queue): queue is Queue => queue !== undefined && (counts?.[queue] ?? 0) > 0).map((queue) => QUEUES[queue])
  return waiting.find((status) => status.tone === 'danger') ?? waiting[0]
}
