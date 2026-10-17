import type { PricedRowDraft } from '@/components/priced-rows-editor'
import type { BotSettingsData, ExtraOption, ExtraOptionInput, ExtraPrices, ExtraPricesRequest } from '@/lib/api-types'
import { amountDraft, tomanOf, wholeNumberOf } from '@/lib/format'
import { asAmount, asWhole } from '@/lib/use-form'

/*
 * A price list of «حجم، زمان و کاربر اضافه» — the shop's (the bot settings' `extra_…`) or a plan's own (`extras.…`) — as a form
 * holds it: for each kind the price of a unit, the amounts offered as buttons (each at a price of its own, or the unit
 * price), and the least and the most a customer may type. What the server keeps of it, what it sends and what the bot
 * then offers are read here, by the server's own rules (Subscriptions\Forms\UnitPrice and ExtraOptions, DTO\ExtraOffer).
 * A device's prices are for 30 days; the bot charges each service its share for the days it has left.
 */

/** The three extras a service may buy: traffic by the GB, time by the day, devices (its IP limit) by the device. */
export type ExtraKind = 'traffic' | 'time' | 'devices'

/** A price list's fields by their bare names — a plan's own; the shop's are the same, each named `extra_…`. */
export type ExtraField = keyof ExtraPrices

const FIELDS: readonly ExtraField[] = [
  'traffic_price',
  'traffic_options',
  'traffic_min',
  'traffic_max',
  'time_price',
  'time_options',
  'time_min',
  'time_max',
  'devices_price',
  'devices_options',
  'devices_min',
  'devices_max',
]

/** An amount offered as a button, as typed (a row of components/priced-rows-editor): its `quantity` and its price (blank: the unit price). */
export type ExtraOptionDraft = PricedRowDraft<'quantity'>

/** A price list as its fields are typed. */
export interface ExtraPricesDraft {
  traffic_price: string
  traffic_options: ExtraOptionDraft[]
  traffic_min: string
  traffic_max: string
  time_price: string
  time_options: ExtraOptionDraft[]
  time_min: string
  time_max: string
  devices_price: string
  devices_options: ExtraOptionDraft[]
  devices_min: string
  devices_max: string
}

/**
 * A price list that sells nothing, where a plan's own list starts: within the shop's bounds once they are read
 * (`unpricedWithin()`), until then within the server's defaults (Subscriptions\Services\ExtrasSettings::fields(): 1–100
 * GB, 1–30 days, 1–5 devices).
 */
export const NO_EXTRA_PRICES: ExtraPricesDraft = {
  traffic_price: '',
  traffic_options: [],
  traffic_min: '1',
  traffic_max: '100',
  time_price: '',
  time_options: [],
  time_min: '1',
  time_max: '30',
  devices_price: '',
  devices_options: [],
  devices_min: '1',
  devices_max: '5',
}

/** A price as its field starts from it: whole Toman in Latin digits ("3000.00" → "3000"); none is blank. */
const priceDraft = (toman: string | null): string => (toman === null ? '' : amountDraft(toman))

/** The amounts offered as rows of the form, each numbered for the draft's own use. */
const optionDrafts = (options: ExtraOption[]): ExtraOptionDraft[] => options.map((option, id) => ({ id, quantity: String(option.quantity), price: priceDraft(option.price) }))

/** A price list as the server keeps it, as its form starts from it: whole Toman in Latin digits, a row an amount. */
export function extraPricesDraft(prices: ExtraPrices): ExtraPricesDraft {
  return {
    traffic_price: priceDraft(prices.traffic_price),
    traffic_options: optionDrafts(prices.traffic_options),
    traffic_min: String(prices.traffic_min),
    traffic_max: String(prices.traffic_max),
    time_price: priceDraft(prices.time_price),
    time_options: optionDrafts(prices.time_options),
    time_min: String(prices.time_min),
    time_max: String(prices.time_max),
    devices_price: priceDraft(prices.devices_price),
    devices_options: optionDrafts(prices.devices_options),
    devices_min: String(prices.devices_min),
    devices_max: String(prices.devices_max),
  }
}

/** The shop's price list as the bot settings keep it (`extra_…`), as its form starts from it. */
export function shopExtraPricesDraft(settings: BotSettingsData): ExtraPricesDraft {
  return extraPricesDraft({
    traffic_price: settings.extra_traffic_price,
    traffic_options: settings.extra_traffic_options,
    traffic_min: settings.extra_traffic_min,
    traffic_max: settings.extra_traffic_max,
    time_price: settings.extra_time_price,
    time_options: settings.extra_time_options,
    time_min: settings.extra_time_min,
    time_max: settings.extra_time_max,
    devices_price: settings.extra_devices_price,
    devices_options: settings.extra_devices_options,
    devices_min: settings.extra_devices_min,
    devices_max: settings.extra_devices_max,
  })
}

/** A price list that sells nothing (NO_EXTRA_PRICES) within the typed bounds of `prices`: a plan's own list started from the shop's. */
export function unpricedWithin(prices: ExtraPricesDraft): ExtraPricesDraft {
  const { traffic_min, traffic_max, time_min, time_max, devices_min, devices_max } = prices
  return { ...NO_EXTRA_PRICES, traffic_min, traffic_max, time_min, time_max, devices_min, devices_max }
}

/** A row that says something: one whose quantity and price are both blank is nothing — the server leaves it out too. */
const said = (options: ExtraOptionDraft[]) => options.filter((option) => option.quantity.trim() !== '' || option.price.trim() !== '')

