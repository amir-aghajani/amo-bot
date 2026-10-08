import { useId } from 'react'
import { Headset, Search } from 'lucide-react'
import { Link } from 'react-router'
import { CustomerFilter } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { Dash, ListView } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { ticketPage, TicketRating, TicketSubject } from '@/components/tickets/ticket-cells'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { TicketRow, TicketsResponse, TicketStatus } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { TICKET_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

/** The tabs after «همه»: the tickets waiting on support first — the queue —, then those waiting on the customer, then the closed. */
const QUEUE: TicketStatus[] = ['open', 'answered', 'closed']

/**
 * The support tickets of the shop, the conversation that moved last first (no column sorts them, by decision: that is
 * the one to look at), with the queue waiting on an answer up front and counted: open one to answer it, close it or open
 * it again.
 */
export function TicketsPage() {
  const tabs = useId()
  // The dashboard's attention card links here with ?status=open; a customer's page with ?user=; a link to one ticket
  // with ?search=#id.
  const list = usePagedList({
    queryKey: queryKeys.tickets,
    read: (query) => api.get<TicketsResponse>('/tickets', query),
    list: 'tickets',
    statuses: ['', ...QUEUE],
    params: ['user'],
    address: '/tickets',
  })

  return (
    <Page>
      <PageHeader
        title="پشتیبانی"
        description="تیکت‌هایی که مشتری‌ها برای پشتیبانی باز کرده‌اند؛ تیکتی که تازه‌ترین پیام را دارد بالاتر است. تیکت‌های «در انتظار پاسخ» منتظر شما هستند و مشتری از پاسخی که این‌جا می‌دهید خبردار می‌شود."
      />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(TICKET_STATUS, QUEUE, { open: list.meta?.open })} onChange={list.setFilter} aria-label="وضعیت تیکت" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره تیکت، مشتری یا موضوع" aria-label="جستجوی تیکت" />
          <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
        </div>

        <ListView
          list={list}
          noun="تیکت‌ها"
          unit="تیکت"
          skeletonRows={6}
          empty={<EmptyState framed icon={Headset} title="هنوز تیکتی باز نشده است" description="تیکت‌هایی که مشتری‌ها برای پشتیبانی باز کنند این‌جا می‌آیند." />}
          noMatch={
            <EmptyState
              framed
              icon={Search}
              title="تیکتی پیدا نشد"
              description={list.narrowed ? 'با این جستجو یا فیلتر چیزی نیست.' : list.filter === 'open' ? 'فعلا تیکتی در انتظار پاسخ نیست.' : 'تیکتی در این وضعیت نیست.'}
            />
          }
        >
          <TicketsTable tickets={list.rows} />
        </ListView>
      </div>
    </Page>
  )
}

function TicketsTable({ tickets }: { tickets: TicketRow[] }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>شماره</TableHead>
          <TableHead>موضوع</TableHead>
          <TableHead>مشتری</TableHead>
          <TableHead>وضعیت</TableHead>
          <TableHead className="hidden sm:table-cell">آخرین فعالیت</TableHead>
          <TableHead className="hidden md:table-cell">پیام‌ها</TableHead>
          <TableHead className="hidden lg:table-cell">امتیاز</TableHead>
          <TableHead className="w-24">
            <span className="sr-only">پاسخ</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {tickets.map((ticket) => (
          <TableRow key={ticket.id}>
            <TableCell className="text-muted-foreground tabular">
              <span dir="ltr">#{ticket.id}</span>
            </TableCell>
            <TableCell>
              <TicketSubject ticket={ticket} />
            </TableCell>
            <TableCell>
              <UserIdentity user={ticket.customer} to={userPage(ticket.customer.id)} />
            </TableCell>
            <TableCell>
              <StatusBadge status={TICKET_STATUS[ticket.status]} />
            </TableCell>
            <TableCell className="hidden text-muted-foreground sm:table-cell">{timeAgo(ticket.last_message_at)}</TableCell>
            <TableCell className="hidden tabular md:table-cell">{formatNumber(ticket.messages_count)}</TableCell>
            <TableCell className="hidden lg:table-cell">{ticket.rating === null ? <Dash /> : <TicketRating rating={ticket.rating} />}</TableCell>
            <TableCell>
              <div className="flex justify-end">
                {/* What waits on support is answered: that row's way in is the primary one — named by its ticket, one of many. */}
                <Button asChild size="sm" variant={ticket.status === 'open' ? 'default' : 'secondary'}>
                  <Link to={ticketPage(ticket.id)}>
                    {ticket.status === 'open' ? 'پاسخ' : 'مشاهده'}
                    <span className="sr-only"> تیکت {idLabel(ticket.id)}</span>
                  </Link>
                </Button>
              </div>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
