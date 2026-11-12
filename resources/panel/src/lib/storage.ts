/*
 * Browser storage, for conveniences only (the theme, the sidebar, its folds, the stale-build reload). Storage may refuse
 * (private mode, blocked site data, a full quota): a read then returns null, a write is lost, and the panel carries on
 * with its defaults.
 */

/** Every key, shared by both panels. The theme's is read by each index.html's inline script too, before React loads. */
export const STORAGE_KEYS = {
  theme: 'amobot-panel-theme',
  sidebar: 'amobot-panel-sidebar',
  closedGroups: 'amobot-panel-nav-closed',
  reloadedAt: 'amobot-panel-reloaded-at',
  /** The installer's key (storage/install-key.txt) as the owner typed it — for the tab only (session storage). */
  installKey: 'amobot-install-key',
  /** The mini app's preview's fingerprint, as a phone keeps it for the bot: asked, allowed, the app's token. */
  previewFingerprint: 'amobot-panel-preview-fingerprint',
  /** What the settings search opened lately, newest first — a list a panel, its name after the key (`…-admin`, `…-agent`). */
  settingsRecent: 'amobot-panel-settings-recent',
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
