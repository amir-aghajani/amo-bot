import { useId, useState } from 'react'
import { CreditCard, Search, XCircle } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { DateFilter } from '@/components/date-filter'
import { EmptyState } from '@/components/empty-state'
import { ListTotal } from '@/components/list-total'
import { ListView, PhoneFacts, RowTitleButton, SortableHead, type SortedList } from '@/components/list-view'
import { PaidFor } from '@/components/orders/order-subject'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { DismissReceiptsModal, type DismissScope } from '@/components/payments/dismiss-receipts'
import { PaymentStatusCell } from '@/components/payments/payment-status-cell'
import { ReviewModal } from '@/components/payments/review-modal'
import { SearchBox } from '@/components/search-box'
import { SelectionBar } from '@/components/selection-bar'
import { statusTabs } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity, UserLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { PaymentResponse, PaymentRow, PaymentSort, PaymentsResponse, PaymentStatus } from '@/lib/api-types'
import { formatMoney, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { PAYMENT_HELD, PAYMENT_STATUS, type Status } from '@/lib/statuses'
import { useNextRow } from '@/lib/use-next-row'
import { useOpenRow } from '@/lib/use-open-row'
import { usePagedList } from '@/lib/use-paged-list'
import { useSelection, type Selection } from '@/lib/use-selection'

/** A tab of the queue: a status, or the online payments held for support and still waiting (`held`, the API's). */
type Filter = PaymentStatus | 'held'

/** The queue's tabs after «همه»: what waits on support first — the receipts waiting for a review, the payments held. */
const QUEUE: Filter[] = ['awaiting_review', 'held', 'paid', 'failed', 'pending']
const TABS: Record<Filter, Status> = { ...PAYMENT_STATUS, held: PAYMENT_HELD }

/** What the headers sort the payments by — the list's own order, the newest first, leads. */
const SORTS: PaymentSort[] = ['created', 'amount']

/** Only a receipt waiting for review is ticked to be dismissed. */
const waiting = (payment: PaymentRow) => payment.status === 'awaiting_review'

/**
 * Every payment, with the receipt review queue up front: open one to see the receipt and accept or refuse it — or tick
 * the receipts waiting for review (or take all of them) to dismiss them at once (DismissReceiptsModal).
 */
export function PaymentsPage() {
  const tabs = useId()
  // The dashboard and the sidebar link here with ?status=awaiting_review, the dashboard with ?status=held too; an order to
  // one of its payments with ?search=#id; a customer's page with ?user=.
  const list = usePagedList({
    queryKey: queryKeys.payments,
    read: (query) => api.get<PaymentsResponse>('/payments', query),
    list: 'payments',
    statuses: ['', ...QUEUE],
    params: ['from', 'to', 'user'],
    ids: ['user'],
    sorts: SORTS,
    address: '/payments',
  })
  const days = { from: list.params.from ?? '', to: list.params.to ?? '' }
  const opened = useOpenRow({ queryKey: queryKeys.payment, read: (id) => api.get<PaymentResponse>(`/payments/${id}`).then((data) => data.payment), list })
  // The review dialog's «بعدی»: the receipts of a queue reviewed in a row.
  const next = useNextRow({ queryKey: queryKeys.payments, list, open: opened.row, onOpen: opened.open })
  // Ticks belong to one list: another tab, search, customer or dates start with nothing selected (its pages keep theirs).
  const selection = useSelection<PaymentRow>(`${list.filter}|${list.params.user}|${list.params.from}|${list.params.to}|${list.typed.trim()}`)
  // A receipt ticked and then decided elsewhere (another tab, the report group) loses its box: its tick goes with it.
  selection.follow(list.rows, waiting)
  const [dismissing, setDismissing] = useState<DismissScope | null>(null)
  const inReview = list.meta?.awaiting_review ?? 0

  // A decision's row goes into the list and the dialog; a receipt decided is no longer one to dismiss.
  const reviewed = (payment: PaymentRow) => {
    opened.apply(payment)
    if (waiting(payment)) selection.refresh(payment)
    else selection.set([payment], false)
  }

  return (
    <Page>
      <PageHeader
        title="پرداخت‌ها"
        description="هر پرداختی که مشتری‌ها شروع کرده‌اند. رسیدهای کارت به کارت این‌جا بررسی می‌شوند؛ تایید یعنی تحویل سرویس یا شارژ کیف پول. پرداخت آنلاین را خود درگاه تایید می‌کند و از جزئیاتش می‌شود از درگاه استعلام کرد. برای رد کردن چند رسید در انتظار با هم، انتخابشان کنید."
      />

      <PageTabs
        id={tabs}
        value={list.filter}
        tabs={statusTabs(TABS, QUEUE, { awaiting_review: list.meta?.awaiting_review, held: list.meta?.held })}
        onChange={list.setFilter}
        aria-label="وضعیت پرداخت"
      />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره پرداخت، سفارش یا مشتری" aria-label="جستجوی پرداخت" />
          <DateFilter
            range={days}
            basis="بر اساس زمان ثبت پرداخت"
            onChange={({ from, to }) => {
              list.setParam('from', from)
              list.setParam('to', to)
            }}
          />
          <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
          {/* Every receipt of the shop: offered only while no search or filter makes the list look like fewer. */}
          {list.filter === 'awaiting_review' && !list.narrowed && inReview > 0 && (
            <Button size="sm" variant="secondary" icon={XCircle} className="ms-auto" onClick={() => setDismissing({ waiting: inReview })}>
              رد همه رسیدهای در انتظار
            </Button>
          )}
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
              description={
                list.narrowed
                  ? 'با این جستجو یا فیلتر چیزی نیست.'
                  : list.filter === 'awaiting_review'
                    ? 'فعلا رسیدی منتظر بررسی نیست.'
                    : list.filter === 'held'
                      ? 'فعلا پرداختی برای پشتیبانی نگه داشته نشده است.'
                      : 'پرداختی در این وضعیت نیست.'
              }
            />
          }
        >
          <PaymentsTable payments={list.rows} sorting={list} selection={selection} onOpen={opened.open} />
        </ListView>
      </div>

      <SelectionBar selection={selection} noun="رسید" label="رسیدهای انتخاب‌شده">
        <Button size="sm" variant="destructive" icon={XCircle} onClick={() => setDismissing({ ids: selection.rows().map((payment) => payment.id) })}>
          رد رسیدهای انتخاب‌شده
        </Button>
      </SelectionBar>

      {/* A decision's row goes into the list, and into the dialog while it stays open (a reminder, a delivery that failed). */}
      <ReviewModal payment={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onReviewed={reviewed} onStale={opened.refresh} next={next} />
      <DismissReceiptsModal
        scope={dismissing}
        onClose={() => setDismissing(null)}
        onDone={() => {
          setDismissing(null)
          selection.clear()
        }}
      />
    </Page>
  )
}

function PaymentsTable({
  payments,
  sorting,
  selection,
  onOpen,
}: {
  payments: PaymentRow[]
  sorting: SortedList<PaymentSort>
  selection: Selection<PaymentRow>
  onOpen: (payment: PaymentRow) => void
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>
            {/* The page's receipts waiting for review: the only rows a dismissal takes. */}
            <Checkbox {...selection.page(payments.filter(waiting))} aria-label="انتخاب همه رسیدهای در انتظار این صفحه" />
          </TableHead>
          <TableHead>پرداخت</TableHead>
          <TableHead className="hidden md:table-cell">مشتری</TableHead>
          {/* What it paid for and when come with the widest screens: a tablet has a phone's room and a half. */}
          <TableHead className="hidden xl:table-cell">بابت</TableHead>
          <SortableHead list={sorting} by="amount">
            مبلغ
          </SortableHead>
          <TableHead className="hidden md:table-cell">وضعیت</TableHead>
          <SortableHead list={sorting} by="created" className="hidden xl:table-cell">
            زمان
          </SortableHead>
          {/* Its button says «بررسی» or «جزئیات», by the payment: the column is named for both. */}
          <TableHead className="md:w-24">
            <span className="sr-only">عملیات</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {payments.map((payment) => {
          const isSelected = selection.has(payment.id)
          return (
            <TableRow key={payment.id} data-state={isSelected ? 'selected' : undefined}>
              <TableCell>
                {waiting(payment) && <Checkbox checked={isSelected} onCheckedChange={(value) => selection.set([payment], value === true)} aria-label={`انتخاب رسید پرداخت #${payment.id}`} />}
              </TableCell>
              <TableCell>
                <div className="grid">
                  <RowTitleButton onClick={() => onOpen(payment)}>
                    <span dir="ltr">#{payment.id}</span>
                  </RowTitleButton>
                  <span className="max-w-44 truncate text-footnote text-muted-foreground">{payment.method}</span>
                </div>
                <PhoneFacts>
                  <PaymentStatusCell payment={payment} decided />
                  <UserLabel user={payment.user} />
                </PhoneFacts>
              </TableCell>
              <TableCell className="hidden md:table-cell">
                <UserIdentity user={payment.user} />
              </TableCell>
              <TableCell className="hidden xl:table-cell">
                <PaidFor order={payment.order} />
              </TableCell>
              <TableCell className="tabular">{formatMoney(payment.amount)}</TableCell>
              <TableCell className="hidden md:table-cell">
                <PaymentStatusCell payment={payment} decided />
              </TableCell>
              <TableCell className="hidden text-muted-foreground xl:table-cell">
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
          )
        })}
      </TableBody>
    </Table>
  )
}
