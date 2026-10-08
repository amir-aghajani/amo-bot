import { useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type PointerEvent, type ReactNode } from 'react'

/*
 * One series over time as an area — the overview's period curve —, drawn as plain SVG: the panel's one chart is a few
 * paths and labels, not a charting library. Time runs left to right whatever the page's direction, while every label —
 * a figure «۱٫۲ میلیون», a day «۱۹ شهریور», the box of a day's figure — is Persian text, read right to left in its place;
 * the figure axis has five rounded steps from zero (none while nothing is above zero), the day axis as many days as fit;
 * a hand or the keyboard on it shows one day's figure.
 */

export interface AreaPoint {
  /** The day, as the axis and the figure's label word it. */
  date: string
  value: number
}

interface AreaChartProps {
  data: AreaPoint[]
  /** What the curve is of, for assistive tech: «درآمد». */
  label: string
  /** A day on the day axis: «۱۴ مهر». */
  formatTick: (date: string) => string
  /** A figure on the figure axis: «۱٫۲ میلیون». */
  formatValue: (value: number) => string
  /** One day's figure, under its day: what the hand or the keyboard is on. */
  tooltip: (point: AreaPoint) => { title: string; body: ReactNode }
}

/* The drawing's geometry — the room the axes take around the plot, in pixels. */
const HEIGHT = 240
const MARGIN = { top: 8, right: 12 }
/** The figure axis' room at the least; wider when its widest label needs it, with `EDGE` to spare at the chart's edge. */
const Y_AXIS = 64
const EDGE = 2
const X_AXIS = 30
/** Between the plot and an axis' labels: the tick's length (none drawn) and the gap after it. */
const TICK = 6
const TICK_GAP = { x: 10, y: 8 }
/** The least room between two day labels. */
const MIN_TICK_GAP = 32
const STEPS = 4
/** How far the figure's box stands off the day it is about. */
const TOOLTIP_OFFSET = 10
const LABEL_FONT = '12px Vazirmatn, ui-sans-serif, system-ui, sans-serif'
/** How tall a label stands — the type at its natural line height: the top figure is moved down to show whole. */
const LABEL_HEIGHT = 22

// The chart's arithmetic — the axes' steps and labels, the curve — is exported for its own test (area-chart.test): what it
// must come to is exact numbers, which no drawing of it can be read for.

/**
 * The figure axis: zero, then four equal steps rounded to a readable whole (5, 10, 15 · 0.15, 0.2, 0.25 of a power of
 * ten) up to past the highest figure — a step one rounding unit longer while four do not reach it. Nothing above zero:
 * zero alone, no scale to read.
 */
export function valueTicks(max: number): number[] {
  if (!(max > 0)) return [0]
  const rough = max / STEPS
  const digits = Math.floor(Math.log10(rough)) + 1
  // The rounding unit: a twentieth of the power of ten above the rough step — a tenth while that step has one digit.
  const unit = digits === 1 ? 1 : 10 ** digits * 0.05
  for (let correction = 0; ; correction++) {
    // (The 1e-9 forgives a float's last digit: 0.15 / 0.05 must stay 3.)
    const step = Math.ceil((Math.ceil(rough / unit - 1e-9) + correction) * unit - 1e-9)
    if (Math.ceil(max / step) <= STEPS) return Array.from({ length: STEPS + 1 }, (_, index) => index * step)
  }
}

/**
 * The days that get a label: from the last one back — moved in to show whole —, each one that fits whole and stands
 * `MIN_TICK_GAP` off the label after it.
 */
export function dayTicks(xs: number[], widths: number[], width: number): { index: number; x: number }[] {
  const shown: { index: number; x: number }[] = []
  let end = width
  for (let index = xs.length - 1; index >= 0; index--) {
    const half = (widths[index] ?? 0) / 2
    const at = xs[index] ?? 0
    const x = index === xs.length - 1 ? Math.min(at, end - half) : at
    if (x - half >= 0 && x + half <= end) {
      shown.unshift({ index, x })
      end = x - half - MIN_TICK_GAP
    }
  }
  return shown
}

/** The sign d3 takes a slope's: zero counts as rising. */
const sign = (value: number) => (value < 0 ? -1 : 1)

/**
 * The curve through the points as an SVG path: monotone in x (d3's curveMonotoneX — Steffen's tangents), so it never
 * swings above a day's figure or below zero between two days.
 */
