import { Menu } from 'lucide-react'
import { useLocation } from 'react-router'
import { IconButton } from '@/components/icon-button'
import { NOT_FOUND_TITLE } from '@/components/not-found'
import { MOBILE_MENU } from '@/components/shell/mobile-drawer'
import { pageTitle } from '@/components/shell/nav'
import { useShell } from '@/components/shell/shell-context'
import { useThemeSwitch } from '@/lib/theme'

/**
 * The phone's bar above the page — the desktop has none, as the Console: the menu (the sidebar as a drawer, the button
 * saying whether it is open), the page's name — the not-found page's at an address no page has —, and the theme.
 */
export function Topbar() {
  const { pathname } = useLocation()
  const { panel, mobileOpen, setMobileOpen } = useShell()
  const theme = useThemeSwitch()

  return (
    <header className="sticky top-0 z-20 flex h-(--topbar-height) shrink-0 items-center gap-2 border-b border-border bg-background/90 px-3 backdrop-blur-sm md:hidden">
      <IconButton onClick={() => setMobileOpen(true)} aria-label="باز کردن منو" aria-expanded={mobileOpen} aria-controls={MOBILE_MENU} className="-ms-1">
        <Menu className="size-[18px]" aria-hidden />
      </IconButton>
      <span className="min-w-0 flex-1 truncate text-heading font-medium">{pageTitle(panel.nav, pathname) ?? NOT_FOUND_TITLE}</span>
      <IconButton onClick={theme.toggle} aria-label={theme.label} icon={theme.icon} />
    </header>
  )
}
