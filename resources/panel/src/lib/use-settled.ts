import { useEffect, useMemo, useState } from 'react'
import { SEARCH_DELAY_MS } from '@/lib/use-paged-list'

/**
 * `value` once it has stayed the same a moment (SEARCH_DELAY_MS) — what typing or ticking settles on —, for a read that
 * follows a form: an id typed, choices ticked in a row, ask once. The first value is there at once. Compared by its
 * JSON, so the same choices made afresh on each render are no change.
 */
export function useSettled<T>(value: T, delay = SEARCH_DELAY_MS): T {
  const key = JSON.stringify(value)
  const [settled, setSettled] = useState(key)
  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(key), delay)
    return () => window.clearTimeout(timer)
  }, [key, delay])

  return useMemo(() => JSON.parse(settled) as T, [settled])
}
