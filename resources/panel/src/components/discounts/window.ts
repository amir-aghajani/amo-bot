import { formatDate, toLatinDigits } from '@/lib/format'
import { fromJalali, monthLength, shopClock, toJalali, type Day } from '@/lib/jalali'

/*
 * When a discount code or a sale holds. The form keeps each end as a Jalali day typed by the admin ("1405/07/20",
 * Persian digits too) and an hour 0–23, both on the shop's clock whatever the browser's zone. The API takes that day and
 * hour as "2026-10-12T18:00" (the server applies the shop's zone) and answers a moment, read back on the shop's clock.
 */

/** One end of a window as the form keeps it: the Jalali day as typed ('' — open on that side) and the hour. */
export interface WindowEnd {
  day: string
  hour: string
}

const pad = (value: number) => String(value).padStart(2, '0')

/** A day as the form shows it: the Jalali "1405/07/20". */
export function jalaliText(day: Day): string {
  const { year, month, day: date } = toJalali(day)
  return `${year}/${pad(month)}/${pad(date)}`
}

/** A saved end (the API's moment, or none) as the form starts from it: its Jalali day and hour on the shop's clock. */
export function windowEnd(iso: string | null, emptyHour = '0'): WindowEnd {
  if (iso === null) return { day: '', hour: emptyHour }
  const { day, hour } = shopClock(iso)
  return { day: jalaliText(day), hour: String(hour) }
}

/** The Gregorian day a typed Jalali day names ("1405/7/20", "۱۴۰۵-۰۷-۲۰"); null for text that is no real day. */
export function dayOf(typed: string): Day | null {
  const match = /^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})$/.exec(toLatinDigits(typed.trim()))
  if (!match) return null
  const [year, month, date] = [Number(match[1]), Number(match[2]), Number(match[3])]
  if (month < 1 || month > 12 || date < 1 || date > monthLength(year, month)) return null
  return fromJalali({ year, month, day: date })
}

/**
 * An end as the API takes it ("2026-10-12T18:00"), or null for none. A day that does not parse goes as typed, so the
 * server refuses it under its field.
 */
export function windowRequest(end: WindowEnd): string | null {
  if (end.day.trim() === '') return null
  const day = dayOf(end.day)
  return day === null ? end.day : `${day}T${pad(Number(end.hour))}:00`
}

/** A saved window in a few words: «تا ۲۰ مهر، ۱۸:۰۰», «از … تا …», «همیشه». */
export function windowWords(starts: string | null, ends: string | null): string {
  if (starts === null && ends === null) return 'همیشه'
  if (starts === null) return `تا ${formatDate(ends)}`
  if (ends === null) return `از ${formatDate(starts)}`
  return `${formatDate(starts)} تا ${formatDate(ends)}`
}
