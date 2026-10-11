import { Fact } from '@/components/fact-list'
import type { DiscountLine, OrderRow } from '@/lib/api-types'
import { formatMoney, formatNumber } from '@/lib/format'

/** What an order's discounts say of it: the Toman in all, the lines, the code. */
type Discounted = Pick<OrderRow, 'discount_amount' | 'discounts' | 'discount_code'>

/**
 * One discount line in the panel's words: the line's own label («فروش ویژه نوروز ۲۰٪», «کد NOWRUZ»), except a personal
 * discount, whose label speaks to the customer («… شما») and is reworded for the admin.
 */
function discountWords(line: DiscountLine): string {
  return line.source === 'personal' ? `تخفیف اختصاصی ${formatNumber(line.percent ?? 0)}٪` : line.label
}

/** Whether the order took anything off. */
export function isDiscounted(order: Pick<OrderRow, 'discount_amount'>): boolean {
  return Number(order.discount_amount) > 0
}

/** The «تخفیف» fact of an order or payment dialog: the total taken off and each discount's share; nothing when none. */
export function DiscountFact({ order }: { order: Discounted }) {
  if (!isDiscounted(order)) return null

  return (
    <Fact label="تخفیف">
      <div className="grid gap-0.5">
        <span className="tabular">{formatMoney(order.discount_amount)}</span>
        {order.discounts.map((line, index) => (
          <span key={index} className="text-footnote text-muted-foreground">
            <bdi>{discountWords(line)}</bdi>: {formatMoney(line.amount)}
          </span>
        ))}
      </div>
    </Fact>
  )
}
