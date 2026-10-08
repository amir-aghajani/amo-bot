import { useId, useState } from 'react'
import { ArrowLeftRight, Radio, Search, X } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { ListView, RowTitleButton, SortableHead, type SortedList } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { MoveDialog } from '@/components/subscriptions/move-dialog'
import { shopServerChoices, useServerChoices, type ServerChoices } from '@/components/subscriptions/server-choices'
import { ServiceExpiry, UsageMeter } from '@/components/subscriptions/service-usage'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { SubscriptionResponse, SubscriptionRow, SubscriptionSort, SubscriptionsResponse, SubscriptionStatus } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { SUBSCRIPTION_STATUS, type Status } from '@/lib/statuses'
import { useOpenRow } from '@/lib/use-open-row'
import { usePagedList } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

/** A status, or the one tab that is not: active services ending within the shop's window (the dashboard links here). */
type Filter = SubscriptionStatus | 'expiring'

const QUEUE: Filter[] = ['active', 'expiring', 'expired', 'disabled', 'deleted']
const TABS: Record<Filter, Status> = { ...SUBSCRIPTION_STATUS, expiring: { label: 'رو به انقضا', tone: 'warning' } }

/** What the headers sort the services by — the list's own order, the newest first, leads. */
const SORTS: SubscriptionSort[] = ['created', 'expires', 'used']

interface SubscriptionsPageProps {
  /** Where the panel reads the servers the list narrows by and services move to — the shop's, unless it reads more. */
  servers?: ServerChoices
  /** A server's own page, in a panel that has one (the owner's). */
  serverPage?: (id: number) => string
  /** The traffic the shop's bot may still sell, where the panel reads it (an agent's own): what extending a service takes its GB from. */
  traffic?: number
}

/** Every service sold, as the shop last saw it on its panel: open one for its link, its numbers and what can be done with it. */
export function SubscriptionsPage({ servers: readServers = shopServerChoices, serverPage, traffic }: SubscriptionsPageProps) {
  const tabs = useId()
  // A server's page links here with ?server=&status=active (its services, to move them off it); the dashboard with
  // ?status=expiring; an order with ?search=#id; a customer's page with ?user=.
  const list = usePagedList({
    queryKey: queryKeys.subscriptions,
    read: (query) => api.get<SubscriptionsResponse>('/subscriptions', query),
    list: 'subscriptions',
    statuses: ['', ...QUEUE],
    params: ['server', 'user'],
    sorts: SORTS,
    address: '/subscriptions',
  })
  const servers = useServerChoices(readServers)
  const opened = useOpenRow({ queryKey: queryKeys.subscription, read: (id) => api.get<SubscriptionResponse>(`/subscriptions/${id}`).then((data) => data.subscription), list })
  const [moving, setMoving] = useState<SubscriptionRow[] | null>(null)
  // Ticks belong to one list: another tab, server, customer or search starts with nothing selected (its pages keep theirs).
  const selection = useSelection<SubscriptionRow>(`${list.filter}|${list.params.server}|${list.params.user}|${list.typed.trim()}`)
  const days = list.meta?.expiring_days

  // An operation's fresh row goes into the list, the modal and the selection (what else it moved is read again — the
  // dialog's operations' `invalidates`).
  const changed = (subscription: SubscriptionRow) => {
    opened.apply(subscription)
    selection.refresh(subscription)
  }

  // A deleted service is gone for good: out of the list and the selection.
  const deleted = (subscription: SubscriptionRow) => {
    list.remove(subscription.id)
    selection.set([subscription], false)
  }

  // A moved service is done with and leaves the selection; one that failed stays ticked for another try.
  const moved = (subscription: SubscriptionRow) => {
    list.replace(subscription)
    selection.set([subscription], false)
  }

  return (
    <Page>
      <PageHeader
        title="اشتراک‌ها"
        description="سرویس‌هایی که مشتری‌ها خریده‌اند، همان‌طور که فروشگاه آخرین بار از پنل دید. هر کدام را باز کنید تا لینک و مصرفش را ببینید، از پنل به‌روزش کنید، زمان و حجمش را بیشتر کنید، به سرور دیگری منتقلش کنید، غیرفعال یا حذفش کنید؛ برای انتقال چند سرویس با هم، انتخابشان کنید."
      />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(TABS, QUEUE, { expiring: list.meta?.expiring })} onChange={list.setFilter} aria-label="وضعیت اشتراک" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره یا نام سرویس، لینک یا مشتری" aria-label="جستجوی اشتراک" />
          <FilterSelect
            label="سرور"
            value={list.params.server ?? ''}
            options={[{ value: '', label: 'همه' }, ...(servers.data ?? []).map((server) => ({ value: String(server.id), label: server.name }))]}
            onChange={(value) => list.setParam('server', value)}
          />
          <CustomerFilter value={list.params.user ?? ''} onClear={() => list.setParam('user', '')} />
        </div>

        <ListView
          list={list}
          noun="اشتراک‌ها"
          unit="اشتراک"
          skeletonRows={6}
          empty={<EmptyState framed icon={Radio} title="هنوز سرویسی فروخته نشده است" description="اولین خرید از ربات این‌جا ظاهر می‌شود." />}
          noMatch={
            <EmptyState
              framed
              icon={Search}
              title="اشتراکی پیدا نشد"
              description={
                list.narrowed
                  ? 'با این جستجو یا فیلتر چیزی نیست.'
                  : list.filter === 'expiring' && days !== undefined
                    ? `فعلا سرویسی در ${formatNumber(days)} روز آینده تمام نمی‌شود.`
                    : 'اشتراکی در این وضعیت نیست.'
              }
            />
          }
        >
          <SubscriptionsTable subscriptions={list.rows} sorting={list} selection={selection} onOpen={opened.open} />
        </ListView>
      </div>

      {/* Pinned to the bottom of the screen while the list scrolls under it, so ticking a row never moves the rows. */}
      {selection.size > 0 && (
        <div
          role="toolbar"
          aria-label="سرویس‌های انتخاب‌شده"
          className="sticky bottom-5 z-10 flex w-fit max-w-full flex-wrap items-center justify-center gap-2 self-center rounded-xl border border-border bg-popover py-1.5 ps-4 pe-1.5 shadow-popover"
        >
          <span className="text-body tabular">{formatNumber(selection.size)} سرویس انتخاب شده</span>
          <Button size="sm" icon={ArrowLeftRight} onClick={() => setMoving(selection.rows())}>
            انتقال به سرور دیگر
          </Button>
          <Button size="icon-sm" variant="ghost" icon={X} onClick={selection.clear} aria-label="لغو انتخاب" />
        </div>
      )}

      <SubscriptionModal
        subscription={opened.row}
        gone={opened.gone}
        onClose={() => opened.open(null)}
        onChanged={changed}
        onDeleted={deleted}
        onStale={opened.refresh}
        onMove={(subscription) => {
          opened.open(null)
          setMoving([subscription])
        }}
        serverPage={serverPage}
        traffic={traffic}
      />
      <MoveDialog subscriptions={moving} servers={readServers} onClose={() => setMoving(null)} onMoved={moved} />
    </Page>
  )
}

