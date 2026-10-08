import { formatBytes, formatNumber } from '@/lib/format'

/** How far a grant got: the services it gave to, passed by and failed on. */
interface GrantProgress {
  total: number
  granted: number
  skipped: number
  failed: number
}

/** What a grant gives each service, worded as the bot words it: "۳ روز و ۱۰ گیگابایت". */
export function giftLabel(grant: { days: number; traffic_bytes: number }): string {
  return [grant.days > 0 ? `${formatNumber(grant.days)} روز` : null, grant.traffic_bytes > 0 ? formatBytes(grant.traffic_bytes) : null].filter(Boolean).join(' و ')
}

/** The services a grant has been through so far. */
export function reached(grant: GrantProgress): number {
  return Math.min(grant.granted + grant.skipped + grant.failed, grant.total)
}

/**
 * How far a running grant got, its numbers named: every active service it goes through (`total` — whether one takes it
 * is its panel's word as its turn comes), how many of them were checked, and what came of those so far:
 * "۵ از ۱۲ سرویس فعال بررسی شد · به ۳ سرویس اضافه شد · ۲ سرویس شامل نشد".
 */
export function progress(grant: GrantProgress): string {
  const parts = [`${formatNumber(reached(grant))} از ${formatNumber(grant.total)} سرویس فعال بررسی شد`, `به ${formatNumber(grant.granted)} سرویس اضافه شد`]
  if (grant.skipped > 0) parts.push(`${formatNumber(grant.skipped)} سرویس شامل نشد`)
  if (grant.failed > 0) parts.push(`${formatNumber(grant.failed)} ناموفق`)
  return parts.join(' · ')
}

/** How a finished grant went: "به ۱۲ سرویس اضافه شد · ۳ سرویس شامل نشد · ۱ ناموفق". */
export function outcome(grant: GrantProgress): string {
  const parts = [grant.granted > 0 ? `به ${formatNumber(grant.granted)} سرویس اضافه شد` : 'به سرویسی اضافه نشد']
  if (grant.skipped > 0) parts.push(`${formatNumber(grant.skipped)} سرویس شامل نشد`)
  if (grant.failed > 0) parts.push(`${formatNumber(grant.failed)} ناموفق`)
  return parts.join(' · ')
}
