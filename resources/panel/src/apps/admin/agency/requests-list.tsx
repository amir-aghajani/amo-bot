import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Inbox, Search, X } from 'lucide-react'
import { ApproveModal } from '@/components/agency/approve-modal'
import { agencyLevelsQuery, agencySettingsQuery } from '@/components/agency/queries'
import { RejectModal } from '@/components/agency/reject-modal'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { Dash, ListView, SortableHead } from '@/components/list-view'
import { Reviewer } from '@/components/reviewer'
import { SearchBox } from '@/components/search-box'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgencyRequestRow, AgencyRequestSort, AgencyRequestsResponse, AgencyRequestStatus } from '@/lib/api-types'
import { formatPricePerGb, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { AGENCY_REQUEST_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

/** The list's statuses: the waiting ones first — the list opens on them —, then the decided, and all. */
const STATUSES: (AgencyRequestStatus | '')[] = ['pending', 'approved', 'rejected', '']

/** The status pill's choices: «همه» first, as on every pill, then the statuses in the list's order. */
const PILL: (AgencyRequestStatus | '')[] = ['', ...STATUSES.filter((status) => status !== '')]

/** What the header sorts the requests by: when each came — the newest first, or the oldest, the queue's way. */
const SORTS: AgencyRequestSort[] = ['created']

/** The requests, waiting ones first: approve on a level, or reject with a note. */
export function RequestsList() {
  const list = usePagedList({
    queryKey: queryKeys.agencyRequests,
    read: (query) => api.get<AgencyRequestsResponse>('/agency/requests', query),
    list: 'requests',
    statuses: STATUSES,
    sorts: SORTS,
    address: '/agents',
  })
  const levels = useQuery(agencyLevelsQuery)
  // An approval offers the program's starting credit (the report group's buttons give that one).
  const rules = useQuery(agencySettingsQuery).data?.settings
  const [approving, setApproving] = useState<AgencyRequestRow | null>(null)
  const [rejecting, setRejecting] = useState<AgencyRequestRow | null>(null)

  const none = (
    <EmptyState
      framed
      icon={Inbox}
      title={list.filter === 'pending' ? 'درخواستی در انتظار بررسی نیست' : 'درخواستی نیست'}
      description="درخواست‌هایی که مشتری‌ها از صفحه «نمایندگی» ربات می‌فرستند این‌جا می‌آید؛ در گروه گزارش‌ها هم با دکمه‌های تایید و رد."
    />
  )

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه تلگرام" aria-label="جستجوی درخواست" />
        <FilterSelect label="وضعیت" value={list.filter} options={PILL.map((value) => ({ value, label: value === '' ? 'همه' : AGENCY_REQUEST_STATUS[value].label }))} onChange={list.setFilter} />
      </div>
      <ListView
        list={list}
        noun="درخواست‌ها"
        unit="درخواست"
        empty={none}
        noMatch={list.narrowed ? <EmptyState framed icon={Search} title="درخواستی پیدا نشد" description="با این جستجو چیزی نیست." /> : none}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>مشتری</TableHead>
              <TableHead>توضیح</TableHead>
              <TableHead>وضعیت</TableHead>
              <SortableHead list={list} by="created" className="hidden md:table-cell">
                زمان
              </SortableHead>
              <TableHead>
                <span className="sr-only">کارها</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((request) => (
              <TableRow key={request.id}>
                <TableCell>
                  <UserIdentity user={request.user} status={request.user_status} />
                </TableCell>
                <TableCell className="max-w-72">
                  {request.note ? <p className="line-clamp-2 whitespace-pre-line">{request.note}</p> : <Dash />}
                  {request.status === 'rejected' && request.reason && <p className="mt-1 line-clamp-1 text-footnote text-muted-foreground">پشتیبانی: {request.reason}</p>}
                </TableCell>
                <TableCell>
                  {/* The spaces between the lines keep them words apart for assistive tech, not one. */}
                  <div className="grid justify-items-start gap-1">
                    <StatusBadge status={AGENCY_REQUEST_STATUS[request.status]} />{' '}
                    {request.level && (
                      <span className="text-footnote text-muted-foreground">
                        {request.level.name} · {formatPricePerGb(request.level.price_per_gb)}
                      </span>
                    )}{' '}
                    {/* Who decided, and when: the time column is when the request came, which the list sorts by. */}
                    {request.decided_at && (
                      <span className="text-footnote text-muted-foreground">
                        {request.reviewer && (
                          <>
                            <Reviewer name={request.reviewer} />
                            {' · '}
                          </>
                        )}
                        {timeAgo(request.decided_at)}
                      </span>
                    )}
                  </div>
                </TableCell>
                <TableCell className="hidden text-muted-foreground md:table-cell">{timeAgo(request.created_at)}</TableCell>
                <TableCell>
                  <div className="flex justify-end gap-2">
                    {request.actions.reject && (
                      <Button size="sm" variant="secondary" icon={X} onClick={() => setRejecting(request)}>
                        رد
                      </Button>
                    )}
                    {request.actions.approve && (
                      <Button size="sm" icon={Check} onClick={() => setApproving(request)}>
                        تایید
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <ApproveModal
        request={approving}
        defaultCredit={rules?.default_credit ?? '0'}
        defaultLevel={levels.data?.levels[0]?.id ?? null}
        onClose={() => setApproving(null)}
        onDone={(request) => {
          list.replace(request)
          setApproving(null)
        }}
      />
      <RejectModal request={rejecting} onClose={() => setRejecting(null)} onDone={list.replace} />
    </>
  )
}
