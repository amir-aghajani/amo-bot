import type { ComponentType, ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FlaskConical, MapPin, PackageX, SearchX, SlidersHorizontal } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import { PlanCard, termLabel } from '@/apps/app/screens/buy/parts/plan-card'
import { tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Chip } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { ACCENT_FILL, IconTile } from '@/apps/app/ui/visuals'
import type { NamedRef, StorePlan, StorePlansResponse } from '@/lib/api-types'
import { cn } from '@/lib/utils'

/*
 * «خرید» — what the shop sells this customer now (`GET /plans`, read signed in: their own prices), as the bot's
 * «خرید اشتراک» offers it: its categories as chips, and, while the plans differ in them, their terms and their locations
 * to narrow the list by. A plan's card opens its checkout (with the location picked here, if any). Above them the ways
 * in that are no plan: a service the customer builds, and a free test account, while the shop offers them. The filters
 * live in the address, so coming back from a plan finds them as they were.
 */

type Group = StorePlansResponse['groups'][number]

/** A group's key in the address: its category's id, or «other» for the plans of none. */
const groupKey = (group: Group): string => (group.category === null ? 'other' : String(group.category.id))

/** A group's chip and heading: its category's name; «سایر پلن‌ها», as the bot says, for the plans of none. */
const groupName = (group: Group): string => group.category?.name ?? 'سایر پلن‌ها'

/** The plans' terms, shortest first, a term that never ends last. */
function termsOf(plans: readonly StorePlan[]): number[] {
  const terms = [...new Set(plans.map((plan) => plan.duration_hours))]
  terms.sort((a, b) => (a === 0 ? 1 : b === 0 ? -1 : a - b))

  return terms
}

/** The locations the plans are sold on, each once, in the order they first come. */
function locationsOf(plans: readonly StorePlan[]): NamedRef[] {
  const seen = new Map<number, NamedRef>()
  for (const location of plans.flatMap((plan) => plan.locations)) {
    if (!seen.has(location.id)) seen.set(location.id, location)
  }

  return [...seen.values()]
}

