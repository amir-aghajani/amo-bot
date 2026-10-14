import type { PricedRowDraft } from '@/components/priced-rows-editor'
import type { BotCustomServicesSettingsRequest, BotSettingsData, PriceTier } from '@/lib/api-types'
import { amountDraft, tomanOf, wholeNumberOf, wholesOf } from '@/lib/format'

/*
 * «سرویس دلخواه»'s settings as the panel edits them (the `custom_services` bot-settings group), and the price the bot
 * asks for what a customer builds — each quantity whole at the rate of the tier it falls into, the GB's price and the
 * days' added (Catalog\Services\CustomServices) —, worked out here from what is typed.
 */

/** A tier as typed (a row of components/priced-rows-editor): where it starts, `from`, and its price a unit. */
export type TierDraft = PricedRowDraft<'from'>

/** A tier as the server reads one typed: its start and its price, whole numbers. */
export interface Tier {
  from: number
  price: number
}

export type QuantityKind = 'traffic' | 'days'

/** The group's form: every number as typed, the amounts offered as one line, the servers ticked. */
export interface CustomServicesDraft {
  custom_enabled: boolean
  custom_traffic_tiers: TierDraft[]
  custom_traffic_min: string
  custom_traffic_max: string
  custom_traffic_options: string
  custom_days_tiers: TierDraft[]
  custom_days_min: string
  custom_days_max: string
  custom_days_options: string
  custom_devices: string
  custom_servers: number[]
}

const tierDrafts = (tiers: PriceTier[] | undefined): TierDraft[] => (tiers ?? []).map((tier, id) => ({ id, from: String(tier.from), price: amountDraft(tier.price) }))

/** A number as its field starts from it; blank where the settings hold none. */
const numberDraft = (number: number | undefined): string => (number === undefined ? '' : String(number))

/**
 * The group as the server keeps it, as its form starts from it. The main shop's settings — the only shop with the group
 * (its page shows no form elsewhere) — always hold every field.
 */
export function customServicesDraft(settings: BotSettingsData): CustomServicesDraft {
  return {
    custom_enabled: settings.custom_enabled ?? false,
    custom_traffic_tiers: tierDrafts(settings.custom_traffic_tiers),
    custom_traffic_min: numberDraft(settings.custom_traffic_min),
    custom_traffic_max: numberDraft(settings.custom_traffic_max),
    custom_traffic_options: (settings.custom_traffic_options ?? []).join(', '),
    custom_days_tiers: tierDrafts(settings.custom_days_tiers),
    custom_days_min: numberDraft(settings.custom_days_min),
    custom_days_max: numberDraft(settings.custom_days_max),
    custom_days_options: (settings.custom_days_options ?? []).join(', '),
    custom_devices: numberDraft(settings.custom_devices),
    custom_servers: settings.custom_servers ?? [],
  }
}

/** A row that says something: one with both its start and its price blank is nothing — the server leaves it out too. */
const said = (rows: TierDraft[]) => rows.filter((row) => row.from.trim() !== '' || row.price.trim() !== '')

/** The group as its PUT sends it: every field as typed, the tier rows that say something, without their ids. */
export function customServicesRequest(draft: CustomServicesDraft): BotCustomServicesSettingsRequest {
  return {
    ...draft,
    custom_traffic_tiers: said(draft.custom_traffic_tiers).map(({ from, price }) => ({ from, price })),
    custom_days_tiers: said(draft.custom_days_tiers).map(({ from, price }) => ({ from, price })),
  }
}

/** The tier rows that say something, smallest start first — the order the server keeps them in —, without their ids. */
function byStart(rows: TierDraft[]): { from: string; price: string }[] {
  const kept = said(rows).map(({ from, price }) => ({ from, price }))
  kept.sort((a, b) => (wholeNumberOf(a.from) ?? 0) - (wholeNumberOf(b.from) ?? 0))
  return kept
}

/** The group as the server reads it, to tell a change from none: the request, the tiers in the order they are kept. */
export function customServicesRead(draft: CustomServicesDraft): unknown {
  return { ...customServicesRequest(draft), custom_traffic_tiers: byStart(draft.custom_traffic_tiers), custom_days_tiers: byStart(draft.custom_days_tiers) }
}

/** The tiers as typed, read as the server reads them — smallest start first; null while a row says something unreadable. */
export function tiersOf(rows: TierDraft[]): Tier[] | null {
  const tiers: Tier[] = []
  for (const row of said(rows)) {
    const from = wholeNumberOf(row.from)
    const price = tomanOf(row.price)
    if (from === null || from < 1 || price === null || price < 1) return null
    tiers.push({ from, price })
  }
  tiers.sort((a, b) => a.from - b.from)
  return tiers
}

/** The tier `quantity` falls into — the last whose start it reaches; null below the first (DTO\PriceTiers::unitPrice()). */
export function tierOf(tiers: Tier[], quantity: number): Tier | null {
  return tiers.filter((tier) => quantity >= tier.from).at(-1) ?? null
}

/** The amounts offered as buttons, as typed; null while one is no whole number. */
export function optionsOf(typed: string): number[] | null {
  return wholesOf(typed)?.map(Number) ?? null
}

/** One quantity's price for the calculator: its tier's rate a unit, and the whole of it. */
interface QuantityPrice {
  quantity: number
  unit: number
  total: number
}

/** What the calculator says: both prices and their sum, or why there is none (a word for the GB, one for the days). */
export type CustomPriceAnswer = { traffic: QuantityPrice; days: QuantityPrice; total: number } | { refusals: Partial<Record<QuantityKind, string>> }

const UNITS: Record<QuantityKind, string> = { traffic: 'گیگابایت', days: 'روز' }

/** A quantity's price within its bounds — the least never below the first tier —, or why there is none. */
function quantityPrice(kind: QuantityKind, typed: string, rows: TierDraft[], min: string, max: string): QuantityPrice | string {
  const tiers = tiersOf(rows)
  if (tiers === null) return 'پله‌های قیمت را درست بنویسید.'
  if (tiers.length === 0) return 'هنوز پله قیمتی ندارد؛ بدون آن فروخته نمی‌شود.'
  const quantity = wholeNumberOf(typed)
  if (quantity === null) return `تعداد ${UNITS[kind]} را بنویسید.`
  const least = Math.max(wholeNumberOf(min) ?? 1, tiers[0]?.from ?? 1)
  const most = wholeNumberOf(max) ?? 0
  const tier = tierOf(tiers, quantity)
  if (tier === null || quantity < least || quantity > most) return `مشتری بین ${least} تا ${most} ${UNITS[kind]} می‌سازد.`
  return { quantity, unit: tier.price, total: tier.price * quantity }
}

/** What `gb` GB for `days` days cost a customer before any discount, by the draft's tiers and bounds. */
export function customPrice(draft: CustomServicesDraft, gb: string, days: string): CustomPriceAnswer {
  const traffic = quantityPrice('traffic', gb, draft.custom_traffic_tiers, draft.custom_traffic_min, draft.custom_traffic_max)
  const time = quantityPrice('days', days, draft.custom_days_tiers, draft.custom_days_min, draft.custom_days_max)
  if (typeof traffic === 'string' || typeof time === 'string') {
    return { refusals: { ...(typeof traffic === 'string' && { traffic }), ...(typeof time === 'string' && { days: time }) } }
  }
  return { traffic, days: time, total: traffic.total + time.total }
}
