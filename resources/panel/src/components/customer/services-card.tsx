import { useState } from 'react'
import { Radio } from 'lucide-react'
import { customerList } from '@/components/customer-filter'
import { useLatestRows } from '@/components/customer/latest-rows'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleButton } from '@/components/list-view'
import { StatusBadge } from '@/components/status-badge'
import { MoveDialog } from '@/components/subscriptions/move-dialog'
import type { ServerChoices } from '@/components/subscriptions/server-choices'
import { ServiceExpiry, UsageMeter } from '@/components/subscriptions/service-usage'
import { SubscriptionModal } from '@/components/subscriptions/subscription-modal'
import { TextLink } from '@/components/text-link'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
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

/**
 * The customer's latest services, each opening the subscriptions screen's dialog — its link, its numbers, everything its
 * state allows (what an operation moves on the page — the customer's counts — is read again by the dialog's operations),
 * a move to another server —, and the way to every one of them on that screen.
 */
export function ServicesCard({ customer, servers, serverPage, traffic }: ServicesCardProps) {
  const latest = useLatestRows({ queryKey: queryKeys.subscriptions, read: (query) => api.get<SubscriptionsResponse>('/subscriptions', query), list: 'subscriptions', user: customer.id })
  const opened = useOpenRow({ queryKey: queryKeys.subscription, read: (id) => api.get<SubscriptionResponse>(`/subscriptions/${id}`).then((data) => data.subscription), list: latest })
  const [moving, setMoving] = useState<SubscriptionRow[] | null>(null)
  const { counts } = customer

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>سرویس‌ها</CardTitle>
          {counts.subscriptions > 0 && <CardDescription>{`${formatNumber(counts.active_subscriptions)} سرویس فعال از ${formatNumber(counts.subscriptions)}`}</CardDescription>}
        </CardHeading>
        {counts.subscriptions > 0 && (
          <CardAction>
            <TextLink to={customerList('/subscriptions', customer.id)} className="text-footnote">
              همه سرویس‌ها
            </TextLink>
          </CardAction>
        )}
      </CardHeader>
      <CardContent>
        <ListView list={latest} noun="سرویس‌ها" skeletonRows={2} empty={<EmptyState compact icon={Radio} title="هنوز سرویسی نخریده است" description="سرویس‌هایی که این مشتری بخرد این‌جا می‌آیند." />}>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>سرویس</TableHead>
                <TableHead className="hidden lg:table-cell">سرور</TableHead>
                <TableHead className="hidden md:table-cell">مصرف</TableHead>
                <TableHead className="hidden sm:table-cell">پایان</TableHead>
                <TableHead>وضعیت</TableHead>
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
                      <span className="text-footnote text-muted-foreground">{subscription.plan?.name ?? 'پلن حذف شده'}</span>
                    </div>
                  </TableCell>
                  <TableCell className="hidden lg:table-cell">{subscription.server.name}</TableCell>
                  <TableCell className="hidden md:table-cell">
                    <UsageMeter traffic={subscription.traffic} className="w-36" />
                  </TableCell>
                  <TableCell className="hidden sm:table-cell">
                    <ServiceExpiry subscription={subscription} />
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={SUBSCRIPTION_STATUS[subscription.status]} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </CardContent>

      <SubscriptionModal
        subscription={opened.row}
        gone={opened.gone}
        onClose={() => opened.open(null)}
        onChanged={opened.apply}
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
    </Card>
  )
}
