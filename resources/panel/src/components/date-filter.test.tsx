import { useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DateFilter } from '@/components/date-filter'
import type { DayRange } from '@/components/date-range-picker'
import { setShopTimeZone } from '@/lib/format'
import { until } from '@/test/render'

/*
 * A list's «تاریخ» filter (components/date-filter) and the Persian calendar behind «بازه دلخواه…»
 * (components/date-range-picker): the pill says the days in words — a preset's name, or the days —, a preset hands the
 * days it names — the shop's days, whatever the browser's clock says —, the calendar shows a Jalali month in Persian
 * digits from Saturday with today marked, opens with the focus on a day and gives it back to the pill, two presses make
 * a range in order (one, a single day), the keys walk it right to left, and Escape leaves it with nothing changed.
 * Today is 14 مهر 1405 (2026-10-06, a Tuesday), the browser in UTC and the shop's zone not known yet.
 */

const BASIS = 'بر اساس زمان ثبت سفارش'

/** The filter as a list holds it: its days, what it was handed. */
function Filtered({ initial = { from: '', to: '' }, onChange }: { initial?: DayRange; onChange: (range: DayRange) => void }) {
  const [range, setRange] = useState(initial)
  return (
    <DateFilter
      range={range}
      basis={BASIS}
      onChange={(next) => {
        setRange(next)
        onChange(next)
      }}
    />
  )
}

function show(initial?: DayRange) {
  const onChange = vi.fn<(range: DayRange) => void>()
  render(<Filtered initial={initial} onChange={onChange} />)
  return onChange
}

const pill = () => screen.getByRole('combobox')

/** The pill opened, and `label` chosen among its options. */
function choose(label: string) {
  fireEvent.keyDown(pill(), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: label }))
}

const calendar = () => screen.getByRole('dialog', { name: 'بازه دلخواه' })
const day = (name: string) => within(calendar()).getByRole('button', { name })
/** The day the keys moved the focus to, by its name. */
const focused = () => (document.activeElement as HTMLElement | null)?.getAttribute('aria-label')

/** «بازه دلخواه…» chosen: the calendar opens once the list has closed. */
async function openCalendar() {
  choose('بازه دلخواه…')
  await until(() => expect(calendar()).toBeTruthy())
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(2026, 9, 6, 12))
  document.documentElement.dir = 'rtl'
})

afterEach(() => {
  document.documentElement.removeAttribute('dir')
  setShopTimeZone(undefined)
})

describe('the pill', () => {
  it('reads «همه» with no days, and a preset hands the days it names', () => {
    const onChange = show()
    expect(pill().textContent).toBe('تاریخهمه')

    choose('۷ روز اخیر')
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-09-30', to: '2026-10-06' })
    expect(pill().textContent).toContain('۷ روز اخیر')

    choose('ماه قبل')
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-08-23', to: '2026-09-22' })

    choose('همه')
    expect(onChange).toHaveBeenLastCalledWith({ from: '', to: '' })
  })

  it('says which date a row is filtered by', () => {
    show()
    fireEvent.keyDown(pill(), { key: 'Enter' })
    expect(within(screen.getByRole('listbox')).getByText(BASIS)).toBeTruthy()
  })

  it('reads days no preset names as the days themselves, with their years when not this one', () => {
    show({ from: '2026-09-23', to: '2026-10-01' })
    expect(pill().textContent).toContain('۱ مهر تا ۹ مهر')
  })

  it('reads a range of another year with its years, and an open end as «از» / «تا»', () => {
    const { unmount } = render(<DateFilter range={{ from: '2025-03-01', to: '2025-03-10' }} basis={BASIS} onChange={vi.fn()} />)
    expect(pill().textContent).toContain('۱۱ اسفند ۱۴۰۳ تا ۲۰ اسفند ۱۴۰۳')
    unmount()
    render(<DateFilter range={{ from: '2026-10-01', to: '' }} basis={BASIS} onChange={vi.fn()} />)
    expect(pill().textContent).toContain('از ۹ مهر')
  })

  it('takes what is no day for no day at all', () => {
    render(<DateFilter range={{ from: 'yesterday', to: '2026-02-31' }} basis={BASIS} onChange={vi.fn()} />)
    expect(pill().textContent).toBe('تاریخهمه')
  })
})