export function monotonePath(points: [number, number][]): string {
  const [first, second] = points
  if (!first) return ''
  if (!second) return `M${first[0]},${first[1]}`
  if (points.length === 2) return `M${first[0]},${first[1]}L${second[0]},${second[1]}`

  const slopes = points.slice(1).map(([x, y], index) => {
    const [px, py] = points[index]!
    return (y - py) / (x - px)
  })
  const tangents = points.map((_, index) => {
    if (index === 0 || index === points.length - 1) return 0
    const h0 = points[index]![0] - points[index - 1]![0]
    const h1 = points[index + 1]![0] - points[index]![0]
    const s0 = slopes[index - 1]!
    const s1 = slopes[index]!
    const p = (s0 * h1 + s1 * h0) / (h0 + h1)
    return (sign(s0) + sign(s1)) * Math.min(Math.abs(s0), Math.abs(s1), 0.5 * Math.abs(p)) || 0
  })
  // The ends lean as the curve leaves them.
  const last = points.length - 1
  tangents[0] = (3 * slopes[0]! - tangents[1]!) / 2
  tangents[last] = (3 * slopes[last - 1]! - tangents[last - 1]!) / 2

  let path = `M${first[0]},${first[1]}`
  for (let index = 0; index < last; index++) {
    const [x0, y0] = points[index]!
    const [x1, y1] = points[index + 1]!
    const dx = (x1 - x0) / 3
    path += `C${x0 + dx},${y0 + dx * tangents[index]!},${x1 - dx},${y1 - dx * tangents[index + 1]!},${x1},${y1}`
  }
  return path
}

let measuring: CanvasRenderingContext2D | null | undefined

/** How wide a label is drawn, in the axis' type (an estimate where the browser draws nothing to measure with). */
function labelWidth(text: string): number {
  measuring ??= document.createElement('canvas').getContext('2d')
  if (!measuring) return text.length * 6.5
  measuring.font = LABEL_FONT
  return measuring.measureText(text).width
}

interface Active {
  index: number
  /** Shown — a hand on the chart, or the keyboard's focus on a day. */
  shown: boolean
  /** Where the hand is, up and down (the figure's box follows it); none for the keyboard: the day's own height. */
  pointerY: number | null
}

