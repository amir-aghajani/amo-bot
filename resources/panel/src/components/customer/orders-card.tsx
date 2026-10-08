import { ShoppingCart } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { OrderModal } from '@/components/orders/order-modal'
import { OrderSubject } from '@/components/orders/order-subject'
import { StatusBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { OrderResponse, OrdersResponse, UserRow } from '@/lib/api-types'
import { formatMoney, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_STATUS, ORDER_TYPE } from '@/lib/statuses'
import { useOpenRow } from '@/lib/use-open-row'

/**
 * The customer's latest orders, each opening the orders screen's dialog — what it was for, how far it got, its payments,
 * a delivery tried again or an unpaid order dropped (what that moves on the page — the customer, their wallet, their
 * payments and services — is read again by the dialog's operations) —, and the way to every one of them on that screen.
 */
export function OrdersCard({ customer }: { customer: UserRow }) {
  const latest = useLatestRows({ queryKey: queryKeys.orders, read: (query) => api.get<OrdersResponse>('/orders', query), list: 'orders', user: customer.id })
  const opened = useOpenRow({ queryKey: queryKeys.order, read: (id) => api.get<OrderResponse>(`/orders/${id}`).then((data) => data.order), list: latest })
  const { orders } = customer.counts

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>سفارش‌ها</CardTitle>
          {orders > 0 && <CardDescription>{formatNumber(orders)} سفارش</CardDescription>}
        </CardHeading>
        {orders > 0 && (
          <CardAction>
            <TextLink to={customerList('/orders', customer.id)} className="text-footnote">
              همه سفارش‌ها
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <ListView
          list={latest}
          noun="سفارش‌ها"
          skeletonRows={2}
          empty={<EmptyState compact icon={ShoppingCart} title="هنوز سفارشی ثبت نکرده است" description="خریدها، تمدیدها و شارژهای این مشتری این‌جا می‌آیند." />}
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>سفارش</TableHead>
                <TableHead className="hidden md:table-cell">بابت</TableHead>
                <TableHead>مبلغ</TableHead>
                <TableHead>وضعیت</TableHead>
                <TableHead className="hidden sm:table-cell">زمان</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {latest.rows.map((order) => (
                <TableRow key={order.id}>
                  <TableCell>
                    <div className="grid">
                      <RowTitleButton onClick={() => opened.open(order)}>
                        <span dir="ltr">#{order.id}</span>
                      </RowTitleButton>
                      <span className="text-footnote text-muted-foreground">{ORDER_TYPE[order.type]}</span>
                    </div>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <OrderSubject order={order} />
                  </TableCell>
                  <TableCell className="whitespace-nowrap tabular">{formatMoney(order.amount)}</TableCell>
                  <TableCell>
                    <StatusBadge status={ORDER_STATUS[order.status]} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">{timeAgo(order.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </CardContent>

      <OrderModal order={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onChanged={opened.apply} onStale={opened.refresh} />
    </Card>
  )
}
