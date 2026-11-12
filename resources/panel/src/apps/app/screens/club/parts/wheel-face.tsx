import { useId, type Ref } from 'react'
import { ACCENT_FILL } from '@/apps/app/ui/visuals'
import type { StoreWheelSlice } from '@/lib/api-types'
import { cn } from '@/lib/utils'

/*
 * The lucky wheel as it is drawn: its slices in tones of the shop's colour (a light one between two of the colour, a
 * deep one where an odd count would put two alike side by side), each prize's words along its radius, a rim of lights,
 * a hub, the light falling on its face from above, and its edge beneath it for depth. The slices turn (`rotor`: the
 * screen turns it); the rim, the light and the hub stay put. The pointer is the screen's own, over the top of the rim.
 */

/** The radius of the slices, in the drawing's units (the rim around them reaches 112). */
const R = 100

/** A slice's fill and the colour of its words: the shop's colour, a light tint of it, a deep shade of it. */
const TONES = [
  { fill: 'var(--app-accent)', ink: 'var(--app-on-accent)' },
  { fill: 'color-mix(in srgb, var(--app-accent) 32%, white)', ink: 'color-mix(in srgb, var(--app-accent) 45%, black)' },
  { fill: 'color-mix(in srgb, var(--app-accent) 62%, black)', ink: '#ffffff' },
] as const

/** Slice `index`'s tone among `count`: the colour and its tint by turns, the shade for an odd count's last. */
function sliceTone(index: number, count: number): (typeof TONES)[number] {
  return count > 1 && count % 2 === 1 && index === count - 1 ? TONES[2] : TONES[index % 2 === 0 ? 0 : 1]
}

/**
 * Slice `index`'s prize's icon tile (ui/visuals IconTile) in the list under the wheel, in the slice's own tone: the shop's
 * colour filled, its tint as the colour's light wash, its shade filled (the icon white on it, as the slice's words are).
 */
export function sliceTile(index: number, count: number): { color: string; look: 'solid' | 'soft' } {
  const tone = sliceTone(index, count)
  if (tone === TONES[1]) return { color: ACCENT_FILL, look: 'soft' }

  return { color: tone === TONES[0] ? ACCENT_FILL : tone.fill, look: 'solid' }
}

/** A point on the circle of radius `radius`, at `angle` degrees clockwise from the top. */
function point(radius: number, angle: number): string {
  const radians = ((angle - 90) * Math.PI) / 180
  return `${(radius * Math.cos(radians)).toFixed(3)} ${(radius * Math.sin(radians)).toFixed(3)}`
}

/** A slice from `start` to `end` degrees clockwise from the top. */
function slicePath(start: number, end: number): string {
  if (end - start >= 360) return `M 0 ${-R} A ${R} ${R} 0 1 1 0 ${R} A ${R} ${R} 0 1 1 0 ${-R} Z`
  return `M 0 0 L ${point(R, start)} A ${R} ${R} 0 ${end - start > 180 ? 1 : 0} 1 ${point(R, end)} Z`
}

/** Words that fit along a slice's radius. */
function shortLabel(label: string, count: number): string {
  const most = count <= 6 ? 16 : count <= 10 ? 13 : 10
  const letters = Array.from(label)
  return letters.length > most ? `${letters.slice(0, most - 1).join('')}…` : label
}

/** How long the words along a slice may run: from the hub's edge (18) to the slices' edge (100), a little clear of both. */
const LABEL_ROOM = 76

/**
 * The words' size on every slice alike: as large as the slices' width allows (fewer slices, larger words), and smaller
 * when the longest words would run past the hub or the rim — a letter about half its size wide.
 */
function labelSize(labels: string[], count: number): number {
  const most = count <= 4 ? 12 : count <= 6 ? 11 : count <= 8 ? 10 : count <= 12 ? 8 : 7
  const longest = Math.max(1, ...labels.map((label) => Array.from(label).length))

  return Math.max(6, Math.min(most, Math.floor((LABEL_ROOM / (longest * 0.5)) * 2) / 2))
}

