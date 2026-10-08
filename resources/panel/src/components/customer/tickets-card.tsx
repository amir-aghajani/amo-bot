import { Headset } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView } from '@/components/list-view'
import { StatusBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { TicketSubject } from '@/components/tickets/ticket-cells'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { TicketsResponse, UserRow } from '@/lib/api-types'
import { formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { TICKET_STATUS } from '@/lib/statuses'

/**
 * The customer's latest support tickets — the conversation that moved last first —, each leading to its own page, where
 * it is answered, and the way to every one of them on the tickets screen.
 */
export function TicketsCard({ customer }: { customer: UserRow }) {
  const latest = useLatestRows({ queryKey: queryKeys.tickets, read: (query) => api.get<TicketsResponse>('/tickets', query), list: 'tickets', user: customer.id })
  const any = latest.total !== undefined && latest.total > 0

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>تیکت‌ها</CardTitle>
          {any && <CardDescription>{formatNumber(latest.total ?? 0)} تیکت</CardDescription>}
        </CardHeading>
        {any && (
          <CardAction>
            <TextLink to={customerList('/tickets', customer.id)} className="text-footnote">
              همه تیکت‌ها
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <ListView
          list={latest}
          noun="تیکت‌ها"
          skeletonRows={2}
          empty={<EmptyState compact icon={Headset} title="هنوز تیکتی باز نکرده است" description="تیکت‌هایی که این مشتری برای پشتیبانی باز کند این‌جا می‌آیند." />}
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>موضوع</TableHead>
                <TableHead className="hidden md:table-cell">شماره</TableHead>
                <TableHead>وضعیت</TableHead>
                <TableHead className="hidden sm:table-cell">آخرین فعالیت</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {latest.rows.map((ticket) => (
                <TableRow key={ticket.id}>
                  <TableCell>
                    <TicketSubject ticket={ticket} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground tabular md:table-cell">
                    <span dir="ltr">#{ticket.id}</span>
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={TICKET_STATUS[ticket.status]} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">{timeAgo(ticket.last_message_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </CardContent>
    </Card>
  )
}
