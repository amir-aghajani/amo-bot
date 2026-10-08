import { useMemo, useState } from 'react'
import { AreaChart, type AreaPoint } from '@/components/dashboard/area-chart'
import { Dash } from '@/components/list-view'
import { PageTabs } from '@/components/page-tabs'
import { TrendChip } from '@/components/trend-chip'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { DashboardResponse, SeriesPoint } from '@/lib/api-types'
import { formatCompact, formatDay, formatMoney, formatNumber, percentChange } from '@/lib/format'

type Metric = 'revenue' | 'orders' | 'users'

/** One metric at a time: a single axis is always readable, a dual-axis chart never is. */
const METRICS: Record<Metric, { label: string; description: string }> = {
  revenue: { label: 'درآمد', description: 'پولی که هر روز واقعا وارد شد — بدون خرید از کیف پول' },
  orders: { label: 'سفارش‌ها', description: 'تعداد سفارش‌های ثبت‌شده در هر روز' },
  users: { label: 'کاربران جدید', description: 'تعداد ثبت‌نام‌ها در هر روز' },
}

const METRIC_OPTIONS = (Object.keys(METRICS) as Metric[]).map((key) => ({ value: key, label: METRICS[key].label }))

interface OverviewChartProps {
  /** The days of the period; undefined when there are none to draw (their read failed): «—». */
  series: SeriesPoint[] | undefined
  kpis: DashboardResponse['kpis'] | undefined
  /** The period's read has not answered yet. */
  loading?: boolean
}

/**
 * The period at a glance (the Console's usage card): the metric's name and what it counts, the period's total with
 * its change against the previous one, the daily curve, and the period's shape — average, best day — at its foot; a
 * skeleton while it is read, «—» when there is nothing to draw.
 */
export function OverviewChart({ series, kpis, loading }: OverviewChartProps) {
  const [metric, setMetric] = useState<Metric>('revenue')
  const meta = METRICS[metric]

  const data = useMemo(() => (series ?? []).map((point) => ({ date: point.date, value: Number(point[metric]) })), [series, metric])

  const stats = useMemo(() => {
    const values = data.map((point) => point.value)
    const total = values.reduce((sum, value) => sum + value, 0)
    const peakIndex = values.reduce((best, value, index) => (value > values[best]! ? index : best), 0)
    return {
      total,
      average: values.length ? total / values.length : 0,
      peak: data[peakIndex],
      empty: values.every((value) => value === 0),
    }
  }, [data])

  const previous = kpis ? Number(metric === 'revenue' ? kpis.revenue.previous : metric === 'orders' ? kpis.orders.previous : kpis.new_users.previous) : null
  const change = previous === null ? null : percentChange(stats.total, previous)

  const formatValue = (value: number) => (metric === 'revenue' ? formatMoney(value) : formatNumber(Math.round(value)))

  // A day under the hand: its date in full, and the metric's figure in its unit (the axis carries the figure alone).
  const tooltip = (point: AreaPoint) => ({
    title: formatDay(point.date, { weekday: 'long', day: 'numeric', month: 'long' }),
    body: (
      <div className="flex items-center justify-between gap-4">
        <span className="text-muted-foreground">{meta.label}</span>
        <span className="font-medium tabular">{formatValue(point.value)}</span>
      </div>
    ),
  })

  return (
    <Card>
      <div className="flex flex-col gap-4 px-4 pt-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="grid gap-3">
          <div className="grid gap-0.5">
            <h2 className="text-body font-medium">{meta.label}</h2>
            <p className="text-footnote text-muted-foreground">{meta.description}</p>
          </div>
          <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            {loading ? (
              <Skeleton className="h-9 w-40" />
            ) : !series ? (
              <span className="text-stat font-medium text-faint">—</span>
            ) : (
              <>
                <span className="text-stat font-medium tracking-tight tabular">{formatValue(stats.total)}</span>
                <TrendChip change={change} />
                <span className="text-footnote text-muted-foreground">نسبت به دوره قبل</span>
              </>
            )}
          </div>
        </div>
        <PageTabs as="choice" value={metric} onChange={setMetric} tabs={METRIC_OPTIONS} aria-label="شاخص" size="sm" className="w-fit" />
      </div>

      <div className="px-2 pt-4 pb-1 sm:px-3">
        {loading ? (
          <Skeleton className="mx-2 h-60" />
        ) : !series ? (
          <div className="grid h-60 place-items-center">
            <Dash />
          </div>
        ) : (
          <div className="relative">
            {stats.empty && <p className="absolute inset-x-0 top-1/2 z-10 -translate-y-1/2 text-center text-body text-muted-foreground">هنوز داده‌ای برای این بازه ثبت نشده است.</p>}
            <AreaChart data={data} label={meta.label} formatTick={formatDay} formatValue={formatCompact} tooltip={tooltip} />
          </div>
        )}
      </div>

      <div className="flex min-h-11 flex-wrap items-center gap-x-5 gap-y-1 border-t border-border px-4 py-3 text-footnote text-muted-foreground">
        {loading ? (
          <Skeleton className="h-4 w-56" />
        ) : !series ? null : (
          <>
            <span>
              میانگین روزانه <span className="font-medium text-foreground tabular">{formatValue(stats.average)}</span>
            </span>
            {stats.peak && !stats.empty && (
              <span>
                بیشترین: <span className="font-medium text-foreground">{formatDay(stats.peak.date)}</span> با{' '}
                <span className="font-medium text-foreground tabular">{formatValue(stats.peak.value)}</span>
              </span>
            )}
            <span className="ms-auto tabular">{formatNumber(data.length)} روز</span>
          </>
        )}
      </div>
    </Card>
  )
}
