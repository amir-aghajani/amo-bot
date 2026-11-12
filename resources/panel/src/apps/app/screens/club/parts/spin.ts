/*
 * The lucky wheel's motion, as numbers. A wheel of `count` slices is turned `rotation` degrees clockwise; slice i spans
 * its own angles [i, i + 1) × 360 / count, clockwise from the top, where the pointer stands. A spin speeds up while the
 * shop draws the slice, then glides to rest on it: an ease-out whose first speed is the spin's — so it never jerks —
 * over at least a few turns, a little past the spot, and back onto it as the pointer's flap would push it.
 */

/** The turning speed at full spin, in degrees a second. */
export const SPEED = 900
/** Seconds to reach full speed. */
export const RAMP = 0.5
/** The least the glide turns (about four seconds at full speed). */
const LEAST = 1260
/** Seconds the overshoot takes to settle back. */
export const SETTLE = 0.45

const turn = (angle: number): number => ((angle % 360) + 360) % 360

/** The slice under the pointer when the wheel is turned `rotation` degrees. */
export function sliceAt(rotation: number, count: number): number {
  return Math.floor(turn(-rotation) / (360 / count)) % count
}

/**
 * The rotation (0 to 360) that rests slice `index` under the pointer — at its middle, or `offset` of a slice from it
 * (−0.5 to 0.5: where in the slice it stops, a look alone).
 */
export function restingAngle(index: number, count: number, offset = 0): number {
  return turn(-(index + 0.5 + offset) * (360 / count))
}

export interface Glide {
  from: number
  /** Where it rests: `from` plus at least `LEAST`, at the resting angle. */
  to: number
  /** How far past `to` it goes before settling back. */
  overshoot: number
  /** Seconds to go from `from` to `to + overshoot`. */
  duration: number
}

/** The glide from `from`, turning at `speed`, to rest at `resting` (mod 360). */
export function glide(from: number, speed: number, resting: number, count: number): Glide {
  const least = from + LEAST
  const to = least + turn(resting - least)
  const overshoot = Math.min(5, (360 / count) * 0.18)
  // An ease-out cubic starts at three times its average speed: the duration that makes its first speed the spin's.
  return { from, to, overshoot, duration: (3 * (to + overshoot - from)) / Math.max(speed, 1) }
}

/** The glide's angle `elapsed` seconds in. */
export function glideAt({ from, to, overshoot, duration }: Glide, elapsed: number): number {
  const t = Math.min(1, elapsed / duration)
  return from + (to + overshoot - from) * (1 - (1 - t) ** 3)
}

/** Settling back from the overshoot onto the resting angle, `elapsed` seconds in. */
export function settleAt({ to, overshoot }: Glide, elapsed: number): number {
  const t = Math.min(1, elapsed / SETTLE)
  return to + overshoot * (1 + Math.cos(Math.PI * t)) * 0.5
}
