import { useMutation, useQuery, useQueryClient, type QueryKey, type UseQueryOptions } from '@tanstack/react-query'
import { toast } from 'sonner'
import { giftLabel } from '@/components/grants/grant-format'
import type { ServerGrantStatus } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { useJobRunner } from '@/lib/use-job-runner'

/** What every grant row has — a server's grant, a mass gift. */
export interface GrantRowBase {
  id: number
  status: ServerGrantStatus
  days: number
  traffic_bytes: number
  granted: number
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
 * A grants card's data: the latest grants, and stopping one. While a grant is under way and the card is on screen, it is
 * worked a request at a time (useJobRunner) so it finishes without waiting for the scheduler; one waiting on its panel is
 * retried every half minute. Running and stopping are mutations, so `invalidates` and the card's own read are refreshed
 * once they end.
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
      toast.success(`${noun} لغو شد`)
    },
    // Refused (it ended meanwhile), the list is read again; the toast says why.
    onError: () => void queryClient.invalidateQueries({ queryKey: query.queryKey }),
  })

  useJobRunner({
    id: running?.id ?? null,
    run: async (id) => (await run(id)).grant,
    workable: (grant) => grant.status === 'running',
    waiting,
    put,
    ended: (grant) => {
      if (grant.status === 'done') toast.success(grant.granted > 0 ? `${giftLabel(grant)} به ${formatNumber(grant.granted)} سرویس اضافه شد` : `${noun} تمام شد؛ سرویسی شامل نشد`)
    },
    invalidates: changes,
  })

  return { read, running, put, cancel }
}
