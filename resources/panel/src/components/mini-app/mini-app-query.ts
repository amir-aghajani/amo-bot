import { createContext, useCallback, useContext } from 'react'
import { queryOptions, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { MiniAppSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * The shop's Telegram mini app settings (the server creates them, switched off, on first read). Saves and a logo
 * changed answer the whole app, which replaces this read rather than reading it again.
 */
export const miniAppQuery = queryOptions({ queryKey: queryKeys.miniApp, queryFn: () => api.get<MiniAppSettingsResponse>('/mini-app') })

/**
 * What the settings page is told as a card saves — its preview opens the app again with what was saved (a read again,
 * on a reconnect, does not) —; nothing outside the page.
 */
export const MiniAppSavedContext = createContext<() => void>(() => undefined)

/** A save's answer, or how to make it of the read (the logo's keeps the word about the menu button). */
type Answer = MiniAppSettingsResponse | ((current: MiniAppSettingsResponse | undefined) => MiniAppSettingsResponse)

/** Puts a card's save's answer in the read's place, and tells the page a card saved. */
export function useApplyMiniApp(): (answer: Answer) => void {
  const queryClient = useQueryClient()
  const saved = useContext(MiniAppSavedContext)

  return useCallback(
    (answer: Answer) => {
      queryClient.setQueryData<MiniAppSettingsResponse>(queryKeys.miniApp, answer)
      saved()
    },
    [queryClient, saved],
  )
}
