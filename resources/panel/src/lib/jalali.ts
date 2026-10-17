import { shownTimeZone } from '@/lib/format'

/*
 * The Jalali calendar for the date filters, on the browser's own Intl `persian` calendar — no date library. A day
 * travels as the API takes it, a Gregorian "2026-10-06" (`Day`); a Jalali date is {year, month 1–12, day}. The
 * arithmetic runs on UTC midnights, so no zone or daylight saving ever moves a day. A moment is read on the shop's clock
 * (`shopClock`), and "today" is the shop's day.
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

/** A moment's Gregorian date and hour in a zone, in Latin digits — made once per zone. */
const clocksIn = new Map<string, Intl.DateTimeFormat>()

/** A moment (a Date, or the API's ISO text) on the shop's clock, whatever the browser's zone: its Gregorian day and its hour, 0–23. */
export function shopClock(moment: Date | string): { day: Day; hour: number } {
  const { zone } = shownTimeZone()
  let clock = clocksIn.get(zone)
  if (!clock) {
    clock = new Intl.DateTimeFormat('en-u-ca-gregory-nu-latn', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' })
    clocksIn.set(zone, clock)
  }
  const parts = clock.formatToParts(typeof moment === 'string' ? new Date(moment) : moment)
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((piece) => piece.type === type)?.value ?? ''
  return { day: `${part('year')}-${part('month')}-${part('day')}`, hour: Number(part('hour')) % 24 }
}

/** Today in the shop's zone, whatever the browser's: the day the server bounds a list's `from`/`to` by. */
export function today(): Day {
  return shopClock(new Date()).day
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
