import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { RefreshCw } from 'lucide-react'
import { AttentionCard } from '@/components/dashboard/attention-card'
import { OverviewChart } from '@/components/dashboard/overview-chart'
import { RecentOrders } from '@/components/dashboard/recent-orders'
import { RecentUsers } from '@/components/dashboard/recent-users'
import { ErrorState } from '@/components/error-state'
import { FilterSelect } from '@/components/filter-select'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { StatCard, StatGrid } from '@/components/stat-card'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { api } from '@/lib/api'
import type { DashboardResponse, RangeDays } from '@/lib/api-types'
import { useSession } from '@/lib/auth'
import { formatAmount, formatNumber, MONEY_UNIT, percentChange, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { cn } from '@/lib/utils'

const RANGES: { value: `${RangeDays}`; label: string }[] = [
  { value: '7', label: '۷ روز گذشته' },
  { value: '30', label: '۳۰ روز گذشته' },
  { value: '90', label: '۹۰ روز گذشته' },
]

/** The services ending within the shop's one "ending soon" window, under the active ones' count. */
function expiringHint(count: number, days: number): string {
  return count > 0 ? `${formatNumber(count)} اشتراک تا ${formatNumber(days)} روز آینده تمام می‌شود` : `هیچ اشتراکی تا ${formatNumber(days)} روز آینده تمام نمی‌شود`
}

/** «صبح بخیر» … by the hour on the admin's clock. */
function greeting(): string {
  const hour = new Date().getHours()
  if (hour >= 5 && hour < 11) return 'صبح بخیر'
  if (hour >= 11 && hour < 15) return 'ظهر بخیر'
  if (hour >= 15 && hour < 19) return 'عصر بخیر'
  return 'شب بخیر'
}

interface DashboardPageProps {
  /** What the panel adds beside what needs a hand (the owner's system card). */
  aside?: ReactNode
  /** What to do about an agent's bot whose traffic sells nothing, the panel's own way (an agent buys in the main bot). */
  trafficHelp?: ReactNode
}

/**
 * The overview, as the Console's: a greeting, the headline figures, the period's curve, what needs a hand (and beside
 * it what a panel adds), and the latest arrivals. Another range keeps the last one's figures on screen, dimmed — worded
 * as that range's, which they are — until its own arrive; a read that failed with nothing to show leaves every card at
 * «—» under the failure — never a zero it does not know, nor an "all clear".
 */
export function DashboardPage({ aside, trafficHelp }: DashboardPageProps) {
  const session = useSession()
  const [range, setRange] = useState<`${RangeDays}`>('30')
  const days = Number(range)

  const { data, isPending, isFetching, isPlaceholderData, error, refetch, dataUpdatedAt } = useQuery({
    queryKey: queryKeys.dashboard(days),
    queryFn: () => api.get<DashboardResponse>('/dashboard', new URLSearchParams({ range: range })),
    refetchInterval: 60_000,
    placeholderData: (previous) => previous,
  })

  const kpis = data?.kpis
  // The days of the figures on screen: the last range's while another one's are read.
  const shownDays = data?.range.days ?? days
  const periodLabel = `نسبت به ${formatNumber(shownDays)} روز قبل از آن`

  return (
    <Page width="dashboard">
      <PageHeader
        voice
        title={
          <>
            {greeting()}، <bdi>{session.name}</bdi>
          </>
        }
        description={
          <>
            خلاصه وضعیت فروشگاه در {formatNumber(shownDays)} روز گذشته
            {dataUpdatedAt > 0 && <span className="text-faint"> · به‌روزرسانی {timeAgo(dataUpdatedAt)}</span>}
          </>
        }
        actions={
          <>
            <FilterSelect label="بازه" value={range} options={RANGES} onChange={setRange} />
            <Tooltip>
              <TooltipTrigger asChild>
                <Button variant="secondary" size="icon" icon={RefreshCw} busy={isFetching} onClick={() => void refetch()} aria-label="به‌روزرسانی" />
              </TooltipTrigger>
              <TooltipContent side="bottom">به‌روزرسانی</TooltipContent>
            </Tooltip>
          </>
        }
      />

      {error && <ErrorState what="داشبورد" error={error} onRetry={() => void refetch()} retrying={isFetching} />}

      {/* The range's own figures: dimmed while another range's are read in their place. */}
      <div aria-busy={isPlaceholderData || undefined} className={cn('flex flex-col gap-6 transition-opacity duration-150', isPlaceholderData && 'opacity-60')}>
        <StatGrid label="شاخص‌های کلیدی">
          <StatCard
            label="درآمد"
            info="پولی که در این بازه واقعا وارد شد؛ خرید از کیف پول دوباره شمرده نمی‌شود، چون پولش موقع شارژ کیف پول شمرده شده است."
            value={kpis && formatAmount(kpis.revenue.value)}
            unit={MONEY_UNIT}
            change={kpis && percentChange(kpis.revenue.value, kpis.revenue.previous)}
            changeLabel={periodLabel}
            loading={isPending}
          />
          <StatCard
            label="سفارش‌ها"
            value={kpis && formatNumber(kpis.orders.value)}
            change={kpis && percentChange(kpis.orders.value, kpis.orders.previous)}
            changeLabel={periodLabel}
            loading={isPending}
          />
          <StatCard
            label="کاربران جدید"
            info={kpis ? `${formatNumber(kpis.users_total)} کاربر در کل` : undefined}
            value={kpis && formatNumber(kpis.new_users.value)}
            change={kpis && percentChange(kpis.new_users.value, kpis.new_users.previous)}
            changeLabel={periodLabel}
            loading={isPending}
          />
          <StatCard label="اشتراک‌های فعال" value={kpis && formatNumber(kpis.active_subscriptions)} hint={data && expiringHint(data.attention.expiring_soon, data.expiring_days)} loading={isPending} />
        </StatGrid>

        <OverviewChart series={data?.series} kpis={kpis} loading={isPending} />
      </div>

      <div className={cn('grid gap-3', aside && 'lg:grid-cols-2')}>
        <AttentionCard attention={data?.attention} trafficShortage={data?.traffic_shortage} trafficHelp={trafficHelp} expiringDays={data?.expiring_days} loading={isPending} />
        {aside}
      </div>

      <RecentOrders orders={data?.recent_orders} loading={isPending} />
      <RecentUsers users={data?.recent_users} loading={isPending} />
    </Page>
  )
}
