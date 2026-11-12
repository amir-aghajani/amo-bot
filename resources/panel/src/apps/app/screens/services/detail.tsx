import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeftRight, BatteryCharging, KeyRound, LifeBuoy, Pencil, RefreshCw, Repeat, WifiOff } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, pictureUrl, problemOf, store } from '@/apps/app/lib/store'
import { serviceTitle, statusOf, useService } from '@/apps/app/parts/service'
import { QuickConnect } from '@/apps/app/screens/services/parts/connect'
import { ServiceRings } from '@/apps/app/screens/services/parts/rings'
import { devicesWords, leftoverWords, removalWords, serviceId, windowWords } from '@/apps/app/screens/services/parts/service'
import { backTo } from '@/apps/app/screens/support/parts/words'
import { confirmBox, inPreview, inTelegram } from '@/apps/app/telegram/webapp'
import { Button, MainAction } from '@/apps/app/ui/button'
import { CopyValue, Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Fact, Row, Section, SwitchRow } from '@/apps/app/ui/section'
import { Sheet } from '@/apps/app/ui/sheet'
import { Empty, ErrorView, LoadingBlocks, Notice, Skeleton } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { Badge, Bar, toneLeft } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreSubscription, StoreSubscriptionResponse } from '@/lib/api-types'
import { formatBytes, formatDate, formatDeadline, formatDuration, formatNumber, timeAgo, UNLIMITED } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * A service's own page: its card — name, state, whether it is connected, a ring for its traffic and one for its term —,
 * the shop's word on what comes (its removal, its renewal window, a period queued), its numbers, its link with the
 * shop's QR card and the apps to add it to, and what may be done with it now — read it from its server again, name it,
 * renew it by itself, change its link, renew it (the main button), buy it extras, hand it on, ask support about it. Each
 * action is offered only while the service and the shop allow it. Opening the page reads the service from its server
 * once (`POST …/refresh`), which also says whether it is connected — not in a panel's preview, which shows the last copy.
 */

type Presence = NonNullable<StoreSubscription['presence']>

/** The service as an answer gave it: its page holds it now, and every list that shows it (home's, the list, a ticket's picker) is read again. */
function keep(id: number, answer: StoreSubscriptionResponse): void {
  queryClient.setQueryData(keys.subscription(id), answer)
  void queryClient.invalidateQueries({ queryKey: keys.subscriptions, predicate: (query) => query.queryKey[1] !== id })
}

export default function ServiceScreen() {
  const id = serviceId(useParams().id)
  const query = useService(id)
  const gone = Number.isNaN(id) || (query.error instanceof ApiError && query.error.status === 404)

  return (
    <Screen back={backTo('/services')} bottom="action">
      {gone ? (
        <Gone />
      ) : query.data !== undefined ? (
        // Shown while there is a copy: a read again that failed must not open the page anew (and read its server again).
        <ServicePage service={query.data.subscription} />
      ) : query.isError ? (
        <ErrorView error={query.error} retry={() => void query.refetch()} />
      ) : (
        <>
          <Skeleton className="h-72" />
          <LoadingBlocks count={3} />
        </>
      )}
    </Screen>
  )
}

/** A service that is not the customer's (any more): handed on, removed, or never theirs. */
function Gone() {
  const navigate = useNavigate()

  return <Empty icon={WifiOff} title="این سرویس در حساب شما نیست" text="شاید منتقل یا حذف شده باشد." action={<Button onClick={() => navigate('/services', { replace: true })}>سرویس‌های من</Button>} />
}

