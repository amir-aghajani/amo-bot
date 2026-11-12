import { dayLabel, dayOf } from '@/apps/app/screens/support/parts/thread'

/*
 * A list of the newest first, set apart by day as a chat or a bank's statement sets it: each day's heading as a
 * conversation's (support/parts/thread) — «امروز», «دیروز», else its Jalali date, on the shop's clock — over its rows.
 */

export interface Day<T> {
  /** The day's heading. */
  label: string
  rows: T[]
}

/** Rows (newest first) by the day of `at`, in their order; a row of no moment under «—». */
export function byDay<T>(rows: readonly T[], at: (row: T) => string | null): Day<T>[] {
  const days: Day<T>[] = []
  for (const row of rows) {
    const moment = at(row)
    const label = moment === null ? '—' : dayLabel(dayOf(moment))
    const last = days.at(-1)
    if (last?.label === label) last.rows.push(row)
    else days.push({ label, rows: [row] })
  }
  return days
}
