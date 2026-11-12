import { useCallback, useEffect, useRef, useState, type FocusEvent } from 'react'

/*
 * A row of full-width slides that snaps as the finger leaves it, moving on by itself every few seconds — never while a
 * finger is on it, while focus is in it (a keyboard's, a screen reader's), while the app is out of sight, or for someone
 * who asked their device for less motion. Which slide is in view is read off where the slides stand, so it holds right
 * to left as left to right.
 */

/** How long a slide stays before the next. */
const DWELL_MS = 5_000

/** Whether the device asks for less motion. */
function reducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** The slide whose middle is nearest the track's. */
function nearest(track: HTMLElement): number {
  const box = track.getBoundingClientRect()
  const middle = box.left + box.width / 2
  let best = 0
  let distance = Number.POSITIVE_INFINITY
  Array.from(track.children).forEach((slide, index) => {
    const own = slide.getBoundingClientRect()
    const off = Math.abs(own.left + own.width / 2 - middle)
    if (off < distance) {
      distance = off
      best = index
    }
  })

  return best
}

export function useCarousel(count: number) {
  const track = useRef<HTMLDivElement>(null)
  const [at, setAt] = useState(0)
  const [held, setHeld] = useState(false)
  const [focused, setFocused] = useState(false)
  const [hidden, setHidden] = useState(() => document.hidden)
  const [still] = useState(reducedMotion)
  const frame = useRef(0)
  const index = Math.min(at, Math.max(0, count - 1))

  /** Bring slide `to` into the middle of the track. */
  const go = useCallback(
    (to: number) => {
      const element = track.current
      const slide = element?.children[to]
      setAt(to)
      if (element === null || slide === undefined) return
      const box = element.getBoundingClientRect()
      const own = slide.getBoundingClientRect()
      element.scrollBy?.({ left: own.left + own.width / 2 - (box.left + box.width / 2), behavior: still ? 'auto' : 'smooth' })
    },
    [still],
  )

  useEffect(() => {
    const seen = () => setHidden(document.hidden)
    document.addEventListener('visibilitychange', seen)

    return () => document.removeEventListener('visibilitychange', seen)
  }, [])

  useEffect(() => () => cancelAnimationFrame(frame.current), [])

  // On by itself: a new slide (by itself or by the finger) starts the wait afresh.
  useEffect(() => {
    if (count < 2 || held || focused || hidden || still) return
    const timer = setTimeout(() => go((index + 1) % count), DWELL_MS)

    return () => clearTimeout(timer)
  }, [count, held, focused, hidden, still, index, go])

  /** What the track listens to: its scroll (which slide is in view), and a finger or a pointer on it. */
  const handlers = {
    onScroll: () => {
      cancelAnimationFrame(frame.current)
      frame.current = requestAnimationFrame(() => {
        if (track.current !== null) setAt(nearest(track.current))
      })
    },
    onPointerDown: () => setHeld(true),
    onPointerUp: () => setHeld(false),
    onTouchEnd: () => setHeld(false),
    onMouseLeave: () => setHeld(false),
  }

  /** What the carousel's whole box listens to (its slides and its dots): focus coming in, and leaving it altogether. */
  const region = {
    onFocus: () => setFocused(true),
    onBlur: (event: FocusEvent<HTMLElement>) => {
      if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false)
    },
  }

  return { track, index, go, handlers, region }
}
