import { useEffect, useRef } from 'react'
import { useMutation, type QueryKey } from '@tanstack/react-query'
import { ApiError } from '@/lib/api'

/** The runner's pause before asking again: after a turn, while a panel is out of reach, after a request that got no answer. */
const NEXT_MS = 1_000
const WAIT_MS = 30_000
const RETRY_MS = 10_000

/** `ms` of the runner's pause: true once it went by, false at once when the run is left (the card went, another job runs). */
function pause(ms: number, left: AbortSignal): Promise<boolean> {
  return new Promise((resolve) => {
    if (left.aborted) {
      resolve(false)
      return
    }
    const leave = () => {
      window.clearTimeout(timer)
      resolve(false)
    }
    const timer = window.setTimeout(() => {
      left.removeEventListener('abort', leave)
      resolve(true)
    }, ms)
    left.addEventListener('abort', leave, { once: true })
  })
}

export interface JobRunnerOptions<Job> {
  /** The job to work on now; null for none. Another id starts another run. */
  id: number | null
  /** One turn on it — a few seconds of the server's work —, answered with the job as it is now. */
  run: (id: number) => Promise<Job>
  /** Whether a turn still works on the job: once not (done, stopped, paused…), the run is over. */
  workable: (job: Job) => boolean
  /** The job waits for a panel out of reach: asked again in half a minute rather than at once. */
  waiting: (job: Job) => boolean
  /** Each answer, into the card's list. */
  put: (job: Job) => void
  /** The run is over with the job so: its toast. */
  ended: (job: Job) => void
  /** What the job changes elsewhere, read again once it is over or refused — the card's own read too. */
  invalidates: readonly QueryKey[]
}

/**
 * A background job a card works on while it is on screen — a grant, a move of a server's services —, so it finishes
 * without waiting for the scheduler: a turn at a time, the next a second after, half a minute while it waits for a panel,
 * ten seconds after a request that got no answer; until a turn no longer works it, or it is refused (4xx: gone, the
 * session ended). What it changed is read again then — not when the card left it unfinished: the scheduler carries on,
 * and the live updates follow. The options are read afresh at each step; only another `id` restarts the run.
 */
export function useJobRunner<Job>({ id, ...options }: JobRunnerOptions<Job>): void {
  const latest = useRef(options)
  useEffect(() => {
    latest.current = options
  })

  const { mutate: work } = useMutation({
    mutationFn: async ({ of, left }: { of: number; left: AbortSignal }): Promise<boolean> => {
      for (;;) {
        let job: Job | null = null
        try {
          job = await latest.current.run(of)
        } catch (error) {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) return !left.aborted
        }
        if (left.aborted) return false

        const current = latest.current
        if (job !== null) {
          current.put(job)
          if (!current.workable(job)) {
            current.ended(job)
            return true
          }
        }
        if (!(await pause(job === null ? RETRY_MS : current.waiting(job) ? WAIT_MS : NEXT_MS, left))) return false
      }
    },
    meta: { invalidates: (over) => (over === true ? latest.current.invalidates : []) },
  })

  useEffect(() => {
    if (id === null) return
    const left = new AbortController()
    work({ of: id, left: left.signal })
    return () => left.abort()
  }, [id, work])
}
