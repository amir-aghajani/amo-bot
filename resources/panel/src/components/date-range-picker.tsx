import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { FormActions } from '@/components/form-footer'
import { IconButton } from '@/components/icon-button'
import { Modal } from '@/components/modal'
import { isRtl } from '@/lib/direction'
import { formatDay, formatNumber } from '@/lib/format'
import { addDays, fromJalali, monthLength, shiftMonth, today, toJalali, weekday, type Day } from '@/lib/jalali'
import { cn } from '@/lib/utils'

/** The days a list is narrowed to: its first and its last, both included — '' leaves that end open. */
export interface DayRange {
  from: Day | ''
  to: Day | ''
}

/** A month of the Persian calendar. */
interface Month {
  year: number
  month: number
}

/** The Persian week, from Saturday: the grid's headings, and their names for assistive tech. */
const WEEK = [
  { short: 'ش', name: 'شنبه' },
  { short: 'ی', name: 'یکشنبه' },
  { short: 'د', name: 'دوشنبه' },
  { short: 'س', name: 'سه‌شنبه' },
  { short: 'چ', name: 'چهارشنبه' },
  { short: 'پ', name: 'پنجشنبه' },
  { short: 'ج', name: 'جمعه' },
]

/** A day in words, with its year: «۱۴ مهر ۱۴۰۵» — that very day, whatever the zone. */
export function fullDay(day: Day): string {
  return formatDay(day, { day: 'numeric', month: 'long', year: 'numeric' })
}

const monthOf = (day: Day): Month => {
  const { year, month } = toJalali(day)
  return { year, month }
}

/** The same day of the month `months` away — the month's last when it has fewer days. */
function sameDayIn(day: Day, months: number): Day {
  const date = toJalali(day)
  const target = shiftMonth(date, months)
  return fromJalali({ ...target, day: Math.min(date.day, monthLength(target.year, target.month)) })
}

interface DateRangePickerProps {
  open: boolean
  /** The days the list is narrowed to now: where the picker starts. */
  range: DayRange
  /** Which moment of a row the days are of, under the title: «بر اساس زمان ثبت سفارش». */
  basis: string
  onApply: (range: { from: Day; to: Day }) => void
  onClose: () => void
}

/**
 * «بازه دلخواه»: a month of the Persian calendar at a time — Persian digits, the week from Saturday, today (the shop's)
 * marked —, a first press for the range's first day and a second for its last (a single day is applied as it is), the
 * keyboard's way through it as a grid's (arrows, Home/End for the week, PageUp/PageDown for the month), and Escape to
 * leave it. It opens with the focus on the range's last day, or today; closed, the focus goes back to what opened it.
 */
export function DateRangePicker({ open, range, basis, onApply, onClose }: DateRangePickerProps) {
  return (
    <Modal open={open} onClose={onClose} size="sm" title="بازه دلخواه" description={basis}>
      {/* Mounted for each opening: it starts from the list's days, not from the last draft. */}
      {open && <RangeForm range={range} onApply={onApply} onClose={onClose} />}
    </Modal>
  )
}

function RangeForm({ range, onApply, onClose }: Pick<DateRangePickerProps, 'range' | 'onApply' | 'onClose'>) {
  const [draft, setDraft] = useState<DayRange>(range)
  const [focused, setFocused] = useState<Day>(() => range.to || range.from || today())
  const [shown, setShown] = useState<Month>(() => monthOf(focused))
  const [error, setError] = useState<string | null>(null)

  const pick = (day: Day) => {
    setFocused(day)
    setError(null)
    setDraft((current) => (!current.from || current.to ? { from: day, to: '' } : day < current.from ? { from: day, to: current.from } : { from: current.from, to: day }))
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!draft.from) {
      setError('هنوز روزی انتخاب نشده است.')
      return
    }
    onApply({ from: draft.from, to: draft.to || draft.from })
  }

  return (
    <form onSubmit={submit} className="grid gap-4" noValidate>
      <MonthGrid
        shown={shown}
        focused={focused}
        draft={draft}
        onFocus={(day) => {
          setFocused(day)
          setShown(monthOf(day))
        }}
        onPick={pick}
      />
      <p aria-live="polite" className="text-footnote text-muted-foreground">
        {draft.from && draft.to
          ? `از ${fullDay(draft.from)} تا ${fullDay(draft.to)}`
          : draft.from
            ? `از ${fullDay(draft.from)} — روز آخر را انتخاب کنید، یا همین یک روز را اعمال کنید.`
            : 'روز اول بازه را انتخاب کنید.'}
      </p>
      <FormActions submitLabel="اعمال" onCancel={onClose} error={error} />
    </form>
  )
}

interface MonthGridProps {
  shown: Month
  focused: Day
  draft: DayRange
  /** The day the keyboard (or a month's arrow) moved to — the grid shows its month. */
  onFocus: (day: Day) => void
  onPick: (day: Day) => void
}

