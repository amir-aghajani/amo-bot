import { useId, useState } from 'react'
import { ArrowLeftRight, Radio, Search } from 'lucide-react'
import { CustomerFilter } from '@/components/customer-filter'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { ListView, RowTitleButton, SortableHead, type SortedList } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { SelectionBar } from '@/components/selection-bar'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { MoveDialog } from '@/components/subscriptions/move-dialog'
import { shopServerChoices, useServerChoices, type ServerChoices } from '@/components/subscriptions/server-choices'
import { CLAIMED, ServicePlan } from '@/components/subscriptions/service-plan'
import { ServiceExpiry, UsageMeter } from '@/components/subscriptions/service-usage'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { SubscriptionKind, SubscriptionResponse, SubscriptionRow, SubscriptionSort, SubscriptionsResponse, SubscriptionStatus } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { SUBSCRIPTION_STATUS, type Status } from '@/lib/statuses'
import { useOpenRow } from '@/lib/use-open-row'
import { usePagedList } from '@/lib/use-paged-list'
import { useSelection, type Selection } from '@/lib/use-selection'
import { cn } from '@/lib/utils'

/** A status, or the one tab that is not: active services ending within the shop's window (the dashboard links here). */
type Filter = SubscriptionStatus | 'expiring'

const QUEUE: Filter[] = ['active', 'expiring', 'expired', 'disabled', 'deleted']
const TABS: Record<Filter, Status> = { ...SUBSCRIPTION_STATUS, expiring: { label: 'رو به انقضا', tone: 'warning' } }

/**
 * The kind pill's words: the free test accounts, every service neither a test nor added by its link — sold, renewed or
 * given —, or the services customers added by their link (the main shop's alone).
 */
const KINDS: Record<SubscriptionKind, string> = { test: 'اکانت تست', regular: 'سرویس عادی', claimed: CLAIMED }

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
  // An agent's shop takes no service by its link: it has no such kind.
  const mainShop = useMainShop()
  const kinds = (Object.keys(KINDS) as SubscriptionKind[]).filter((kind) => mainShop || kind !== 'claimed')
  // A server's page links here with ?server=&status=active (its services, to move them off it); the dashboard with
  // ?status=expiring; an order with ?search=#id; a customer's page with ?user=.
  const list = usePagedList({
    queryKey: queryKeys.subscriptions,
    read: (query) => api.get<SubscriptionsResponse>('/subscriptions', query),
    list: 'subscriptions',
    statuses: ['', ...QUEUE],
    params: ['server', 'user', 'kind'],
    choices: { kind: kinds },
    sorts: SORTS,
    address: '/subscriptions',
  })
  const servers = useServerChoices(readServers)
  const opened = useOpenRow({ queryKey: queryKeys.subscription, read: (id) => api.get<SubscriptionResponse>(`/subscriptions/${id}`).then((data) => data.subscription), list })
  const [moving, setMoving] = useState<SubscriptionRow[] | null>(null)
  // Ticks belong to one list: another tab, server, kind, customer or search starts with nothing selected (its pages
  // keep theirs).
  const selection = useSelection<SubscriptionRow>(`${list.filter}|${list.params.server}|${list.params.user}|${list.params.kind}|${list.typed.trim()}`)
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
        description={`سرویس‌های مشتری‌ها (${mainShop ? 'خریده‌شده، هدیه، اکانت تست یا افزوده‌شده با لینک مشتری' : 'خریده‌شده، هدیه یا اکانت تست'})، همان‌طور که فروشگاه آخرین بار از پنل دید. هر کدام را باز کنید تا لینک و مصرفش را ببینید، از پنل به‌روزش کنید، زمان و حجمش را بیشتر کنید، به سرور دیگری منتقلش کنید، به مشتری دیگری بدهید، غیرفعال یا حذفش کنید؛ برای انتقال چند سرویس با هم به سرور دیگر، انتخابشان کنید.`}
      />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(TABS, QUEUE, { expiring: list.meta?.expiring })} onChange={list.setFilter} aria-label="وضعیت اشتراک" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره یا نام کاربری سرویس، لینک یا مشتری" aria-label="جستجوی اشتراک" />
          <FilterSelect
            label="سرور"
            value={list.params.server ?? ''}
            options={[{ value: '', label: 'همه' }, ...(servers.data ?? []).map((server) => ({ value: String(server.id), label: server.name }))]}
            onChange={(value) => list.setParam('server', value)}
          />
          <FilterSelect
            label="نوع"
            value={list.params.kind ?? ''}
            options={[{ value: '', label: 'همه' }, ...kinds.map((kind) => ({ value: kind, label: KINDS[kind] }))]}
            onChange={(value) => list.setParam('kind', value)}
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

      <SelectionBar selection={selection} noun="سرویس" label="سرویس‌های انتخاب‌شده">
        <Button size="sm" icon={ArrowLeftRight} onClick={() => setMoving(selection.rows())}>
          انتقال به سرور دیگر
        </Button>
      </SelectionBar>

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

function SubscriptionsTable({
  subscriptions,
  sorting,
  selection,
  onOpen,
}: {
  subscriptions: SubscriptionRow[]
  sorting: SortedList<SubscriptionSort>
  selection: Selection<SubscriptionRow>
  onOpen: (subscription: SubscriptionRow) => void
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>
            <Checkbox {...selection.page(subscriptions)} aria-label="انتخاب همه سرویس‌های این صفحه" />
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
                  <ServicePlan subscription={subscription} />
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
