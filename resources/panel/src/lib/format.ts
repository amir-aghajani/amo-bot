/*
 * Every quantity, amount and date the panels show, in Persian: Persian digits, the Jalali calendar, Toman for money.
 * Identifiers (ids, ports, versions) keep their Latin digits and never come through here. Dates are shown in the shop's
 * time zone (setShopTimeZone), as the bot words them for customers, whatever zone the browser is in. Where a formatter
 * mirrors the bot's wording, its PHP counterpart is named (Messages::…).
 */

const LOCALE = 'fa-IR'
const JALALI = 'fa-IR-u-ca-persian'

const numberFormat = new Intl.NumberFormat(LOCALE)
const wholeFormat = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 })
const oneDecimalFormat = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 1 })
const compactFormat = new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 })

/** `value` in `format`, never «−۰»: a value below zero that rounds to nothing (-0.4 Toman, a "-0.00") is zero. */
function signed(format: Intl.NumberFormat, value: number): string {
  const magnitude = format.format(Math.abs(value))
  return value < 0 && magnitude !== format.format(0) ? format.format(value) : magnitude
}

export function formatNumber(value: number | string): string {
  return signed(numberFormat, Number(value))
}

/** "۱۲ هزار" — a chart's axis, where the unit is the title's. */
export function formatCompact(value: number | string): string {
  return signed(compactFormat, Number(value))
}

/** A share: "۱۲٫۵٪". */
export function formatPercent(value: number): string {
  return `${signed(oneDecimalFormat, value)}٪`
}

/** A change against a baseline, signed: "+۱۲٫۵٪" / "−۳٪" — a change that rounds to nothing is "۰٪", unsigned. */
export function formatChange(value: number): string {
  const shown = formatPercent(Math.abs(value))
  const sign = shown === formatPercent(0) ? '' : value > 0 ? '+' : '−'

  return `${sign}${shown}`
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
  return signed(wholeFormat, Number(value))
}

/** "۱۲۰٬۰۰۰ تومان". */
export function formatMoney(value: number | string): string {
  return `${formatAmount(value)} ${MONEY_UNIT}`
}