export default function CatalogScreen() {
  const { data: shop } = useShop()
  const plans = useQuery({ queryKey: keys.plans, queryFn: () => store.get<StorePlansResponse>('/plans') })
  const [params, setParams] = useSearchParams()

  const selling = shop?.shop.taking_orders ?? true
  const builder = selling && (shop?.shop.custom_services ?? false)
  const tests = shop?.shop.programs.test_accounts ?? false

  /** Narrow the list by one filter (null: none). The address is replaced, not added to: back leaves the catalogue rather than undoing a filter. */
  const filter = (name: 'category' | 'term' | 'server', value: string | null) =>
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (value === null) next.delete(name)
        else next.set(name, value)
        // Another category has terms and locations of its own.
        if (name === 'category') {
          next.delete('term')
          next.delete('server')
        }
        return next
      },
      { replace: true },
    )

  const groups = (plans.data?.groups ?? []).filter((group) => group.plans.length > 0)
  const category = groups.some((group) => groupKey(group) === params.get('category')) ? params.get('category') : null
  const inCategory = category === null ? groups : groups.filter((group) => groupKey(group) === category)
  const categoryPlans = inCategory.flatMap((group) => group.plans)
  const terms = termsOf(categoryPlans)
  const locations = locationsOf(categoryPlans)
  const term = terms.find((hours) => String(hours) === params.get('term')) ?? null
  const server = locations.find((location) => String(location.id) === params.get('server')) ?? null
  const shown = inCategory
    .map((group) => ({
      ...group,
      plans: group.plans.filter((plan) => (term === null || plan.duration_hours === term) && (server === null || plan.locations.some((location) => location.id === server.id))),
    }))
    .filter((group) => group.plans.length > 0)

  return (
    <Screen title="خرید سرویس" bottom="tabs">
      {!selling && <Notice tone="warning">فعلا خرید ممکن نیست؛ کمی بعد دوباره سر بزنید. سرویس‌های فعلی شما مثل قبل کار می‌کنند.</Notice>}

      {(builder || tests) && (
        <div className={cn('grid gap-3', builder && tests && 'grid-cols-2')}>
          {builder && <Entry to="/buy/custom" icon={SlidersHorizontal} tint={ACCENT_FILL} title="سرویس دلخواه" text="حجم و مدت را خودتان انتخاب کنید" />}
          {tests && <Entry to="/test" icon={FlaskConical} tint="var(--app-success)" title="اکانت تست رایگان" text="پیش از خرید، سرویس را امتحان کنید" />}
        </div>
      )}

      {plans.isPending ? (
        <CatalogSkeleton />
      ) : plans.isError ? (
        <ErrorView error={plans.error} retry={() => void plans.refetch()} />
      ) : groups.length === 0 ? (
        <Empty icon={PackageX} title="فعلا پلنی برای فروش نیست" text="کمی بعد دوباره سر بزنید." />
      ) : (
        <>
          {/* The filters, only while there is one to choose: an empty block would still take the screen's gap. */}
          {(groups.length > 1 || terms.length > 1 || locations.length > 1) && (
            <div className="flex flex-col gap-2">
              {groups.length > 1 && (
                <ChipRow label="دسته‌بندی">
                  <Chip active={category === null} onClick={() => filter('category', null)}>
                    همه
                  </Chip>
                  {groups.map((group) => (
                    <Chip key={groupKey(group)} active={category === groupKey(group)} onClick={() => filter('category', groupKey(group))}>
                      {groupName(group)}
                    </Chip>
                  ))}
                </ChipRow>
              )}
              {terms.length > 1 && (
                <ChipRow label="مدت">
                  <Chip active={term === null} onClick={() => filter('term', null)}>
                    همه مدت‌ها
                  </Chip>
                  {terms.map((hours) => (
                    <Chip key={hours} active={term === hours} onClick={() => filter('term', String(hours))}>
                      {termLabel(hours)}
                    </Chip>
                  ))}
                </ChipRow>
              )}
              {locations.length > 1 && (
                <ChipRow label="لوکیشن">
                  <Chip active={server === null} onClick={() => filter('server', null)}>
                    همه لوکیشن‌ها
                  </Chip>
                  {locations.map((location) => (
                    <Chip key={location.id} active={server?.id === location.id} onClick={() => filter('server', String(location.id))}>
                      <span className="inline-flex items-center gap-1">
                        <MapPin aria-hidden className="size-3.5 shrink-0" />
                        {location.name}
                      </span>
                    </Chip>
                  ))}
                </ChipRow>
              )}
            </div>
          )}

          {shown.length === 0 ? (
            <Empty
              icon={SearchX}
              title="پلنی با این انتخاب نیست"
              text="مدت یا لوکیشن دیگری انتخاب کنید."
              action={
                <Button variant="secondary" size="sm" onClick={() => setParams(category === null ? {} : { category }, { replace: true })}>
                  نمایش همه
                </Button>
              }
            />
          ) : (
            shown.map((group) => (
              <section key={groupKey(group)} className="flex flex-col gap-3" aria-label={groupName(group)}>
                {shown.length > 1 && <h2 className="text-section-header px-4 text-footnote font-semibold">{groupName(group)}</h2>}
                {group.plans.map((plan) => (
                  <PlanCard key={plan.id} plan={plan} to={selling ? `/buy/plans/${plan.id}` : undefined} state={server === null ? undefined : { serverId: server.id }} />
                ))}
              </section>
            ))
          )}
        </>
      )}
    </Screen>
  )
}

/** A row of chips that scrolls sideways, edge to edge. */
function ChipRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div role="group" aria-label={label} className="-mx-4 flex [scrollbar-width:none] gap-2 overflow-x-auto px-4 py-1 [&::-webkit-scrollbar]:hidden">
      {children}
    </div>
  )
}

interface EntryProps {
  to: string
  icon: ComponentType<{ className?: string }>
  /** Its icon's colour: the shop's (ACCENT_FILL) or one of the app's tints. */
  tint: string
  title: string
  text: string
}

/** A way in that is no plan: a tile above the plans, its icon in a soft circle of its colour. */
function Entry({ to, icon, tint, title, text }: EntryProps) {
  return (
    <Link to={to} onClick={() => tap()} className="card press animate-rise flex flex-col items-start gap-3 p-4">
      <IconTile icon={icon} size="lg" color={tint} look="soft" round />
      <span className="flex flex-col gap-0.5">
        <span className="text-body font-bold">{title}</span>
        <span className="text-hint text-caption">{text}</span>
      </span>
    </Link>
  )
}

/** The catalogue while it loads: a row of chips and a few cards. */
function CatalogSkeleton() {
  return (
    <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
      <div className="flex gap-2 py-1">
        {['w-14', 'w-20', 'w-16'].map((width) => (
          <Skeleton key={width} className={cn('h-9 rounded-full', width)} />
        ))}
      </div>
      {[0, 1, 2].map((index) => (
        <Skeleton key={index} className="h-44" />
      ))}
    </div>
  )
}
