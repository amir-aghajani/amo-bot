import type { ComponentType, ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BadgePercent, ChevronLeft, FerrisWheel, Megaphone, Ticket, UsersRound } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { useCarousel } from '@/apps/app/screens/home/parts/carousel'
import { selection, tap } from '@/apps/app/telegram/haptics'
import { openAddress } from '@/apps/app/telegram/webapp'
import { Skeleton } from '@/apps/app/ui/states'
import { Countdown, IconTile } from '@/apps/app/ui/visuals'
import type { StoreResponse, StoreSale, StoreSalesResponse } from '@/lib/api-types'
import { formatNumber, formatPercent } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * The home screen's banners, one at a time in a row that moves on by itself (parts/carousel): the shop's word to every
 * customer, the timed sales running now with the time each has left, and the wheel, the nightly draw and invitations
 * while the shop runs them. A tap leads to what the banner speaks of; under the row, a dot for each, 24px apart and
 * tapped over 44px.
 */

interface Banner {
  key: string
  icon: ComponentType<{ className?: string }>
  /** Its gradient's two colours. */
  colors: [string, string]
  /** The colour of its words and icon: white on the app's own gradients; the shop's colour's own ink on one of it. */
  ink?: string
  /** A small line over the title. */
  kicker: string
  title: ReactNode
  text?: ReactNode
  /** Where a tap leads: a screen of the app, or an address (a t.me one opens inside Telegram, any other web one in its browser). */
  to?: string
  href?: string
}

/** What a sale is on, in words: «روی همه خریدها», «روی خرید و تمدید». */
function saleScope(sale: StoreSale): string {
  if (sale.order_types === null) return 'روی همه خریدها'
  const words = new Set(sale.order_types.map((type) => (type === 'purchase' ? 'خرید سرویس' : type === 'renewal' ? 'تمدید' : 'حجم و زمان اضافه')))

  return `روی ${[...words].join(' و ')}`
}

function saleBanner(sale: StoreSale): Banner {
  const buys = sale.order_types === null || sale.order_types.includes('purchase')

  return {
    key: `sale-${sale.id}`,
    icon: BadgePercent,
    colors: ['#ff7a18', '#e8287a'],
    kicker: sale.name,
    title: `${formatPercent(sale.percent)} تخفیف ${saleScope(sale)}`,
    text: sale.ends_at !== null && (
      <span className="inline-flex items-center gap-1">
        پایان تخفیف: <Countdown to={sale.ends_at} done="تمام شد" />
      </span>
    ),
    to: buys ? '/buy' : '/services',
  }
}

/** The banners of what the shop runs, in order: its word, its sales, then its programs. */
function bannersOf(shop: StoreResponse, sales: StoreSale[]): Banner[] {
  const { programs } = shop.shop
  const announcement = shop.app?.announcement ?? null
  const banners: Banner[] = []

  if (announcement !== null) {
    banners.push({
      key: 'announcement',
      icon: Megaphone,
      colors: ['color-mix(in srgb, var(--app-accent) 82%, white)', 'color-mix(in srgb, var(--app-accent) 70%, black)'],
      ink: 'var(--app-on-accent)',
      kicker: 'اطلاعیه فروشگاه',
      title: <span className="line-clamp-3 text-body font-semibold whitespace-pre-line">{announcement.text}</span>,
      ...(announcement.url === null ? {} : { href: announcement.url }),
    })
  }
  banners.push(...sales.map(saleBanner))
  if (programs.wheel) {
    banners.push({ key: 'wheel', icon: FerrisWheel, colors: ['#7c4dff', '#d63ad8'], kicker: 'گردونه شانس', title: 'گردونه را بچرخانید و جایزه ببرید', to: '/club/wheel' })
  }
  if (programs.lottery) {
    banners.push({ key: 'lottery', icon: Ticket, colors: ['#f7a400', '#f0590e'], kicker: 'قرعه‌کشی شبانه', title: 'هر شب بین خریداران فروشگاه قرعه‌کشی می‌شود', to: '/club/lottery' })
  }
  if (shop.referral.enabled) {
    const share = formatPercent(shop.referral.rate)
    banners.push({
      key: 'referral',
      icon: UsersRound,
      colors: ['#12b886', '#0b8bb0'],
      kicker: 'دعوت از دوستان',
      title: shop.referral.first_only ? `${share} از اولین پرداخت هر دوست، برای شما` : `${share} از پرداخت‌های دوستانتان، برای شما`,
      text: 'لینک دعوت خود را بفرستید',
      to: '/club/referral',
    })
  }

  return banners
}