describe('the shop’s days', () => {
  // 00:05 in Tehran on 15 مهر (2026-10-07) — the browser's UTC still on the 14th, 20:35.
  beforeEach(() => {
    vi.setSystemTime(new Date('2026-10-06T20:35:00Z'))
    setShopTimeZone('Asia/Tehran')
  })

  it('are what a preset names, whatever the browser’s clock says', () => {
    const onChange = show()

    choose('امروز')
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-10-07', to: '2026-10-07' })
    expect(pill().textContent).toContain('امروز')
    choose('دیروز')
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-10-06', to: '2026-10-06' })
    choose('۷ روز اخیر')
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-10-01', to: '2026-10-07' })
  })

  it('mark today on the calendar', async () => {
    show()
    await openCalendar()

    expect(day('۱۵ مهر ۱۴۰۵').getAttribute('aria-current')).toBe('date')
    expect(day('۱۴ مهر ۱۴۰۵').getAttribute('aria-current')).toBeNull()
  })
})

describe('the calendar', () => {
  it('shows the month in Persian digits from Saturday, today marked, and makes a range of two presses in order', async () => {
    const onChange = show()
    await openCalendar()

    expect(within(calendar()).getByRole('heading', { name: 'مهر ۱۴۰۵' })).toBeTruthy()
    expect(
      within(calendar())
        .getAllByRole('columnheader')
        .map((cell) => cell.textContent),
    ).toEqual(['ششنبه', 'ییکشنبه', 'ددوشنبه', 'سسه‌شنبه', 'چچهارشنبه', 'پپنجشنبه', 'ججمعه'])
    expect(day('۱۴ مهر ۱۴۰۵').getAttribute('aria-current')).toBe('date')
    expect(day('۱۴ مهر ۱۴۰۵').textContent).toBe('۱۴')
    // 1 Mehr 1405 is a Wednesday: four days of the week before it are blank.
    expect(within(calendar()).getAllByRole('row')[1]?.querySelectorAll('button')).toHaveLength(3)

    fireEvent.click(day('۹ مهر ۱۴۰۵'))
    fireEvent.click(day('۲ مهر ۱۴۰۵'))
    expect(day('۲ مهر ۱۴۰۵').getAttribute('aria-pressed')).toBe('true')
    expect(within(calendar()).getByText('از ۲ مهر ۱۴۰۵ تا ۹ مهر ۱۴۰۵')).toBeTruthy()
    fireEvent.click(within(calendar()).getByRole('button', { name: 'اعمال' }))

    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-09-24', to: '2026-10-01' })
    expect(screen.queryByRole('dialog', { name: 'بازه دلخواه' })).toBeNull()
    expect(pill().textContent).toContain('۲ مهر تا ۹ مهر')
  })

  it('opens with the focus on today, and gives it back to the pill once applied', async () => {
    show()
    await openCalendar()
    expect(focused()).toBe('۱۴ مهر ۱۴۰۵')

    fireEvent.click(day('۳ مهر ۱۴۰۵'))
    fireEvent.click(within(calendar()).getByRole('button', { name: 'اعمال' }))

    expect(document.activeElement).toBe(pill())
  })

  it('opens with the focus on the range’s last day, and gives it back to the pill as Escape closes it', async () => {
    show({ from: '2026-09-23', to: '2026-10-01' })
    await openCalendar()
    expect(focused()).toBe('۹ مهر ۱۴۰۵')

    fireEvent(calendar(), new Event('cancel', { cancelable: true }))

    expect(document.activeElement).toBe(pill())
  })

  it('applies one day as a range of that day, and asks for a day before anything', async () => {
    const onChange = show()
    await openCalendar()

    fireEvent.click(within(calendar()).getByRole('button', { name: 'اعمال' }))
    expect(within(calendar()).getByText('هنوز روزی انتخاب نشده است.')).toBeTruthy()
    expect(onChange).not.toHaveBeenCalled()

    fireEvent.click(day('۵ مهر ۱۴۰۵'))
    fireEvent.click(within(calendar()).getByRole('button', { name: 'اعمال' }))
    expect(onChange).toHaveBeenLastCalledWith({ from: '2026-09-27', to: '2026-09-27' })
  })

  it('walks right to left with the keys: the next day on the left, the week with Home and End, the month with PageUp and PageDown', async () => {
    show()
    await openCalendar()
    const grid = within(calendar()).getByRole('grid')
    expect(day('۱۴ مهر ۱۴۰۵').tabIndex).toBe(0)

    fireEvent.keyDown(grid, { key: 'ArrowLeft' })
    expect(focused()).toBe('۱۵ مهر ۱۴۰۵')
    fireEvent.keyDown(grid, { key: 'ArrowRight' })
    fireEvent.keyDown(grid, { key: 'ArrowRight' })
    expect(focused()).toBe('۱۳ مهر ۱۴۰۵')
    fireEvent.keyDown(grid, { key: 'ArrowDown' })
    expect(focused()).toBe('۲۰ مهر ۱۴۰۵')
    // 20 مهر is a Monday: its week runs from 18 مهر (Saturday) to 24 مهر (Friday).
    fireEvent.keyDown(grid, { key: 'Home' })
    expect(focused()).toBe('۱۸ مهر ۱۴۰۵')
    fireEvent.keyDown(grid, { key: 'End' })
    expect(focused()).toBe('۲۴ مهر ۱۴۰۵')

    fireEvent.keyDown(grid, { key: 'PageDown' })
    expect(within(calendar()).getByRole('heading', { name: 'آبان ۱۴۰۵' })).toBeTruthy()
    expect(focused()).toBe('۲۴ آبان ۱۴۰۵')
    for (let week = 0; week < 4; week++) fireEvent.keyDown(grid, { key: 'ArrowUp' })
    expect(focused()).toBe('۲۶ مهر ۱۴۰۵')
    expect(within(calendar()).getByRole('heading', { name: 'مهر ۱۴۰۵' })).toBeTruthy()
  })

  it('pages by its arrows, and a month shorter than the day ends on its last', async () => {
    show({ from: '2026-09-22', to: '2026-09-22' })
    await openCalendar()
    expect(within(calendar()).getByRole('heading', { name: 'شهریور ۱۴۰۵' })).toBeTruthy()

    fireEvent.click(within(calendar()).getByRole('button', { name: 'ماه بعد' }))
    expect(within(calendar()).getByRole('heading', { name: 'مهر ۱۴۰۵' })).toBeTruthy()
    expect(day('۳۰ مهر ۱۴۰۵').tabIndex).toBe(0)
    fireEvent.click(within(calendar()).getByRole('button', { name: 'ماه قبل' }))
    fireEvent.click(within(calendar()).getByRole('button', { name: 'ماه قبل' }))
    expect(within(calendar()).getByRole('heading', { name: 'مرداد ۱۴۰۵' })).toBeTruthy()
  })

  it('closes on Escape with the days as they were', async () => {
    const onChange = show({ from: '2026-10-01', to: '2026-10-06' })
    await openCalendar()
    fireEvent.click(day('۳ مهر ۱۴۰۵'))

    fireEvent(calendar(), new Event('cancel', { cancelable: true }))

    expect(screen.queryByRole('dialog', { name: 'بازه دلخواه' })).toBeNull()
    expect(onChange).not.toHaveBeenCalled()
    expect(pill().textContent).toContain('۹ مهر تا ۱۴ مهر')
  })
})
