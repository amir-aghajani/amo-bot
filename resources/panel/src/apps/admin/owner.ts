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

/** Every shop the owner may open, the main bot's first (the shop picker's read; a shell read, after the page's). */
export function useShops() {
  return useQuery({ queryKey: queryKeys.shops, queryFn: () => api.get<ShopsResponse>('/shops').then((data) => data.shops), enabled: useShellReads() })
}

/** A shop as a menu item's link (useOpenShop's `link`): `onSelect` for the item, `href` and `onClick` for its <a>. */
export interface ShopLink {
  href: string
  onClick: (event: MouseEvent) => void
  onSelect: () => void
}

/** A press the page handles itself: the main button with no key that asks for a new tab or window. */
const plainPress = (event: MouseEvent) => event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey

/**
 * Opening a shop (the shop picker, the agents list): ask about unsaved drafts first, then load the panel afresh at the
 * shop's dashboard address — nothing of the current shop stays in memory. `opening` is the shop being opened.
 *
 * `link(id)` is a menu item that is a real link (DropdownMenuItem asChild over an <a>): `onSelect` goes on the item,
 * `href` and `onClick` on the link. A new tab or window opens it as any link; a plain press (a click, Enter) goes through
 * `open` from the item's select, so the menu closes first — a press the link itself held back (preventDefault) would
 * keep the menu open, and its pointer lock, under the unsaved-changes question.
 */
export function useOpenShop() {
  const [opening, setOpening] = useState<number | null>(null)

  const open = useCallback(async (id: number) => {
    if ((await confirmLeave()) === null) return
    setOpening(id)
    window.location.assign(shopHome(id))
  }, [])

  const link = useCallback(
    (id: number): ShopLink => {
      // The press on the link: the item's select runs inside it, after the link's own handler.
      let press: MouseEvent | null = null
      return {
        href: shopHome(id),
        onClick: (event: MouseEvent) => {
          press = event
        },
        onSelect: () => {
          if (press === null || !plainPress(press)) return
          press.preventDefault()
          void open(id)
        },
      }
    },
    [open],
  )

  return { open, opening, link }
}
