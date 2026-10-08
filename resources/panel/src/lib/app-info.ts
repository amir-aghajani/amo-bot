import { useQuery } from '@tanstack/react-query'
import { rootApi } from '@/lib/api'
import type { AppInfo } from '@/lib/api-types'
import { setShopTimeZone } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/**
 * GET /api/app, asked once per load (and again when the owner saves it): the shop's name for the brand, whether it is
 * installed, and its time zone — every date the panel shows reads in it from the moment it is known.
 */
export function useAppInfo() {
  return useQuery({ queryKey: queryKeys.app, queryFn: readAppInfo, staleTime: Infinity })
}

async function readAppInfo(): Promise<AppInfo> {
  const info = await rootApi.get<AppInfo>('/app')
  setShopTimeZone(info.timezone)
  return info
}

/** The shop's name, as the brand shows it. */
export function useAppName(): string {
  return useAppInfo().data?.name || 'AmoBot'
}