function ServicePage({ service }: { service: StoreSubscription }) {
  const navigate = useNavigate()
  const { data: shop } = useShop()
  const id = service.id
  const [presence, setPresence] = useState<Presence | null>(service.presence)
  /** Why the service could not be read from its server now: the numbers below are its last copy. */
  const [unread, setUnread] = useState<string | null>(null)
  /** The server may not be asked again yet: a 429, for its Retry-After. */
  const [resting, setResting] = useState(false)
  const [naming, setNaming] = useState(false)

  const read = (manual: boolean) => ({
    mutationFn: () => store.post(`/subscriptions/${id}/refresh`),
    meta: { quiet: !manual },
    onSuccess: (answer: StoreSubscriptionResponse) => {
      keep(id, answer)
      setPresence(answer.subscription.presence)
      setUnread(null)
      if (manual) told('اطلاعات سرویس به‌روز شد.')
    },
    onError: (error: unknown) => {
      if (error instanceof ApiError && error.status === 429) {
        setResting(true)
        window.setTimeout(() => setResting(false), (error.facts.retryAfter ?? 60) * 1000)
      } else if (error instanceof ApiError && error.status === 502) {
        setUnread(problemOf(error))
      }
    },
  })
  const opening = useMutation(read(false))
  const refresh = useMutation(read(true))
  const { mutate: readOnOpen } = opening
  const asked = useRef(false)
  const deleted = service.status === 'deleted'
  useEffect(() => {
    // A panel's preview shows the last copy the shop read: asking the server is the customer's to do.
    if (asked.current || deleted || inPreview()) return
    asked.current = true
    readOnOpen()
  }, [readOnOpen, deleted])

  const autoRenew = useMutation({
    mutationFn: (on: boolean) => store.patch(`/subscriptions/${id}`, { auto_renew: on }),
    onSuccess: (answer) => {
      keep(id, answer)
      told(answer.subscription.auto_renew.on ? 'تمدید خودکار روشن شد.' : 'تمدید خودکار خاموش شد.')
    },
  })
  const rotate = useMutation({
    mutationFn: () => store.post(`/subscriptions/${id}/rotate-link`),
    onSuccess: (answer) => {
      keep(id, answer)
      told('لینک سرویس عوض شد؛ لینک تازه را در برنامه‌ها اضافه کنید.')
    },
    // A server that failed the change may have made it: its link is read again before it is shown.
    onError: () => void queryClient.invalidateQueries({ queryKey: keys.subscription(id) }),
  })

  const status = statusOf(service)
  const title = serviceTitle(service)
  const renewable = service.renewable || service.picks_plan
  const opens = renewable ? windowWords(service.renewal_window) : null
  const removal = removalWords(service)
  const leftover = leftoverWords(service.traffic.remaining_bytes, service.next_period)
  const extras = service.extras.traffic || service.extras.time || service.extras.devices
  const transferable = (shop?.shop.service_transfers ?? false) && !service.is_test && !deleted

  return (
    <>
      <section className="glass animate-rise rounded-card relative isolate flex flex-col gap-5 overflow-hidden p-5 shadow-[0_14px_36px_-22px_var(--app-accent)]">
        <span aria-hidden className="absolute -end-16 -top-20 -z-10 size-56 rounded-full bg-[color-mix(in_srgb,var(--app-accent)_28%,transparent)] blur-3xl" />
        <span aria-hidden className="absolute -start-12 -bottom-24 -z-10 size-48 rounded-full bg-[color-mix(in_srgb,var(--app-accent)_14%,transparent)] blur-3xl" />
        {/* The badges' first line is the title's centre line: a 24px pill 4px down a 32px line. */}
        <div className="flex items-start gap-3">
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            <h1 className="truncate text-title font-extrabold">{title}</h1>
            {title !== service.name && (
              <p className="text-hint truncate text-footnote">
                <span className="ltr">{service.name}</span>
              </p>
            )}
          </div>
          <div className="flex shrink-0 flex-col items-end gap-2 pt-1">
            <Badge tone={status.tone}>{status.label}</Badge>
            {service.is_test && <Badge tone="warning">اکانت تست</Badge>}
          </div>
        </div>
        <ServiceRings service={service} />
        <PresenceLine presence={presence} synced={service.synced_at} />
      </section>

      {removal !== null && <Notice tone="danger">{removal}</Notice>}
      {service.status === 'disabled' && <Notice tone="danger">این سرویس را پشتیبانی غیرفعال کرده است؛ برای اطلاعات بیشتر با پشتیبانی در تماس باشید.</Notice>}
      {deleted && <Notice tone="warning">این سرویس دیگر روی سرور نیست و لینکی ندارد.</Notice>}
      {unread !== null && <Notice tone="warning">{`${unread} اطلاعات زیر مربوط به ${timeAgo(service.synced_at)} است.`}</Notice>}
      {opens !== null && <Notice>{opens}</Notice>}
      {leftover !== null && <Notice>{leftover}</Notice>}

      <Usage service={service} />

      {service.link !== null && (
        <>
          <Section title="لینک اشتراک" footer="این لینک را در برنامه اتصال اضافه کنید و به کسی ندهید.">
            {/* The QR card and the link's field are one height (64px), side by side. */}
            <div className="flex items-center gap-3 p-4">
              <QrCard id={id} link={service.link} />
              <div className="min-w-0 flex-1">
                <CopyValue label="لینک اشتراک" value={service.link} done="لینک اشتراک کپی شد" />
              </div>
            </div>
          </Section>
          <QuickConnect link={service.link} title={title} />
        </>
      )}

      <Section title="مدیریت سرویس">
        {!deleted && (
          <Row
            icon={RefreshCw}
            tint="#34aadc"
            title="به‌روزرسانی از سرور"
            subtitle={refresh.isPending ? 'در حال خواندن…' : resting ? 'کمی بعد دوباره امتحان کنید' : `آخرین بار ${timeAgo(service.synced_at)}`}
            disabled={refresh.isPending || resting}
            onClick={() => refresh.mutate()}
          />
        )}
        <Row icon={Pencil} tint="#5856d6" title="نام دلخواه" value={service.label || 'ندارد'} onClick={() => setNaming(true)} />
        {service.auto_renew.offered && (
          <SwitchRow
            icon={Repeat}
            tint="#34c759"
            title="تمدید خودکار"
            subtitle={`${formatNumber(service.auto_renew.days_before)} روز پیش از پایان، از کیف پول تمدید می‌شود`}
            on={service.auto_renew.on}
            busy={autoRenew.isPending}
            onChange={(on) => autoRenew.mutate(on)}
          />
        )}
        {service.link_rotation && (
          <Row
            icon={KeyRound}
            tint="#ff9500"
            title="تغییر لینک"
            subtitle={rotate.isPending ? 'در حال تغییر…' : 'لینک تازه؛ دستگاه‌های قبلی قطع می‌شوند'}
            disabled={rotate.isPending}
            onClick={() => {
              void confirmBox('لینک سرویس عوض می‌شود و همه دستگاه‌هایی که با لینک فعلی وصل‌اند قطع می‌شوند؛ بعد باید لینک تازه را در برنامه‌ها اضافه کنید. ادامه می‌دهید؟').then((sure) => {
                if (sure) rotate.mutate()
              })
            }}
          />
        )}
        {extras && <Row icon={BatteryCharging} tint="#ff2d55" title="خرید حجم، زمان یا کاربر اضافه" to={`/services/${id}/extras`} />}
        {transferable && <Row icon={ArrowLeftRight} tint="#af52de" title="انتقال سرویس" subtitle="به یک مشتری دیگر فروشگاه" to={`/services/${id}/transfer`} />}
        <Row icon={LifeBuoy} tint="#8e8e93" title="پشتیبانی این سرویس" to={`/support/new?subscription=${id}`} />
      </Section>

      <RenameSheet open={naming} onClose={() => setNaming(false)} service={service} />

      <MainAction text={renewable ? 'تمدید سرویس' : null} onClick={() => navigate(`/services/${id}/renew`)} />
    </>
  )
}

