/*
 * The browser storage the panels keep their conveniences in — the theme, the sidebar, its folds, the stale-build reload.
 * Storage may refuse (private mode, blocked site data, a full quota): a read then has nothing, a write is lost, and the
 * panel carries on with its defaults.
 */

/** Every key, shared by both panels. The theme's is read by each index.html's inline script too, before React loads. */
export const STORAGE_KEYS = {
  theme: 'amobot-panel-theme',
  sidebar: 'amobot-panel-sidebar',
  closedGroups: 'amobot-panel-nav-closed',
  reloadedAt: 'amobot-panel-reloaded-at',
  /** The installer's key (storage/install-key.txt) as the owner typed it — for the tab only (session storage). */
  installKey: 'amobot-install-key',
} as const

type Kind = 'local' | 'session'

function storage(kind: Kind): Storage {
  return kind === 'local' ? window.localStorage : window.sessionStorage
}

export function readStored(key: string, kind: Kind = 'local'): string | null {
  try {
    return storage(kind).getItem(key)
  } catch {
    return null
  }
}

export function writeStored(key: string, value: string, kind: Kind = 'local'): void {
  try {
    storage(kind).setItem(key, value)
  } catch {
    // Not kept: the panel works the same, it just forgets.
  }
}

export function forgetStored(key: string, kind: Kind = 'local'): void {
  try {
    storage(kind).removeItem(key)
  } catch {
    // Nothing to forget where nothing could be kept.
  }
}