interface WheelFaceProps {
  slices: StoreWheelSlice[]
  /** The group the screen turns. */
  rotor: Ref<SVGGElement>
  /** The lights on the rim twinkle while it spins. */
  spinning: boolean
}

export function WheelFace({ slices, rotor, spinning }: WheelFaceProps) {
  const id = useId().replace(/[^a-zA-Z0-9_-]/g, '')
  const count = Math.max(1, slices.length)
  const angle = 360 / count
  const labels = slices.map((slice) => shortLabel(slice.label, count))
  const fontSize = labelSize(labels, count)

  return (
    <svg viewBox="-120 -120 240 248" className="block w-full overflow-visible" role="img" aria-label="گردونه شانس">
      <defs>
        <linearGradient id={`${id}-rim`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" style={{ stopColor: 'color-mix(in srgb, var(--app-accent) 55%, white)' }} />
          <stop offset="0.5" style={{ stopColor: 'var(--app-accent)' }} />
          <stop offset="1" style={{ stopColor: 'color-mix(in srgb, var(--app-accent) 55%, black)' }} />
        </linearGradient>
        <radialGradient id={`${id}-light`} cx="0.38" cy="0.22" r="0.85">
          <stop offset="0" stopColor="#ffffff" stopOpacity="0.18" />
          <stop offset="0.55" stopColor="#ffffff" stopOpacity="0" />
          <stop offset="1" stopColor="#000000" stopOpacity="0.16" />
        </radialGradient>
        <radialGradient id={`${id}-hub`} cx="0.4" cy="0.3" r="0.8">
          <stop offset="0" stopColor="#ffffff" />
          <stop offset="1" style={{ stopColor: 'color-mix(in srgb, var(--app-accent) 18%, white)' }} />
        </radialGradient>
      </defs>

      {/* The wheel's edge, under its face: its thickness, seen from the tilt. */}
      <circle cx="0" cy="8" r="112" style={{ fill: 'color-mix(in srgb, var(--app-accent) 45%, black)' }} />
      <circle r="112" fill={`url(#${id}-rim)`} />

      <g ref={rotor}>
        {labels.map((words, index) => {
          const tone = sliceTone(index, count)
          const middle = (index + 0.5) * angle
          // Along the radius; on the left half turned about, so that every label reads upright before the first spin.
          const label = middle > 180 ? `rotate(${middle + 90}) translate(${-R * 0.6} 0)` : `rotate(${middle - 90}) translate(${R * 0.6} 0)`

          return (
            <g key={index}>
              <path d={slicePath(index * angle, (index + 1) * angle)} style={{ fill: tone.fill }} stroke="rgb(255 255 255 / 0.4)" strokeWidth="0.8" />
              <text transform={label} textAnchor="middle" dominantBaseline="central" fontSize={fontSize} fontWeight="700" style={{ fill: tone.ink }}>
                {words}
              </text>
            </g>
          )
        })}
      </g>

      {/* The light on its face, and the lights around its rim. */}
      <circle r={R} fill={`url(#${id}-light)`} pointerEvents="none" />
      <circle r={R} fill="none" stroke="rgb(0 0 0 / 0.18)" strokeWidth="1.5" />
      <g className={cn(spinning && 'animate-pulse')}>
        {Array.from({ length: 18 }, (_, index) => (
          <circle key={index} cx={0} cy={-106} r="2.6" transform={`rotate(${index * 20})`} fill="#ffffff" opacity={index % 2 === 0 ? 0.95 : 0.55} />
        ))}
      </g>

      <circle r="19" fill="rgb(0 0 0 / 0.18)" cy="1.5" />
      <circle r="18" fill={`url(#${id}-hub)`} />
      <circle r="11.5" style={{ fill: 'var(--app-accent)' }} />
      <path d="M 0 -6 L 1.8 -1.9 L 6 -1.6 L 2.8 1.2 L 3.7 5.4 L 0 3.2 L -3.7 5.4 L -2.8 1.2 L -6 -1.6 L -1.8 -1.9 Z" fill="#ffffff" />
    </svg>
  )
}
