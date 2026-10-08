import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CustomEmojiRow, CustomEmojisResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** What the panel shows for a premium emoji whose picture cannot be had and that the list does not keep (any more). */
const UNKNOWN_EMOJI = '✨'

/**
 * The premium emoji the bot keeps (an admin showed it with /emoji), with how Telegram draws each one — one query for the
 * pickers and every premium emoji the panel shows.
 */
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
 * The plain emoji a premium emoji stands for, by its id — what `PremiumEmoji` shows without its picture, for an id that
 * travels alone (a keyboard button's icon) rather than inside its `<tg-emoji>` tag: the kept list's, else what its tag
 * said (notePlainEmoji()).
 */
export function usePlainEmoji(): (id: string) => string {
  const { data } = useCustomEmojis()

  return (id) => data?.emojis.find((emoji) => emoji.id === id)?.emoji ?? fromTags.get(id) ?? UNKNOWN_EMOJI
}
