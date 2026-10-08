import { useCallback, useRef, useState } from 'react'
import { ApiError } from '@/lib/api'

interface UseProbeOptions {
  /** Where a refusal goes when the screen shows it somewhere other than under the button (a field, the form). */
  onFailure?: (failure: ApiError) => void
}

/**
 * A "test it" button beside a form: `run()` sends the probe — the request its screen writes, of the draft as it stands
 * (`api.post('/servers/test', body)`) —, keeps what came back until the next edit (`clear()`), locks the button
 * meanwhile. The refusal is kept too, for the screens that show it in place; `onFailure` is for the ones that route it
 * elsewhere. An answer that comes after the draft changed (or after another probe started) is about other input: it is
 * dropped, unsaid.
 */
export function useProbe<T>(probe: () => Promise<T>, { onFailure }: UseProbeOptions = {}) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<T | null>(null)
  const [failure, setFailure] = useState<ApiError | null>(null)
  // The latest probe, or edit: an answer belongs to the probe that is still the latest.
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
      // Anything but the API's answer is the panel's own bug: it goes on, to the panel's net for those (lib/error-reporting).
      if (!(e instanceof ApiError)) throw e
      if (sent !== latest.current) return undefined
      setFailure(e)
      onFailure?.(e)
      return undefined
    } finally {
      if (sent === latest.current) setBusy(false)
    }
  }, [onFailure, probe])

  /** The draft changed: what the last probe said — or will say — is about other input. */
  const clear = useCallback(() => {
    latest.current++
    setBusy(false)
    setResult(null)
    setFailure(null)
  }, [])

  return { busy, result, failure, run, clear }
}