/** Whether it is connected — known once its server was read now —, else when the shop last read it. */
function PresenceLine({ presence, synced }: { presence: Presence | null; synced: string | null }) {
  const online = presence?.online === true
  const words =
    presence === null
      ? `به‌روزرسانی: ${timeAgo(synced)}`
      : online
        ? 'آنلاین'
        : presence.last_online_at !== null
          ? `آخرین اتصال: ${timeAgo(presence.last_online_at)}`
          : presence.online === false
            ? 'هنوز وصل نشده'
            : `به‌روزرسانی: ${timeAgo(synced)}`

  return (
    <p className="text-hint flex items-center justify-center gap-2 text-footnote">
      {presence !== null && (
        <span className="relative flex size-2 shrink-0" aria-hidden>
          {online && <span className="absolute inset-0 animate-ping rounded-full bg-success opacity-60" />}
          <span className={cn('relative size-2 rounded-full', online ? 'bg-success' : 'bg-fill-strong')} />
        </span>
      )}
      <span className={cn(online && 'font-medium text-success')}>{words}</span>
    </p>
  )
}

/** Its numbers: the traffic's quota, used and left (with a bar), its devices, its term's start and end, its plan and server. */
function Usage({ service }: { service: StoreSubscription }) {
  const { limit_bytes: limit, used_bytes: used, remaining_bytes: remaining } = service.traffic
  const { duration_hours: hours, starts_at: starts, expires_at: expires, awaits_first_use: waiting } = service.term

  return (
    <Section title="مصرف و مشخصات">
      {limit > 0 && (
        <div className="px-4 pt-4 pb-2">
          <Bar value={used / limit} tone={service.status === 'active' ? toneLeft(used / limit) : 'hint'} />
        </div>
      )}
      <Fact label="حجم کل">{limit > 0 ? formatBytes(limit) : UNLIMITED}</Fact>
      <Fact label="مصرف‌شده">{formatBytes(used)}</Fact>
      <Fact label="باقی‌مانده">{limit > 0 ? formatBytes(remaining ?? Math.max(0, limit - used)) : UNLIMITED}</Fact>
      <Fact label="تعداد کاربر">{devicesWords(service.devices, service.next_devices)}</Fact>
      <Fact label="شروع">{starts !== null ? formatDate(starts) : waiting ? 'با اولین اتصال' : '—'}</Fact>
      <Fact label="پایان">{expires !== null ? formatDeadline(expires) : hours === 0 ? UNLIMITED : `${formatDuration(hours)} بعد از اولین اتصال`}</Fact>
      {service.plan !== null && <Fact label="پلن">{service.plan.name}</Fact>}
      <Fact label="لوکیشن">{service.server.name}</Fact>
    </Section>
  )
}

