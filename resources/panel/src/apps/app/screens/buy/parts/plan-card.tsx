import { useState, type CSSProperties } from 'react'
import { BadgePercent, CalendarClock, ChevronLeft, Crown, Flame, Gauge, ThumbsUp, Timer, Users, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router'
import { tap } from '@/apps/app/telegram/haptics'
import { Badge, Countdown, Price, type Tone } from '@/apps/app/ui/visuals'
import type { PlanBadge, StorePlan, StorePlanDiscount } from '@/lib/api-types'
import { formatDevices, formatDuration, formatGb, formatNumber, UNLIMITED } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * A plan as the shop sells it: its card in the catalogue — the label the shop gave it, what it gives, its price for this
 * customer with what it was before struck through, and a discount's words with the time left while a sale ends soon —,
 * and the same pieces the checkout shows again. Its pills are all one height: a badge's (24px, a 14px icon).
 */

/** Each label a plan's card may wear, in a colour of its own; a VIP plan's gold is no tone (a pill of its own). */
const BADGES: Record<PlanBadge, { label: string; icon: LucideIcon; color: string; tone: Tone | null }> = {
  bestseller: { label: 'پرفروش‌ترین', icon: Flame, color: 'var(--app-danger)', tone: 'danger' },
  recommended: { label: 'پیشنهاد ما', icon: ThumbsUp, color: 'var(--app-success)', tone: 'success' },
  vip: { label: 'VIP', icon: Crown, color: '#d4a017', tone: null },
}

/** The gold of a VIP plan's label. */
const GOLD: CSSProperties = { background: 'linear-gradient(135deg, #fbe7a1, #d9a520)', color: '#4a3200' }

/** A plan's label: «پرفروش‌ترین», «پیشنهاد ما» or «VIP» — a badge, the gold one drawn as a badge is. */
function PlanBadgeTag({ badge }: { badge: PlanBadge }) {
  const { label, icon: Icon, tone } = BADGES[badge]
  const content = (
    <>
      <Icon aria-hidden />
      {label}
    </>
  )

  if (tone !== null) return <Badge tone={tone}>{content}</Badge>

  return (
    <span className="inline-flex h-6 shrink-0 items-center gap-1 rounded-full px-2.5 text-caption leading-none font-semibold whitespace-nowrap [&_svg]:size-3.5" style={GOLD}>
      {content}
    </span>
  )
}

interface PlanHeadingProps {
  plan: Pick<StorePlan, 'name' | 'description' | 'badge'>
  as?: 'h2' | 'h3'
  /** Its note in full (the checkout's), not cut at two lines (the catalogue's). */
  full?: boolean
}

/** A plan's name and its note, its label at the end of the name's first line (centred on it, however the name wraps). */
export function PlanHeading({ plan, as: Heading = 'h3', full = false }: PlanHeadingProps) {
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-start justify-between gap-3">
        <Heading className="min-w-0 text-heading font-bold">{plan.name}</Heading>
        {plan.badge !== null && (
          <span className="flex h-6.5 shrink-0 items-center">
            <PlanBadgeTag badge={plan.badge} />
          </span>
        )}
      </div>
      {plan.description !== null && <p className={cn('text-hint text-footnote', !full && 'line-clamp-2')}>{plan.description}</p>}
    </div>
  )
}

const HOURS_IN_DAY = 24
const HOURS_IN_MONTH = 720
const HOURS_IN_YEAR = 8760

/** A term as a filter names it: «۱ ماهه», «۳ ماهه», «۷ روزه», «۱ ساله», «۶ ساعته»; 0 is «نامحدود». */
export function termLabel(hours: number): string {
  if (hours <= 0) return UNLIMITED
  if (hours % HOURS_IN_YEAR === 0) return `${formatNumber(hours / HOURS_IN_YEAR)} ساله`
  if (hours % HOURS_IN_MONTH === 0) return `${formatNumber(hours / HOURS_IN_MONTH)} ماهه`
  if (hours % HOURS_IN_DAY === 0) return `${formatNumber(hours / HOURS_IN_DAY)} روزه`
  return `${formatNumber(hours)} ساعته`
}

