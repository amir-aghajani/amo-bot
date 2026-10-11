import { createContext, useContext, useEffect, useSyncExternalStore } from 'react'
import { createListeners } from '@/lib/listeners'

/**
 * Where floating layers (select lists, menus, tooltips) portal to: document.body by default, or the open `Modal`'s own
 * <dialog> — while a modal dialog is open, anything portalled to the body sits below the top layer and is inert.
 */
export const PortalContainerContext = createContext<HTMLElement | null>(null)

export function usePortalContainer(): HTMLElement | undefined {
  return useContext(PortalContainerContext) ?? undefined
}

/*
 * The modal layers open now (the dialogs and the phone's drawer), the last one on top. The toasts are drawn inside the
 * topmost one: anywhere else they would sit under its backdrop, inert.
 */
const layers: { element: HTMLElement; drawer: boolean }[] = []
const changes = createListeners()

/** While `open`, `dialog` is the topmost layer: a Modal's, or the phone's drawer (`drawer`). */
export function useModalLayer(dialog: HTMLElement | null, open: boolean, { drawer = false }: { drawer?: boolean } = {}): void {
  useEffect(() => {
    if (!open || dialog === null) return
    const layer = { element: dialog, drawer }
    layers.push(layer)
    changes.notify()
    return () => {
      layers.splice(layers.lastIndexOf(layer), 1)
      changes.notify()
    }
  }, [dialog, open, drawer])
}

/** The layer on top of the others, or null while none is open. */
export function useTopModal(): HTMLElement | null {
  return useSyncExternalStore(changes.subscribe, () => layers.at(-1)?.element ?? null)
}

/** Whether a dialog is open over the page; the drawer does not count (the sidebar shortcut opens and shuts it). */
export function modalOpen(): boolean {
  return layers.some((layer) => !layer.drawer)
}
