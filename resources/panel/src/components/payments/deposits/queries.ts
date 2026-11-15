import { queryOptions, type QueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * «تایید خودکار واریز» as the shop has it: its switch, the address each phone app posts to and the key it sends (only
 * while it is on), when the last SMS and test came, how many of the week approved nothing, and Rikhtam's connection.
 * Its switch, a new key and each change to Rikhtam answer it whole, put in place of this read.
 */
export const depositSettingsQuery = queryOptions({ queryKey: queryKeys.depositSettings, queryFn: () => api.get<DepositSettingsResponse>('/deposits/settings') })

/** Puts the settings a change answered whole (a new key, each change to Rikhtam) in place of the read. */
export function keepDepositSettings(client: QueryClient, settings: DepositSettingsResponse): void {
  client.setQueryData<DepositSettingsResponse>(queryKeys.depositSettings, settings)
}
