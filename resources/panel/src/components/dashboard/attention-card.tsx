import type { ReactNode } from 'react'
import { CheckCircle2, ChevronLeft, Clock, CreditCard, MessageSquare, ServerCrash, Star, TimerOff, XCircle, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router'
import { TrafficShortageNotice } from '@/components/agency/traffic-shortage'
import { EmptyState } from '@/components/empty-state'
import { Dash } from '@/components/list-view'
import { Badge } from '@/components/ui/badge'
import { Card, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { DashboardResponse, TrafficShortage } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { formatNumber } from '@/lib/format'
import { ORDER_STATUS, ORDER_STUCK, PAYMENT_STATUS, REVIEW_STATUS, TICKET_STATUS, type Tone } from '@/lib/statuses'
import { cn } from '@/lib/utils'

type Attention = DashboardResponse['attention']

/** Each queue in its list's words (the vocabularies of lib/statuses), linking to the list as the queue's tab shows it — the services ending soonest first. */
const ITEMS: { key: keyof Attention; label: string; icon: LucideIcon; to: string; tone: Tone }[] = [
  { key: 'payments_to_review', label: `پرداخت‌های ${PAYMENT_STATUS.awaiting_review.label}`, icon: CreditCard, to: '/payments?status=awaiting_review', tone: PAYMENT_STATUS.awaiting_review.tone },
  { key: 'stuck_orders', label: `سفارش‌های ${ORDER_STUCK.label}`, icon: XCircle, to: '/orders?status=stuck', tone: ORDER_STUCK.tone },
  { key: 'open_tickets', label: `تیکت‌های ${TICKET_STATUS.open.label}`, icon: MessageSquare, to: '/tickets?status=open', tone: TICKET_STATUS.open.tone },
  { key: 'pending_reviews', label: `نظرات ${REVIEW_STATUS.pending.label}`, icon: Star, to: '/reviews?status=pending', tone: REVIEW_STATUS.pending.tone },
  { key: 'servers_with_errors', label: 'سرورهای دارای خطا', icon: ServerCrash, to: '/servers', tone: 'danger' },
  { key: 'expiring_soon', label: 'اشتراک‌های رو به انقضا', icon: TimerOff, to: '/subscriptions?status=expiring&sort=expires&dir=asc', tone: 'neutral' },
  { key: 'pending_orders', label: `سفارش‌های ${ORDER_STATUS.pending.label}`, icon: Clock, to: '/orders?status=pending', tone: 'neutral' },
]

const ICON_TONE: Record<Tone, string> = { neutral: 'text-faint', info: 'text-info', success: 'text-success', warning: 'text-warning', danger: 'text-danger' }

interface AttentionCardProps {
  /** The queues' counts; undefined when they could not be read: the card says nothing of them, least of all "all clear". */
  attention: Attention | undefined
  /** An agent's shop whose traffic sells nothing, said first; null otherwise. */
  trafficShortage: TrafficShortage | null | undefined
  /** What to do about it, the panel's own way. */
  trafficHelp?: ReactNode
  /** The shop's "ending soon" window, in days. */
  expiringDays: number | undefined
  /** The counts' read has not answered yet. */
  loading?: boolean
}

/**
 * Queues that need a human: each row links straight to the filtered list — and, above them, an agent's bot that sells
 * nothing. Counts that could not be read are «—»: an empty queue is only ever said of counts that came in, and of what
 * the shop on screen has — the servers are the main bot's shop's to watch, an agent's has none.
 */
export function AttentionCard({ attention, trafficShortage, trafficHelp, expiringDays, loading }: AttentionCardProps) {
  const mainShop = useMainShop()
  const items = ITEMS.filter((item) => (attention?.[item.key] ?? 0) > 0)
  const labelOf = (item: (typeof ITEMS)[number]) => (item.key === 'expiring_soon' && expiringDays !== undefined ? `${item.label} (${formatNumber(expiringDays)} روز)` : item.label)
  const total = items.reduce((sum, item) => sum + (attention?.[item.key] ?? 0), trafficShortage ? 1 : 0)
  const unknown = !loading && attention === undefined

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>نیازمند توجه</CardTitle>
          <CardDescription>{loading ? 'در حال بررسی…' : unknown ? <Dash /> : total === 0 ? 'موردی برای پیگیری وجود ندارد.' : `${formatNumber(total)} مورد منتظر اقدام شماست`}</CardDescription>
        </CardHeading>
      </CardHeader>
      <div className="px-2 pb-2">
        {loading ? (
          <div className="grid gap-1 px-2 pb-2">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-9 w-full" />
            ))}
          </div>
        ) : unknown ? null : total === 0 ? (
          <EmptyState
            compact
            icon={CheckCircle2}
            title="همه‌چیز مرتب است"
            description={mainShop ? 'پرداخت‌ها، سفارش‌ها، تیکت‌ها، نظرات و سرورها در وضعیت عادی هستند.' : 'پرداخت‌ها، سفارش‌ها، تیکت‌ها و نظرات در وضعیت عادی هستند.'}
          />
        ) : (
          <ul className="grid gap-px">
            {trafficShortage && (
              <li className="pb-2">
                <TrafficShortageNotice shortage={trafficShortage} help={trafficHelp} />
              </li>
            )}
            {items.map((item) => (
              <li key={item.key}>
                <Link to={item.to} className="group flex h-10 items-center gap-3 rounded-lg px-2.5 text-body transition-colors duration-100 outline-none hover:bg-fill focus-visible:focus-ring">
                  <item.icon className={cn('size-4 shrink-0', ICON_TONE[item.tone])} strokeWidth={1.75} aria-hidden />
                  <span className="min-w-0 flex-1 truncate">{labelOf(item)}</span>
                  <Badge variant={item.tone} className="min-w-6 tabular">
                    {formatNumber(attention?.[item.key] ?? 0)}
                  </Badge>
                  <ChevronLeft className="size-4 text-faint transition-colors group-hover:text-foreground ltr:rotate-180" aria-hidden />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  )
}
