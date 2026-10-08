import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { WebsiteResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * The shop's website as its settings page shows it — made, switched off, the first time it is asked for. Its one read:
 * every card's save and a new key answer the website whole, which goes back in place of it (setQueryData), never read
 * again.
 */
export const websiteQuery = queryOptions({ queryKey: queryKeys.website, queryFn: () => api.get<WebsiteResponse>('/website') })
