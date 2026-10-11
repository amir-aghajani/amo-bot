import type { QueryKey } from '@tanstack/react-query'
import type { BotSettingsResponse, BotSettingsSaved } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'

interface BotSettingsGroupOptions<T> {
  /** What else shows the group's values, read again once it is saved. */
  invalidates?: readonly QueryKey[]
  /** What the values come to as the server reads them, where that is not their text trimmed (a list kept as a set). */
  reads?: (values: T) => unknown
}

/**
 * One bot-settings group as a card's form: the draft starts from `initial` and follows the server's copy; `save` PUTs
 * the group as typed (the server reads numbers the way an admin types them), and the answered settings go into the
 * page's cached copy.
 */
export function useBotSettingsGroup<T extends object>(initial: T, save: (values: T) => Promise<BotSettingsSaved>, { invalidates, reads }: BotSettingsGroupOptions<T> = {}) {
  return useSettingsGroup<T, BotSettingsResponse, BotSettingsSaved>({
    save,
    queryKey: queryKeys.botSettings,
    apply: (current, result) => current && { ...current, settings: result.settings },
    initial,
    saved: 'تنظیمات ربات ذخیره شد',
    invalidates,
    reads,
  })
}
