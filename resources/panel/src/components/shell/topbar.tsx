import { Menu } from 'lucide-react'
import { useLocation } from 'react-router'
import { IconButton } from '@/components/icon-button'
import { NOT_FOUND_TITLE } from '@/components/not-found'
import { HeaderActions } from '@/components/shell/header-actions'
import { MOBILE_MENU } from '@/components/shell/mobile-drawer'
import { pageTitle } from '@/components/shell/nav'
import { useShell } from '@/components/shell/shell-context'

/**
 * The bar above every page, stuck to the top while the page scrolls: the page's name (on a phone, after the menu button
 * that opens the drawer — a finger's 44px; on a desktop, quietly, beside the sidebar), then the header's actions
 * (HeaderActions).
 */
export function Topbar() {
  const { pathname } = useLocation()
  const { panel, mobileOpen, setMobileOpen } = useShell()

  return (
    <header className="sticky top-0 z-20 flex h-(--topbar-height) shrink-0 items-center gap-2 border-b border-border bg-background/90 px-3 backdrop-blur-sm md:px-6">
      <IconButton onClick={() => setMobileOpen(true)} aria-label="باز کردن منو" aria-expanded={mobileOpen} aria-controls={MOBILE_MENU} className="-ms-2 size-11 md:hidden">
        <Menu className="size-[18px]" aria-hidden />
      </IconButton>
      <span className="min-w-0 flex-1 truncate text-heading font-medium md:text-body md:font-normal md:text-muted-foreground">{pageTitle(panel.nav, pathname) ?? NOT_FOUND_TITLE}</span>
      <HeaderActions />
    </header>
  )
}
