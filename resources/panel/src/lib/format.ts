/*
 * Every number and date the panels show, in Persian: Persian digits, the Jalali calendar for dates, "۳ روز قبل" for how
 * long ago. Money is always Toman. Quantities, money and dates only — identifiers (ids, ports, versions) keep their Latin
 * digits and never come through here. Each formatter is made once. A date and a time read in the shop's zone
 * (setShopTimeZone), as the bot words them for the customer, whatever zone the browser is in.
 */

const LOCALE = 'fa-IR'
const JALALI = 'fa-IR-u-ca-persian'

const numberFormat = new Intl.NumberFormat(LOCALE)
const wholeFormat = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 })
const oneDecimalFormat = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 1 })
const compactFormat = new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 })

export function formatNumber(value: number | string): string {
  return numberFormat.format(Number(value))
}

/** "۱۲ هزار" — a chart's axis, where the unit is the title's. */
export function formatCompact(value: number | string): string {
  return compactFormat.format(Number(value))
}

/** A share: "۱۲٫۵٪". */
export function formatPercent(value: number): string {
  return `${oneDecimalFormat.format(value)}٪`
}

/** A change against a baseline, signed: "+۱۲٫۵٪" / "−۳٪". */
export function formatChange(value: number): string {
  const sign = value > 0 ? '+' : value < 0 ? '−' : ''

  return `${sign}${formatPercent(Math.abs(value))}`
}

/** Percentage change between two values; null when there is no baseline to compare with. */
export function percentChange(current: number | string, previous: number | string): number | null {
  const a = Number(current)
  const b = Number(previous)
  if (b === 0) return null
  return ((a - b) / Math.abs(b)) * 100
}

export const MONEY_UNIT = 'تومان'

/** An amount of Toman without its unit, "۱۲۰٬۰۰۰" — for where the unit is set apart (a stat card). Toman has no fraction. */
export function formatAmount(value: number | string): string {
  return wholeFormat.format(Number(value))
}

/** "۱۲۰٬۰۰۰ تومان". */
export function formatMoney(value: number | string): string {
  return `${formatAmount(value)} ${MONEY_UNIT}`
}

/** A wallet as the bot words it (Messages::balance): "۸۰٬۰۰۰ تومان", below zero "۲۰٬۰۰۰ تومان بدهی" — an agent's credit in use. */
export function formatBalance(balance: string): string {
  return Number(balance) < 0 ? `${formatMoney(-Number(balance))} بدهی` : formatMoney(balance)
}

/** An agency level's price: "هر گیگابایت ۳٬۰۰۰ تومان". */
export function formatPricePerGb(price: string): string {
  return `هر گیگابایت ${formatMoney(price)}`
}

/** An amount as a form edits it: whole Toman in Latin digits ("120000.00" → "120000"), as the API takes it back. */
export function amountDraft(value: string): string {
  return String(Math.round(Number(value)))
}

/** What 0 means for a quota, a term or a number of devices — no limit — in the bot's one word for it (Messages::UNLIMITED). */
export const UNLIMITED = 'نامحدود'

/** A term: "۳۰ روز", 0 = نامحدود. */
export function formatDays(days: number): string {
  return days > 0 ? `${formatNumber(days)} روز` : UNLIMITED
}

/** Devices at once: "۲ دستگاه", 0 = نامحدود. */
export function formatDevices(count: number): string {
  return count > 0 ? `${formatNumber(count)} دستگاه` : UNLIMITED
}

/** A plan's traffic, in gigabytes as the plan holds it (decimals allowed): "۳۰ گیگابایت", 0 = نامحدود. */
export function formatGb(gb: number): string {
  return gb > 0 ? `${formatNumber(gb)} گیگابایت` : UNLIMITED
}

const BYTE_UNITS = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت']

/**
 * An amount of traffic or memory: "۱٫۵ گیگابایت", "۵۱۲ مگابایت"; none is "۰ گیگابایت", in the unit traffic is counted in
 * everywhere else (a quota of 0 is `UNLIMITED` — say so yourself).
 */
export function formatBytes(bytes: number): string {
  let value = Math.max(0, bytes)
  if (value === 0) return `${formatNumber(0)} گیگابایت`
  let unit = 0
  while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
    value /= 1024
    unit++
  }
  return `${(value < 10 ? oneDecimalFormat : wholeFormat).format(value)} ${BYTE_UNITS[unit]}`
}

/** "۳۰ دقیقه", "۲ ساعت", "۱ ساعت و ۳۰ دقیقه", "۱ روز" — a span of minutes, worded as the bot words it (Persian::minutes). */
export function formatDuration(minutes: number): string {
  minutes = Math.max(0, Math.round(minutes))
  if (minutes >= 1440 && minutes % 1440 === 0) return `${formatNumber(minutes / 1440)} روز`
  const parts: string[] = []
  if (minutes >= 60) parts.push(`${formatNumber(Math.floor(minutes / 60))} ساعت`)
  if (minutes % 60 !== 0 || parts.length === 0) parts.push(`${formatNumber(minutes % 60)} دقیقه`)
  return parts.join(' و ')
}

