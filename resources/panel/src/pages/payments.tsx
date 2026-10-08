import { useId } from 'react'
import { CreditCard, Search } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { DateFilter } from '@/components/date-filter'
import { EmptyState } from '@/components/empty-state'
import { ListTotal } from '@/components/list-total'
import { ListView, RowTitleButton, SortableHead, type SortedList } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { ReviewModal } from '@/components/payments/review-modal'
import { Reviewer } from '@/components/reviewer'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { PaymentResponse, PaymentRow, PaymentSort, PaymentsResponse, PaymentStatus } from '@/lib/api-types'
import { formatMoney, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_TYPE, PAYMENT_STATUS } from '@/lib/statuses'
import { useNextRow } from '@/lib/use-next-row'
import { useOpenRow } from '@/lib/use-open-row'
import { usePagedList } from '@/lib/use-paged-list'

/** The queue's tabs after «همه»: the receipts waiting for a review first. */
const QUEUE: PaymentStatus[] = ['awaiting_review', 'paid', 'failed', 'pending']

/** What the headers sort the payments by — the list's own order, the newest first, leads. */
const SORTS: PaymentSort[] = ['created', 'amount']

/** Every payment, with the receipt review queue up front: open one to see the receipt and accept or refuse it. */
export function PaymentsPage() {
  const tabs = useId()
  // The dashboard and the sidebar link here with ?status=awaiting_review; an order to one of its payments with ?search=#id;
  // a customer's page with ?user=.
  const list = usePagedList({
    queryKey: queryKeys.payments,
    read: (query) => api.get<PaymentsResponse>('/payments', query),
    list: 'payments',
    statuses: ['', ...QUEUE],
    params: ['from', 'to', 'user'],
    sorts: SORTS,
    address: '/payments',
  })
  const days = { from: list.params.from ?? '', to: list.params.to ?? '' }
  const opened = useOpenRow({ queryKey: queryKeys.payment, read: (id) => api.get<PaymentResponse>(`/payments/${id}`).then((data) => data.payment), list })
  // The review dialog's «بعدی»: the receipts of a queue reviewed in a row.
  const next = useNextRow({ queryKey: queryKeys.payments, list, open: opened.row, onOpen: opened.open })

  return (
    <Page>
      <PageHeader title="پرداخت‌ها" description="هر پرداختی که مشتری‌ها شروع کرده‌اند. رسیدهای کارت به کارت این‌جا بررسی می‌شوند؛ تایید یعنی تحویل سرویس یا شارژ کیف پول." />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(PAYMENT_STATUS, QUEUE, { awaiting_review: list.meta?.awaiting_review })} onChange={list.setFilter} aria-label="وضعیت پرداخت" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره پرداخت یا سفارش، نام یا شناسه مشتری" aria-label="جستجوی پرداخت" />
          <DateFilter
            range={days}
            basis="بر اساس زمان ثبت پرداخت"
            onChange={({ from, to }) => {
              list.setParam('from', from)
              list.setParam('to', to)
            }}
          />
          <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
        </div>

        <ListTotal
          count={list.meta?.paid}
          amount={list.meta?.paid_amount}
          noun="پرداخت موفق"
          none="در این روزها پرداخت موفقی نیست."
          dated={days.from !== '' || days.to !== ''}
          stale={list.isPlaceholderData}
        />

        <ListView
          list={list}
          noun="پرداخت‌ها"
          unit="پرداخت"
          skeletonRows={6}
          empty={<EmptyState framed icon={CreditCard} title="هنوز پرداختی ثبت نشده است" description="اولین خرید یا شارژ کیف پول از ربات این‌جا ظاهر می‌شود." />}
          noMatch={
            <EmptyState
              framed
              icon={Search}
              title="پرداختی پیدا نشد"
              description={list.narrowed ? 'با این جستجو یا فیلتر چیزی نیست.' : list.filter === 'awaiting_review' ? 'فعلا رسیدی منتظر بررسی نیست.' : 'پرداختی در این وضعیت نیست.'}
            />
          }
        >
          <PaymentsTable payments={list.rows} sorting={list} onOpen={opened.open} />
        </ListView>
      </div>

      {/* A decision's row goes into the list, and into the dialog while it stays open (a reminder, a delivery that failed). */}
      <ReviewModal payment={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onReviewed={opened.apply} onStale={opened.refresh} next={next} />
    </Page>
  )
}

function PaymentsTable({ payments, sorting, onOpen }: { payments: PaymentRow[]; sorting: SortedList<PaymentSort>; onOpen: (payment: PaymentRow) => void }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>پرداخت</TableHead>
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
            <span className="sr-only">بررسی</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {payments.map((payment) => (
          <TableRow key={payment.id}>
            <TableCell>
              <div className="grid">
                <RowTitleButton onClick={() => onOpen(payment)}>
                  <span dir="ltr">#{payment.id}</span>
                </RowTitleButton>
                <span className="max-w-44 truncate text-footnote text-muted-foreground">{payment.method}</span>
              </div>
            </TableCell>
            <TableCell>
              <UserIdentity user={payment.user} />
            </TableCell>
            <TableCell className="hidden md:table-cell">
              <div className="grid">
                <span>{payment.order.plan ?? ORDER_TYPE[payment.order.type]}</span>
                <span className="text-footnote text-muted-foreground">
                  {payment.order.plan ? `${ORDER_TYPE[payment.order.type]} · ` : ''}سفارش <span dir="ltr">#{payment.order.id}</span>
                </span>
              </div>
            </TableCell>
            <TableCell className="tabular">{formatMoney(payment.amount)}</TableCell>
            <TableCell>
              {/* The spaces between the lines keep them words apart for assistive tech («ناموفق توسط …»), not one. */}
              <div className="grid justify-items-start gap-0.5">
                <StatusBadge status={PAYMENT_STATUS[payment.status]} /> {payment.auto_approved && <span className="text-caption text-faint">تایید خودکار</span>}
                {payment.reviewer && !payment.auto_approved && (
                  <span className="text-caption text-faint">
                    توسط <Reviewer name={payment.reviewer} />
                  </span>
                )}
              </div>
            </TableCell>
            <TableCell className="hidden text-muted-foreground lg:table-cell">
              {/* When it was made — what the column sorts by —, and when its receipt came. */}
              <div className="grid">
                <span>{timeAgo(payment.created_at)}</span>
                {payment.receipt?.sent_at && <span className="text-footnote">رسید: {timeAgo(payment.receipt.sent_at)}</span>}
              </div>
            </TableCell>
            <TableCell>
              <div className="flex justify-end">
                <Button size="sm" variant={payment.status === 'awaiting_review' ? 'default' : 'secondary'} onClick={() => onOpen(payment)}>
                  {payment.status === 'awaiting_review' ? 'بررسی' : 'جزئیات'}
                </Button>
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