/** The shop's QR card of the link, small; tapped, large in a sheet to save. Nothing when the shop cannot draw it. */
function QrCard({ id, link }: { id: number; link: string }) {
  const [picture, setPicture] = useState<{ link: string; url: string | null } | null>(null)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    let url: string | null = null
    let gone = false
    pictureUrl(`/subscriptions/${id}/qr`).then(
      (made) => {
        if (gone) URL.revokeObjectURL(made)
        else {
          url = made
          setPicture({ link, url: made })
        }
      },
      () => {
        if (!gone) setPicture({ link, url: null })
      },
    )

    return () => {
      gone = true
      if (url !== null) URL.revokeObjectURL(url)
    }
  }, [id, link])

  if (picture === null || picture.link !== link) return <Skeleton className="rounded-control size-16 shrink-0" />
  if (picture.url === null) return null

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="press rounded-control size-16 shrink-0 overflow-hidden bg-white shadow-card" aria-label="نمایش بزرگ QR">
        <img src={picture.url} alt="QR لینک اشتراک" className="size-16 object-cover" />
      </button>
      <Sheet open={open} onClose={() => setOpen(false)} title="QR لینک اشتراک">
        <div className="flex flex-col items-center gap-3">
          <img src={picture.url} alt="QR لینک اشتراک" className="rounded-card w-full max-w-sm bg-white" />
          <p className="text-hint text-center text-footnote">{inTelegram() ? 'برای ذخیره، انگشت را روی تصویر نگه دارید؛ یا آن را با دستگاه دیگری اسکن کنید.' : 'آن را با دستگاه دیگری اسکن کنید.'}</p>
          {!inTelegram() && (
            <a href={picture.url} download="qr.jpg" className="text-accent-text inline-flex h-11 items-center px-4 text-body leading-none font-semibold">
              ذخیره تصویر
            </a>
          )}
        </div>
      </Sheet>
    </>
  )
}

/** «نام دلخواه»: the customer's own name for the service, set, changed or dropped. */
function RenameSheet({ open, onClose, service }: { open: boolean; onClose: () => void; service: StoreSubscription }) {
  const rename = useMutation({
    mutationFn: (label: string | null) => store.patch(`/subscriptions/${service.id}`, { label }),
    meta: { quiet: true },
    onSuccess: (answer) => {
      keep(service.id, answer)
      told(answer.subscription.label === null ? 'نام دلخواه سرویس حذف شد.' : 'نام سرویس ذخیره شد.')
      onClose()
    },
  })

  return (
    <Sheet
      open={open}
      onClose={() => {
        rename.reset()
        onClose()
      }}
      title="نام دلخواه سرویس"
    >
      {/* Drawn only while the sheet is open: each opening starts from the name the service has now. */}
      <RenameForm service={service} rename={rename} />
    </Sheet>
  )
}

interface Rename {
  mutate: (label: string | null) => void
  isPending: boolean
  variables: string | null | undefined
  error: unknown
}

function RenameForm({ service, rename }: { service: StoreSubscription; rename: Rename }) {
  const [typed, setTyped] = useState(service.label ?? '')
  const problem = rename.error !== null ? (fieldProblem(rename.error, 'label') ?? problemOf(rename.error)) : undefined
  // One change at a time: a name saved and dropped at once would land in either order.
  const send = (label: string | null) => {
    if (!rename.isPending) rename.mutate(label)
  }

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        send(typed.trim() === '' ? null : typed.trim())
      }}
    >
      <Field label="نام" hint="حداکثر ۳۲ حرف؛ در ربات هم کنار سرویس دیده می‌شود." error={problem}>
        {(fieldId) => <TextInput id={fieldId} value={typed} maxLength={32} placeholder={service.name} invalid={problem !== undefined} onChange={(event) => setTyped(event.target.value)} />}
      </Field>
      <div className="flex flex-col gap-3">
        <Button type="submit" block busy={rename.isPending && rename.variables !== null} disabled={rename.isPending}>
          ذخیره
        </Button>
        {service.label !== null && (
          <Button variant="danger" block busy={rename.isPending && rename.variables === null} disabled={rename.isPending} onClick={() => send(null)}>
            حذف نام دلخواه
          </Button>
        )}
      </div>
    </form>
  )
}
