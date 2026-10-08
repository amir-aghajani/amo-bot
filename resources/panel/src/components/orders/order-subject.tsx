import { Dash } from '@/components/list-view'
import type { OrderRow } from '@/lib/api-types'
import { ORDER_TYPE } from '@/lib/statuses'

/** What the order was for: the plan (on its server) or the service it renewed; a top-up or an agent's traffic is its type. */
export function OrderSubject({ order }: { order: OrderRow }) {
  if (order.type === 'wallet_topup' || order.type === 'traffic') {
    return <span className="text-muted-foreground">{ORDER_TYPE[order.type]}</span>
  }
  if (!order.plan && !order.subscription) {
    return <Dash />
  }

  return (
    <div className="grid">
      <span>{order.plan?.name ?? ORDER_TYPE[order.type]}</span>
      <span className="text-footnote text-muted-foreground">{order.type === 'renewal' && order.subscription ? <bdi dir="ltr">{order.subscription.name}</bdi> : order.server?.name}</span>
    </div>
  )
}
