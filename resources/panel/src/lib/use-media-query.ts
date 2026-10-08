import { useSyncExternalStore } from 'react'

/** One subscription per query, kept: a new one every render would make React subscribe again every render. */
const subscriptions = new Map<string, (changed: () => void) => () => void>()

function subscriptionTo(query: string): (changed: () => void) => () => void {
  let subscribe = subscriptions.get(query)
  if (subscribe === undefined) {
    subscribe = (changed) => {
      const list = window.matchMedia(query)
      list.addEventListener('change', changed)
      return () => list.removeEventListener('change', changed)
    }
    subscriptions.set(query, subscribe)
  }
  return subscribe
}

/** Whether a media query matches now, followed as it changes (the window narrows, the system asks for less motion). */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(subscriptionTo(query), () => window.matchMedia(query).matches)
}

/**
 * A phone: the sidebar is a drawer behind the topbar's menu — below Tailwind's `md` (48rem), where the layout's desktop
 * starts; in rem as the layout is, so a larger root font moves both together.
 */
export const PHONE = '(width < 48rem)'

/** The system asks for reduced motion: nothing decorative moves. */
export const REDUCED_MOTION = '(prefers-reduced-motion: reduce)'