/**
 * The rows ticked, kept across the list's pages; another `scope` (a tab, a filter, a search) starts with none. A row
 * an operation handed back replaces its ticked copy (`refresh`).
 */
function useSelection<Row extends { id: number }>(scope: string) {
  const [selected, setSelected] = useState<{ scope: string; rows: ReadonlyMap<number, Row> }>({ scope, rows: new Map() })
  const rows = selected.scope === scope ? selected.rows : new Map<number, Row>()
  const change = (next: (current: ReadonlyMap<number, Row>) => ReadonlyMap<number, Row>) => setSelected((current) => ({ scope, rows: next(current.scope === scope ? current.rows : new Map()) }))

  return {
    size: rows.size,
    has: (id: number) => rows.has(id),
    rows: () => [...rows.values()],
    /** Tick or untick rows; the header's box does the whole page. */
    set: (some: Row[], on: boolean) =>
      change((current) => {
        const next = new Map(current)
        for (const row of some) {
          if (on) next.set(row.id, row)
          else next.delete(row.id)
        }
        return next
      }),
    refresh: (row: Row) => change((current) => (current.has(row.id) ? new Map(current).set(row.id, row) : current)),
    clear: () => change(() => new Map()),
  }
}

type Selection = ReturnType<typeof useSelection<SubscriptionRow>>

function SubscriptionsTable({
  subscriptions,
  sorting,
  selection,
  onOpen,
}: {
  subscriptions: SubscriptionRow[]
  sorting: SortedList<SubscriptionSort>
  selection: Selection
  onOpen: (subscription: SubscriptionRow) => void
}) {
  const ticked = subscriptions.filter((s) => selection.has(s.id)).length
  const pageTicked = ticked === 0 ? false : ticked === subscriptions.length ? true : 'indeterminate'

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>
            <Checkbox checked={pageTicked} onCheckedChange={(value) => selection.set(subscriptions, value === true)} aria-label="انتخاب همه سرویس‌های این صفحه" />
          </TableHead>
          <TableHead>سرویس</TableHead>
          <TableHead>مشتری</TableHead>
          <TableHead className="hidden lg:table-cell">سرور</TableHead>
          <SortableHead list={sorting} by="used" label="حجم مصرف‌شده" className="hidden md:table-cell">
            مصرف
          </SortableHead>
          <SortableHead list={sorting} by="expires" first="asc" label="زمان پایان" className="hidden sm:table-cell">
            پایان
          </SortableHead>
          <TableHead>وضعیت</TableHead>
          <TableHead className="w-20">
            <span className="sr-only">جزئیات</span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {subscriptions.map((subscription) => {
          const isSelected = selection.has(subscription.id)
          return (
            <TableRow key={subscription.id} data-state={isSelected ? 'selected' : undefined} className={cn(subscription.status === 'deleted' && 'text-muted-foreground')}>
              <TableCell>
                <Checkbox checked={isSelected} onCheckedChange={(value) => selection.set([subscription], value === true)} aria-label={`انتخاب ${subscription.name}`} />
              </TableCell>
              <TableCell>
                <div className="grid">
                  <RowTitleButton onClick={() => onOpen(subscription)}>
                    <bdi dir="ltr" className="text-footnote">
                      {subscription.name}
                    </bdi>
                  </RowTitleButton>
                  <span className="text-footnote text-muted-foreground">{subscription.plan?.name ?? 'پلن حذف شده'}</span>
                </div>
              </TableCell>
              <TableCell>
                <UserIdentity user={subscription.user} />
              </TableCell>
              <TableCell className="hidden lg:table-cell">{subscription.server.name}</TableCell>
              <TableCell className="hidden md:table-cell">
                <UsageMeter traffic={subscription.traffic} className="w-40" />
              </TableCell>
              <TableCell className="hidden sm:table-cell">
                <ServiceExpiry subscription={subscription} />
              </TableCell>
              <TableCell>
                <StatusBadge status={SUBSCRIPTION_STATUS[subscription.status]} />
              </TableCell>
              <TableCell>
                <div className="flex justify-end">
                  <Button size="sm" variant="secondary" onClick={() => onOpen(subscription)}>
                    جزئیات
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
