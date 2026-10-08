import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PlanOptions } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** A server as the subscriptions screen picks one: to narrow the list by, or to move a service to. */
interface ServerChoice {
  id: number
  name: string
  /** Why nothing can be moved onto it now — the server's own answer (switched off, without subscription links, full…); null when it can. */
  unsellable_reason: string | null
  /** Its running services, when the panel reads them (the owner's: every shop's); null otherwise. */
  active: number | null
}

/** Where a panel reads the servers its subscriptions screen picks from. */
export type ServerChoices = () => Promise<ServerChoice[]>

/** The shop's servers as the plan form offers them — what any panel may read. */
export const shopServerChoices: ServerChoices = () =>
  api.get<PlanOptions>('/plans/options').then((data) => data.servers.map((server) => ({ id: server.id, name: server.name, unsellable_reason: server.unsellable_reason, active: null })))

/** The servers, read the panel's way (one way per panel, so one key). */
export function useServerChoices(read: ServerChoices) {
  return useQuery({ queryKey: queryKeys.serverChoices, queryFn: read })
}
