import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AccountResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** The agent's account (bot, traffic, level, wallet): the account page's read, and the traffic extensions draw on. */
export const accountQuery = queryOptions({ queryKey: queryKeys.account, queryFn: () => api.get<AccountResponse>('/account') })
