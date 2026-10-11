import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { WebsiteResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * The shop's website settings (the server creates them, switched off, on first read). Saves answer the whole website,
 * which replaces this read rather than reading it again.
 */
export const websiteQuery = queryOptions({ queryKey: queryKeys.website, queryFn: () => api.get<WebsiteResponse>('/website') })