/** A wallet as the bot words it (Messages::balance): "۸۰٬۰۰۰ تومان", below zero "۲۰٬۰۰۰ تومان بدهی" — an agent's credit in use. */
export function formatBalance(balance: string): string {
  const amount = Number(balance)
  return amount < 0 && formatAmount(-amount) !== formatAmount(0) ? `${formatMoney(-amount)} بدهی` : formatMoney(amount)
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

/** A number of days (extra time sold, an order's): "۳۰ روز", 0 = نامحدود. */
export function formatDays(days: number): string {
  return days > 0 ? `${formatNumber(days)} روز` : UNLIMITED
}

const HOURS_IN_DAY = 24
const MINUTES_IN_DAY = 1_440

/** A term in hours (`duration_hours`) as the bot words it (Messages::duration): "۳۰ روز", "۶ ساعت", "۱ روز و ۶ ساعت"; 0 = نامحدود. */
export function formatDuration(hours: number): string {
  if (hours <= 0) return UNLIMITED
  const parts: string[] = []
  if (hours >= HOURS_IN_DAY) parts.push(`${formatNumber(Math.floor(hours / HOURS_IN_DAY))} روز`)
  if (hours % HOURS_IN_DAY !== 0) parts.push(`${formatNumber(hours % HOURS_IN_DAY)} ساعت`)
  return parts.join(' و ')
}

/** Devices at once — a plan's, a service's, «کاربر اضافه» bought —, as the bot counts them (Messages::devices): "۲ کاربر", 0 = نامحدود. */
export function formatDevices(count: number): string {
  return count > 0 ? `${formatNumber(count)} کاربر` : UNLIMITED
}

/** A plan's traffic, in gigabytes as the plan holds it (decimals allowed): "۳۰ گیگابایت", 0 = نامحدود. */
export function formatGb(gb: number): string {
  return gb > 0 ? `${formatNumber(gb)} گیگابایت` : UNLIMITED
}

const BYTE_UNITS = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت']

/** A megabyte in bytes: traffic kept in whole MB (a test's) as formatBytes takes it. */
export const MEGABYTE = 1024 ** 2

/**
 * An amount of traffic or memory in its largest unit: "۱٫۵ گیگابایت", "۵۱۲ مگابایت"; zero is "۰ گیگابایت". (A quota of
 * 0 means unlimited — the caller says `UNLIMITED` itself.)
 */
export function formatBytes(bytes: number): string {
  let value = Math.max(0, bytes)
  if (value === 0) return `${formatNumber(0)} گیگابایت`
  let unit = 0
  // A value that rounds to 1024 is the next unit's one: "۱ گیگابایت", never "۱٬۰۲۴ مگابایت".
  while (Math.round(value) >= 1024 && unit < BYTE_UNITS.length - 1) {
    value /= 1024
    unit++
  }
  return `${(value < 10 ? oneDecimalFormat : wholeFormat).format(value)} ${BYTE_UNITS[unit]}`
}

/** "۳۰ دقیقه", "۲ ساعت", "۱ ساعت و ۳۰ دقیقه", "۱ روز" — a span of minutes, worded as the bot words it (Persian::minutes). */
export function formatMinutes(minutes: number): string {
  minutes = Math.max(0, Math.round(minutes))
  if (minutes >= MINUTES_IN_DAY && minutes % MINUTES_IN_DAY === 0) return `${formatNumber(minutes / MINUTES_IN_DAY)} روز`
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
 * The digits of what was typed (Persian ones too), for a field that takes nothing else (a card number, a page, minutes).
 * Never for an amount: «1.5» would read as 15 — use `decimalOf`.
 */
export function digitsOnly(text: string): string {
  return toLatinDigits(text).replace(/\D/g, '')
}

/**
 * A typed amount in the API's form ("1.5", two decimals at most, as Input::decimal reads it): Persian or Arabic digits,
 * «٬» «,» or spaces between groups of three, «٫» «.» or «/» for the decimal point. Null for anything else — never
 * misread as another number.
 */
export function decimalOf(typed: string): string | null {
  // Thousands set apart only between groups of three, as Input::decimalOf() reads them: «2,5» is no 25.
  const text = toLatinDigits(typed.trim()).replace(/٬/g, ',').replace(/\s+/g, ' ').replace(/[٫/]/g, '.')
  const match = /^(\d{1,3}(?:[, ]\d{3})+|\d{1,12})(\.\d{1,2})?$/.exec(text)
  const whole = match?.[1]?.replace(/[, ]/g, '') ?? ''
  return match && whole.length <= 12 ? whole + (match[2] ?? '') : null
}

/** The words Input::amountOf() multiplies an amount by: «۵۰ هزار» is 50,000. */
const SCALES: Readonly<Record<string, number>> = { هزار: 1_000, میلیون: 1_000_000 }

/**
 * An amount of money with what Input::amountOf() takes after it (Unit::Toman): «هزار» or «میلیون», then a word of Toman
 * — any other word is no amount, so «۵۰۰ ریال» is never read as 500 Toman, nor «۲۰ گیگ» as 20.
 */
const AMOUNT_WORDS = /^(.*?)[\s\u{200C}]*(هزار|میلیون)?[\s\u{200C}]*(?:تومانی|تومان|تومنی|تومن|ت|toman)?$/iu

/** The most an amount reads as (Input::amountOf()): twelve digits. */
const AMOUNT_MOST = 999_999_999_999

/**
 * A typed price as whole Toman — «هزار» or «میلیون» and a word of Toman after it allowed («۳۰٬۰۰۰ تومان», «۵۰ هزار تومان») —, read
 * the way the server reads it (`Input::amountOf()`); null for anything else.
 */
export function tomanOf(typed: string): number | null {
  const [, typedNumber = '', scale = ''] = AMOUNT_WORDS.exec(typed.trim().replace(/ي/g, 'ی').replace(/ك/g, 'ک')) ?? []
  const number = decimalOf(typedNumber)
  if (number === null || !/^\d+(?:\.0{1,2})?$/.test(number)) return null
  const amount = Number.parseInt(number, 10) * (SCALES[scale] ?? 1)
  return amount <= AMOUNT_MOST ? amount : null
}

/** A typed amount as a request sends it: the API's form when it is one (decimalOf), else as typed, for the API to refuse. */
export function decimalInput(typed: string): string {
  return decimalOf(typed) ?? typed.trim()
}

/** A typed whole number ("10000", "10,000", "۱۰٬۰۰۰") as its digits, read as Input::wholeOf() reads it; else null. */
export function wholeOf(typed: string): string | null {
  const number = decimalOf(typed)
  return number !== null && !number.includes('.') ? number : null
}

/** A typed whole number (wholeOf) as a number, for a hint or a preview to work with; null while it is none. */
export function wholeNumberOf(typed: string): number | null {
  const number = wholeOf(typed)
  return number === null ? null : Number(number)
}

/**
 * A typed list of whole numbers ("50000, 100000", "50,000 100,000", "۵۰٬۰۰۰، ۱۰۰٬۰۰۰"), read as Input::numbersOf()
 * reads it: separated by spaces, «،» or commas — except a comma between groups of three digits, which is a thousands
 * separator. Null when one item is not a whole number.
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

const MINUTES_IN_MONTH = 43_200
const MINUTES_IN_YEAR = 525_600

/** Rounded to the nearest whole, never "-0". */
const whole = (value: number): number => Math.round(value) || 0

/**
 * The time between two moments in its largest unit, rounded: "۴۰ ثانیه", "۵ دقیقه", "۳ ساعت", "۱۲ روز", "۴ ماه",
 * "۲ سال" (a month is 30 days; 12 months are "۱ سال"). Days and longer ignore a daylight-saving hour in between.
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

const HOUR_MS = 3_600_000
const DAY_MS = 86_400_000

/** Whether a deadline is less than a day away — not past yet: then its hour matters, and the hours left are counted. */
const withinADay = (left: number): boolean => left > 0 && left < DAY_MS

/** How long until a deadline, rounded up, as the bot words it (Messages::expiry): "۱۲ روز مانده", "۵ ساعت مانده", "تمام شده". */
export function timeLeft(iso: string): string {
  const left = toDate(iso).getTime() - Date.now()

  if (left <= 0) return 'تمام شده'
  return withinADay(left) ? `${formatNumber(Math.ceil(left / HOUR_MS))} ساعت مانده` : `${formatNumber(Math.ceil(left / DAY_MS))} روز مانده`
}

/** The zone dates are shown in: the shop's once /api/app said it, the browser's until then. */
let shopTimeZone: string | undefined

/** Show every date in the shop's zone (APP_TIMEZONE, from /api/app). A zone the browser does not know is ignored. */
export function setShopTimeZone(zone: string | undefined): void {
  try {
    shopTimeZone = zone ? new Intl.DateTimeFormat(LOCALE, { timeZone: zone }).resolvedOptions().timeZone : undefined
  } catch {
    shopTimeZone = undefined
  }
}

/** The zone dates are shown in now ("Asia/Tehran"), and whether it is the shop's (`shop`) or still the browser's. */
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

/** A service's end as the bot words it (Messages::expiry): the day, and its hour too when it is less than a day away. */
export function formatDeadline(iso: string): string {
  return withinADay(toDate(iso).getTime() - Date.now()) ? formatDate(iso) : formatDate(iso, { dateStyle: 'medium' })
}

/**
 * A calendar day the API sends ("2026-09-17", already the shop's day) as a short Jalali day, "۲۶ شهریور" — the same day
 * whatever the browser's zone (it is read at UTC).
 */
export function formatDay(day: string, options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' }): string {
  const [year = NaN, month = NaN, date = NaN] = day.split('-').map(Number)
  return jalali({ ...options, timeZone: 'UTC' }).format(Date.UTC(year, month - 1, date))
}

/**
 * The first letters of a name's first two words that have one: a handle («@agent_bot») gives its first letter, not the
 * «@», and a word of emoji (Telegram names often start with one) gives nothing — never half of one.
 */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .map((word) => /[\p{L}\p{N}]/u.exec(word)?.[0] ?? '')
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase()
}