const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹'
const ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩'

/** Latin digits as Persian ones, one by one — no grouping, no zeroes dropped (a clock's "۰:۰۵"). */
function toPersianDigits(text: string): string {
  return text.replace(/[0-9]/g, (digit) => PERSIAN_DIGITS.charAt(Number(digit)))
}

/** Typed Persian/Arabic digits back to Latin: a typed number as the API takes it. */
export function toLatinDigits(text: string): string {
  return text.replace(/[۰-۹]/g, (digit) => String(PERSIAN_DIGITS.indexOf(digit))).replace(/[٠-٩]/g, (digit) => String(ARABIC_DIGITS.indexOf(digit)))
}

/**
 * Only the digits of what was typed (Persian ones too) — for a whole number the field takes nothing else of (a card's
 * digits, a page, minutes); an amount that may have decimals is `decimalOf`'s, never this, which would read «1.5» as 15.
 */
export function digitsOnly(text: string): string {
  return toLatinDigits(text).replace(/\D/g, '')
}

/**
 * An amount as typed — Persian or Arabic digits, «٬» «,» or spaces between thousands, «٫» «.» or the «/» a Persian hand
 * writes for the decimal point — the way the API takes one (Input::decimal: "1.5", two decimals at most); null when it
 * is no such number: never read as another one.
 */
export function decimalOf(typed: string): string | null {
  // Thousands set apart only between groups of three, as Input::decimalOf() reads them: «2,5» is no 25.
  const text = toLatinDigits(typed.trim()).replace(/٬/g, ',').replace(/\s+/g, ' ').replace(/[٫/]/g, '.')
  const match = /^(\d{1,3}(?:[, ]\d{3})+|\d{1,12})(\.\d{1,2})?$/.exec(text)
  const whole = match?.[1]?.replace(/[, ]/g, '') ?? ''
  return match && whole.length <= 12 ? whole + (match[2] ?? '') : null
}

/**
 * A typed amount as a request carries it: in the API's form when it reads as one (decimalOf), else as typed — for the API
 * to refuse in its own words.
 */
export function decimalInput(typed: string): string {
  return decimalOf(typed) ?? typed.trim()
}

/**
 * A whole number as an admin types it into a setting — "10000", "10,000", "۱۰٬۰۰۰" —, its thousands set apart only
 * between groups of three, as the API reads it (Input::wholeOf()): its digits, or null when it is no such number.
 */
export function wholeOf(typed: string): string | null {
  const number = decimalOf(typed)
  return number !== null && !number.includes('.') ? number : null
}

/**
 * The numbers of a list an admin types — "50000, 100000", "50,000 100,000", "۵۰٬۰۰۰، ۱۰۰٬۰۰۰" —, as the API reads them
 * (Input::numbersOf()): apart by spaces, «،» or a comma — but a comma between groups of three digits, which sets a
 * number's thousands apart —, each read as wholeOf() reads one; null when one is no such number.
 */
export function wholesOf(typed: string): string[] | null {
  const items =
    toLatinDigits(typed)
      .replace(/٬/g, ',')
      .match(/\d{1,3}(?:,\d{3})+(?!\d)|[^\s,،]+/g) ?? []
  const numbers = items.map(wholeOf)
  return numbers.every((number): number is string => number !== null) ? numbers : null
}

/** "6037997700001119" → "6037 9977 0000 1119"; partial input is grouped as far as it goes. */
export function formatCardNumber(digits: string): string {
  return digits.replace(/(\d{4})(?=\d)/g, '$1 ')
}

/** A moment the API sent (ISO 8601 with its offset, as the server writes every one), or one the panel has itself. */
type Moment = string | number | Date

const toDate = (moment: Moment): Date => new Date(moment)

/** A wait as a clock counts it down, minutes and seconds: "۱۴:۰۵", "۰:۴۵" (a refusal's Retry-After). */
export function formatCountdown(seconds: number): string {
  const whole = Math.max(0, Math.ceil(seconds))
  return toPersianDigits(`${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`)
}

const MINUTES_IN_DAY = 1_440
const MINUTES_IN_MONTH = 43_200
const MINUTES_IN_YEAR = 525_600

/** Rounded to the nearest whole, never "-0". */
const whole = (value: number): number => Math.round(value) || 0

/**
 * The time between two moments in its largest unit, rounded: "۴۰ ثانیه", "۵ دقیقه", "۳ ساعت", "۱۲ روز" (up to a month
 * of 30 days), "۴ ماه", "۲ سال" — 12 months are "۱ سال". Days and longer go by the calendar: an hour daylight saving took
 * or gave between the two is no part of them.
 */
