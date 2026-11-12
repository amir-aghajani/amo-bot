import { appConfig } from '@/lib/config'
import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

/*
 * What the settings search opened lately, newest first, by entry id — kept in this browser for this panel (the owner's
 * and an agent's panels share the site's storage), a few more than «اخیر» shows: an id the shop on screen does not have
 * is passed over there. Storage that refuses leaves the list empty, never the search broken.
 */

/** How many are kept. */
const KEPT = 10

/** This panel's list: the owner's (`…-admin`) or the agent's (`…-agent`). */
const KEY = `${STORAGE_KEYS.settingsRecent}-${appConfig.shop === null ? 'agent' : 'admin'}`

export function readRecent(): string[] {
  try {
    const stored: unknown = JSON.parse(readStored(KEY) ?? '[]')
    return Array.isArray(stored) ? stored.filter((id): id is string => typeof id === 'string').slice(0, KEPT) : []
  } catch {
    return []
  }
}

/** Puts `id` first; returns the list as it now is. */
export function recordRecent(id: string): string[] {
  const next = [id, ...readRecent().filter((other) => other !== id)].slice(0, KEPT)
  writeStored(KEY, JSON.stringify(next))
  return next
}
