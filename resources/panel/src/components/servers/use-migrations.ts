import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { ServerMigrationRow, ServerMigrationsResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useJobRunner } from '@/lib/use-job-runner'

/** A server's card's read: its latest moves, and the clients they left on its panel. */
const migrationsQuery = (serverId: number) => queryOptions({ queryKey: queryKeys.serverMigrations(serverId), queryFn: () => api.get<ServerMigrationsResponse>(`/servers/${serverId}/migrations`) })

/** A job a turn works on: queued or running. */
export const workable = (job: ServerMigrationRow) => job.status === 'queued' || job.status === 'running'

/** A job not over yet — paused too —: the server is the source of one at a time. */
export const isOpen = (job: ServerMigrationRow) => workable(job) || job.status === 'paused'

/** What the owner does to a job from its line. */
export type MigrationAction = 'pause' | 'resume' | 'cancel' | 'rescue'

const DONE: Record<MigrationAction, string> = {
  pause: 'انتقال متوقف شد',
  resume: 'انتقال ادامه پیدا کرد',
  cancel: 'انتقال لغو شد',
  rescue: 'انتقال در حالت نجات ادامه پیدا می‌کند',
}

/** What a move changes elsewhere, read again once it is over or the owner acts on it: the services, the servers' counts, the dashboard. */
const CHANGES = [queryKeys.subscriptions, queryKeys.everyServer, queryKeys.servers, queryKeys.serverChoices, queryKeys.dashboards]

/** A run of a move ended under the card: done, or left unfinished because the target could take no more. */
function ended(job: ServerMigrationRow) {
  if (job.status === 'done') toast.success(job.moved > 0 ? `${formatNumber(job.moved)} سرویس به «${job.target.name}» منتقل شد` : 'انتقال تمام شد؛ سرویسی منتقل نشد')
  if (job.status === 'failed') toast.error('انتقال ناتمام ماند؛ دلیلش روی کارت آمده است')
}

/**
 * A server's «انتقال سرویس‌ها» card's data: its latest moves, the clients they left on its panel, and the owner's actions
 * on a move. While a move is queued or running and the card is on screen, it is worked a request at a time
 * (useJobRunner), as a grant is: one held by a panel out of reach is asked again every half minute; the scheduler carries
 * on without the card.
 */
export function useMigrations(serverId: number) {
  const queryClient = useQueryClient()
  const query = migrationsQuery(serverId)
  const read = useQuery(query)
  const current = read.data?.migrations.find(isOpen) ?? null
  const changes = [query.queryKey, ...CHANGES]

  /** The job as the server now has it, into the list: in its place, or on top when it is new. */
  const put = (job: ServerMigrationRow) =>
    queryClient.setQueryData<ServerMigrationsResponse>(
      query.queryKey,
      (data) => data && { ...data, migrations: data.migrations.some((row) => row.id === job.id) ? data.migrations.map((row) => (row.id === job.id ? job : row)) : [job, ...data.migrations] },
    )

  const act = useMutation({
    mutationFn: ({ job, action }: { job: ServerMigrationRow; action: MigrationAction }) => api.post(`/servers/${serverId}/migrations/${job.id}/${action}`),
    meta: { invalidates: changes },
    onSuccess: ({ migration }, { action }) => {
      put(migration)
      toast.success(DONE[action])
    },
    // Refused (it moved on meanwhile), the list is read again; the toast says why.
    onError: () => void queryClient.invalidateQueries({ queryKey: query.queryKey }),
  })

  useJobRunner({
    id: current !== null && workable(current) ? current.id : null,
    run: async (id) => (await api.post(`/servers/${serverId}/migrations/${id}/run`)).migration,
    workable,
    waiting: (job) => job.waiting_reason !== null,
    put,
    ended,
    invalidates: changes,
  })

  return { read, current, put, act, changes }
}