function Slide({ banner, onOpen }: { banner: Banner; onOpen: () => void }) {
  const Icon = banner.icon
  const leads = banner.to !== undefined || banner.href !== undefined

  const face = (
    <>
      <Icon aria-hidden className="absolute -end-4 -bottom-5 -z-10 size-32 -rotate-12 opacity-15" />
      <span aria-hidden className="absolute -start-10 -top-14 -z-10 size-36 rounded-full bg-white/20 blur-2xl" />
      <span className="flex items-center gap-2 text-caption font-semibold">
        <IconTile icon={Icon} size="sm" color={banner.ink ?? '#ffffff'} look="soft" round />
        <span className="truncate opacity-90">{banner.kicker}</span>
      </span>
      <span className="flex flex-col gap-1">
        <span className="text-heading font-bold">{banner.title}</span>
        {(banner.text || leads) && (
          <span className="flex items-center gap-1 text-caption font-medium opacity-90">
            {banner.text}
            {leads && <ChevronLeft aria-hidden className="size-3.5" />}
          </span>
        )}
      </span>
    </>
  )
  // At least 144px; a slide with more words grows, and every slide of the row with it (the row stretches them alike).
  const frame = 'rounded-card relative isolate flex min-h-36 w-full shrink-0 snap-center flex-col justify-between gap-3 overflow-hidden p-5 text-start'
  const paint = { background: `linear-gradient(125deg, ${banner.colors[0]}, ${banner.colors[1]})`, color: banner.ink ?? '#ffffff' }

  return leads ? (
    <button type="button" onClick={onOpen} className={frame} style={paint}>
      {face}
    </button>
  ) : (
    <div className={frame} style={paint}>
      {face}
    </div>
  )
}

export function Banners({ shop }: { shop: StoreResponse }) {
  const navigate = useNavigate()
  const sales = useQuery({ queryKey: keys.sales, queryFn: () => store.get<StoreSalesResponse>('/sales') })
  const banners = sales.isPending ? [] : bannersOf(shop, sales.data?.sales ?? [])
  const { track, index, go, handlers, region } = useCarousel(banners.length)

  if (sales.isPending) return <Skeleton className="h-36" />
  if (banners.length === 0) return null

  const open = (banner: Banner) => {
    tap()
    if (banner.href !== undefined) openAddress(banner.href)
    else if (banner.to !== undefined) navigate(banner.to)
  }

  return (
    <section aria-label="بنرها" aria-roledescription="carousel" {...region} className="animate-rise flex flex-col gap-2">
      <div ref={track} {...handlers} className="rounded-card flex snap-x snap-mandatory [scrollbar-width:none] gap-3 overflow-x-auto [&::-webkit-scrollbar]:hidden">
        {banners.map((banner) => (
          <Slide key={banner.key} banner={banner} onOpen={() => open(banner)} />
        ))}
      </div>
      {banners.length > 1 && (
        <div className="flex h-4 items-center justify-center">
          {banners.map((banner, at) => (
            <button
              key={banner.key}
              type="button"
              aria-label={`بنر ${formatNumber(at + 1)}`}
              aria-current={at === index}
              onClick={() => {
                selection()
                go(at)
              }}
              className="tap-row relative flex h-4 items-center px-[9px]"
            >
              <span className={cn('block h-1.5 rounded-full transition-[width,background-color] duration-300', at === index ? 'bg-accent w-5' : 'bg-fill-strong w-1.5')} />
            </button>
          ))}
        </div>
      )}
    </section>
  )
}
