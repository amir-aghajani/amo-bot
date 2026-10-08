import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AreaChart, dayTicks, monotonePath, valueTicks, type AreaPoint } from '@/components/dashboard/area-chart'

/*
 * The overview's curve (components/dashboard/area-chart), drawn as plain SVG: the figure axis in five rounded steps from
 * zero — none with nothing above zero —, as many days as fit on the day axis — the last one always —, every label Persian
 * text read right to left in its place, a curve that never swings past a day's figure, and one day's figure under the
 * hand or the keyboard.
 */

describe('the figure axis', () => {
  it('climbs from zero in four rounded steps to past the highest figure', () => {
    expect(valueTicks(5_200_000)).toEqual([0, 1_500_000, 3_000_000, 4_500_000, 6_000_000])
    expect(valueTicks(1_150_000)).toEqual([0, 300_000, 600_000, 900_000, 1_200_000])
    expect(valueTicks(7)).toEqual([0, 2, 4, 6, 8])
  })

  it('counts in wholes — and is zero alone with nothing above zero: no scale to read', () => {
    expect(valueTicks(3)).toEqual([0, 1, 2, 3, 4])
    expect(valueTicks(0)).toEqual([0])
  })
})

describe('the day axis', () => {
  it('labels days from the last one back, as far apart as they fit, the last moved in to show whole', () => {
    const xs = Array.from({ length: 30 }, (_, index) => 64 + index * (826 / 29))

    const shown = dayTicks(xs, Array<number>(30).fill(40), 902)

    expect(shown.at(-1)).toEqual({ index: 29, x: 882 })
    expect(shown.map((tick) => tick.index)).toEqual([2, 5, 8, 11, 14, 17, 20, 23, 26, 29])
  })

  it('labels every day that has room', () => {
    const shown = dayTicks([64, 200, 336, 472], [30, 30, 30, 30], 600)

    expect(shown.map((tick) => tick.index)).toEqual([0, 1, 2, 3])
  })
})

describe('the curve', () => {
  it('is a point, a line, then a smooth curve', () => {
    expect(monotonePath([])).toBe('')
    expect(monotonePath([[10, 20]])).toBe('M10,20')
    expect(
      monotonePath([
        [0, 0],
        [10, 10],
      ]),
    ).toBe('M0,0L10,10')
    expect(
      monotonePath([
        [0, 30],
        [10, 20],
        [20, 10],
      ]),
    ).toMatch(/^M0,30C.+C.+,20,10$/)
  })

  it('stays flat where the figures do — never past a day’s figure', () => {
    const path = monotonePath([
      [0, 100],
      [10, 0],
      [20, 0],
      [30, 100],
    ])
    // The segment between the two zero days keeps to zero.
    expect(path).toContain('C13.333333333333334,0,16.666666666666668,0,20,0')
  })
})

const DAYS: AreaPoint[] = [
  { date: '2026-10-01', value: 0 },
  { date: '2026-10-02', value: 120_000 },
  { date: '2026-10-03', value: 80_000 },
]

