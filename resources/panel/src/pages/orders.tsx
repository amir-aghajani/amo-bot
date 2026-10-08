import { useId } from 'react'
import { Search, ShoppingCart } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { DateFilter } from '@/components/date-filter'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { ListTotal } from '@/components/list-total'
import { ListView, RowTitleButton, SortableHead, type SortedList } from '@/components/list-view'
import { OrderModal } from '@/components/orders/order-modal'
import { OrderSubject } from '@/components/orders/order-subject'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { OrderResponse, OrderRow, OrderSort, OrdersResponse, OrderStatus, OrderType } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { formatMoney, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_STATUS, ORDER_STUCK, ORDER_TYPE, type Status } from '@/lib/statuses'
import { useOpenRow } from '@/lib/use-open-row'
import { usePagedList } from '@/lib/use-paged-list'

/** A tab of the queue: a status, or the orders paid and not delivered (`stuck`, the API's). */
type Filter = OrderStatus | 'stuck'

/** The queue's tabs after «همه»: what waits on support first — paid and not delivered. */
const QUEUE: Filter[] = ['stuck', 'pending', 'fulfilled', 'cancelled', 'refunded']
const TABS: Record<Filter, Status> = { ...ORDER_STATUS, stuck: ORDER_STUCK }

/** What the headers sort the orders by — the list's own order, the newest first, leads. */
const SORTS: OrderSort[] = ['created', 'amount']

/** Every order — purchases, renewals, wallet top-ups — with those paid and not delivered up front: open one to deliver it again or drop it. */
export function OrdersPage() {
  const tabs = useId()
  // An agent's traffic is bought in the main bot alone: an agent's shop has no such orders.
  const mainShop = useMainShop()
  const types = (Object.keys(ORDER_TYPE) as OrderType[]).filter((type) => mainShop || type !== 'traffic')
  // The dashboard's attention card and the sidebar link here with ?status=stuck / ?status=pending; another screen with
  // ?search=#id; a customer's page with ?user=.
  const list = usePagedList({
    queryKey: queryKeys.orders,
    read: (query) => api.get<OrdersResponse>('/orders', query),
    list: 'orders',
    statuses: ['', ...QUEUE],
    params: ['type', 'from', 'to', 'user'],
    // A type the shop has no orders of (another shop's link) is no filter: «همه» shows, and the address says so.
    choices: { type: types },
    sorts: SORTS,
    address: '/orders',
  })
  const days = { from: list.params.from ?? '', to: list.params.to ?? '' }
  const opened = useOpenRow({ queryKey: queryKeys.order, read: (id) => api.get<OrderResponse>(`/orders/${id}`).then((data) => data.order), list })

  return (
    <Page>
      <PageHeader
        title="سفارش‌ها"
        description="هر خرید، تمدید و شارژ کیف پولی که مشتری‌ها ثبت کرده‌اند. سفارشی که پرداخت شده ولی تحویل نشده در «نیازمند رسیدگی» است و همین‌جا دوباره تحویل داده می‌شود؛ سفارش پرداخت‌نشده لغو می‌شود و رسیدها در صفحه پرداخت‌ها بررسی می‌شوند."
      />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(TABS, QUEUE, { stuck: list.meta?.stuck })} onChange={list.setFilter} aria-label="وضعیت سفارش" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره سفارش، مشتری، پلن یا سرویس" aria-label="جستجوی سفارش" />
          <FilterSelect
            label="نوع"
            value={list.params.type ?? ''}
            options={[{ value: '', label: 'همه' }, ...types.map((type) => ({ value: type, label: ORDER_TYPE[type] }))]}
            onChange={(value) => list.setParam('type', value)}
          />
          <DateFilter
            range={days}
            basis="بر اساس زمان ثبت سفارش"
            onChange={({ from, to }) => {
              list.setParam('from', from)
              list.setParam('to', to)
            }}
          />
          <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
        </div>

        <ListTotal
          count={list.meta?.sold}
          amount={list.meta?.sold_amount}
          noun="سفارش فروخته‌شده"
          none="در این روزها سفارشی فروخته نشده است."
          dated={days.from !== '' || days.to !== ''}
          stale={list.isPlaceholderData}
        />

        <ListView
          list={list}
          noun="سفارش‌ها"
          unit="سفارش"
          skeletonRows={6}
          empty={<EmptyState framed icon={ShoppingCart} title="هنوز سفارشی ثبت نشده است" description="اولین خرید یا شارژ کیف پول از ربات این‌جا ظاهر می‌شود." />}
          noMatch={
            <EmptyState
              framed
              icon={Search}
              title="سفارشی پیدا نشد"
              description={list.narrowed ? 'با این جستجو یا فیلتر چیزی نیست.' : list.filter === 'stuck' ? 'فعلا سفارشی نیست که پرداخت شده و تحویل نشده باشد.' : 'سفارشی در این وضعیت نیست.'}
            />
          }
        >
          <OrdersTable orders={list.rows} sorting={list} onOpen={opened.open} />
        </ListView>
      </div>

      <OrderModal order={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onChanged={opened.apply} onStale={opened.refresh} />
    </Page>
  )
}

function OrdersTable({ orders, sorting, onOpen }: { orders: OrderRow[]; sorting: SortedList<OrderSort>; onOpen: (order: OrderRow) => void }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>سفارش</TableHead>
          <TableHead>مشتری</TableHead>
          <TableHead className="hidden md:table-cell">بابت</TableHead>
          <SortableHead list={sorting} by="amount">
            مبلغ
          </SortableHead>
          <TableHead>وضعیت</TableHead>
          <SortableHead list={sorting} by="created" className="hidden lg:table-cell">
            زمان
          </SortableHead>
          <TableHead className="w-24">
            <span className="sr-only">جزئیات</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {orders.map((order) => (
          <TableRow key={order.id}>
            <TableCell>
              <div className="grid">
                <RowTitleButton onClick={() => onOpen(order)}>
                  <span dir="ltr">#{order.id}</span>
                </RowTitleButton>
                <span className="text-footnote text-muted-foreground">{ORDER_TYPE[order.type]}</span>
              </div>
            </TableCell>
            <TableCell>
              <UserIdentity user={order.user} />
            </TableCell>
            <TableCell className="hidden md:table-cell">
              <OrderSubject order={order} />
            </TableCell>
            <TableCell className="tabular">{formatMoney(order.amount)}</TableCell>
            <TableCell>
              <StatusBadge status={ORDER_STATUS[order.status]} />
            </TableCell>
            <TableCell className="hidden text-muted-foreground lg:table-cell">{timeAgo(order.created_at)}</TableCell>
            <TableCell>
              <div className="flex justify-end">
                <Button size="sm" variant={order.actions.retry ? 'default' : 'secondary'} onClick={() => onOpen(order)}>
                  {order.actions.retry ? 'رسیدگی' : 'جزئیات'}
                </Button>
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
