import { isDiscounted } from '@/components/orders/order-discounts'
import type { OrderRow } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'
import { MANUAL_PAYMENT, ORDER_PRICING } from '@/lib/statuses'

/**
 * An order's amount in a list cell, with quiet lines under it for a special price (first purchase, agent) — or, for one
 * support made by hand, that it was and how it was paid — and a discount.
 */
export function OrderAmount({ order }: { order: Pick<OrderRow, 'amount' | 'pricing' | 'discount_amount' | 'discount_code' | 'manual'> }) {
  const pricing = order.manual ? `سفارش دستی · ${MANUAL_PAYMENT[order.manual.payment]}` : ORDER_PRICING[order.pricing]
  return (
    <div className="grid">
      <span>{formatMoney(order.amount)}</span>
      {pricing && <span className="text-footnote text-muted-foreground">{pricing}</span>}
      {isDiscounted(order) && (
        <span className="text-footnote text-muted-foreground">
          {formatMoney(order.discount_amount)} تخفیف
          {order.discount_code && (
            <>
              {' · '}
              <bdi dir="ltr">{order.discount_code.code}</bdi>
            </>
          )}
        </span>
      )}
    </div>
  )
}
