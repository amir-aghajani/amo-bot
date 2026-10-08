import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

/*
 * A tab opened before an upgrade still runs the old build, whose page files the upgrade replaced: the first page it
 * has not loaded yet fails to load. One reload brings the new build; a second failure soon after is not that, and is
 * left to the error screen (no reload loop).
 */

/** How long after a reload a failed page load counts as "the reload did not help". */
const GRACE_MS = 30_000

/** A failure to load one of the build's files — the module import or its preload. */
export function isStaleBuildError(error: unknown): boolean {
  if (!(error instanceof Error)) return false
  const message = error.message.toLowerCase()
  return (
    message.includes('dynamically imported module') || // Chrome, Firefox
    message.includes('importing a module script failed') || // Safari
    message.includes('unable to preload') || // Vite's preload helper
    message.includes('is not a valid javascript mime type')
  )
}

/** Reload once for a new build: true when the page is reloading, false when it just did and that did not help. */
export function reloadForNewBuild(): boolean {
  if (reloadedLately()) return false

  writeStored(STORAGE_KEYS.reloadedAt, String(Date.now()), 'session')
  window.location.reload()
  return true
}

/**
 * The tab reloaded for a new build a moment ago: a file still missing now is not that — the reload gave up, and the
 * error screen says why (lib/failure). Without storage the grace cannot hold: never.
 */
export function reloadedLately(): boolean {
  return Date.now() - Number(readStored(STORAGE_KEYS.reloadedAt, 'session') ?? 0) < GRACE_MS
}

/** How many loads asked for ahead of need are under way. */
let ahead = 0

/**
 * A file loaded before it is needed — a page a link is about to open (lib/prefetch): a failure says nothing, and does
 * not reload the tab either (`loadingAhead`: Vite's preload error waits); the load that is needed tells, and reloads.
 */
export function loadAhead(load: () => Promise<unknown>): void {
  ahead++
  load()
    .catch(() => undefined)
    .finally(() => ahead--)
}

/** A load asked for ahead of need is under way — its failure is no reason to reload. */
export function loadingAhead(): boolean {
  return ahead > 0
}
