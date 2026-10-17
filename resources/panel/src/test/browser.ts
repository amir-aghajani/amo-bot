import { vi } from 'vitest'

/** What a browser answers a page whose site data is blocked, wherever it reaches for storage. */
function insecure(): never {
  throw new DOMException('The operation is insecure.', 'SecurityError')
}

/**
 * The browser keeps nothing for the site (blocked site data): reaching either storage throws, as browsers do. Undone
 * before the test's clean-up (src/test/setup).
 */
export function blockStorage(): void {
  vi.spyOn(window, 'localStorage', 'get').mockImplementation(insecure)
  vi.spyOn(window, 'sessionStorage', 'get').mockImplementation(insecure)
}

/** happy-dom's own handle on the test's window: what sizes it. */
const happyDOM = (window as unknown as { happyDOM: { setViewport: (size: { width: number; height: number }) => void } }).happyDOM

/** The window's size as a test starts. */
const initial = { width: window.innerWidth, height: window.innerHeight }

/**
 * The window made `width` wide — a phone's, a tablet's —, as its media queries read it. Put back after the test
 * (src/test/setup); wrap it in act() while something drawn follows the window.
 */
export function resizeWindow(width: number): void {
  happyDOM.setViewport({ width, height: initial.height })
}

/** The window back at its size as the test started (src/test/setup, after every test). */
export function restoreWindow(): void {
  if (window.innerWidth !== initial.width || window.innerHeight !== initial.height) happyDOM.setViewport(initial)
}
