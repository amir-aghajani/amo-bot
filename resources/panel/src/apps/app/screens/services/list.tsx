import { useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { ChevronLeft, Link2, MapPin, ShoppingBag, Wifi } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { problemOf, store } from '@/apps/app/lib/store'
import { serviceTitle, statusOf } from '@/apps/app/parts/service'
import { ServiceMiniRings } from '@/apps/app/screens/services/parts/rings'
import { tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Segmented } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { Badge } from '@/apps/app/ui/visuals'
import type { StoreSubscription, StoreSubscriptionsResponse } from '@/lib/api-types'

/*
 * «سرویس‌ها», a tab: the customer's services, newest first (`GET /subscriptions`, a page at a time), each a card with
 * its name, where it stands and what is left of its traffic and term, opening its own page. Above them, adding a
 * service by its link while the shop takes them; with none yet, the way to buy one.
 */

type Filter = 'all' | 'active' | 'expired'

const FILTERS: readonly { value: Filter; label: string }[] = [
  { value: 'all', label: 'همه' },
  { value: 'active', label: 'فعال' },
  { value: 'expired', label: 'منقضی‌شده' },
]

/** The services' pages, kept apart from a service's own read (keys.subscription) under the same prefix. */
const pagesKey = (filter: Filter) => [...keys.subscriptions, 'pages', filter] as const

export default function ServicesScreen() {
  const navigate = useNavigate()
  const { data: shop } = useShop()
  const [filter, setFilter] = useState<Filter>('all')
  const claims = shop?.shop.service_claims ?? false
  const list = useInfiniteQuery({
    queryKey: pagesKey(filter),
    queryFn: ({ pageParam }) => store.get<StoreSubscriptionsResponse>('/subscriptions', new URLSearchParams({ page: String(pageParam), ...(filter === 'all' ? {} : { status: filter }) })),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  })
  const services = list.data?.pages.flatMap((page) => page.subscriptions) ?? []
  const none = list.data !== undefined && services.length === 0

  return (
    <Screen
      title="سرویس‌های من"
      bottom="tabs"
      actions={
        claims && (
          <Button variant="secondary" size="sm" icon={<Link2 />} onClick={() => navigate('/services/claim')}>
            افزودن با لینک
          </Button>
        )
      }
    >
      {(filter !== 'all' || services.length > 0) && <Segmented value={filter} options={FILTERS} onChange={setFilter} />}

      {list.data === undefined ? (
        list.isError ? (
          <ErrorView error={list.error} retry={() => void list.refetch()} />
        ) : (
          <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
            {[0, 1, 2].map((index) => (
              <Skeleton key={index} className="h-58.5" />
            ))}
          </div>
        )
      ) : none && filter !== 'all' ? (
        <Empty icon={Wifi} title={filter === 'active' ? 'سرویس فعالی ندارید' : 'سرویس منقضی‌شده‌ای ندارید'} />
      ) : none ? (
        <Empty
          icon={Wifi}
          title="هنوز سرویسی ندارید"
          text="یک سرویس بخرید تا لینک اتصالش اینجا باشد."
          action={
            <div className="flex flex-col items-center gap-2">
              <Button icon={<ShoppingBag />} onClick={() => navigate('/buy')}>
                خرید سرویس
              </Button>
              {claims && (
                <Button variant="plain" size="sm" onClick={() => navigate('/services/claim')}>
                  افزودن سرویس با لینک
                </Button>
              )}
            </div>
          }
        />
      ) : (
        <div className="flex flex-col gap-3">
          {services.map((service, index) => (
            <ServiceCard key={service.id} service={service} delay={index < 6 ? index * 40 : 0} />
          ))}
          {list.isFetchNextPageError && <Notice tone="danger">{problemOf(list.error)}</Notice>}
          {list.hasNextPage && (
            <Button variant="secondary" block busy={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
              سرویس‌های بیشتر
            </Button>
          )}
        </div>
      )}
    </Screen>
  )
}

/**
 * A service's card: its name and client name, its state, a ring for each of its traffic and term, and where it is — the
 * title, the first ring and the server's pin on one start edge.
 */
function ServiceCard({ service, delay }: { service: StoreSubscription; delay: number }) {
  const status = statusOf(service)
  const labelled = service.label !== null && service.label !== ''

  return (
    <Link to={`/services/${service.id}`} onClick={() => tap()} className="card press animate-rise flex flex-col gap-4 p-4" style={{ animationDelay: `${delay}ms`, animationFillMode: 'backwards' }}>
      <span className="flex items-center gap-3">
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="truncate text-heading font-bold">{serviceTitle(service)}</span>
          <span className="text-hint truncate text-footnote">{labelled ? <span className="ltr">{service.name}</span> : (service.plan?.name ?? (service.is_test ? 'اکانت تست' : 'بدون پلن'))}</span>
        </span>
        <Badge tone={status.tone}>{status.label}</Badge>
        <ChevronLeft aria-hidden className="text-hint size-4.5 shrink-0 opacity-50" />
      </span>

      <ServiceMiniRings service={service} />

      <span className="text-hint flex min-h-6 items-center gap-1.5 text-footnote">
        <MapPin aria-hidden className="size-3.5 shrink-0" />
        <span className="truncate">{service.server.name}</span>
        {service.is_test && (
          <Badge tone="warning" className="ms-auto">
            تست
          </Badge>
        )}
      </span>
    </Link>
  )
}
