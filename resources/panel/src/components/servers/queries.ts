import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { ServerDetailResponse, ServerDriversResponse, ServersResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/* The owner's reads of the servers: the list, one server with its inbounds, the connectors a server is added with. */

export const serversQuery = queryOptions({ queryKey: queryKeys.servers, queryFn: () => api.get<ServersResponse>('/servers') })

export const serverQuery = (id: number) => queryOptions({ queryKey: queryKeys.server(id), queryFn: () => api.get<ServerDetailResponse>(`/servers/${id}`) })

/** The connectors the API knows: the add-server picker, the server page's form. They change only with an upgrade. */
export const serverDriversQuery = queryOptions({
  queryKey: queryKeys.serverDrivers,
  queryFn: () => api.get<ServerDriversResponse>('/servers/drivers').then((data) => data.drivers),
  staleTime: 5 * 60_000,
})
