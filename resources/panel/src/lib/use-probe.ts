import { useCallback, useRef, useState } from 'react'
import { ApiError } from '@/lib/api'

interface UseProbeOptions {
  /** Where a refusal goes when the screen shows it somewhere other than under the button (a field, the form). */
  onFailure?: (failure: ApiError) => void
}

/**
 * A "test it" button beside a form: `run()` sends the probe (`api.post('/servers/test', body)`) and keeps its answer —
 * or its refusal — until the draft changes (`clear()`); `busy` meanwhile. An answer that arrives after the draft
 * changed, or after another probe started, is about other input and is dropped.
 */
export function useProbe<T>(probe: () => Promise<T>, { onFailure }: UseProbeOptions = {}) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<T | null>(null)
  const [failure, setFailure] = useState<ApiError | null>(null)
  // Bumped by every probe and every edit: an answer counts only for the probe that is still the latest.
  const latest = useRef(0)

  const run = useCallback(async (): Promise<T | undefined> => {
    const sent = ++latest.current
    setBusy(true)
    setResult(null)
    setFailure(null)
    try {
      const data = await probe()
      if (sent !== latest.current) return undefined
      setResult(data)
      return data
    } catch (e) {
      // Anything but the API's answer is a bug of the panel's: it goes on to lib/error-reporting.
      if (!(e instanceof ApiError)) throw e
      if (sent !== latest.current) return undefined
      setFailure(e)
      onFailure?.(e)
      return undefined
    } finally {
      if (sent === latest.current) setBusy(false)
    }
  }, [onFailure, probe])

  /** The draft changed: what the last probe said (or will say) is about other input. */
  const clear = useCallback(() => {
    latest.current++
    setBusy(false)
    setResult(null)
    setFailure(null)
  }, [])

  return { busy, result, failure, run, clear }
}
