import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * «تایید خودکار واریز» as the shop has it: its switch, the address each phone app posts to and the key it sends (only
 * while it is on), when the last SMS and test came, and how many of the week approved nothing. Its switch and a new key
 * answer it whole, put in place of this read.
 */
export const depositSettingsQuery = queryOptions({ queryKey: queryKeys.depositSettings, queryFn: () => api.get<DepositSettingsResponse>('/deposits/settings') })
