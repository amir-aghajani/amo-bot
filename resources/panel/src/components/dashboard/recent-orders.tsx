import { ShoppingCart } from 'lucide-react'
import { Link } from 'react-router'
import { EmptyState } from '@/components/empty-state'
import { Dash } from '@/components/list-view'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserLabel, userPage } from '@/components/user-identity'
import type { RecentOrder } from '@/lib/api-types'
import { formatMoney, timeAgo } from '@/lib/format'
import { ORDER_STATUS, ORDER_TYPE } from '@/lib/statuses'

/**
 * The latest purchases, renewals and top-ups — each number the way to the order on the orders screen, each customer to
 * their page —, as a section of the overview with a link to the orders; «—» when they could not be read (`orders`
 * undefined, no longer `loading`).
 */
export function RecentOrders({ orders, loading }: { orders: RecentOrder[] | undefined; loading?: boolean }) {
  return (
    <section aria-labelledby="recent-orders" className="grid gap-3">
      <div className="flex items-center justify-between gap-3 px-1">
        <h2 id="recent-orders" className="text-heading font-semibold">
          آخرین سفارش‌ها
        </h2>
        <TextLink to="/orders" inline className="text-footnote whitespace-nowrap">
          مشاهده همه
        </TextLink>
      </div>
      <Card className="px-4 py-1.5">
        {loading ? (
          <div className="grid gap-1.5 py-2.5">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-9 w-full" />
            ))}
          </div>
        ) : !orders ? (
          <div className="grid place-items-center py-6">
            <Dash />
          </div>
        ) : orders.length === 0 ? (
          <EmptyState compact icon={ShoppingCart} title="هنوز سفارشی ثبت نشده است" description="اولین خرید از ربات این‌جا نمایش داده می‌شود." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>سفارش</TableHead>
                <TableHead>مشتری</TableHead>
                <TableHead className="hidden md:table-cell">پلن</TableHead>
                <TableHead>وضعیت</TableHead>
                <TableHead className="text-end">مبلغ</TableHead>
                <TableHead className="hidden text-end sm:table-cell">زمان</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orders.map((order) => (
                <TableRow key={order.id}>
                  <TableCell className="tabular">
                    <TextLink to={searchLink('/orders', order.id)}>
                      <span dir="ltr">#{order.id}</span>
                    </TextLink>
                  </TableCell>
                  <TableCell className="max-w-44 truncate font-medium">
                    <Link to={userPage(order.user.id)} className="rounded-sm underline-offset-4 outline-none hover:underline focus-visible:focus-ring">
                      <UserLabel user={order.user} />
                    </Link>
                  </TableCell>
                  <TableCell className="hidden max-w-48 truncate text-muted-foreground md:table-cell">{order.plan ?? ORDER_TYPE[order.type]}</TableCell>
                  <TableCell>
                    <StatusBadge status={ORDER_STATUS[order.status]} />
                  </TableCell>
                  <TableCell className="text-end tabular">{formatMoney(order.amount)}</TableCell>
                  <TableCell className="hidden text-end text-muted-foreground sm:table-cell">{timeAgo(order.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
    </section>
  )
}
