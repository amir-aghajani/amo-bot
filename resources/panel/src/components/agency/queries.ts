import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { AgencyLevelsResponse, AgencySettingsResponse, AgencySummaryResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/* The owner's reads of the agency program, each defined once: the page's numbers (the sidebar's pending count too), the levels, the rules. */

export const agencySummaryQuery = queryOptions({ queryKey: queryKeys.agency, queryFn: () => api.get<AgencySummaryResponse>('/agency') })

export const agencyLevelsQuery = queryOptions({ queryKey: queryKeys.agencyLevels, queryFn: () => api.get<AgencyLevelsResponse>('/agency/levels') })

export const agencySettingsQuery = queryOptions({ queryKey: queryKeys.agencySettings, queryFn: () => api.get<AgencySettingsResponse>('/agency/settings') })
