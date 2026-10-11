import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ErrorState } from '@/components/error-state'
import { Skeleton } from '@/components/ui/skeleton'
import type { BotSettingsData } from '@/lib/api-types'
import { botSettingsQuery } from '@/lib/queries'

interface WithBotSettingsProps {
  /** What failed to load, in the failure's title: «تنظیمات گردونه». */
  what: string
  children: (settings: BotSettingsData) => ReactNode
}

/**
 * Draws a bot-settings card that lives on a page of its own (cashback, discount codes, gift codes, the draw, the wheel)
 * once the settings are read: a placeholder meanwhile, the failure with a retry in its place.
 */
export function WithBotSettings({ what, children }: WithBotSettingsProps) {
  const { data, error, refetch, isRefetching } = useQuery(botSettingsQuery)
  if (!data) {
    return error ? <ErrorState what={what} error={error} onRetry={() => void refetch()} retrying={isRefetching} /> : <Skeleton className="h-40 w-full rounded-xl" />
  }

  return children(data.settings)
}
