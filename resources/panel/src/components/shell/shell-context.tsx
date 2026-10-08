import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react'
import { useLocation } from 'react-router'
import { modalOpen } from '@/components/portal-container'
import type { PanelNav } from '@/components/shell/nav'
import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'
import { PHONE, useMediaQuery } from '@/lib/use-media-query'

/*
 * What the shell shares: the panel it is drawn for (its navigation, who signs in to it, what only it has in the
 * column), whether the desktop sidebar is collapsed to an icon rail (the admin's choice, else a rail on a window below
 * 64rem), whether the phone's drawer is open, and the Ctrl/⌘+B shortcut.
 */

/** What a panel puts into the shared shell. */
export interface PanelShell {
  nav: PanelNav
  /** Who is signed in, as the account row words it («مالک فروشگاه», «نماینده»). */
  role: string
  /** Under the brand, on the menu's level — the owner's shop picker (it reads the rail from `useRail()`). */
  picker?: ComponentType
  /** Beside a section's name in a column, what waits there (the agents' pending requests) — drawn by the panel. */
  sectionBadge?: ComponentType<{ area: string; section: string }>
}

interface ShellState {
  panel: PanelShell
  collapsed: boolean
  toggleCollapsed: () => void
  isMobile: boolean
  mobileOpen: boolean
  setMobileOpen: (open: boolean) => void
  /** The shell's own reads (the menu's counts, the owner's shops) may go: the page drawn with the shell has asked first. */
  shellReads: boolean
}

const ShellContext = createContext<ShellState | null>(null)

/**
 * A window where the full column would crowd the page — a tablet, below Tailwind's `lg` (64rem): the sidebar is a rail
 * there unless the admin chose otherwise. In rem, as the layout is.
 */
const NARROW = '(width < 64rem)'

/** The admin's own choice — collapsed or not —, kept for the next visit; null while they have made none. */
function readChoice(): boolean | null {
  const stored = readStored(STORAGE_KEYS.sidebar)
  return stored === 'collapsed' || stored === 'expanded' ? stored === 'collapsed' : null
}

/** True on Apple platforms, where the shortcut modifier is ⌘ instead of Ctrl. */
const isApplePlatform = /Mac|iPhone|iPad|iPod/.test(navigator.userAgent)

/** A key pressed in a field — a shortcut there is the field's own. */
function typedInto(target: EventTarget | null): boolean {
  return target instanceof HTMLElement && (target.isContentEditable || target.matches('input, textarea, select'))
}

/** The modifier key as users see it in hints: "⌘" or "Ctrl". */
export const MOD_KEY = isApplePlatform ? '⌘' : 'Ctrl'

export function ShellProvider({ panel, children }: { panel: PanelShell; children: ReactNode }) {
  // The admin's choice wins; until they make one, a rail on a narrow window — as it narrows and widens.
  const [choice, setChoice] = useState<boolean | null>(readChoice)
  const narrow = useMediaQuery(NARROW)
  const collapsed = choice ?? narrow
  const isMobile = useMediaQuery(PHONE)
  const [mobileOpen, setMobileOpen] = useState(false)
  const { pathname } = useLocation()

  // Navigating always dismisses the phone drawer.
  const [drawerPath, setDrawerPath] = useState(pathname)
  if (drawerPath !== pathname) {
    setDrawerPath(pathname)
    setMobileOpen(false)
  }

  const toggleCollapsed = useCallback(() => {
    setChoice(!collapsed)
    writeStored(STORAGE_KEYS.sidebar, collapsed ? 'expanded' : 'collapsed')
  }, [collapsed])

  // Ctrl/⌘+B toggles the sidebar (or the drawer on phones) — the key by its place, so a Persian layout's «ذ» is B too —,
  // but not in a field (where it is the text editor's bold) nor under a dialog.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const mod = isApplePlatform ? event.metaKey : event.ctrlKey
      if (!mod || event.altKey || event.shiftKey || event.code !== 'KeyB' || typedInto(event.target) || modalOpen()) return
      event.preventDefault()
      if (isMobile) setMobileOpen((open) => !open)
      else toggleCollapsed()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [isMobile, toggleCollapsed])

  // The server answers a panel's requests one at a time (they share its session): what the page on screen shows is asked
  // for first, the shell's own reads a moment after the shell is drawn — after the page drawn with it has asked.
  const [shellReads, setShellReads] = useState(false)
  useEffect(() => {
    const timer = window.setTimeout(() => setShellReads(true))
    return () => window.clearTimeout(timer)
  }, [])

  const value = useMemo<ShellState>(
    () => ({ panel, collapsed, toggleCollapsed, isMobile, mobileOpen, setMobileOpen, shellReads }),
    [panel, collapsed, toggleCollapsed, isMobile, mobileOpen, shellReads],
  )

  return <ShellContext.Provider value={value}>{children}</ShellContext.Provider>
}

export function useShell(): ShellState {
  const context = useContext(ShellContext)
  if (!context) throw new Error('useShell must be used inside <ShellProvider>')
  return context
}

/** Whether a read of the shell's own may go (`shellReads`): behind the page's in the shell, at once anywhere else. */
export function useShellReads(): boolean {
  return useContext(ShellContext)?.shellReads ?? true
}
