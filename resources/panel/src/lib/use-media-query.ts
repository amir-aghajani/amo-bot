import { useCallback, useSyncExternalStore } from 'react'

/** Whether a media query matches now, followed as it changes (the window narrows, the system asks for less motion). */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (changed: () => void) => {
      const list = window.matchMedia(query)
      list.addEventListener('change', changed)
      return () => list.removeEventListener('change', changed)
    },
    [query],
  )

  return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches)
}

/**
 * A phone: below Tailwind's `md` (48rem), where the sidebar becomes a drawer behind the topbar. In rem like the layout,
 * so a larger root font moves both together.
 */
export const PHONE = '(width < 48rem)'

/** The system asks for reduced motion: nothing decorative moves. */
export const REDUCED_MOTION = '(prefers-reduced-motion: reduce)'
