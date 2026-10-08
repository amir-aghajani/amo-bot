import { useEffect, useRef, type DialogHTMLAttributes, type RefObject } from 'react'

/** The controls a form starts with, in a dialog: a field the keyboard reaches (not a hidden one behind a custom control). */
const FIELDS = ['input:not([type="hidden"]):not([readonly])', 'textarea:not([readonly])', 'select', 'button[role="combobox"]'].map((field) => `${field}:not(:disabled):not([tabindex="-1"])`).join(', ')

/**
 * Drives a native <dialog> from React state: showModal()/close() follow `open` (so the focus trap,
 * Escape and the top layer come from the browser), the initial focus goes to a [data-autofocus]
 * control inside, else to the dialog's first field (a form's first input), else the browser's
 * choice, and the page behind stops scrolling meanwhile — the modal dialog does not do that by
 * itself — without moving sideways as its scrollbar goes. Closed, the focus goes back to what had it
 * as the dialog opened: a menu's item, gone with its menu, stands for the menu's trigger. It hands
 * back the dialog's handlers for a dismissal — Escape, or a click on the backdrop — which call
 * `onDismiss` and leave the state to React.
 */
export function useNativeDialog(
  ref: RefObject<HTMLDialogElement | null>,
  open: boolean,
  onDismiss: () => void,
): Pick<DialogHTMLAttributes<HTMLDialogElement>, 'onCancel' | 'onClose' | 'onPointerDown' | 'onClick'> {
  const opener = useRef<Element | null>(null)
  // A press that started on the backdrop: a click whose press started elsewhere is no dismissal.
  const backdropPress = useRef(false)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) {
      opener.current = openerOf(document.activeElement)
      dialog.showModal()
      const first = dialog.querySelector<HTMLElement>('[data-autofocus]') ?? [...dialog.querySelectorAll<HTMLElement>(FIELDS)].find((field) => field.closest('[hidden]') === null)
      first?.focus()
    } else if (!open && dialog.open) {
      dialog.close()
      const back = opener.current
      opener.current = null
      // What is gone meanwhile (a selection bar that emptied) would drop the focus on the document: the main column
      // takes it instead.
      if (back instanceof HTMLElement && back.isConnected) back.focus()
      else document.getElementById('main')?.focus()
    }
  }, [ref, open])

  useEffect(() => {
    if (!open) return
    const root = document.documentElement
    const previous = { overflow: root.style.overflow, gutter: root.style.scrollbarGutter }
    // The page's scrollbar keeps its room while it is gone, so nothing behind the dialog moves sideways.
    if (window.innerWidth > root.clientWidth) root.style.scrollbarGutter = 'stable'
    root.style.overflow = 'hidden'
    return () => {
      root.style.overflow = previous.overflow
      root.style.scrollbarGutter = previous.gutter
    }
  }, [open])

  return {
    onCancel: (event) => {
      // Escape: the browser would close the dialog itself; React stays in charge of the state. A second Escape in a
      // row it does not let refuse: `onClose` asks then, once.
      event.preventDefault()
      if (event.cancelable) onDismiss()
    },
    onClose: () => {
      // The browser closed it all the same (a second Escape in a row cannot be refused): it opens again, and the
      // dismissal goes the usual way — so the dialog is open exactly while React says so.
      const dialog = ref.current
      if (open && dialog && !dialog.open) {
        dialog.showModal()
        onDismiss()
      }
    },
    onPointerDown: (event) => {
      backdropPress.current = event.target === event.currentTarget
    },
    onClick: (event) => {
      // The click's target is the dialog itself on its backdrop — but also when a press on a control ends on a
      // floating layer portalled into the dialog (their common ancestor): only a press that began there dismisses.
      if (backdropPress.current && event.target === event.currentTarget) onDismiss()
      backdropPress.current = false
    },
  }
}

/** Where the focus goes back to: the element that had it — or, for a menu's item (or the menu), the menu's trigger. */
function openerOf(element: Element | null): Element | null {
  const menu = element?.closest('[role="menu"][aria-labelledby]')
  return (menu && document.getElementById(menu.getAttribute('aria-labelledby') ?? '')) || element
}
