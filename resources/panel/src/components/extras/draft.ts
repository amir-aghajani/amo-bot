import type { ExtraOption, ExtraOptionInput, ExtraPrices, ExtraPricesRequest } from '@/lib/api-types'
import { amountDraft, decimalOf, wholeNumberOf, wholeOf } from '@/lib/format'

/*
 * A price list of «حجم و زمان اضافه» — the shop's (the bot settings' `extra_…`) or a plan's own (`extras.…`) — as a form
 * holds it: for each kind the price of a unit, the amounts offered as buttons (each at a price of its own, or the unit
 * price), and the least and the most a customer may type. What the server keeps of it, what it sends and what the bot
 * then offers are read here, by the server's own rules (Subscriptions\Forms\UnitPrice and ExtraOptions, DTO\ExtraOffer).
 */

/** The two extras a service may buy: traffic by the GB, time by the day. */
export type ExtraKind = 'traffic' | 'time'

/** A price list's fields by their bare names — a plan's own; the shop's are the same, each named `extra_…`. */
export type ExtraField = keyof ExtraPrices

const FIELDS: readonly ExtraField[] = ['traffic_price', 'traffic_options', 'traffic_min', 'traffic_max', 'time_price', 'time_options', 'time_min', 'time_max']

/** How many amounts a price list offers as buttons at most (ExtraOptions::MOST). */
export const OPTIONS_MOST = 8

/**
 * An amount offered as a button, as typed: its quantity and its price (blank: the unit price). `id` is local to the draft
 * so rows keep their elements and focus when one is removed; it is never sent.
 */
export interface ExtraOptionDraft {
  id: number
  quantity: string
  price: string
}

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
}

/** A price list that sells nothing, where a plan's own list starts: the typed bounds at the server's defaults (1–100 GB, 1–30 days). */
export const NO_EXTRA_PRICES: ExtraPricesDraft = {
  traffic_price: '',
  traffic_options: [],
  traffic_min: '1',
  traffic_max: '100',
  time_price: '',
  time_options: [],
  time_min: '1',
  time_max: '30',
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
  }
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
  }
}

/** A typed price as whole Toman, read the way the server reads it (`Input::amountOf()`); null for anything else. */
export function tomanOf(typed: string): number | null {
  const number = decimalOf(typed.trim().replace(/\s*\p{L}+$/u, ''))
  return number !== null && /^\d+(?:\.0{1,2})?$/.test(number) ? Number.parseInt(number, 10) : null
}

/** A whole number as the server reads one typed (Input::wholeOf()); the text, trimmed, when it is none — for the server to refuse. */
function wholeRead(typed: string): number | string {
  const number = wholeOf(typed)
  return number === null ? typed.trim() : Number(number)
}

/** A price as the server reads one: blank is none; whole Toman, or the text, trimmed, when it is none — a plan's own prices too. */
export function priceRead(typed: string): number | string | null {
  return typed.trim() === '' ? null : (tomanOf(typed) ?? typed.trim())
}

/** Amounts the smallest first, as the server keeps them — one it cannot read after them, in the order typed. */
function byQuantity(a: { quantity: number | string }, b: { quantity: number | string }): number {
  if (typeof a.quantity === 'number') return typeof b.quantity === 'number' ? a.quantity - b.quantity : -1
  return typeof b.quantity === 'number' ? 1 : 0
}

/** The amounts offered as the server keeps them: the rows that say something, read, the smallest first. */
function optionsRead(rows: ExtraOptionDraft[]) {
  const read = said(rows).map((option) => ({ quantity: wholeRead(option.quantity), price: priceRead(option.price) }))
  read.sort(byQuantity)
  return read
}

/**
 * A price list as the server keeps it, for useForm's `reads`: empty rows dropped, the rest smallest first, numbers by
 * their digits — so a blank row, the rows' order or how a number is written is no change.
 */
export function extraPricesRead(draft: ExtraPricesDraft) {
  return {
    traffic_price: priceRead(draft.traffic_price),
    traffic_options: optionsRead(draft.traffic_options),
    traffic_min: wholeRead(draft.traffic_min),
    traffic_max: wholeRead(draft.traffic_max),
    time_price: priceRead(draft.time_price),
    time_options: optionsRead(draft.time_options),
    time_min: wholeRead(draft.time_min),
    time_max: wholeRead(draft.time_max),
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
 */
export function extraOffer(draft: ExtraPricesDraft, kind: ExtraKind): ExtraOfferPreview {
  const prices =
    kind === 'traffic'
      ? { unit: draft.traffic_price, rows: draft.traffic_options, min: draft.traffic_min, max: draft.traffic_max }
      : { unit: draft.time_price, rows: draft.time_options, min: draft.time_min, max: draft.time_max }
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
 * the bot settings, `extras.traffic_price` in a plan.
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
