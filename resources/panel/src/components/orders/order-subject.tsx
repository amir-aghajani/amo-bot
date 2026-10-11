import { Dash } from '@/components/list-view'
import type { OrderRow, PaymentRow } from '@/lib/api-types'
import { formatBytes, formatDays } from '@/lib/format'
import { ORDER_TYPE } from '@/lib/statuses'

/**
 * What an order brings when it is an amount (extra traffic or time, an agent's traffic), with the label its dialog shows
 * it under; null for other orders.
 */
export function orderBrings(order: Pick<OrderRow, 'type' | 'traffic_bytes' | 'days'>): { label: string; amount: string } | null {
  switch (order.type) {
    case 'extra_traffic':
      return order.traffic_bytes === null ? null : { label: 'حجم اضافه', amount: formatBytes(order.traffic_bytes) }
    case 'extra_time':
      return order.days === null ? null : { label: 'زمان اضافه', amount: formatDays(order.days) }
    case 'traffic':
      return order.traffic_bytes === null ? null : { label: 'حجم', amount: formatBytes(order.traffic_bytes) }
    default:
      return null
  }
}

/** What an order was for, in a list cell: the plan and server, or the service it renewed or added to, or the amount. */
export function OrderSubject({ order }: { order: OrderRow }) {
  const brings = orderBrings(order)
  if (order.type === 'wallet_topup' || order.type === 'traffic') {
    return brings ? <span>{brings.amount}</span> : <span className="text-muted-foreground">{ORDER_TYPE[order.type]}</span>
  }
  if (!order.plan && !order.subscription) {
    return brings ? <span>{brings.amount}</span> : <Dash />
  }

  // A renewal and an extra are a service's: its name on the panel says which.
  const service = order.type !== 'purchase' && order.subscription ? <bdi dir="ltr">{order.subscription.name}</bdi> : null
  return (
    <div className="grid">
      <span>{brings?.amount ?? order.plan?.name ?? ORDER_TYPE[order.type]}</span>
      <span className="text-footnote text-muted-foreground">{service ?? order.server?.name}</span>
    </div>
  )
}

/** What a payment paid for (an amount, the plan or the order type), with the order's type and number under it. */
export function PaidFor({ order }: { order: PaymentRow['order'] }) {
  const title = orderBrings(order)?.amount ?? order.plan

  return (
    <div className="grid">
      <span>{title ?? ORDER_TYPE[order.type]}</span>
      <span className="text-footnote text-muted-foreground">
        {title ? `${ORDER_TYPE[order.type]} · ` : ''}سفارش <span dir="ltr">#{order.id}</span>
      </span>
    </div>
  )
}
