import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { isStepping } from '@/components/imports/words'
import { api, ApiError } from '@/lib/api'
import type { ImportState, ImportStepResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** The pause between two steps, as a background job's runner leaves (lib/use-job-runner's NEXT_MS). */
const NEXT_MS = 1_000

/** The pause before asking again when a step was not taken: another step (another tab, the console) holds the import. */
const HELD_MS = 3_000

/** The first wait after a step that got no answer, doubled after each one more. */
const FIRST_RETRY_MS = 2_000

/** How many steps in a row may fail before the runner stops asking on its own: one that fails every time is said. */
const GIVE_UP_AFTER = 4

/** A step that failed: the failure, for the import it was of, and whether the runner asks again by itself. */
interface Trouble {
  of: number
  error: ApiError
  again: boolean
}

/**
 * `ms` of the runner's pause — or, with null, until `wake` —: true once it went by or was cut short, false at once when
 * the run is left.
 */
function pause(ms: number | null, left: AbortSignal, wake: { current: (() => void) | null }): Promise<boolean> {
  return new Promise((resolve) => {
    if (left.aborted) {
      resolve(false)
      return
    }
    const end = (went: boolean) => {
      if (timer !== undefined) window.clearTimeout(timer)
      left.removeEventListener('abort', leave)
      wake.current = null
      resolve(went)
    }
    const leave = () => end(false)
    const timer = ms === null ? undefined : window.setTimeout(() => end(true), ms)
    wake.current = () => end(true)
    left.addEventListener('abort', leave, { once: true })
  })
}

export interface ImportRunner {
  /**
   * The last step failed: the failure, and whether the next try goes by itself after a wait (`again`) — or only on
   * `retry()`: a step refused, or one that failed several times in a row.
   */
  trouble: { error: ApiError; again: boolean } | null
  /** Try the next step now, without waiting. */
  retry: () => void
}

/**
 * The import's steps while it reads or writes and the screen is on: `POST continue` — a step of the server's work, about
 * fifteen seconds — over and over, a second apart, each answer handed to `put`, until the import is no longer stepping
 * (reviewed, done, failed, cancelled). A step not taken — another one holds the import (another tab, the console) — is
 * asked again after a few seconds. A step that got no answer, or the server's own failure, is tried again after a wait
 * that doubles; after a few such in a row, and at once for a step refused, the runner stops asking on its own and the
 * failure is said until `retry()` — a refused step reads the imports again too, in case it moved on. A step on an import
 * that is gone (404) ends the run and the imports are read again; a session that ended (401) ends it as well. Leaving
 * the screen leaves the run; an open import is the imports' `current`, so coming back to it starts the run again where
 * the import stands. Only another `id` restarts it.
 */
export function useImportRunner(id: number | null, put: (state: ImportState) => void): ImportRunner {
  const queryClient = useQueryClient()
  const latest = useRef(put)
  useEffect(() => {
    latest.current = put
  })
  const wake = useRef<(() => void) | null>(null)
  const [trouble, setTrouble] = useState<Trouble | null>(null)

  const { mutate: run } = useMutation({
    mutationFn: async ({ of, left }: { of: number; left: AbortSignal }): Promise<boolean> => {
      let failures = 0
      for (;;) {
        let answer: ImportStepResponse
        try {
          answer = await api.post(`/imports/${of}/continue`)
        } catch (error) {
          // The panel's own bug ends the run, said and reported as any mutation's.
          if (!(error instanceof ApiError)) throw error
          if (left.aborted) return false
          if (error.status === 401 || error.status === 404) {
            setTrouble(null)
            return true
          }
          const transient = error.status === 0 || error.status >= 500 || error.status === 429
          failures += 1
          const again = transient && failures < GIVE_UP_AFTER
          setTrouble({ of, error, again })
          if (!transient) void queryClient.invalidateQueries({ queryKey: queryKeys.imports })
          const asked = error.status === 429 && error.facts.retryAfter ? error.facts.retryAfter * 1000 : 0
          if (!(await pause(again ? Math.max(asked, FIRST_RETRY_MS * 2 ** (failures - 1)) : null, left, wake))) return false
          continue
        }
        if (left.aborted) return false
        failures = 0
        setTrouble(null)
        latest.current(answer.import)
        if (!isStepping(answer.import)) return true
        if (!(await pause(answer.stepped ? NEXT_MS : HELD_MS, left, wake))) return false
      }
    },
    meta: { invalidates: (over) => (over === true ? [queryKeys.imports] : []) },
  })

  useEffect(() => {
    if (id === null) return
    const left = new AbortController()
    run({ of: id, left: left.signal })
    return () => left.abort()
  }, [id, run])

  return { trouble: trouble !== null && trouble.of === id ? { error: trouble.error, again: trouble.again } : null, retry: () => wake.current?.() }
}
