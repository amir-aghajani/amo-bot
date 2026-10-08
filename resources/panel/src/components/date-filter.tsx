import { useRef, useState } from 'react'
import { Select as SelectPrimitive } from 'radix-ui'
import { DateRangePicker, fullDay, type DayRange } from '@/components/date-range-picker'
import { FilterPillTrigger } from '@/components/filter-select'
import { SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator } from '@/components/ui/select'
import { formatDay } from '@/lib/format'
import { addDays, isDay, monthRange, shiftMonth, today, toJalali, type Day } from '@/lib/jalali'

/** The days a preset names, counted back from today — the shop's day, as the server bounds the list's days. */
const PRESETS: { value: string; label: string; range: (today: Day) => DayRange }[] = [
  { value: 'today', label: 'امروز', range: (now) => ({ from: now, to: now }) },
  { value: 'yesterday', label: 'دیروز', range: (now) => ({ from: addDays(now, -1), to: addDays(now, -1) }) },
  { value: 'week', label: '۷ روز اخیر', range: (now) => ({ from: addDays(now, -6), to: now }) },
  { value: 'thirty', label: '۳۰ روز اخیر', range: (now) => ({ from: addDays(now, -29), to: now }) },
  { value: 'this-month', label: 'این ماه', range: (now) => monthRange(toJalali(now).year, toJalali(now).month) },
  {
    value: 'last-month',
    label: 'ماه قبل',
    range: (now) => {
      const { year, month } = shiftMonth(toJalali(now), -1)
      return monthRange(year, month)
    },
  },
]

/* Radix keeps '' for "no value": «همه» travels under a name of its own, and so does «بازه دلخواه…», which is never the
   value — choosing it opens the picker, every time —, as does a range no preset names, which no choice is. */
const ALL = '__all__'
const CUSTOM = '__custom__'
const RANGE = '__range__'

/** A range in a few words: «۱ مهر تا ۱۴ مهر» — with the years when it is not this one. */
function rangeLabel({ from, to }: DayRange, now: Day): string {
  const year = toJalali(now).year
  const word = (day: Day) => (toJalali(day).year === year ? formatDay(day) : fullDay(day))
  if (from && to) return from === to ? word(from) : `${word(from)} تا ${word(to)}`
  return from ? `از ${word(from)}` : `تا ${word(to)}`
}

interface DateFilterProps {
  /** The list's days now ('' = open at that end). */
  range: DayRange
  onChange: (range: DayRange) => void
  /** Which moment of a row the days are of, said in the choices and on the picker: «بر اساس زمان ثبت سفارش». */
  basis: string
}

/**
 * A list's «تاریخ» filter, in the pill of the other filters (FilterSelect's look): «همه», the presets — today, yesterday,
 * the last 7 and 30 days, this Jalali month and the last, the shop's days — and «بازه دلخواه…», which opens the Persian
 * calendar (DateRangePicker). What it holds are the days themselves (`from`, `to`), so a preset is the days it named when
 * chosen.
 */
export function DateFilter({ range: given, onChange, basis }: DateFilterProps) {
  const [picking, setPicking] = useState(false)
  // «بازه دلخواه…» opens the calendar once the list has closed and handed the focus back to the pill: the calendar takes
  // the pill for what opened it — the option is gone with its list — and gives it the focus back as it closes.
  const custom = useRef(false)
  const now = today()
  // What is no day (a hand-edited address) narrows nothing — the API takes it for none as well.
  const range: DayRange = { from: isDay(given.from) ? given.from : '', to: isDay(given.to) ? given.to : '' }
  const preset = PRESETS.find((option) => {
    const named = option.range(now)
    return named.from === range.from && named.to === range.to
  })
  const value = !range.from && !range.to ? ALL : (preset?.value ?? RANGE)
  const label = value === ALL ? 'همه' : (preset?.label ?? rangeLabel(range, now))

  const choose = (next: string) => {
    if (next === CUSTOM) custom.current = true
    else if (next === ALL) onChange({ from: '', to: '' })
    else {
      const chosen = PRESETS.find((option) => option.value === next)
      if (chosen) onChange(chosen.range(now))
    }
  }

  // Called as the list closes, before it focuses the pill; the calendar is drawn after, the focus on the pill by then.
  const closed = () => {
    if (!custom.current) return
    custom.current = false
    setPicking(true)
  }

  return (
    <>
      <SelectPrimitive.Root value={value} onValueChange={choose}>
        <FilterPillTrigger label="تاریخ" value={label} />
        <SelectContent className="min-w-48" onCloseAutoFocus={closed}>
          <SelectGroup>
            <SelectLabel>{basis}</SelectLabel>
            <SelectItem value={ALL}>همه</SelectItem>
            <SelectSeparator />
            {PRESETS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
            <SelectSeparator />
            <SelectItem value={CUSTOM}>بازه دلخواه…</SelectItem>
          </SelectGroup>
        </SelectContent>
      </SelectPrimitive.Root>

      <DateRangePicker
        open={picking}
        range={range}
        basis={basis}
        onApply={(days) => {
          setPicking(false)
          onChange(days)
        }}
        onClose={() => setPicking(false)}
      />
    </>
  )
}
