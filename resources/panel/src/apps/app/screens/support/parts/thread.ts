import { formatDay } from '@/lib/format'
import { addDays, shopClock, today, type Day } from '@/lib/jalali'

/*
 * A conversation laid out as a messenger lays it out: its messages under the day they were written — «امروز», «دیروز»,
 * else the Jalali date, on the shop's clock —, and in a day the runs one side wrote in a row, the last of a run drawn
 * with the bubble's tail.
 */

/** The shop's day a moment fell on; today for none (a message being written now). */
export function dayOf(moment: string | null): Day {
  return moment === null ? today() : shopClock(moment).day
}

/** A day's Jalali year, to tell whether it is this year's. */
const year = (day: Day) => formatDay(day, { year: 'numeric' })

/** A day's separator (a ledger's day heading too, wallet/parts/days): «امروز», «دیروز», else its Jalali date — with its year when it is not this year's. */
export function dayLabel(day: Day): string {
  const now = today()
  if (day === now) return 'امروز'
  if (day === addDays(now, -1)) return 'دیروز'

  return formatDay(day, year(day) === year(now) ? { day: 'numeric', month: 'long' } : { day: 'numeric', month: 'long', year: 'numeric' })
}

/** An item of the thread; `last` when it ends its side's run that day (the bubble with the tail). */
interface Placed<T> {
  item: T
  last: boolean
}

export interface ThreadDay<T> {
  day: Day
  items: Placed<T>[]
}

/** The items (oldest first) under their days, each marked when it ends its side's run. */
export function threadOf<T>(items: readonly T[], at: (item: T) => string | null, mine: (item: T) => boolean): ThreadDay<T>[] {
  const days: ThreadDay<T>[] = []
  items.forEach((item, index) => {
    const day = dayOf(at(item))
    const next = items[index + 1]
    const last = next === undefined || mine(next) !== mine(item) || dayOf(at(next)) !== day
    const current = days.at(-1)
    if (current !== undefined && current.day === day) current.items.push({ item, last })
    else days.push({ day, items: [{ item, last }] })
  })

  return days
}
