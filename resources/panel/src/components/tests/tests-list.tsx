import { FlaskConical, Search } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { Dash, ListView, PhoneFacts } from '@/components/list-view'
import { SearchBox } from '@/components/search-box'
import { StatusBadge } from '@/components/status-badge'
import { UsageMeter } from '@/components/subscriptions/service-usage'
import { TestTerms } from '@/components/tests/test-terms'
import { searchLink, TextLink } from '@/components/text-link'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { TestAccountRow, TestAccountsResponse, TestAccountState } from '@/lib/api-types'
import { formatDate, timeAgo, timeLeft } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { TEST_STATE } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

const STATES = Object.keys(TEST_STATE) as TestAccountState[]

/**
 * The tests customers took, newest first, searched by customer, narrowed to a state or to one customer (`?user=`): the
 * terms each got, its traffic used, where it stands with its service, and whether the customer bought after it.
 */
export function TestsList() {
  const list = usePagedList({
    queryKey: queryKeys.tests,
    read: (query) => api.get<TestAccountsResponse>('/tests', query),
    list: 'tests',
    statuses: ['', ...STATES],
    params: ['user'],
    ids: ['user'],
    address: '/tests',
  })

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه تلگرام مشتری" aria-label="جستجوی اکانت تست" />
        <FilterSelect
          label="وضعیت"
          value={list.filter}
          options={[{ value: '', label: 'همه' }, ...STATES.map((state) => ({ value: state, label: TEST_STATE[state].label }))]}
          onChange={list.setFilter}
        />
        <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
      </div>
      <ListView
        list={list}
        noun="اکانت‌های تست"
        unit="اکانت تست"
        skeletonRows={6}
        empty={<EmptyState framed icon={FlaskConical} title="هنوز کسی اکانت تست نگرفته است" description="هر بار که مشتری‌ای در ربات اکانت تست بگیرد، این‌جا می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="اکانت تستی پیدا نشد" description="با این جستجو یا فیلتر اکانتی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>مشتری</TableHead>
              <TableHead className="hidden lg:table-cell">سرور</TableHead>
              <TableHead className="hidden md:table-cell">حجم و مدت</TableHead>
              <TableHead className="hidden md:table-cell">مصرف</TableHead>
              <TableHead className="hidden md:table-cell">وضعیت</TableHead>
              <TableHead className="hidden sm:table-cell">زمان</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((test) => (
              <TableRow key={test.id} className={cn(test.state === 'removed' && 'text-muted-foreground')}>
                <TableCell>
                  <UserIdentity user={test.customer} to={userPage(test.customer.id)} badge={test.bought_after && <Badge variant="success">خرید کرد</Badge>} />
                  <PhoneFacts>
                    <State test={test} />
                  </PhoneFacts>
                </TableCell>
                <TableCell className="hidden lg:table-cell">{test.server ? <bdi>{test.server.name}</bdi> : <span className="text-muted-foreground">سرور حذف شده</span>}</TableCell>
                <TableCell className="hidden md:table-cell">
                  <TestTerms bytes={test.traffic_bytes} hours={test.duration_hours} onHold={test.on_hold} />
                </TableCell>
                <TableCell className="hidden md:table-cell">{test.subscription ? <UsageMeter traffic={test.subscription.traffic} className="w-36" /> : <Dash />}</TableCell>
                <TableCell className="hidden md:table-cell">
                  <State test={test} />
                </TableCell>
                <TableCell className="hidden text-muted-foreground sm:table-cell">
                  <time dateTime={test.created_at} title={formatDate(test.created_at)}>
                    {timeAgo(test.created_at)}
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

/** Where a test stands, how long a running one has left, and its service on the subscriptions screen while it has one. */
function State({ test }: { test: TestAccountRow }) {
  const service = test.subscription

  return (
    <div className="grid justify-items-start gap-1">
      <StatusBadge status={TEST_STATE[test.state]} />
      {test.state === 'running' && service?.expires_at && <span className="text-footnote text-muted-foreground">{timeLeft(service.expires_at)}</span>}
      {service && (
        <TextLink to={searchLink('/subscriptions', service.id)} className="text-footnote">
          <bdi dir="ltr">{service.name}</bdi>
        </TextLink>
      )}
    </div>
  )
}
