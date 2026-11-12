import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, Plus, RefreshCw, Wifi } from 'lucide-react'
import { Link } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { serviceTitle, statusOf, termLeft, trafficLeft, type Left } from '@/apps/app/parts/service'
import { tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Section } from '@/apps/app/ui/section'
import { Skeleton } from '@/apps/app/ui/states'
import { Badge, IconTile, Ring, RingValue } from '@/apps/app/ui/visuals'
import type { StoreSubscription, StoreSubscriptionsResponse } from '@/lib/api-types'

/*
 * The customer's running services on the home screen: glass cards in a row that snaps as it scrolls, each with its
 * name, its server and where it stands, and a ring for the traffic left and one for the days — a tap opens the service.
 * With none running, one card leads to buying the first (or, for a customer whose services all ended, to them).
 */

/**
 * The row's own card: 288px, so the next card shows at its edge on a phone and says the row scrolls; 192px high, the
 * height a service's card comes to, so the skeletons and the cards in its place hold the same room.
 */
const CARD = 'w-72 max-w-full shrink-0 snap-start rounded-card'
const HEIGHT = 'min-h-48'

/** Glass over the row's coloured light, with a hairline edge as Telegram's own floating panels have. */
const GLASS = 'glass border border-white/45 shadow-[0_12px_32px_-20px_rgb(0_0_0/0.45)] dark:border-white/10'

/**
 * What is left split for a ring: its figure and unit inside («۶۰» «گیگابایت»), the rest of the words under it — «حجم مانده»
 * for what is simply left, else what the words go on to say («از اولین اتصال»). Without a limit, «نامحدود» alone inside.
 */
function ringWords(left: Left, name: string): { unit?: string; label: string } {
  if (left.fill === null) return { label: left.unit }
  const at = left.unit.indexOf(' ')
  if (at === -1) return { unit: left.unit, label: name }
  const rest = left.unit.slice(at + 1)

  return { unit: left.unit.slice(0, at), label: rest === 'مانده' ? `${name} مانده` : rest }
}

/** One ring with what is left at its centre and the words of it under. */
function Gauge({ left, name }: { left: Left; name: string }) {
  const { unit, label } = ringWords(left, name)

  return (
    <div className="flex min-w-0 flex-col items-center gap-2" aria-label={`${name}: ${left.figure} ${left.unit}`}>
      <Ring value={left.fill} size={72} stroke={6} tone={left.tone}>
        <span aria-hidden>
          <RingValue value={left.figure} unit={unit} />
        </span>
      </Ring>
      <span aria-hidden className="text-hint max-w-full truncate text-caption">
        {label}
      </span>
    </div>
  )
}

function ServiceCard({ service }: { service: StoreSubscription }) {
  const status = statusOf(service)

  return (
    <Link to={`/services/${service.id}`} onClick={() => tap()} className={`press ${CARD} ${GLASS} flex flex-col gap-4 p-4`}>
      <span className="flex flex-col gap-0.5">
        <span className="flex items-center gap-2">
          <span className="min-w-0 flex-1 truncate text-body font-bold">{serviceTitle(service)}</span>
          <Badge tone={status.tone}>{status.label}</Badge>
        </span>
        <span className="text-hint truncate text-caption">{[service.server.name, service.plan?.name].filter(Boolean).join(' · ')}</span>
      </span>
      <span className="grid grid-cols-2 gap-3">
        <Gauge left={trafficLeft(service)} name="حجم" />
        <Gauge left={termLeft(service)} name="زمان" />
      </span>
    </Link>
  )
}

/** The card in place of services: buy the first one — or, with ended ones only, see them. */
function NoneRunning({ ended }: { ended: boolean }) {
  return (
    <Link to={ended ? '/services' : '/buy'} onClick={() => tap()} className={`press ${CARD} ${GLASS} ${HEIGHT} flex flex-col justify-between gap-4 p-4`}>
      <IconTile icon={ended ? Wifi : Plus} size="xl" className="shadow-[0_8px_20px_-10px_var(--app-accent)]" />
      <span className="flex flex-col gap-1">
        <span className="text-heading font-bold">{ended ? 'سرویس فعالی ندارید' : 'اولین سرویس خود را بخرید'}</span>
        <span className="text-hint flex items-center gap-1 text-footnote">
          {ended ? 'سرویس‌های تمام‌شده را تمدید کنید' : 'پلن‌های فروشگاه را ببینید'}
          <ChevronLeft aria-hidden className="size-4" />
        </span>
      </span>
    </Link>
  )
}

/** The card in place of services that could not be read: a word and the way to ask again. */
function Unread({ retry }: { retry: () => void }) {
  return (
    <div className={`${CARD} ${GLASS} ${HEIGHT} flex flex-col items-start justify-center gap-3 p-4`} role="alert">
      <span className="text-body">سرویس‌های شما خوانده نشد.</span>
      <Button variant="secondary" size="sm" icon={<RefreshCw />} onClick={retry}>
        تلاش دوباره
      </Button>
    </div>
  )
}

export function ServiceCarousel() {
  const running = useQuery({
    queryKey: [...keys.subscriptions, 'home', 'active'],
    queryFn: () => store.get<StoreSubscriptionsResponse>('/subscriptions', new URLSearchParams({ status: 'active' })),
  })
  const none = running.data?.subscriptions.length === 0
  // Only with none running: whether the customer has any at all, to word the empty card.
  const any = useQuery({ queryKey: [...keys.subscriptions, 'home', 'any'], queryFn: () => store.get<StoreSubscriptionsResponse>('/subscriptions'), enabled: none })

  const services = running.data?.subscriptions
  const total = running.data?.meta.total ?? 0

  return (
    <Section
      title="سرویس‌های فعال"
      bare
      action={
        total > 0 && (
          <Link to="/services" onClick={() => tap()} className="text-accent-text text-footnote font-medium">
            همه
          </Link>
        )
      }
    >
      <div className="relative isolate">
        {/* The light the glass is over: the shop's colour and a cooler one beside it, blurred. */}
        <span aria-hidden className="bg-accent absolute start-4 -top-4 -z-10 size-40 rounded-full opacity-30 blur-3xl" />
        <span aria-hidden className="absolute end-6 -bottom-2 -z-10 size-32 rounded-full bg-[#7c4dff] opacity-20 blur-3xl" />
        <div className="-mx-4 flex snap-x snap-mandatory scroll-px-4 [scrollbar-width:none] gap-3 overflow-x-auto px-4 pt-1 pb-3 [&::-webkit-scrollbar]:hidden">
          {running.isError ? (
            <Unread retry={() => void running.refetch()} />
          ) : services === undefined || (none && any.isPending) ? (
            <>
              <Skeleton className={`${CARD} h-48`} />
              <Skeleton className={`${CARD} h-48`} />
            </>
          ) : services.length === 0 ? (
            <NoneRunning ended={(any.data?.meta.total ?? 0) > 0} />
          ) : (
            services.map((service) => <ServiceCard key={service.id} service={service} />)
          )}
        </div>
      </div>
    </Section>
  )
}
