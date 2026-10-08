import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AccountResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * «حساب نمایندگی»: the agent's bot and the traffic it may still sell, their level and wallet with the shop — the account
 * page's read, and the traffic the subscriptions screen's extensions take their GB from.
 */
export const accountQuery = queryOptions({ queryKey: queryKeys.account, queryFn: () => api.get<AccountResponse>('/account') })
