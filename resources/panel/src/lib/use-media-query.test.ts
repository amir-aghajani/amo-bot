import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { PHONE, useMediaQuery } from '@/lib/use-media-query'

/*
 * A media query followed as it changes (lib/use-media-query): subscribed once — not again on every render of every
 * component that asks (two hundred emoji in a picker) — and read afresh when it changes. A phone is what the layout takes
 * for one: below Tailwind's `md` (48rem).
 */

/** happy-dom's own handle on the test's window: what sizes it. */
const browser = (window as unknown as { happyDOM: { setViewport: (size: { width: number; height: number }) => void } }).happyDOM

describe('a phone', () => {
  it('is a window below the layout’s md, 48rem', () => {
    const { innerWidth: width, innerHeight: height } = window
    try {
      browser.setViewport({ width: 767, height })
      expect(window.matchMedia(PHONE).matches).toBe(true)

      browser.setViewport({ width: 768, height })
      expect(window.matchMedia(PHONE).matches).toBe(false)
    } finally {
      browser.setViewport({ width, height })
    }
  })
})

describe('a media query', () => {
  it('is subscribed to once, whatever the renders, and followed as it changes', () => {
    let matches = false
    const listeners: (() => void)[] = []
    const added = vi.fn((_type: string, listener: () => void) => listeners.push(listener))
    vi.spyOn(window, 'matchMedia').mockImplementation(
      (query) =>
        ({
          get matches() {
            return matches
          },
          media: query,
          onchange: null,
          addEventListener: added,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }) as unknown as MediaQueryList,
    )
    const { result, rerender } = renderHook(() => useMediaQuery('(max-width: 767px)'))

    rerender()
    rerender()
    expect(added).toHaveBeenCalledTimes(1)
    expect(result.current).toBe(false)

    matches = true
    act(() => listeners.forEach((listener) => listener()))
    expect(result.current).toBe(true)
  })
})
