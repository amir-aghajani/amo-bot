import { useState } from 'react'
import { Radio } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { LatestCard, useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { StatusBadge } from '@/components/status-badge'
import { MoveDialog } from '@/components/subscriptions/move-dialog'
import type { ServerChoices } from '@/components/subscriptions/server-choices'
import { ServicePlan } from '@/components/subscriptions/service-plan'
import { ServiceExpiry, UsageMeter } from '@/components/subscriptions/service-usage'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { SubscriptionResponse, SubscriptionRow, SubscriptionsResponse, UserRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { SUBSCRIPTION_STATUS } from '@/lib/statuses'
import { useOpenRow } from '@/lib/use-open-row'
import { cn } from '@/lib/utils'

interface ServicesCardProps {
  customer: UserRow
  /** Where the panel reads the servers a service moves to. */
  servers: ServerChoices
  /** A server's own page, in a panel that has one (the owner's). */
  serverPage?: (id: number) => string
  /** The traffic the shop's bot may still sell, where the panel reads it (an agent's): what extending a service takes its GB from. */
  traffic?: number
}

/** The customer's latest services, each opening the subscriptions screen's dialog (moves included), with a link to all of them. */
export function ServicesCard({ customer, servers, serverPage, traffic }: ServicesCardProps) {
  const latest = useLatestRows({ queryKey: queryKeys.subscriptions, read: (query) => api.get<SubscriptionsResponse>('/subscriptions', query), list: 'subscriptions', user: customer.id })
  const opened = useOpenRow({ queryKey: queryKeys.subscription, read: (id) => api.get<SubscriptionResponse>(`/subscriptions/${id}`).then((data) => data.subscription), list: latest })
  const [moving, setMoving] = useState<SubscriptionRow[] | null>(null)
  const { counts } = customer

  // A service handed to another customer is theirs now: it leaves this customer's card, its dialog following it.
  const changed = (subscription: SubscriptionRow) => {
    opened.apply(subscription)
    if (subscription.user.id !== customer.id) latest.remove(subscription.id)
  }

  return (
    <LatestCard
      title="سرویس‌ها"
      count={counts.subscriptions > 0 ? `${formatNumber(counts.active_subscriptions)} سرویس فعال از ${formatNumber(counts.subscriptions)}` : null}
      all={{ to: customerList('/subscriptions', customer.id), label: 'همه سرویس‌ها' }}
    >
      <ListView list={latest} noun="سرویس‌ها" skeletonRows={2} empty={<EmptyState compact icon={Radio} title="هنوز سرویسی نخریده است" description="سرویس‌هایی که این مشتری بخرد این‌جا می‌آیند." />}>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>سرویس</TableHead>
              <TableHead className="hidden lg:table-cell">سرور</TableHead>
              <TableHead className="hidden md:table-cell">مصرف</TableHead>
              <TableHead className="hidden sm:table-cell">پایان</TableHead>
              <TableHead className="hidden md:table-cell">وضعیت</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {latest.rows.map((subscription) => (
              <TableRow key={subscription.id} className={cn(subscription.status === 'deleted' && 'text-muted-foreground')}>
                <TableCell>
                  <div className="grid">
                    <RowTitleButton onClick={() => opened.open(subscription)}>
                      <bdi dir="ltr" className="text-footnote">
                        {subscription.name}
                      </bdi>
                    </RowTitleButton>
                    <ServicePlan subscription={subscription} />
                  </div>
                  <PhoneFacts>
                    <StatusBadge status={SUBSCRIPTION_STATUS[subscription.status]} />
                  </PhoneFacts>
                </TableCell>
                <TableCell className="hidden lg:table-cell">{subscription.server.name}</TableCell>
                <TableCell className="hidden md:table-cell">
                  <UsageMeter traffic={subscription.traffic} className="w-36" />
                </TableCell>
                <TableCell className="hidden sm:table-cell">
                  <ServiceExpiry subscription={subscription} />
                </TableCell>
                <TableCell className="hidden md:table-cell">
                  <StatusBadge status={SUBSCRIPTION_STATUS[subscription.status]} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <SubscriptionModal
        subscription={opened.row}
        gone={opened.gone}
        onClose={() => opened.open(null)}
        onChanged={changed}
        onDeleted={(subscription) => latest.remove(subscription.id)}
        onStale={opened.refresh}
        onMove={(subscription) => {
          opened.open(null)
          setMoving([subscription])
        }}
        serverPage={serverPage}
        traffic={traffic}
      />
      <MoveDialog subscriptions={moving} servers={servers} onClose={() => setMoving(null)} onMoved={latest.replace} />
    </LatestCard>
  )
}