function distance(from: Date, to: Date): string {
  const [start, end] = from.getTime() <= to.getTime() ? [from, to] : [to, from]
  const minutes = (end.getTime() - start.getTime()) / 60_000
  const calendarMinutes = minutes - (end.getTimezoneOffset() - start.getTimezoneOffset())
  if (minutes < 1) return `${formatNumber(whole(minutes * 60))} ثانیه`
  if (minutes < 60) return `${formatNumber(whole(minutes))} دقیقه`
  if (minutes < MINUTES_IN_DAY) return `${formatNumber(whole(minutes / 60))} ساعت`
  if (calendarMinutes < MINUTES_IN_MONTH) return `${formatNumber(whole(calendarMinutes / MINUTES_IN_DAY))} روز`
  if (calendarMinutes < MINUTES_IN_YEAR) {
    const months = whole(calendarMinutes / MINUTES_IN_MONTH)
    return months === 12 ? `${formatNumber(1)} سال` : `${formatNumber(months)} ماه`
  }
  return `${formatNumber(whole(calendarMinutes / MINUTES_IN_YEAR))} سال`
}

/** A span of seconds in its largest unit: "۳ روز", "۵ ساعت" (a panel's uptime). */
export function formatSpan(seconds: number): string {
  return distance(new Date(0), new Date(Math.max(0, seconds) * 1000))
}

/** How long ago: "۳ روز قبل" — or ahead, "در ۲ ساعت" —, "همین حالا" within the minute; "—" for none. */
export function timeAgo(moment: Moment | null | undefined): string {
  if (moment === null || moment === undefined || moment === '') return '—'
  const date = toDate(moment)
  const now = new Date()
  if (Math.abs(now.getTime() - date.getTime()) < 60_000) return 'همین حالا'
  return date > now ? `در ${distance(now, date)}` : `${distance(date, now)} قبل`
}

const DAY_MS = 86_400_000

/** How long until a deadline, as the bot words it for the customer: "۱۲ روز مانده", "کمتر از یک روز مانده", "تمام شده". */
export function timeLeft(iso: string): string {
  const days = (toDate(iso).getTime() - Date.now()) / DAY_MS

  return days <= 0 ? 'تمام شده' : days < 1 ? 'کمتر از یک روز مانده' : `${formatNumber(Math.ceil(days))} روز مانده`
}

/** The zone dates and times read in: the shop's, once /api/app said it; the browser's own until then. */
let shopTimeZone: string | undefined

/**
 * Show every date and time in the shop's zone (APP_TIMEZONE, which GET /api/app says — lib/app-info): the zone the bot
 * words its dates in for the customer. A zone the browser does not know leaves the browser's own.
 */
export function setShopTimeZone(zone: string | undefined): void {
  try {
    shopTimeZone = zone ? new Intl.DateTimeFormat(LOCALE, { timeZone: zone }).resolvedOptions().timeZone : undefined
  } catch {
    shopTimeZone = undefined
  }
}

/**
 * The zone dates and times read in now, by its name ("Asia/Tehran"): the shop's (`shop`) once /api/app said it, else the
 * browser's own — what today's day is in (lib/jalali), and what a moment shown before the shop's zone is known names.
 */
export function shownTimeZone(): { zone: string; shop: boolean } {
  return shopTimeZone === undefined ? { zone: new Intl.DateTimeFormat().resolvedOptions().timeZone, shop: false } : { zone: shopTimeZone, shop: true }
}

const dateFormats = new Map<string, Intl.DateTimeFormat>()

/** The Jalali calendar's formatter for these options, made once per set of options (the zone among them). */
function jalali(options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  const key = JSON.stringify(options)
  let format = dateFormats.get(key)
  if (!format) {
    format = new Intl.DateTimeFormat(JALALI, options)
    dateFormats.set(key, format)
  }
  return format
}

/** Absolute Jalali date-time in the shop's zone, e.g. "۲۶ شهریور ۱۴۰۵، ۱۸:۳۰"; "—" for none. */
export function formatDate(moment: Moment | null | undefined, options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' }): string {
  if (moment === null || moment === undefined || moment === '') return '—'
  return jalali({ ...options, timeZone: shopTimeZone }).format(toDate(moment))
}

/**
 * A day of the shop's calendar as the API sends one ("2026-09-17" — the dashboard's days, the shop's already) as a short
 * Jalali day for chart axes and tooltips, "۲۶ شهریور": that very day, in whatever zone the browser is.
 */
export function formatDay(day: string, options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' }): string {
  const [year = NaN, month = NaN, date = NaN] = day.split('-').map(Number)
  return jalali({ ...options, timeZone: 'UTC' }).format(Date.UTC(year, month - 1, date))
}

/** The first letters of a name's first two words — of a handle («@agent_bot»), its first letter, not the «@». */
export function initials(name: string): string {
  return name
    .trim()
    .replace(/^@/, '')
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part.charAt(0))
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
