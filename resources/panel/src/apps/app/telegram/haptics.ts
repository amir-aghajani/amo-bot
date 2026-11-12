import { when } from '@/apps/app/telegram/webapp'

/*
 * The phone's touch feedback (Telegram's HapticFeedback, Bot API 6.1): a tap's weight, a result, a choice moving. Each
 * does nothing outside Telegram or on a client without it.
 */

/** A tap on something that does a thing: light for a plain one, medium for one that commits, heavy for a big moment. */
export function tap(weight: 'light' | 'medium' | 'heavy' | 'soft' | 'rigid' = 'light'): void {
  when('6.1', (app) => app.HapticFeedback.impactOccurred(weight))
}

/** The outcome of an action: it worked, it needs a look, it failed. */
export function result(kind: 'success' | 'warning' | 'error'): void {
  when('6.1', (app) => app.HapticFeedback.notificationOccurred(kind))
}

/** A choice moved (a tab, a segment, a slider's step) — never for a confirmation. */
export function selection(): void {
  when('6.1', (app) => app.HapticFeedback.selectionChanged())
}
