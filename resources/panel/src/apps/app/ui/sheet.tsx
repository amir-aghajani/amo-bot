import { useEffect, useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useBackButton, useSheetOpen } from '@/apps/app/telegram/buttons'
import { cn } from '@/lib/utils'

/*
 * A sheet that rises from the bottom over the screen (a payment, a choice, a form of a few fields): the page beneath dims
 * and stays put, a tap outside, Escape or Telegram's back button closes it, and it keeps clear of the phone's bottom edge
 * and of Telegram's controls over a full-screen app. Telegram's main button is put away while it is open (telegram/
 * buttons). Focus moves into it as it opens, Tab goes round inside it, and focus goes back to what opened it as it closes.
 */

interface SheetProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  children: ReactNode
  className?: string
}

/** What Tab stops at inside the sheet. */
const STOPS = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** Tab kept inside `panel`: on from its last stop to its first, back (Shift) from its first to its last. */
function keepTab(event: KeyboardEvent, panel: HTMLElement): void {
  const stops = [...panel.querySelectorAll<HTMLElement>(STOPS)]
  const first = stops[0]
  const last = stops.at(-1)
  const at = document.activeElement
  if (first === undefined || last === undefined) {
    event.preventDefault()
    panel.focus()
  } else if (event.shiftKey && (at === first || at === panel || !panel.contains(at))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (at === last || !panel.contains(at))) {
    event.preventDefault()
    first.focus()
  }
}

export function Sheet({ open, onClose, title, children, className }: SheetProps) {
  const panel = useRef<HTMLDivElement>(null)
  const titleId = useId()
  // The latest onClose for Escape: a caller's inline one is new each render, and the sheet must not take focus again then.
  const close = useRef(onClose)
  useEffect(() => {
    close.current = onClose
  })
  useBackButton(open ? onClose : null)
  useSheetOpen(open)

  useEffect(() => {
    if (!open) return
    const opener = document.activeElement
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panel.current?.focus()
    const keys = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close.current()
      else if (event.key === 'Tab' && panel.current !== null) keepTab(event, panel.current)
    }
    window.addEventListener('keydown', keys)

    return () => {
      document.body.style.overflow = overflow
      window.removeEventListener('keydown', keys)
      if (opener instanceof HTMLElement && opener !== document.body && opener.isConnected) opener.focus({ preventScroll: true })
    }
  }, [open])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-40 flex items-end justify-center">
      <button type="button" aria-label="بستن" onClick={onClose} className="animate-fade absolute inset-0 bg-black/45" />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title === undefined ? undefined : titleId}
        tabIndex={-1}
        className={cn(
          'bg-page animate-rise relative max-h-[calc(88vh_-_var(--app-top))] w-full max-w-xl overflow-y-auto rounded-t-[28px] px-4 pt-2 pb-[calc(var(--app-bottom)+1.5rem)] shadow-[0_-8px_32px_-12px_rgb(0_0_0/0.3)] outline-none',
          className,
        )}
      >
        <div className="bg-fill-strong mx-auto mb-4 h-1 w-9 rounded-full" aria-hidden />
        {title !== undefined && (
          <h2 id={titleId} className="mb-4 px-1 text-heading font-bold">
            {title}
          </h2>
        )}
        {children}
      </div>
    </div>,
    document.body,
  )
}
