import { queryOptions } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PanelPreferences, PanelPreferencesResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * What the panel's user keeps for themselves on every device — their favorites in the settings search —, read as the
 * search first opens (or its trigger is about to be pressed), and again on an opening a minute later.
 */
export const preferencesQuery = queryOptions({
  queryKey: queryKeys.preferences,
  queryFn: () => api.get<PanelPreferencesResponse>('/preferences').then((data): PanelPreferences => data.preferences),
  staleTime: 60_000,
})