/** What a plan gives, as pills: its traffic, its term and its devices at once. */
export function PlanFacts({ plan }: { plan: Pick<StorePlan, 'traffic_gb' | 'duration_hours' | 'devices'> }) {
  const facts = [
    { icon: Gauge, text: plan.traffic_gb > 0 ? formatGb(plan.traffic_gb) : 'حجم نامحدود' },
    { icon: CalendarClock, text: plan.duration_hours > 0 ? formatDuration(plan.duration_hours) : 'زمان نامحدود' },
    { icon: Users, text: plan.devices > 0 ? formatDevices(plan.devices) : 'کاربر نامحدود' },
  ]

  return (
    <ul className="flex flex-wrap gap-2">
      {facts.map(({ icon: Icon, text }) => (
        <li key={text} className="inline-flex h-6 items-center gap-1 rounded-full bg-fill px-2.5 text-caption leading-none font-medium whitespace-nowrap">
          <Icon aria-hidden className="text-hint size-3.5 shrink-0" />
          {text}
        </li>
      ))}
    </ul>
  )
}

/** Within this long of its end a sale shows the time it has left. */
const ENDS_SOON_MS = 3 * 86_400_000

/** The automatic discount a price took — its words —, and the time left while a sale ends within a few days. */
export function PlanDiscount({ discount }: { discount: StorePlanDiscount }) {
  const ends = discount.ends_at
  const [shownAt] = useState(() => Date.now())
  const soon = ends !== null && new Date(ends).getTime() - shownAt < ENDS_SOON_MS

  return (
    <span className="flex flex-wrap items-center gap-2">
      <Badge tone="success">
        <BadgePercent aria-hidden />
        {discount.label}
      </Badge>
      {soon && (
        <Badge tone="warning">
          <Timer aria-hidden />
          <Countdown to={ends} done="تمام شد" />
        </Badge>
      )}
    </span>
  )
}

/** The reader's own price below the plan's `price`, when they have one: a first purchase's or an agent's, the lower. */
export function ownPrice(plan: StorePlan): { label: string; amount: string } | null {
  const own = [
    { label: 'قیمت خرید اول', amount: plan.first_purchase_price },
    { label: 'قیمت نماینده', amount: plan.agent_price },
  ].filter((price): price is { label: string; amount: string } => price.amount !== null && Number(price.amount) < Number(plan.price))
  own.sort((a, b) => Number(a.amount) - Number(b.amount))

  return own[0] ?? null
}

/** What the plan cost before the reader's own price and discount, while they made it less. */
function priceBefore(plan: StorePlan): string | null {
  return Number(plan.offer_price) < Number(plan.price) ? plan.price : null
}

/** A labelled plan's card: a hairline of its label's colour over the card's own lift. */
function badgeRing(badge: PlanBadge | null): CSSProperties | undefined {
  if (badge === null) return undefined

  return { boxShadow: `var(--app-elevation), inset 0 0 0 1px color-mix(in srgb, ${BADGES[badge].color} 50%, transparent)` }
}

interface PlanCardProps {
  plan: StorePlan
  /** Its checkout's address; absent while nothing can be bought (the card is shown, not opened). */
  to?: string
  /** What the checkout is opened with (the location the catalogue was filtered by). */
  state?: unknown
}

/** A plan in the catalogue: a card that opens its checkout — its price and «خرید» on one centre line at its foot. */
export function PlanCard({ plan, to, state }: PlanCardProps) {
  const own = ownPrice(plan)
  const body = (
    <>
      <PlanHeading plan={plan} />
      <div className="flex flex-col gap-2">
        <PlanFacts plan={plan} />
        {(plan.discount !== null || own !== null) && (
          <div className="flex flex-wrap items-center gap-2">
            {own !== null && <Badge tone="accent">{own.label}</Badge>}
            {plan.discount !== null && <PlanDiscount discount={plan.discount} />}
          </div>
        )}
      </div>
      <div className="flex items-center justify-between gap-3">
        <Price amount={plan.offer_price} before={priceBefore(plan)} className="min-w-0 text-title" />
        {to !== undefined && (
          <span className="bg-accent text-on-accent inline-flex h-9 shrink-0 items-center gap-0.5 rounded-full ps-4 pe-3 text-footnote leading-none font-semibold">
            خرید
            <ChevronLeft aria-hidden className="size-4" />
          </span>
        )}
      </div>
    </>
  )
  const card = 'card flex flex-col gap-4 p-4'

  if (to === undefined) {
    return (
      <article className={cn(card, 'opacity-70')} style={badgeRing(plan.badge)}>
        {body}
      </article>
    )
  }

  return (
    <Link to={to} state={state} onClick={() => tap()} className={cn(card, 'press animate-rise')} style={badgeRing(plan.badge)}>
      {body}
    </Link>
  )
}
