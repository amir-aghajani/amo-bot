import { useEffect, useState } from 'react'

/*
 * What a checkout prices once the customer stops moving it — a slider's GB, an amount typed —, not at every step.
 */

/** `value` once it stayed the same for `ms` (the first at once); `ms` 0 is `value` itself (a button pressed: no wait). */
export function useSettled<T>(value: T, ms: number): T {
  const [settled, setSettled] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), ms)
    return () => clearTimeout(timer)
  }, [value, ms])

  return ms === 0 ? value : settled
}
