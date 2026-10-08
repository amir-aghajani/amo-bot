import type { QueryKey } from '@tanstack/react-query'
import type { ConfigSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'

interface ConfigGroupOptions<T extends object> {
  /** The group's PUT of the draft (`api.put('/settings/config/app', values)`) — a secret in it only when typed. */
  save: (values: T) => Promise<ConfigSettingsResponse>
  /** The toast once it is saved. */
  saved: string
  /** Other reads the group shows in, read again once it is saved (the shop's name in the brand). */
  invalidates?: readonly QueryKey[]
  /** What the values come to as the server reads them, where that is not their text trimmed (a driver's form: its payload). */
  reads?: (values: T) => unknown
}

/**
 * One group of the panel's own settings (config.php) as a card's form: a save PUTs the group and the whole screen's copy is
 * what it answers — every group is read again from the file.
 */
export function useConfigGroup<T extends object>(initial: T, { save, saved, invalidates, reads }: ConfigGroupOptions<T>) {
  return useSettingsGroup<T, ConfigSettingsResponse, ConfigSettingsResponse>({
    save,
    queryKey: queryKeys.configSettings,
    apply: (_current, result) => result,
    initial,
    saved,
    invalidates,
    reads,
  })
}
