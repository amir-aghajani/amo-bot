import { useState } from 'react'
import type { CSSProperties } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { Check, ChevronLeft, CircleSlash, Wifi } from 'lucide-react'
import { keys } from '@/apps/app/lib/query'
import { problemOf, store } from '@/apps/app/lib/store'
import { serviceTitle } from '@/apps/app/parts/service'
import { selection, tap } from '@/apps/app/telegram/haptics'
import { Button } from '@/apps/app/ui/button'
import { Sheet } from '@/apps/app/ui/sheet'
import { ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import type { NamedRef, StoreSubscriptionsResponse } from '@/lib/api-types'
import { SUBSCRIPTION_STATUS } from '@/lib/statuses'
import { cn } from '@/lib/utils'

/*
 * Which of the customer's services a new ticket is about: a row that shows the one picked (or none), and a sheet of their
 * services (`GET /subscriptions`, any state, a page at a time) to pick another or none.
 */

/** The services' pages the picker reads, under the services' prefix so a change to them reads these again. */
const pickerPages = [...keys.subscriptions, 'support-picker'] as const

interface ServicePickerProps {
  value: NamedRef | null
  onChange: (service: NamedRef | null) => void
  /** The service picked already is still being read: none is shown yet. */
  pending?: boolean
  /** The id field's refusal. */
  error?: string
}

export function ServicePicker({ value, onChange, pending = false, error }: ServicePickerProps) {
  const [open, setOpen] = useState(false)
  const services = useInfiniteQuery({
    queryKey: pickerPages,
    queryFn: ({ pageParam }) => store.get<StoreSubscriptionsResponse>('/subscriptions', new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
    enabled: open,
  })
  const rows = services.data?.pages.flatMap((page) => page.subscriptions) ?? []
  const choose = (service: NamedRef | null) => {
    if (service?.id !== value?.id) selection()
    onChange(service)
    setOpen(false)
  }

  return (
    // Laid out as a Field is (ui/fields), its label and note 16px in — not one, so the button keeps its own words as its name.
    <div className="flex flex-col gap-2">
      <span className="text-section-header px-4 text-footnote font-semibold">سرویس مربوط</span>
      <button
        type="button"
        onClick={() => {
          tap()
          setOpen(true)
        }}
        aria-haspopup="dialog"
        className={cn('press rounded-control bg-section flex h-12 w-full items-center gap-3 px-4 text-start shadow-card', error !== undefined && 'ring-2 ring-danger')}
      >
        <Wifi aria-hidden className="text-accent-text size-5 shrink-0" />
        <span className={cn('min-w-0 flex-1 truncate text-body', value === null && 'text-hint')}>{value !== null ? value.name : pending ? 'در حال خواندن…' : 'هیچ‌کدام'}</span>
        <ChevronLeft aria-hidden className="text-hint size-4.5 shrink-0 opacity-50" />
      </button>
      {error !== undefined ? (
        <p className="px-4 text-caption text-danger" role="alert">
          {error}
        </p>
      ) : (
        <p className="text-hint px-4 text-caption">اگر سوال درباره یکی از سرویس‌هایتان است، آن را انتخاب کنید.</p>
      )}

      <Sheet open={open} onClose={() => setOpen(false)} title="درباره کدام سرویس؟">
        <div className="card overflow-hidden" role="radiogroup" aria-label="سرویس‌ها">
          <Choice icon={CircleSlash} tint="var(--app-hint)" title="هیچ‌کدام" subtitle="سوال کلی، بدون سرویس" picked={value === null} onPick={() => choose(null)} />
          {services.data === undefined ? (
            services.isError ? (
              <ErrorView error={services.error} retry={() => void services.refetch()} />
            ) : (
              <div className="flex flex-col gap-2 p-4" role="status" aria-label="در حال بارگذاری">
                <Skeleton className="h-10" />
                <Skeleton className="h-10" />
              </div>
            )
          ) : (
            rows.map((service) => (
              <Choice
                key={service.id}
                icon={Wifi}
                title={serviceTitle(service)}
                subtitle={`${service.label ? `${service.name} · ` : ''}${SUBSCRIPTION_STATUS[service.status].label}`}
                picked={value?.id === service.id}
                onPick={() => choose({ id: service.id, name: serviceTitle(service) })}
              />
            ))
          )}
        </div>
        {services.isFetchNextPageError && (
          <div className="mt-2">
            <Notice tone="danger">{problemOf(services.error)}</Notice>
          </div>
        )}
        {services.hasNextPage && (
          <Button variant="plain" block className="mt-2" busy={services.isFetchingNextPage} onClick={() => void services.fetchNextPage()}>
            سرویس‌های بیشتر
          </Button>
        )}
      </Sheet>
    </div>
  )
}

/** A choice's hairline starts where its words do, as a section's row's (ui/section Row): past its 32px icon and 12px gap. */
const CHOICE_INSET = { '--row-inset': '3.75rem' } as CSSProperties

/** A choice in the sheet, drawn as a section's row is: its icon in its square, its words, and a tick on the one picked. */
function Choice({ icon, tint, title, subtitle, picked, onPick }: { icon: typeof Wifi; tint?: string; title: string; subtitle: string; picked: boolean; onPick: () => void }) {
  return (
    <button type="button" role="radio" aria-checked={picked} onClick={onPick} className="list-row flex min-h-12 w-full items-center gap-3 px-4 text-start active:bg-fill" style={CHOICE_INSET}>
      <IconTile icon={icon} color={tint} />
      <span className="flex min-w-0 flex-1 flex-col gap-0.5 py-3">
        <span className="truncate text-body">{title}</span>
        <span className="text-hint truncate text-footnote">{subtitle}</span>
      </span>
      {picked && <Check aria-hidden className="text-accent-text size-5 shrink-0" />}
    </button>
  )
}
