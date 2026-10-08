import { shownTimeZone } from '@/lib/format'

/*
 * The Persian (Jalali) calendar the date filters pick days in, on the browser's own Intl (its `persian` calendar) — no
 * date library. A day travels as the API takes it: a Gregorian calendar date, "2026-10-06" (`Day`); a Jalali one is
 * {year, month, day}, month 1 (فروردین) to 12. The arithmetic runs on UTC midnights, so no zone and no daylight saving
 * ever moves a day; today is the shop's, and the words and the digits are lib/format's.
 */

/** A calendar day as the API takes it: "YYYY-MM-DD", Gregorian. */
export type Day = string

export interface JalaliDate {
  year: number
  /** 1 (فروردین) to 12 (اسفند). */
  month: number
  day: number
}

const DAY_MS = 86_400_000

/** The persian calendar's numbers, in Latin digits, read at UTC: what `toJalali` takes apart. */
const PARTS = new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', { timeZone: 'UTC', year: 'numeric', month: 'numeric', day: 'numeric' })

/** A day's UTC midnight. */
function midnight(day: Day): number {
  const [year = 0, month = 1, date = 1] = day.split('-').map(Number)
  return Date.UTC(year, month - 1, date)
}

function dayAt(ms: number): Day {
  return new Date(ms).toISOString().slice(0, 10)
}

/** True for a real calendar day in the API's shape ("2026-02-31" is none). */
export function isDay(text: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(text) && dayAt(midnight(text)) === text
}

/** A moment's Gregorian date in a zone, in Latin digits — made once per zone. */
const datesIn = new Map<string, Intl.DateTimeFormat>()

/**
 * Today on the shop's calendar: the day it is now in the zone the panel's dates read in (lib/format — the shop's, once
 * /api/app said it), whatever zone the browser is in — the days a list's `from`/`to` name, as the server bounds them.
 */
export function today(): Day {
  const { zone } = shownTimeZone()
  let dates = datesIn.get(zone)
  if (!dates) {
    dates = new Intl.DateTimeFormat('en-u-ca-gregory-nu-latn', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' })
    datesIn.set(zone, dates)
  }
  const parts = dates.formatToParts(new Date())
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((piece) => piece.type === type)?.value ?? ''
  return `${part('year')}-${part('month')}-${part('day')}`
}

export function addDays(day: Day, days: number): Day {
  return dayAt(midnight(day) + days * DAY_MS)
}

/** The day's place in the Persian week, which starts on Saturday: 0 = شنبه … 6 = جمعه. */
export function weekday(day: Day): number {
  return (new Date(midnight(day)).getUTCDay() + 1) % 7
}

export function toJalali(day: Day): JalaliDate {
  const parts = PARTS.formatToParts(new Date(midnight(day)))
  const part = (type: Intl.DateTimeFormatPartTypes) => Number(parts.find((p) => p.type === type)?.value)
  return { year: part('year'), month: part('month'), day: part('day') }
}

/** -1, 0 or 1: which of two Jalali dates comes first. */
function compare(a: JalaliDate, b: JalaliDate): number {
  return Math.sign(a.year - b.year || a.month - b.month || a.day - b.day)
}

/**
 * The Gregorian day of a Jalali date: the year's first day falls around 21 March of the Gregorian year 621 later, a
 * guess at most a day or two off, which Intl then settles.
 */
export function fromJalali({ year, month, day }: JalaliDate): Day {
  const dayOfYear = month <= 6 ? (month - 1) * 31 + day : 186 + (month - 7) * 30 + day
  let ms = Date.UTC(year + 621, 2, 20) + (dayOfYear - 1) * DAY_MS
  for (let step = 0; step < 7; step++) {
    const off = compare({ year, month, day }, toJalali(dayAt(ms)))
    if (off === 0) break
    ms += off * DAY_MS
  }
  return dayAt(ms)
}

/** Days in a Jalali month: 31 in the first six, 30 in the next five, 29 in اسفند — 30 in a leap year. */
export function monthLength(year: number, month: number): number {
  if (month <= 6) return 31
  if (month <= 11) return 30
  return toJalali(addDays(fromJalali({ year, month: 12, day: 29 }), 1)).month === 12 ? 30 : 29
}

/** The Jalali month `months` away (negative: back). */
export function shiftMonth({ year, month }: { year: number; month: number }, months: number): { year: number; month: number } {
  const index = year * 12 + month - 1 + months
  return { year: Math.floor(index / 12), month: (index % 12) + 1 }
}

/** The first and the last day of a Jalali month. */
export function monthRange(year: number, month: number): { from: Day; to: Day } {
  return { from: fromJalali({ year, month, day: 1 }), to: fromJalali({ year, month, day: monthLength(year, month) }) }
}