export function AreaChart({ data, label, formatTick, formatValue, tooltip }: AreaChartProps) {
  const frame = useRef<HTMLDivElement>(null)
  const box = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(0)
  const [active, setActive] = useState<Active | null>(null)

  // As wide as its column — measured before it is first drawn, and again as the column changes.
  useLayoutEffect(() => {
    const element = frame.current
    if (!element) return
    setWidth(element.getBoundingClientRect().width)
    const observer = new ResizeObserver(([entry]) => setWidth(entry?.contentRect.width ?? 0))
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  const ticks = useMemo(() => valueTicks(Math.max(0, ...data.map((point) => point.value))), [data])
  // Nothing above zero has no scale: the baseline alone, its figure unsaid — the period's curve lies on it.
  const scaled = ticks.length > 1
  // A figure's label ends at its tick and runs toward the chart's edge: the axis is as wide as the widest one needs.
  const yAxis = useMemo(() => (scaled ? Math.max(Y_AXIS, Math.ceil(Math.max(...ticks.map((tick) => labelWidth(formatValue(tick)))) + TICK + TICK_GAP.y + EDGE)) : Y_AXIS), [ticks, scaled, formatValue])
  const plot = { left: yAxis, right: width - MARGIN.right, top: MARGIN.top, bottom: HEIGHT - X_AXIS }
  const ceiling = ticks.at(-1) || 1
  const xs = useMemo(
    () => data.map((_, index) => (data.length === 1 ? (plot.left + plot.right) / 2 : plot.left + (index * (plot.right - plot.left)) / (data.length - 1))),
    [data, plot.left, plot.right],
  )
  const yOf = (value: number) => plot.bottom - (value / ceiling) * (plot.bottom - plot.top)
  const points = data.map((point, index): [number, number] => [xs[index]!, yOf(point.value)])
  const line = monotonePath(points)
  const days = useMemo(() => {
    const labels = data.map((point) => formatTick(point.date))
    return dayTicks(
      xs,
      labels.map((text) => labelWidth(text)),
      width,
    ).map((tick) => ({ ...tick, text: labels[tick.index]! }))
  }, [data, xs, width, formatTick])

  const shown = active?.shown ? data[active.index] : undefined
  const x = shown ? xs[active!.index]! : 0
  const anchorY = shown ? (active!.pointerY ?? yOf(shown.value)) : 0

  // The figure's box stands off its day — on the side with room for it, measured as it is drawn: after the day, or
  // before it at the right edge; under the hand, or over it at the foot.
  useLayoutEffect(() => {
    const element = box.current
    if (!element) return
    const left = x + TOOLTIP_OFFSET + element.offsetWidth > width ? x - TOOLTIP_OFFSET - element.offsetWidth : x + TOOLTIP_OFFSET
    const top = anchorY + TOOLTIP_OFFSET + element.offsetHeight > HEIGHT ? Math.max(plot.top, anchorY - TOOLTIP_OFFSET - element.offsetHeight) : anchorY + TOOLTIP_OFFSET
    element.style.transform = `translate(${left}px, ${top}px)`
  })

  const point = (event: PointerEvent<SVGSVGElement>) => {
    const bounds = event.currentTarget.getBoundingClientRect()
    const at = event.clientX - bounds.left
    let index = 0
    for (let candidate = 1; candidate < xs.length; candidate++) if (Math.abs(xs[candidate]! - at) < Math.abs(xs[index]! - at)) index = candidate
    if (data.length) setActive({ index, shown: true, pointerY: event.clientY - bounds.top })
  }

  // The keyboard walks the days: the first day as it arrives, the arrows to the next or the one before, Enter to hide
  // or show the day's figure.
  const key = (event: KeyboardEvent<SVGSVGElement>) => {
    if (!data.length) return
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      const forward = event.key === 'ArrowRight'
      const next = active === null ? (forward ? 0 : data.length - 1) : active.index + (forward ? 1 : -1)
      if (next < 0 || next >= data.length) return
      event.preventDefault()
      setActive({ index: next, shown: true, pointerY: null })
    } else if (event.key === 'Enter' && active !== null) {
      setActive({ ...active, shown: !active.shown })
    }
  }

  const content = shown && tooltip(shown)

  return (
    <div ref={frame} dir="ltr" className="relative h-60 w-full text-caption">
      {width > 0 && (
        <svg
          width={width}
          height={HEIGHT}
          viewBox={`0 0 ${width} ${HEIGHT}`}
          role="application"
          aria-label={label}
          tabIndex={0}
          className="block outline-none"
          onPointerDown={point}
          onPointerMove={point}
          onPointerLeave={() => setActive((current) => current && { ...current, shown: false })}
          onFocus={() => setActive((current) => current ?? (data.length ? { index: 0, shown: true, pointerY: null } : null))}
          onBlur={() => setActive((current) => current && { ...current, shown: false })}
          onKeyDown={key}
        >
          {ticks.map((tick) => (
            <line key={tick} x1={plot.left} x2={plot.right} y1={yOf(tick)} y2={yOf(tick)} className="stroke-border" />
          ))}
          {/* The labels are Persian, read right to left — inheriting the chart's left to right, «۱۹ شهریور» would read
              «شهریور ۱۹». Right to left, a text's start is its right edge: a figure's stands at its tick. */}
          <g direction="rtl" className="fill-faint">
            {scaled &&
              ticks.map((tick) => (
                <text key={tick} x={plot.left - TICK - TICK_GAP.y} y={Math.max(yOf(tick), LABEL_HEIGHT / 2)} dy="0.355em" textAnchor="start">
                  {formatValue(tick)}
                </text>
              ))}
            {days.map((day) => (
              <text key={day.index} x={day.x} y={plot.bottom + TICK + TICK_GAP.x} dy="0.71em" textAnchor="middle">
                {day.text}
              </text>
            ))}
          </g>
          {points.length > 0 && (
            <>
              <path d={`${line}L${points.at(-1)![0]},${plot.bottom}L${points[0]![0]},${plot.bottom}Z`} className="fill-(--chart-1)" fillOpacity={0.1} />
              <path d={line} fill="none" strokeWidth={2} className="stroke-(--chart-1)" />
            </>
          )}
          {shown && (
            <>
              <line x1={x} x2={x} y1={plot.top} y2={plot.bottom} className="stroke-border" />
              <circle cx={x} cy={yOf(shown.value)} r={4} strokeWidth={2} className="fill-(--chart-1) stroke-(--surface-2)" />
            </>
          )}
        </svg>
      )}
      {content && (
        <div
          ref={box}
          dir="rtl"
          className="pointer-events-none absolute top-0 left-0 grid min-w-44 items-start gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-2 text-caption text-popover-foreground shadow-popover"
        >
          <div className="font-medium">{content.title}</div>
          {content.body}
        </div>
      )}
    </div>
  )
}
