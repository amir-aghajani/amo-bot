import { useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient, type QueryKey, type UseQueryOptions } from '@tanstack/react-query'
import { toast } from 'sonner'
import { giftLabel } from '@/components/grants/grant-format'
import { ApiError } from '@/lib/api'
import type { ServerGrantStatus } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'

/** What every grant row has — a server's grant, a mass gift. */
export interface GrantRowBase {
  id: number
  status: ServerGrantStatus
  days: number
  traffic_bytes: number
  granted: number
}

/** The runner's pause before asking again: after a batch, while a panel is out of reach, after a request that failed. */
const NEXT_MS = 1_000
const WAIT_MS = 30_000
const RETRY_MS = 10_000

/** `ms` of the runner's pause: true once it went by, false at once when the run is left (the card went, another grant runs). */
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

interface UseGrantsOptions<Row extends GrantRowBase, Data extends { grants: Row[] }, Q extends QueryKey> {
  /** The card's read: the latest grants, and whom a new one would reach. */
  query: UseQueryOptions<Data, Error, Data, Q>
  /** A grant's writes, by its id: a few seconds of work on it, and its stop — each answered with the grant as it is now. */
  run: (id: number) => Promise<{ grant: Row }>
  cancel: (id: number) => Promise<{ grant: Row }>
  /** The grant waits for a panel out of reach: the runner asks again in half a minute rather than at once. */
  waiting: (grant: Row) => boolean
  /** What a grant changes elsewhere, read again once it is over — done, stopped or refused: the services, the servers, the dashboard. */
  invalidates: readonly QueryKey[]
  /** The grant's name in its toasts: «افزودن زمان و حجم», «هدیه همگانی». */
  noun: string
}

/**
 * A grants card's data (a server's «افزودن زمان و حجم», the mass gift): the latest grants, putting one the server
 * answered with into the list, stopping one — and, while one is under way and its card is on screen, working on it, a
 * request at a time, each a few seconds of the server's work, so it finishes without waiting for the scheduler; one that
 * waits for its panel is asked again every half minute. Working on a grant, like stopping one, is a mutation: once it is
 * over, what it changed is read again (`invalidates`) — and the card's own read, whom the next would reach.
 */
export function useGrants<Row extends GrantRowBase, Data extends { grants: Row[] }, Q extends QueryKey>({ query, run, cancel: stop, waiting, invalidates, noun }: UseGrantsOptions<Row, Data, Q>) {
  const queryClient = useQueryClient()
  const read = useQuery(query)
  const running = read.data?.grants.find((grant) => grant.status === 'running') ?? null
  // What a grant over changed — and whom the next would reach, the card's own read.
  const changes = [query.queryKey, ...invalidates]

  /** The grant as the server now has it, into the list: in its place, or on top when it is new. */
  const put = (grant: Row) =>
    queryClient.setQueryData<Data>(
      query.queryKey,
      (current) => current && { ...current, grants: current.grants.some((row) => row.id === grant.id) ? current.grants.map((row) => (row.id === grant.id ? grant : row)) : [grant, ...current.grants] },
    )

  const cancel = useMutation({
    mutationFn: (grant: Row) => stop(grant.id),
    meta: { invalidates: changes },
    onSuccess: ({ grant }) => {
      put(grant)
      toast.success(`${noun} متوقف شد`)
    },
    // Refused (it ended meanwhile), the list is read again; the toast says why.
    onError: () => void queryClient.invalidateQueries({ queryKey: query.queryKey }),
  })

  // The runner reads the latest of these when it wakes; it restarts only for another grant.
  const latest = useRef({ put, run, waiting, noun })
  useEffect(() => {
    latest.current = { put, run, waiting, noun }
  })

  // The grant worked on until it is over — done or stopped, or refused (4xx: gone, the session ended); no answer at all is
  // asked again in a while. Its answer is whether it is over: what the grant changed is read again then, not when the
  // card left it unfinished — the scheduler carries on, and the live updates follow.
  const { mutate: work } = useMutation({
    mutationFn: async ({ id, left }: { id: number; left: AbortSignal }): Promise<boolean> => {
      for (;;) {
        let grant: Row | null = null
        try {
          grant = (await latest.current.run(id)).grant
        } catch (error) {
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) return !left.aborted
        }
        if (left.aborted) return false

        const current = latest.current
        if (grant !== null) {
          current.put(grant)
          if (grant.status !== 'running') {
            if (grant.status === 'done') {
              toast.success(grant.granted > 0 ? `${giftLabel(grant)} به ${formatNumber(grant.granted)} سرویس اضافه شد` : `${current.noun} تمام شد؛ سرویسی شامل نشد`)
            }
            return true
          }
        }
        if (!(await pause(grant === null ? RETRY_MS : current.waiting(grant) ? WAIT_MS : NEXT_MS, left))) return false
      }
    },
    meta: { invalidates: (over) => (over === true ? changes : []) },
  })
  const runningId = running?.id ?? null

  useEffect(() => {
    if (runningId === null) return
    const left = new AbortController()
    work({ id: runningId, left: left.signal })
    return () => left.abort()
  }, [runningId, work])

  return { read, running, put, cancel }
}