/**
 * The month as a grid of its days, the week's first column at the inline start. One day takes the Tab stop (the focused
 * one) — and the focus as the picker opens — and the keys move it; a day outside the month brings its month. Between a
 * picked first day and the day under the pointer the range is drawn before the second press. On a phone a day is a
 * finger's size (44px, as the row's width allows), the columns sharing the row evenly.
 */
function MonthGrid({ shown, focused, draft, onFocus, onPick }: MonthGridProps) {
  const caption = useId()
  const grid = useRef<HTMLTableElement>(null)
  // The keys moved the focus: the day it moved to takes it once it is drawn.
  const moved = useRef(false)
  const [hovered, setHovered] = useState<Day | null>(null)

  useEffect(() => {
    if (!moved.current) return
    moved.current = false
    grid.current?.querySelector<HTMLButtonElement>(`[data-day="${focused}"]`)?.focus()
  }, [focused])

  const first = fromJalali({ ...shown, day: 1 })
  const days = Array.from({ length: monthLength(shown.year, shown.month) }, (_, index) => addDays(first, index))
  const cells: (Day | null)[] = [...Array<null>(weekday(first)).fill(null), ...days]
  while (cells.length % 7 !== 0) cells.push(null)
  const weeks = Array.from({ length: cells.length / 7 }, (_, row) => cells.slice(row * 7, row * 7 + 7))

  const now = today()
  // The range drawn: the picked one, or — its first day picked — up to the day under the pointer.
  const end = draft.to || (draft.from && hovered ? hovered : draft.from)
  const low = end < draft.from ? end : draft.from
  const high = end < draft.from ? draft.from : end

  const move = (to: Day) => {
    moved.current = true
    onFocus(to)
  }

  // Right to left, the next day is the one on the left.
  const forward = isRtl() ? 'ArrowLeft' : 'ArrowRight'
  const backward = isRtl() ? 'ArrowRight' : 'ArrowLeft'
  const STEPS: Record<string, (day: Day) => Day> = {
    [forward]: (day) => addDays(day, 1),
    [backward]: (day) => addDays(day, -1),
    ArrowDown: (day) => addDays(day, 7),
    ArrowUp: (day) => addDays(day, -7),
    Home: (day) => addDays(day, -weekday(day)),
    End: (day) => addDays(day, 6 - weekday(day)),
    PageDown: (day) => sameDayIn(day, 1),
    PageUp: (day) => sameDayIn(day, -1),
  }

  const onKeyDown = (event: KeyboardEvent<HTMLTableElement>) => {
    const step = STEPS[event.key]
    if (!step) return
    event.preventDefault()
    move(step(focused))
  }

  return (
    <div className="grid gap-2">
      <div className="flex items-center gap-2">
        <IconButton aria-label="ماه قبل" onClick={() => onFocus(sameDayIn(focused, -1))}>
          <ChevronLeft className="size-4 rtl:rotate-180" aria-hidden />
        </IconButton>
        <h3 id={caption} aria-live="polite" className="flex-1 text-center text-heading font-medium">
          {formatDay(first, { month: 'long' })} {formatDay(first, { year: 'numeric' })}
        </h3>
        <IconButton aria-label="ماه بعد" onClick={() => onFocus(sameDayIn(focused, 1))}>
          <ChevronRight className="size-4 rtl:rotate-180" aria-hidden />
        </IconButton>
      </div>

      <table ref={grid} role="grid" aria-labelledby={caption} onKeyDown={onKeyDown} onMouseLeave={() => setHovered(null)} className="w-full table-fixed border-collapse">
        <thead>
          <tr>
            {WEEK.map(({ short, name }) => (
              <th key={name} scope="col" className="pb-1 text-caption font-normal text-faint">
                <span aria-hidden>{short}</span>
                <span className="sr-only">{name}</span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {weeks.map((week) => (
            <tr key={week.find((day) => day !== null)}>
              {week.map((day, column) => {
                if (day === null) return <td key={`blank-${column}`} />
                const edge = day === draft.from || day === draft.to
                const within = low !== '' && day > low && day < high
                return (
                  <td key={day} className="p-0 text-center md:p-0.5">
                    <button
                      type="button"
                      data-day={day}
                      data-autofocus={day === focused || undefined}
                      tabIndex={day === focused ? 0 : -1}
                      aria-label={fullDay(day)}
                      aria-pressed={edge}
                      aria-current={day === now ? 'date' : undefined}
                      onClick={() => onPick(day)}
                      onFocus={() => onFocus(day)}
                      onMouseEnter={() => setHovered(day)}
                      className={cn(
                        'relative mx-auto flex h-11 w-full max-w-11 items-center justify-center rounded-lg border border-transparent text-body tabular transition-colors duration-100 outline-none hover:bg-fill-hover focus-visible:focus-ring md:size-9',
                        within && 'bg-info-soft/40',
                        edge && 'border-selected bg-info-soft font-medium hover:bg-info-soft',
                        day === now && 'font-semibold after:absolute after:bottom-1 after:size-1 after:rounded-full after:bg-current',
                      )}
                    >
                      {formatNumber(days.indexOf(day) + 1)}
                    </button>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
