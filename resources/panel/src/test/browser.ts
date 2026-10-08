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
