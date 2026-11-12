import { useEffect, useRef, useSyncExternalStore } from 'react'
import { fitText, telegram, when, type TelegramWebApp } from '@/apps/app/telegram/webapp'

/*
 * Telegram's own buttons as React hooks: the back button in its header (Bot API 6.1) and the main button at the bottom
 * of its screen. A screen that uses one owns it while it is shown and leaves it hidden when it goes; the main button is
 * put away while a sheet is open over the screen. Outside Telegram neither exists and a screen draws its own
 * (useMainButton's `native` says which) — only in the tests: app.tsx's gate shows a browser no screen.
 */

/** Who holds the back button now, the latest last: a sheet over a screen closes before the screen goes back. */
const holders: { current: (() => void) | null }[] = []

/** The one handler Telegram's back button calls: the latest holder's. */
function pressed(): void {
  holders.at(-1)?.current?.()
}

/** The Telegram whose back button calls `pressed`: listened to once (a new one — a test's — is listened to in turn). */
let listening: TelegramWebApp | null = null

/** Telegram's button shown while anyone holds it. */
function sync(): void {
  when('6.1', (app) => {
    if (listening !== app) {
      app.BackButton.onClick(pressed)
      listening = app
    }
    if (holders.length > 0) app.BackButton.show()
    else app.BackButton.hide()
  })
}

/**
 * Telegram's back button while `onBack` is given: tapping it calls the latest `onBack` of whoever took it last (a sheet
 * over its screen). Let go when `onBack` turns null or the component goes; hidden once nobody holds it.
 */
export function useBackButton(onBack: (() => void) | null): void {
  const latest = useRef(onBack)
  useEffect(() => {
    latest.current = onBack
  })
  const shown = onBack !== null

  useEffect(() => {
    if (!shown) return
    const holder = latest
    holders.push(holder)
    sync()

    return () => {
      holders.splice(holders.lastIndexOf(holder), 1)
      sync()
    }
  }, [shown])
}

export interface MainButtonOptions {
  /** Its words; null hides it. */
  text: string | null
  onClick: () => void
  /** False greys it out (a form not ready). */
  enabled?: boolean
  /** A spinner on it while the action runs; it takes no tap meanwhile. */
  busy?: boolean
  /** Telegram's shine across it: the one action the screen is for (a purchase's payment). */
  shine?: boolean
}

/** The most a bottom button's words may be (Telegram's script throws on more). */
const BUTTON_MOST = 64

/** A call to Telegram's main button. Its refusal (the script throws on what it will not take) leaves the screen standing. */
function attempt(call: () => void): void {
  try {
    call()
  } catch {
    // The button stays as it was; the screen goes on.
  }
}

/** How many sheets are open over the screen now (ui/sheet), and who is told when that changes. */
let sheets = 0
const sheetWatchers = new Set<() => void>()

function watchSheets(watcher: () => void): () => void {
  sheetWatchers.add(watcher)

  return () => {
    sheetWatchers.delete(watcher)
  }
}

const anySheet = () => sheets > 0

function countSheets(change: number): void {
  sheets += change
  sheetWatchers.forEach((watcher) => watcher())
}

/**
 * A sheet open over the screen while `open` (ui/sheet). Telegram's main button sits outside the page, where a tap would
 * reach the screen beneath the sheet: it is put away while any sheet is open, and shown again as it was once the last
 * one closes.
 */
export function useSheetOpen(open: boolean): void {
  useEffect(() => {
    if (!open) return
    countSheets(1)

    return () => countSheets(-1)
  }, [open])
}

/**
 * Telegram's main button while `text` is given — enabled, busy and shining as asked, its words cut to what Telegram
 * takes —, tapping it calling the latest `onClick`; put away while a sheet is open over the screen. Answers whether
 * Telegram draws it: false outside Telegram, where the screen draws its own button.
 */
export function useMainButton({ text, onClick, enabled = true, busy = false, shine = false }: MainButtonOptions): boolean {
  const latest = useRef(onClick)
  useEffect(() => {
    latest.current = onClick
  })
  const app = telegram()
  const covered = useSyncExternalStore(watchSheets, anySheet)
  const words = text === null || text.trim() === '' ? null : fitText(text, BUTTON_MOST)

  useEffect(() => {
    if (app === null || words === null) return
    const button = app.MainButton
    const click = () => {
      if (enabled && !busy && !anySheet()) latest.current()
    }
    attempt(() => button.onClick(click))

    return () => attempt(() => button.offClick(click))
  }, [app, words, enabled, busy])

  useEffect(() => {
    if (app === null) return
    const button = app.MainButton
    if (words === null || covered) {
      attempt(() => button.setParams({ is_visible: false }))
      return
    }
    // The spinner first: hiding it makes the button active again, so whether it is active is set after.
    attempt(() => {
      if (busy) button.showProgress(false)
      else if (button.isProgressVisible) button.hideProgress()
    })
    attempt(() => button.setParams({ text: words, is_visible: true, is_active: enabled && !busy, ...(app.isVersionAtLeast('7.10') ? { has_shine_effect: shine } : {}) }))
  }, [app, words, enabled, busy, shine, covered])

  useEffect(() => {
    if (app === null) return

    return () => {
      attempt(() => app.MainButton.hideProgress())
      attempt(() => app.MainButton.setParams({ is_visible: false }))
    }
  }, [app])

  return app !== null && text !== null
}
