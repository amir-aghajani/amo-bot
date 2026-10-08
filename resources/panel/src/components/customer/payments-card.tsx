import { CreditCard } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { ReviewModal } from '@/components/payments/review-modal'
import { StatusBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { PaymentResponse, PaymentsResponse, UserRow } from '@/lib/api-types'
import { formatMoney, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_TYPE, PAYMENT_STATUS } from '@/lib/statuses'
import { useNextRow } from '@/lib/use-next-row'
import { useOpenRow } from '@/lib/use-open-row'

/**
 * The customer's latest payments, each opening the payments screen's dialog — the receipt, the facts, every decision its
 * state allows (what a decision moves on the page — the customer, their wallet, their orders and services — is read
 * again by the dialog's operations), «بعدی» through the card —, and the way to every one of them on that screen.
 */
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
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>پرداخت‌ها</CardTitle>
          {latest.total !== undefined && latest.total > 0 && <CardDescription>{formatNumber(latest.total)} پرداخت</CardDescription>}
        </CardHeading>
        {latest.total !== undefined && latest.total > 0 && (
          <CardAction>
            <TextLink to={customerList('/payments', customer.id)} className="text-footnote">
              همه پرداخت‌ها
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <ListView
          list={latest}
          noun="پرداخت‌ها"
          skeletonRows={2}
          empty={<EmptyState compact icon={CreditCard} title="هنوز پرداختی نکرده است" description="پرداخت‌های این مشتری — کارت به کارت یا از کیف پول — این‌جا می‌آیند." />}
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
                  <TableCell className="hidden md:table-cell">{payment.order.plan ?? ORDER_TYPE[payment.order.type]}</TableCell>
                  <TableCell className="whitespace-nowrap tabular">{formatMoney(payment.amount)}</TableCell>
                  <TableCell>
                    <StatusBadge status={PAYMENT_STATUS[payment.status]} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">{timeAgo(payment.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </CardContent>

      <ReviewModal payment={opened.row} gone={opened.gone} onClose={() => opened.open(null)} onReviewed={opened.apply} onStale={opened.refresh} next={next} />
    </Card>
  )
}
