import { FerrisWheel, Search } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { ListView, PhoneFacts } from '@/components/list-view'
import { SearchBox } from '@/components/search-box'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { WheelSpinRow, WheelSpinsResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatDate, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { WHEEL_PRIZE_KIND, type Status } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

const GIVEN: Status = { label: 'داده شد', tone: 'success' }
const NOT_GIVEN: Status = { label: 'داده نشد', tone: 'danger' }

/**
 * The spins made, newest first, searched by customer or narrowed to one (`?user=`): the slice each landed on and
 * whether the prize was given — with where it went — or why not.
 */
export function SpinsList() {
  const list = usePagedList({
    queryKey: queryKeys.wheelSpins,
    read: (query) => api.get<WheelSpinsResponse>('/wheel/spins', query),
    list: 'spins',
    params: ['user'],
    ids: ['user'],
    address: '/wheel/spins',
  })

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه تلگرام مشتری" aria-label="جستجوی چرخش" />
        <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
      </div>
      <ListView
        list={list}
        noun="چرخش‌ها"
        unit="چرخش"
        skeletonRows={6}
        empty={<EmptyState framed icon={FerrisWheel} title="هنوز کسی گردونه را نچرخانده است" description="هر بار که مشتری‌ای در ربات گردونه را بچرخاند، جایزه‌اش این‌جا می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="چرخشی پیدا نشد" description="با این جستجو یا فیلتر چرخشی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>مشتری</TableHead>
              <TableHead>جایزه</TableHead>
              <TableHead className="hidden md:table-cell">نتیجه</TableHead>
              <TableHead className="hidden sm:table-cell">زمان</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((spin) => (
              <TableRow key={spin.id}>
                <TableCell>
                  <UserIdentity user={spin.customer} to={userPage(spin.customer.id)} />
                  <PhoneFacts>
                    <Outcome spin={spin} />
                  </PhoneFacts>
                </TableCell>
                <TableCell>
                  <div className="grid gap-0.5">
                    <bdi className="font-medium">{spin.label}</bdi>
                    <span className="text-footnote text-muted-foreground">{WHEEL_PRIZE_KIND[spin.kind]}</span>
                  </div>
                </TableCell>
                <TableCell className="hidden md:table-cell">
                  <Outcome spin={spin} />
                </TableCell>
                <TableCell className="hidden text-muted-foreground sm:table-cell">
                  <time dateTime={spin.created_at} title={formatDate(spin.created_at)}>
                    {timeAgo(spin.created_at)}
                  </time>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>
    </>
  )
}

/** Whether a spin's prize was given, and where it went or why not. */
function Outcome({ spin }: { spin: WheelSpinRow }) {
  return (
    <div className="grid justify-items-start gap-1">
      <StatusBadge status={spin.given ? GIVEN : NOT_GIVEN} /> {spin.outcome && <span className="max-w-xs text-footnote text-muted-foreground">{spin.outcome}</span>}
      {spin.order ? (
        <TextLink to={searchLink('/orders', spin.order.id)} className="text-footnote">
          سفارش {idLabel(spin.order.id)}
        </TextLink>
      ) : (
        spin.subscription && (
          <TextLink to={searchLink('/subscriptions', spin.subscription.id)} className="text-footnote">
            <bdi dir="ltr">{spin.subscription.name}</bdi>
          </TextLink>
        )
      )}
    </div>
  )
}