/** The rows that say something, as a request sends them: as typed, without the draft's own ids. */
const optionInputs = (options: ExtraOptionDraft[]): ExtraOptionInput[] => said(options).map(({ quantity, price }) => ({ quantity, price }))

/** A price list as its request sends it — every field as typed, the rows that say something, no row's id. */
export function extraPricesRequest(draft: ExtraPricesDraft): Required<ExtraPricesRequest> {
  return {
    traffic_price: draft.traffic_price,
    traffic_options: optionInputs(draft.traffic_options),
    traffic_min: draft.traffic_min,
    traffic_max: draft.traffic_max,
    time_price: draft.time_price,
    time_options: optionInputs(draft.time_options),
    time_min: draft.time_min,
    time_max: draft.time_max,
    devices_price: draft.devices_price,
    devices_options: optionInputs(draft.devices_options),
    devices_min: draft.devices_min,
    devices_max: draft.devices_max,
  }
}

/** Amounts the smallest first, as the server keeps them — one it cannot read after them, in the order typed. */
function byQuantity(a: { quantity: number | string }, b: { quantity: number | string }): number {
  if (typeof a.quantity === 'number') return typeof b.quantity === 'number' ? a.quantity - b.quantity : -1
  return typeof b.quantity === 'number' ? 1 : 0
}

/** The amounts offered as the server keeps them: the rows that say something, read, the smallest first. */
function optionsRead(rows: ExtraOptionDraft[]) {
  const read = said(rows).map((option) => ({ quantity: asWhole(option.quantity), price: asAmount(option.price) }))
  read.sort(byQuantity)
  return read
}

/**
 * A price list as the server keeps it, for useForm's `reads`: empty rows dropped, the rest smallest first, numbers by
 * their digits — so a blank row, the rows' order or how a number is written is no change.
 */
export function extraPricesRead(draft: ExtraPricesDraft) {
  return {
    traffic_price: asAmount(draft.traffic_price),
    traffic_options: optionsRead(draft.traffic_options),
    traffic_min: asWhole(draft.traffic_min),
    traffic_max: asWhole(draft.traffic_max),
    time_price: asAmount(draft.time_price),
    time_options: optionsRead(draft.time_options),
    time_min: asWhole(draft.time_min),
    time_max: asWhole(draft.time_max),
    devices_price: asAmount(draft.devices_price),
    devices_options: optionsRead(draft.devices_options),
    devices_min: asWhole(draft.devices_min),
    devices_max: asWhole(draft.devices_max),
  }
}

/** What a price list sells of one kind, as the bot offers it (DTO\ExtraOffer). */
export interface ExtraOfferPreview {
  /** The amounts offered as buttons, the smallest first: each with what it costs — null without a price (its own, or the unit's), which the server refuses to save. */
  buttons: { quantity: number; price: number | null }[]
  /** Any amount from `min` to `max` may be typed, at `price` each — while a unit price prices it; null otherwise. */
  typed: { min: number; max: number; price: number } | null
}

/**
 * What the typed price list sells of `kind`, by the server's rule: each amount offered at its own price or the unit
 * price times it, and with a unit price any amount between the bounds. Invalid amounts are dropped, repeats merged.
 * Devices as set, for 30 days: what each service pays is its share for the days it has left.
 */
export function extraOffer(draft: ExtraPricesDraft, kind: ExtraKind): ExtraOfferPreview {
  const prices = { unit: draft[`${kind}_price`], rows: draft[`${kind}_options`], min: draft[`${kind}_min`], max: draft[`${kind}_max`] }
  const unit = positive(tomanOf(prices.unit))
  const buttons = new Map<number, number | null>()
  for (const row of prices.rows) {
    const quantity = positive(wholeNumberOf(row.quantity))
    if (quantity === null || buttons.has(quantity)) continue
    const own = row.price.trim() === '' ? null : positive(tomanOf(row.price))
    if (row.price.trim() !== '' && own === null) continue
    buttons.set(quantity, own ?? (unit === null ? null : unit * quantity))
  }
  const offered = [...buttons].map(([quantity, price]) => ({ quantity, price }))
  offered.sort((a, b) => a.quantity - b.quantity)
  const [min, max] = [wholeNumberOf(prices.min), wholeNumberOf(prices.max)]
  return {
    buttons: offered,
    typed: unit !== null && min !== null && max !== null && min <= max ? { min, max, price: unit } : null,
  }
}

const positive = (number: number | null): number | null => (number !== null && number >= 1 ? number : null)

/**
 * A price list's fields under a form's names for them — the names the server refuses them by: `extra_traffic_price` in
 * the bot settings, `extras.traffic_price` in a plan (and so for time and devices).
 */
export type ExtraPricesAs<P extends string> = { [K in ExtraField as `${P}${K}`]: ExtraPricesDraft[K] }

/** A price list's fields as a form holds them, each under `prefix` and its name. */
export function extraPricesAs<P extends string>(prefix: P, prices: ExtraPricesDraft): ExtraPricesAs<P> {
  return Object.fromEntries(FIELDS.map((field) => [`${prefix}${field}`, prices[field]])) as ExtraPricesAs<P>
}

/** The price list a form holds under `prefix`. */
export function extraPricesIn<P extends string>(prefix: P, values: ExtraPricesAs<P>): ExtraPricesDraft {
  const named: Record<string, unknown> = values
  return Object.fromEntries(FIELDS.map((field) => [field, named[`${prefix}${field}`]])) as unknown as ExtraPricesDraft
}