describe('the chart', () => {
  beforeEach(() => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, 600, 240))
    vi.spyOn(SVGElement.prototype, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, 600, 240))
  })

  const drawn = () =>
    render(
      <AreaChart
        data={DAYS}
        label="درآمد"
        formatTick={(date) => `روز ${date.slice(-2)}`}
        formatValue={(value) => `${value / 1000}K`}
        tooltip={(point) => ({ title: `روز ${point.date.slice(-2)}`, body: <span>{point.value} تومان</span> })}
      />,
    )

  it('draws the grid with its figures and the days', () => {
    drawn()

    const chart = screen.getByRole('application', { name: 'درآمد' })
    expect(chart.querySelectorAll('line')).toHaveLength(5)
    for (const figure of ['0K', '30K', '60K', '90K', '120K']) expect(screen.getByText(figure)).toBeTruthy()
    for (const day of ['روز 01', 'روز 02', 'روز 03']) expect(screen.getByText(day)).toBeTruthy()
  })

  it('reads its labels right to left in their places — a figure’s right edge at its tick, a day centred under it', () => {
    drawn()

    const figure = screen.getByText('120K')
    const day = screen.getByText('روز 02')
    // Right to left, a text's start is its right edge (measured in a browser: «۱۹ شهریور» left to right reads «شهریور ۱۹»).
    expect(figure.closest('[direction="rtl"]')).not.toBeNull()
    expect(figure.getAttribute('text-anchor')).toBe('start')
    expect(day.closest('[direction="rtl"]')).not.toBeNull()
    expect(day.getAttribute('text-anchor')).toBe('middle')
  })

  it('makes the figure axis as wide as its widest label, so none runs past the chart’s edge', () => {
    const measure = { font: '', measureText: (text: string) => ({ width: text.length * 10 }) }
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(measure as unknown as CanvasRenderingContext2D)
    render(
      <AreaChart
        data={DAYS}
        label="درآمد"
        formatTick={(date) => `روز ${date.slice(-2)}`}
        formatValue={(value) => (value === 0 ? '0' : `${value / 1000} میلیون تومان`)}
        tooltip={(point) => ({ title: `روز ${point.date.slice(-2)}`, body: <span>{point.value} تومان</span> })}
      />,
    )

    // A figure's label ends at its x (right to left, its start) and runs left: it needs its width of room before x.
    for (const text of screen.getAllByText(/میلیون تومان$/)) {
      expect(Number(text.getAttribute('x'))).toBeGreaterThanOrEqual(measure.measureText(text.textContent ?? '').width)
    }
  })

  it('draws a period with nothing in it on its baseline alone: no scale of figures it does not have', () => {
    render(
      <AreaChart
        data={DAYS.map((point) => ({ ...point, value: 0 }))}
        label="درآمد"
        formatTick={(date) => `روز ${date.slice(-2)}`}
        formatValue={(value) => `${value} تومان`}
        tooltip={(point) => ({ title: `روز ${point.date.slice(-2)}`, body: <span>{point.value}</span> })}
      />,
    )

    const chart = screen.getByRole('application', { name: 'درآمد' })
    expect(chart.querySelectorAll('line')).toHaveLength(1)
    expect(screen.queryByText(/تومان/)).toBeNull()
    for (const day of ['روز 01', 'روز 02', 'روز 03']) expect(screen.getByText(day)).toBeTruthy()
  })

  it('shows the day under the hand, its box right to left, and nothing once the hand has left', () => {
    drawn()
    const chart = screen.getByRole('application', { name: 'درآمد' })

    fireEvent.pointerMove(chart, { clientX: 330, clientY: 100 })
    expect(screen.getByText('120000 تومان')).toBeTruthy()
    expect(screen.getByText('روز 02', { selector: 'div' }).closest('[dir]')?.getAttribute('dir')).toBe('rtl')

    fireEvent.pointerLeave(chart)
    expect(screen.queryByText('120000 تومان')).toBeNull()
  })

  it('walks the days with the keyboard: the first as it arrives, the arrows, Enter to hide', () => {
    drawn()
    const chart = screen.getByRole('application', { name: 'درآمد' })

    fireEvent.focus(chart)
    expect(screen.getByText('0 تومان')).toBeTruthy()
    fireEvent.keyDown(chart, { key: 'ArrowRight' })
    fireEvent.keyDown(chart, { key: 'ArrowRight' })
    fireEvent.keyDown(chart, { key: 'ArrowRight' })
    expect(screen.getByText('80000 تومان')).toBeTruthy()
    fireEvent.keyDown(chart, { key: 'Enter' })
    expect(screen.queryByText('80000 تومان')).toBeNull()
    fireEvent.keyDown(chart, { key: 'ArrowLeft' })
    expect(screen.getByText('120000 تومان')).toBeTruthy()

    fireEvent.blur(chart)
    expect(screen.queryByText('120000 تومان')).toBeNull()
  })
})
