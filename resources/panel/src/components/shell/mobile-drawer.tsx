import { useCallback, useRef, useState } from 'react'
import { PortalContainerContext, useModalLayer } from '@/components/portal-container'
import { useShell } from '@/components/shell/shell-context'
import { SidebarBody } from '@/components/shell/sidebar'
import { useNativeDialog } from '@/lib/use-native-dialog'

/** The drawer's id: what the topbar's menu button controls. */
export const MOBILE_MENU = 'mobile-menu'

/**
 * The phone navigation: the sidebar's column inside a native <dialog> — on a phone the only column. showModal() gives
 * the focus trap, Escape handling, inert background and top-layer stacking for free; the slide is plain CSS (.app-drawer
 * in index.css). The column stays drawn on a phone while the drawer is shut, so it slides out whole. The menus opened
 * from inside (the shop picker) portal into the drawer, as a Modal's do, and the toasts are drawn in
 * it while it is open: the body is inert and below the top layer meanwhile.
 */
export function MobileDrawer() {
  const { mobileOpen, setMobileOpen, isMobile } = useShell()
  const ref = useRef<HTMLDialogElement>(null)
  const [container, setContainer] = useState<HTMLDialogElement | null>(null)
  const close = () => setMobileOpen(false)
  const open = mobileOpen && isMobile
  const handlers = useNativeDialog(ref, open, close)
  useModalLayer(container, open, { drawer: true })

  const attach = useCallback((node: HTMLDialogElement | null) => {
    ref.current = node
    setContainer(node)
  }, [])

  return (
    <dialog ref={attach} id={MOBILE_MENU} aria-label="منوی اصلی" {...handlers} className="app-drawer">
      <PortalContainerContext.Provider value={container}>{isMobile && <SidebarBody onClose={close} />}</PortalContainerContext.Provider>
    </dialog>
  )
}
