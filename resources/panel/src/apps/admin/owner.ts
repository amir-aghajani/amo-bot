import { useCallback, useState, type MouseEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useShellReads } from '@/components/shell/shell-context'
import { api } from '@/lib/api'
import type { CredentialsRequest, RecoveryRequest, ShopsResponse } from '@/lib/api-types'
import { useAuth } from '@/lib/auth'
import { shopHome } from '@/lib/config'
import { queryKeys } from '@/lib/query-keys'
import { confirmLeave } from '@/lib/use-unsaved-guard'

/** The owner's ways into their panel and around it, on top of its session (lib/auth). */
export function useOwner() {
  const { enter, renew } = useAuth()

  /** Sign in with the login config.php keeps: the panel opens on the shop its address names. */
  const login = useCallback((username: string, password: string) => enter(() => api.post('/auth/login', { username, password }, { signIn: true })), [enter])

  /** Change the login config.php keeps: this browser stays signed in under it, in the shop it has open; every other is signed out. */
  const changeCredentials = useCallback((change: CredentialsRequest) => renew(() => api.put('/auth/credentials', change)), [renew])

  /** A lost login's way back in begins: the panel writes a one-time key to the host's files — where, and until when. */
  const recoveryKey = useCallback(() => api.post('/auth/recovery/key'), [])

  /** A new login set with that key: signed in under it, in the shop the address names; every other session ends. */
  const recover = useCallback((recovery: RecoveryRequest) => enter(() => api.post('/auth/recovery', recovery, { signIn: true })), [enter])

  return { login, changeCredentials, recoveryKey, recover }
}

/**
 * Every shop the owner may open — the main bot's first; live: an agent's new shop shows up (the `agency` area). The shop
 * picker's, a read of the shell's own: behind the page's (`useShellReads()`).
 */
export function useShops() {
  return useQuery({ queryKey: queryKeys.shops, queryFn: () => api.get<ShopsResponse>('/shops').then((data) => data.shops), enabled: useShellReads() })
}

/** A press the page handles itself: the main button, no key that asks the browser for a new tab or window. */
const plainPress = (event: MouseEvent) => event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey

/**
 * Opening a shop — the shop picker's, the agents list's: the unsaved-changes question first (every draft goes), then the
 * panel loaded afresh at the shop's dashboard, whose address names it (lib/config) — nothing of this shop's stays on
 * screen, in the cache or under way. `opening` is the shop being opened meanwhile. A link to a shop (`link(id)`) is its
 * address: opened in a new tab as any link is, and here, by a plain press, the same way.
 */
export function useOpenShop() {
  const [opening, setOpening] = useState<number | null>(null)

  const open = useCallback(async (id: number) => {
    if ((await confirmLeave()) === null) return
    setOpening(id)
    window.location.assign(shopHome(id))
  }, [])

  const link = useCallback(
    (id: number) => ({
      href: shopHome(id),
      onClick: (event: MouseEvent) => {
        if (!plainPress(event)) return
        event.preventDefault()
        void open(id)
      },
    }),
    [open],
  )

  return { open, opening, link }
}
