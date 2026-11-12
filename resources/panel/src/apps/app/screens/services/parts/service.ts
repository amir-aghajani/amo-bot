import { idOf } from '@/apps/app/screens/support/parts/words'
import type { NextDevices, StoreNextPeriod, StoreSubscription } from '@/lib/api-types'
import { formatBytes, formatDate, formatDeadline, formatDevices, formatNumber, formatPercent } from '@/lib/format'

/*
 * A customer's service as the screens under /services word it besides what every screen shows of it (parts/service): its
 * id in the address, and the shop's words on what comes next — its renewal window, a queued period, its removal.
 */

/** The service's id in the address (`/services/:id`), read as every id in an address is (support/parts/words); NaN for one that names none. */
export function serviceId(param: string | undefined): number {
  return idOf(param) ?? Number.NaN
}

/**
 * While the shop holds the service's renewal back («آستانه مجاز تمدید»): from when it may be renewed, in the shop's own
 * words for it; null while it may be renewed now.
 */
export function windowWords(gate: StoreSubscription['renewal_window']): string | null {
  if (gate.open) return null
  const opens: string[] = []
  if (gate.opens_at !== null) opens.push(`از ${formatDate(gate.opens_at)}`)
  if (gate.days_before !== null) opens.push(`${formatNumber(gate.days_before)} روز پیش از پایانش`)
  if (gate.traffic_percent !== null) opens.push(`وقتی ${formatPercent(gate.traffic_percent)} از حجم یا کمتر مانده باشد`)

  return opens.length === 0 ? 'تمدید این سرویس بعد از پایانش ممکن است.' : `تمدید این سرویس ${opens.join(' یا ')} ممکن است.`
}

/**
 * With a renewal queued behind the period in use: how much of what is left goes with that period, and what the next one
 * starts with — as the bot says it; null when nothing goes.
 */
export function leftoverWords(remaining: number | null, next: StoreNextPeriod | null): string | null {
  if (next === null || remaining === null || remaining <= next.bytes) return null

  return `${formatBytes(remaining - next.bytes)} از حجم باقی‌مانده مربوط به دوره فعلی است و فقط تا ${formatDeadline(next.ends_at)} قابل استفاده است؛ از آن روز دوره جدید با ${formatBytes(next.bytes)} شروع می‌شود.`
}

/** Devices at once, and the limit a renewal queued after the period in use: «۲ کاربر · از ۲۸ آبان ۴ کاربر». */
export function devicesWords(devices: number, next: NextDevices | null): string {
  return next === null ? formatDevices(devices) : `${formatDevices(devices)} · از ${formatDeadline(next.from)} ${formatDevices(next.devices)}`
}

/** When the shop removes the service by itself, as a warning; null when it does not. */
export function removalWords(service: StoreSubscription, now: number = Date.now()): string | null {
  if (service.removes_at === null) return null
  const when = new Date(service.removes_at).getTime() <= now ? 'به‌زودی' : formatDate(service.removes_at)
  if (service.is_test) return `این اکانت تست ${when} حذف می‌شود.`

  return `این سرویس ${when} از سرور و از سرویس‌های شما حذف می‌شود؛ برای نگه داشتنش پیش از آن تمدیدش کنید.`
}
