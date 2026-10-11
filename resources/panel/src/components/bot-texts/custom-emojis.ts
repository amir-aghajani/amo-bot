import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CustomEmojiRow, CustomEmojisResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** What the panel shows for a premium emoji whose picture cannot be had and that the list does not keep (any more). */
const UNKNOWN_EMOJI = '✨'

/** The premium emoji the bot knows (an admin showed them with /emoji) and how Telegram draws each: one query for the whole panel. */
export const useCustomEmojis = () => useQuery({ queryKey: queryKeys.customEmojis, queryFn: () => api.get<CustomEmojisResponse>('/bot/custom-emojis') })

/** A kept premium emoji by its id; undefined for one the list does not keep, or while it loads. */
export function useCustomEmoji(id: string): CustomEmojiRow | undefined {
  const { data } = useCustomEmojis()

  return data?.emojis.find((emoji) => emoji.id === id)
}

/** What premium emoji met in a tag stand for (one lifted from a button's label into its icon), for the page's life. */
const fromTags = new Map<string, string>()

/** Keep what a premium emoji stands for, as its tag said, for when its id travels alone — the list may not keep it. */
export function notePlainEmoji(id: string, emoji: string): void {
  fromTags.set(id, emoji)
}

/**
 * The plain emoji a premium emoji id stands for, for an id that travels without its `<tg-emoji>` tag (a button's icon):
 * from the kept list, else from what its tag said when it was lifted (notePlainEmoji()).
 */
export function usePlainEmoji(): (id: string) => string {
  const { data } = useCustomEmojis()

  return (id) => data?.emojis.find((emoji) => emoji.id === id)?.emoji ?? fromTags.get(id) ?? UNKNOWN_EMOJI
}
