import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { preferencesQuery } from '@/components/settings-search/preferences'
import { api } from '@/lib/api'
import type { PanelPreferences } from '@/lib/api-types'
import { toastFailure } from '@/lib/failure'
import { formatNumber } from '@/lib/format'

/** The most favorites the server keeps (PanelFavoritesRequest). */
const FAVORITES_MOST = 100

/** The favorites' writes run one after another, so the server ends on the last list. */
const SCOPE = { id: 'panel-favorites' }

/**
 * The settings search's favorites — the stored list, in the user's order, ids the panel on screen does not have among
 * them (a main shop's section while an agent's shop is open, a setting since removed): they are hidden, never dropped.
 *
 * A change shows at once and goes to the server as the whole list. Changes in a row go one after another, each from the
 * list as it is by then, so the last one wins; a failure puts back what the server holds — unless another change is on
 * its way, which carries the list — and says so. Read only while the search is open (`open`): as it first opens, and
 * again on an opening once the list is a minute old.
 */
export function useFavorites(open: boolean) {
  const client = useQueryClient()
  const { queryKey } = preferencesQuery
  const read = useQuery({ ...preferencesQuery, subscribed: open })
  /** The writes under way, and the list the server last answered. */
  const writes = useRef(0)
  const confirmed = useRef<PanelPreferences | null>(null)

  const save = useMutation({
    scope: SCOPE,
    // Said here, in the search's own words.
    meta: { quiet: true },
    mutationFn: (favorites: string[]) => api.put('/preferences/favorites', { favorites }),
    onMutate: async (favorites) => {
      // Before the first of a row of changes, the cache holds what the server does.
      if (writes.current === 0) confirmed.current = client.getQueryData(queryKey) ?? null
      writes.current++
      client.setQueryData(queryKey, (current) => current && { ...current, favorites })
      // A read under way would land the old list over the new one.
      await client.cancelQueries({ queryKey })
    },
    onSuccess: (answer) => {
      confirmed.current = answer.preferences
      if (writes.current === 1) client.setQueryData(queryKey, answer.preferences)
    },
    onError: (error) => {
      if (writes.current === 1 && confirmed.current !== null) client.setQueryData(queryKey, confirmed.current)
      toastFailure(error, 'علاقه‌مندی‌ها ذخیره نشد')
    },
    onSettled: () => {
      writes.current--
    },
  })

  /** The stored list as it is now — not as this render saw it —; undefined until it is read. */
  const now = () => client.getQueryData(queryKey)?.favorites

  /** Stars an entry, or takes its star off; nothing until the list is read (a change then would drop what is stored). */
  const toggle = (id: string): boolean | null => {
    const current = now()
    if (current === undefined) return null
    const starring = !current.includes(id)
    if (starring && current.length >= FAVORITES_MOST) {
      toast.error(`بیشتر از ${formatNumber(FAVORITES_MOST)} علاقه‌مندی نمی‌شود داشت`, { description: 'یکی را بردارید و دوباره امتحان کنید.' })
      return null
    }
    save.mutate(starring ? [...current, id] : current.filter((favorite) => favorite !== id))
    return starring
  }

  /** Moves a favorite one place up (-1) or down (1) among those `shown` — the hidden ones stay where they are. */
  const move = (id: string, delta: -1 | 1, shown: ReadonlySet<string>): boolean => {
    const current = now()
    if (current === undefined) return false
    const from = current.indexOf(id)
    let to = from + delta
    while (to >= 0 && to < current.length && !shown.has(current[to] ?? '')) to += delta
    const other = current[to]
    if (from === -1 || other === undefined) return false
    const next = [...current]
    next[from] = other
    next[to] = id
    save.mutate(next)
    return true
  }

  return { favorites: read.data?.favorites, read, toggle, move }
}
