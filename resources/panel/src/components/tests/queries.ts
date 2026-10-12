import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { TestServersResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** Every server with its test terms and whether it gives a test now in the open shop: the tests page's servers, and its notice. */
export const testServersQuery = queryOptions({ queryKey: queryKeys.testServers, queryFn: () => api.get<TestServersResponse>('/tests/servers').then((data) => data.servers) })
