import { useQuery } from '@tanstack/react-query'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { toneLeft, type Tone } from '@/apps/app/ui/visuals'
import type { StoreSubscription, StoreSubscriptionResponse, SubscriptionStatus } from '@/lib/api-types'
import { formatBytes, formatDuration, formatNumber, UNLIMITED } from '@/lib/format'
import { SUBSCRIPTION_STATUS } from '@/lib/statuses'

/*
 * A customer's service as every screen that shows one words and draws it — its own pages, home's carousel, a ticket about
 * it: read by its id, its name, where it stands, and what is left of its traffic and its term (a ring's fill and the
 * figure in its middle).
 */

/** One of the customer's services, as `GET /subscriptions/{id}` answers it (kept under keys.subscription(id)); read while `enabled`. */
export function useService(id: number, enabled = true) {
  return useQuery({
    queryKey: keys.subscription(id),
    queryFn: () => store.get<StoreSubscriptionResponse>(`/subscriptions/${id}`),
    enabled: enabled && Number.isInteger(id) && id > 0,
  })
}

/** What the customer calls the service: their own name for it, else its name on the server. */
export function serviceTitle(service: Pick<StoreSubscription, 'name' | 'label'>): string {
  return service.label || service.name
}

const STATUS_TONE: Record<SubscriptionStatus, Tone> = { active: 'success', expired: 'warning', disabled: 'danger', deleted: 'hint' }

/** Where the service stands, as its badge says it: a new one that waits for its first connection is said so. */
export function statusOf(service: StoreSubscription): { label: string; tone: Tone } {
  if (service.status === 'active' && service.term.awaits_first_use) return { label: 'منتظر اولین اتصال', tone: 'accent' }

  return { label: SUBSCRIPTION_STATUS[service.status].label, tone: STATUS_TONE[service.status] }
}

/**
 * What is left of a quantity, as a ring draws it: its fill (null: no limit), its figure and the words after it («۶۰» and
 * «گیگابایت مانده», the whole of it said together) — and, for a ring that holds its figure, the figure's own unit under
 * it and the ring's one label beside or under it.
 */
export interface Left {
  fill: number | null
  figure: string
  unit: string
  /** The figure's unit alone, under it inside a ring: «گیگابایت», «روز»; with no limit, the word for it (under an ∞). */
  measure: string
  /** The ring's label: «حجم مانده», «زمان مانده», «از اولین اتصال», «۲ گیگابایت مصرف». */
  caption: string
  tone: Tone
}

/** A quantity in words split for a ring's middle: «۶۰ گیگابایت» → «۶۰» and «گیگابایت». */
function split(words: string): [string, string] {
  const at = words.indexOf(' ')

  return at === -1 ? [words, ''] : [words.slice(0, at), words.slice(at + 1)]
}

/** A running service's tone by what it has used; any other's is faint. */
function toneOf(service: StoreSubscription, used: number): Tone {
  return service.status === 'active' ? toneLeft(used) : 'hint'
}

/** The traffic left of its quota (`0`: unlimited). */
export function trafficLeft(service: StoreSubscription): Left {
  const { limit_bytes: limit, used_bytes: used, remaining_bytes: remaining } = service.traffic
  if (limit === 0) return { fill: null, figure: UNLIMITED, unit: `${formatBytes(used)} مصرف`, measure: UNLIMITED, caption: `${formatBytes(used)} مصرف`, tone: toneOf(service, 0) }
  const left = Math.max(0, remaining ?? limit - used)
  const [figure, unit] = split(formatBytes(left))

  return { fill: left / limit, figure, unit: `${unit} مانده`, measure: unit, caption: 'حجم مانده', tone: toneOf(service, 1 - left / limit) }
}

const HOUR = 3_600_000
const DAY = 86_400_000

/** The term left until its end: whole days, the hours of its last day, its length while it waits for its first connection. */
export function termLeft(service: StoreSubscription, now: number = Date.now()): Left {
  const { duration_hours: hours, starts_at: starts, expires_at: expires, awaits_first_use: waiting } = service.term
  if (hours === 0) return { fill: null, figure: UNLIMITED, unit: 'زمان', measure: UNLIMITED, caption: 'زمان نامحدود', tone: toneOf(service, 0) }
  if (waiting || expires === null) {
    // Days and hours together are said in hours: one figure, as a ring's middle holds one.
    const [figure, unit] = hours > 24 && hours % 24 !== 0 ? [formatNumber(hours), 'ساعت'] : split(formatDuration(hours))
    return { fill: 1, figure, unit: `${unit} از اولین اتصال`, measure: unit, caption: 'از اولین اتصال', tone: toneOf(service, 0) }
  }
  const left = new Date(expires).getTime() - now
  if (left <= 0) return { fill: 0, figure: formatNumber(0), unit: 'روز مانده', measure: 'روز', caption: 'زمان مانده', tone: service.status === 'active' ? 'danger' : 'hint' }
  const whole = starts === null ? hours * HOUR : new Date(expires).getTime() - new Date(starts).getTime()
  const fill = whole > 0 ? Math.min(1, left / whole) : 0
  const measure = left < DAY ? 'ساعت' : 'روز'

  return {
    fill,
    figure: formatNumber(Math.ceil(left / (left < DAY ? HOUR : DAY))),
    unit: `${measure} مانده`,
    measure,
    caption: 'زمان مانده',
    tone: toneOf(service, 1 - fill),
  }
}
