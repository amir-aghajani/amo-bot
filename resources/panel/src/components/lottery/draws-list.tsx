import { Dices } from 'lucide-react'
import { Link } from 'react-router'
import { EmptyState } from '@/components/empty-state'
import { ListView } from '@/components/list-view'
import { searchLink, TextLink } from '@/components/text-link'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserLabel, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { LotteryDrawRow, LotteryDrawsResponse } from '@/lib/api-types'
import { formatDay, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { usePagedList } from '@/lib/use-paged-list'

/** A draw's winners, each linked to their page (and to the service the prize went to), or why there are none. */
function Winners({ draw }: { draw: LotteryDrawRow }) {
  if (draw.winners.length === 0) {
    return <span className="text-muted-foreground">{draw.entries === 0 ? 'بدون شرکت‌کننده' : 'جایزه به کسی داده نشد'}</span>
  }

  return (
    <ul className="grid gap-1">
      {draw.winners.map((winner) => (
        <li key={winner.id} className="flex flex-wrap items-baseline gap-x-2">
          <Link to={userPage(winner.customer.id)} className="rounded-sm font-medium underline-offset-4 outline-none hover:underline focus-visible:focus-ring">
            <UserLabel user={winner.customer} />
          </Link>
          {winner.subscription_id !== null && (
            <TextLink to={searchLink('/subscriptions', winner.subscription_id)} className="text-footnote">
              سرویس
            </TextLink>
          )}
        </li>
      ))}
    </ul>
  )
}

/** The nightly draws, newest first: each one's night, entries, prize and winners. */
export function DrawsList() {
  const list = usePagedList({
    queryKey: queryKeys.lotteryDraws,
    read: (query) => api.get<LotteryDrawsResponse>('/lottery/draws', query),
    list: 'draws',
    address: '/lottery',
  })

  return (
    <ListView
      list={list}
      noun="قرعه‌کشی‌ها"
      unit="قرعه‌کشی"
      empty={<EmptyState framed icon={Dices} title="هنوز قرعه‌کشی‌ای انجام نشده است" description="وقتی قرعه‌کشی شبانه روشن باشد، هر شب نتیجه‌اش اینجا می‌آید: تعداد شانس‌ها، جایزه و برنده‌ها." />}
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>شب</TableHead>
            <TableHead>شانس‌ها</TableHead>
            <TableHead className="hidden md:table-cell">جایزه</TableHead>
            <TableHead>برنده‌ها</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {list.rows.map((draw) => (
            <TableRow key={draw.id}>
              <TableCell className="tabular">{formatDay(draw.day, { year: 'numeric', month: 'long', day: 'numeric' })}</TableCell>
              <TableCell className="tabular">{formatNumber(draw.entries)}</TableCell>
              <TableCell className="hidden md:table-cell">{draw.prize.words}</TableCell>
              <TableCell className="whitespace-normal">
                <Winners draw={draw} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </ListView>
  )
}
