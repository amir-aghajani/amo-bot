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

/**
 * Reload once for a new build: true when the page is reloading; false when it just did and that did not help, and while
 * the browser is offline — the file is missing because the network is, and a reload would leave only the browser's own
 * offline page.
 */
export function reloadForNewBuild(): boolean {
  if (navigator.onLine === false || reloadedLately()) return false

  writeStored(STORAGE_KEYS.reloadedAt, String(Date.now()), 'session')
  // Where the tab keeps nothing, a page that is itself a reload of a moment ago counts as ours: never a reload loop.
  if (readStored(STORAGE_KEYS.reloadedAt, 'session') === null && pageReloadedLately()) return false
  window.location.reload()
  return true
}

/**
 * The tab reloaded for a new build a moment ago, so a file missing now is not a stale build: the error screen says the
 * build's files are incomplete (lib/failure). Always false without storage.
 */
export function reloadedLately(): boolean {
  return Date.now() - Number(readStored(STORAGE_KEYS.reloadedAt, 'session') ?? 0) < GRACE_MS
}

/** This page was itself loaded by a reload, within the grace. */
function pageReloadedLately(): boolean {
  const [navigation] = performance.getEntriesByType('navigation')
  return navigation !== undefined && 'type' in navigation && navigation.type === 'reload' && performance.now() < GRACE_MS
}

/** How many loads asked for ahead of need are under way. */
let ahead = 0

/**
 * Load a file before it is needed (a page a link is about to open, lib/prefetch). Its failure is silent and reloads
 * nothing (`loadingAhead` holds Vite's preload error back); the load that is needed reports and reloads.
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
