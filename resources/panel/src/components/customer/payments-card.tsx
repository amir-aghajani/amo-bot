import { CreditCard } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { LatestCard, useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { PaidFor } from '@/components/orders/order-subject'
import { ReviewModal } from '@/components/payments/review-modal'
import { StatusBadge } from '@/components/status-badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { PaymentResponse, PaymentsResponse, UserRow } from '@/lib/api-types'
import { formatMoney, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { HELD, PAYMENT_STATUS, TO_WALLET } from '@/lib/statuses'
import { useNextRow } from '@/lib/use-next-row'
import { useOpenRow } from '@/lib/use-open-row'

/** The customer's latest payments, each opening the payments screen's dialog («بعدی» walks the card), with a link to all of them. */
export function PaymentsCard({ customer }: { customer: UserRow }) {
  const latest = useLatestRows({ queryKey: queryKeys.payments, read: (query) => api.get<PaymentsResponse>('/payments', query), list: 'payments', user: customer.id })
  const opened = useOpenRow({ queryKey: queryKeys.payment, read: (id) => api.get<PaymentResponse>(`/payments/${id}`).then((data) => data.payment), list: latest })
  // «بعدی» goes through the card's payments: one page of them.
  const next = useNextRow({
    queryKey: latest.queryKey,
    list: { rows: latest.rows, meta: undefined, setPage: () => undefined, isPlaceholderData: false },
    open: opened.row,
    onOpen: opened.open,
  })

  return (
    <LatestCard title="پرداخت‌ها" count={latest.total ? `${formatNumber(latest.total)} پرداخت` : null} all={{ to: customerList('/payments', customer.id), label: 'همه پرداخت‌ها' }}>
      <ListView
        list={latest}
        noun="پرداخت‌ها"
        skeletonRows={2}
        empty={<EmptyState compact icon={CreditCard} title="هنوز پرداختی نکرده است" description="پرداخت‌های این مشتری — کارت به کارت، آنلاین یا از کیف پول — این‌جا می‌آیند." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>پرداخت</TableHead>
              <TableHead className="hidden md:table-cell">بابت</TableHead>
              <TableHead>مبلغ</TableHead>
              <TableHead>وضعیت</TableHead>
              <TableHead className="hidden sm:table-cell">زمان</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {latest.rows.map((payment) => (
              <TableRow key={payment.id}>
                <TableCell>
                  <div className="grid">
                    <RowTitleButton onClick={() => opened.open(payment)}>
                      <span dir="ltr">#{payment.id}</span>
                    </RowTitleButton>
                    <span className="max-w-40 truncate text-footnote text-muted-foreground">{payment.method}</span>
                  </div>
                </TableCell>
                <TableCell className="hidden md:table-cell">
                  <PaidFor order={payment.order} />
                </TableCell>
                <TableCell className="whitespace-nowrap tabular">{formatMoney(payment.amount)}</TableCell>
                <TableCell>
                  {/* The spaces between the lines keep them words apart for assistive tech, not one. */}
                  <div className="grid justify-items-start gap-0.5">
                    <StatusBadge status={PAYMENT_STATUS[payment.status]} /> {payment.to_wallet && <span className="text-caption text-warning">{TO_WALLET}</span>}{' '}
                    {payment.held && <span className="text-caption text-warning">{HELD}</span>}
                  </div>
                </TableCell>
                <TableCell className="hidden text-muted-foreground sm:table-cell">{timeAgo(payment.created_at)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <ReviewModal payment={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onReviewed={opened.apply} onStale={opened.refresh} next={next} />
    </LatestCard>
  )
}
