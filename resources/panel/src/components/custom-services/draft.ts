import type { PricedRowDraft } from '@/components/priced-rows-editor'
import type { BotCustomServicesSettingsRequest, BotSettingsData, PriceTier } from '@/lib/api-types'
import { amountDraft, tomanOf, wholeNumberOf, wholesOf } from '@/lib/format'
import { asAmount, asSet, asWhole } from '@/lib/use-form'

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

/** The tier rows that say something as the server keeps them: each start and price read as it reads them, smallest start first. */
function tiersRead(rows: TierDraft[]): { from: number | string; price: number | string | null }[] {
  const kept = said(rows).map(({ from, price }) => ({ from: asWhole(from), price: asAmount(price) }))
  kept.sort((a, b) => (typeof a.from === 'number' ? a.from : 0) - (typeof b.from === 'number' ? b.from : 0))
  return kept
}

/** Amounts offered as the server keeps them (Core\Forms\Fields\Numbers): each once, in no order — or the text, trimmed, while one is no number. */
const optionsRead = (typed: string) => {
  const numbers = wholesOf(typed)
  return numbers === null ? typed.trim() : asSet(numbers.map(Number))
}

/**
 * The group as the server reads it, to tell a change from none: numbers by their digits («۳٬۰۰۰» is 3000), the tiers in
 * the order they are kept, the amounts offered and the servers as sets.
 */
export function customServicesRead(draft: CustomServicesDraft): unknown {
  return {
    custom_enabled: draft.custom_enabled,
    custom_traffic_tiers: tiersRead(draft.custom_traffic_tiers),
    custom_traffic_min: asWhole(draft.custom_traffic_min),
    custom_traffic_max: asWhole(draft.custom_traffic_max),
    custom_traffic_options: optionsRead(draft.custom_traffic_options),
    custom_days_tiers: tiersRead(draft.custom_days_tiers),
    custom_days_min: asWhole(draft.custom_days_min),
    custom_days_max: asWhole(draft.custom_days_max),
    custom_days_options: optionsRead(draft.custom_days_options),
    custom_devices: asWhole(draft.custom_devices),
    custom_servers: asSet(draft.custom_servers),
  }
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

/** What a customer may build of a quantity, as the server bounds it (CustomServices::bounds()): from the least set — never below the first tier — to the most. */
interface Bounds {
  least: number
  most: number
}

/** The bounds of a quantity priced by `tiers`, its least and most as typed. */
function boundsOf(tiers: Tier[], min: string, max: string): Bounds {
  return { least: Math.max(wholeNumberOf(min) ?? 1, tiers[0]?.from ?? 1), most: wholeNumberOf(max) ?? 0 }
}

/**
 * The amounts typed as buttons as the bot offers them (CustomServices::options()), each once and the smallest first: those
 * within what a customer may build (`shown`), and those it leaves out (`left`) by those `bounds` — none while the tiers
 * cannot bound anything yet.
 */
export interface Offered {
  shown: number[]
  left: number[]
  bounds: Bounds | null
}

/** What the bot offers of the amounts typed as buttons (Offered); null while none is typed, or one is no whole number. */
export function offeredOf(rows: TierDraft[], min: string, max: string, typed: string): Offered | null {
  const options = optionsOf(typed)
  if (options === null || options.length === 0) return null
  const amounts = [...new Set(options)]
  amounts.sort((a, b) => a - b)
  const tiers = tiersOf(rows)
  if (tiers === null || tiers.length === 0) return { shown: amounts, left: [], bounds: null }
  const bounds = boundsOf(tiers, min, max)
  const within = (amount: number) => amount >= bounds.least && amount <= bounds.most
  return { shown: amounts.filter(within), left: amounts.filter((amount) => !within(amount)), bounds }
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
  const { least, most } = boundsOf(tiers, min, max)
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
