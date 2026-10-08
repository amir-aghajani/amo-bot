import { useSyncExternalStore } from 'react'
import { onlineManager } from '@tanstack/react-query'

const subscribe = (changed: () => void) => onlineManager.subscribe(changed)

/**
 * Whether the browser is online, as react-query reads it (its onlineManager, the browser's `online` and `offline`
 * events): offline, the panel's reads and changes wait — paused, not failed — and the live poll with them; back online,
 * they go on, and the poll asks at once.
 */
export function useOnline(): boolean {
  return useSyncExternalStore(subscribe, () => onlineManager.isOnline())
}
