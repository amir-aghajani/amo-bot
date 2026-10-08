import { createContext, useContext, useEffect, useSyncExternalStore } from 'react'

/**
 * Where floating layers (select lists, menus, tooltips) should portal to. Defaults to document.body;
 * `Modal` provides its own <dialog>, because anything portalled to the body sits below the top layer
 * and is inert while a modal dialog is open.
 */
export const PortalContainerContext = createContext<HTMLElement | null>(null)

export function usePortalContainer(): HTMLElement | undefined {
  return useContext(PortalContainerContext) ?? undefined
}

/*
 * The modal layers open now — the dialogs, and the phone's drawer —, the last one on top. What the whole panel shares and
 * must keep in sight above them — the toasts — is drawn in the topmost one: anything else sits below the top layer, under
 * its backdrop, inert.
 */
const layers: { element: HTMLElement; drawer: boolean }[] = []
const listeners = new Set<() => void>()
const changed = () => listeners.forEach((listener) => listener())

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** While `open`, `dialog` is the topmost layer: a Modal's own — or the phone's drawer (`drawer`). */
export function useModalLayer(dialog: HTMLElement | null, open: boolean, { drawer = false }: { drawer?: boolean } = {}): void {
  useEffect(() => {
    if (!open || dialog === null) return
    const layer = { element: dialog, drawer }
    layers.push(layer)
    changed()
    return () => {
      layers.splice(layers.lastIndexOf(layer), 1)
      changed()
    }
  }, [dialog, open, drawer])
}

/** The layer on top of the others, or null while none is open. */
export function useTopModal(): HTMLElement | null {
  return useSyncExternalStore(subscribe, () => layers.at(-1)?.element ?? null)
}

/** Whether a dialog is open over the page now — the page under it takes no shortcut (the drawer, which the shortcut opens and shuts, is none). */
export function modalOpen(): boolean {
  return layers.some((layer) => !layer.drawer)
}
