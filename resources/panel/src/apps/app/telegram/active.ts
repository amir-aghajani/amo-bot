import { useEffect, useState } from 'react'
import { telegram } from '@/apps/app/telegram/webapp'

/*
 * Whether the customer has the app in front of them. A screen that reads something again every few seconds does so only
 * then: react-query alone stops when the page is hidden, but a mini app minimised in Telegram (Bot API 8.0) may keep its
 * page shown — Telegram says so with its `deactivated` and `activated` events.
 */

/** Whether the app is in front: its page shown, and — Bot API 8.0 — the mini app not minimised in Telegram. */
export function useAppActive(): boolean {
  const [active, setActive] = useState(() => document.visibilityState !== 'hidden')
  useEffect(() => {
    const app = telegram()
    const visible = () => setActive(document.visibilityState !== 'hidden')
    const activated = () => setActive(true)
    const deactivated = () => setActive(false)
    document.addEventListener('visibilitychange', visible)
    app?.onEvent('activated', activated)
    app?.onEvent('deactivated', deactivated)

    return () => {
      document.removeEventListener('visibilitychange', visible)
      app?.offEvent('activated', activated)
      app?.offEvent('deactivated', deactivated)
    }
  }, [])

  return active
}
