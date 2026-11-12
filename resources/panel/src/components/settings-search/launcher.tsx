import { Component, createContext, Suspense, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { IconButton } from '@/components/icon-button'
import { Kbd } from '@/components/kbd'
import { modalOpen } from '@/components/portal-container'
import { preferencesQuery } from '@/components/settings-search/preferences'
import { RailTooltip, useRail } from '@/components/shell/rail'
import { MOD_ARIA, MOD_KEY, typedInto, useShell, withModifier } from '@/components/shell/shell-context'
import { toastFailure } from '@/lib/failure'
import { lazyPage } from '@/lib/lazy-page'
import { cn } from '@/lib/utils'

/*
 * The settings search's way in, in the shell: its triggers (the topbar's, the settings' column's), its shortcuts —
 * Ctrl/⌘+K anywhere, «/» outside a field — and its dialog, whose code (and the index with it) loads once the panel is
 * idle, or sooner when wanted: the trigger about to be pressed, or opened.
 */

/** The dialog, a chunk of its own. */
const SettingsSearchDialog = lazyPage(
  () => import('@/components/settings-search/dialog'),
  (module) => module.SettingsSearchDialog,
)

/** What the search's name is, on its triggers and its dialog. */
export const SEARCH_LABEL = 'جستجوی تنظیمات'

/** How long after the panel starts its dialog's code loads at the latest, where the browser has no idle callback (Safari). */
const IDLE_FALLBACK_MS = 3000

/** The shortcut as `aria-keyshortcuts` names it. */
const SHORTCUTS = `${MOD_ARIA}+K /`

interface SettingsSearch {
  open: boolean
  show: () => void
  /** The trigger is about to be pressed: the dialog's code and the favorites start loading. */
  prepare: () => void
}

const SettingsSearchContext = createContext<SettingsSearch | null>(null)

function useSettingsSearch(): SettingsSearch {
  const context = useContext(SettingsSearchContext)
  if (!context) throw new Error('useSettingsSearch must be used inside <SettingsSearchProvider>')
  return context
}

/** The search for the shell (inside its ShellProvider): its state, its shortcuts and its dialog once wanted. */
export function SettingsSearchProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const { setMobileOpen } = useShell()
  const [open, setOpen] = useState(false)
  // Drawn from its first opening on, and kept: it fades out as it closes, and opens again at once.
  const [wanted, setWanted] = useState(false)

  const prepare = useCallback(() => {
    SettingsSearchDialog.preload()
    void client.prefetchQuery(preferencesQuery)
  }, [client])

  const show = useCallback(() => {
    // From the phone's drawer: the drawer goes, the search takes the screen.
    setMobileOpen(false)
    setWanted(true)
    setOpen(true)
  }, [setMobileOpen])

  const close = useCallback(() => setOpen(false), [])

  // The dialog's code once the panel is idle: a first Ctrl/⌘+K draws it at once, so no key typed straight after is lost.
  useEffect(() => {
    if (typeof window.requestIdleCallback !== 'function') {
      const timer = window.setTimeout(() => SettingsSearchDialog.preload(), IDLE_FALLBACK_MS)
      return () => window.clearTimeout(timer)
    }
    const handle = window.requestIdleCallback(() => SettingsSearchDialog.preload(), { timeout: IDLE_FALLBACK_MS })
    return () => window.cancelIdleCallback(handle)
  }, [])

  // Ctrl/⌘+K opens and closes it anywhere, a field too; «/» opens it outside a field. By the key's place, so a Persian
  // layout's «ن» is K too; not over another dialog.
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.defaultPrevented || event.repeat) return
      const toggling = withModifier(event) && !event.altKey && !event.shiftKey && event.code === 'KeyK'
      const slash = !event.ctrlKey && !event.metaKey && !event.altKey && ((event.code === 'Slash' && !event.shiftKey) || event.key === '/') && !typedInto(event.target)
      if (!toggling && !slash) return
      if (open) {
        if (!toggling) return
        event.preventDefault()
        setOpen(false)
        return
      }
      if (modalOpen()) return
      event.preventDefault()
      show()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, show])

  const value = useMemo(() => ({ open, show, prepare }), [open, show, prepare])

  return (
    <SettingsSearchContext.Provider value={value}>
      {children}
      {wanted && (
        <DialogBoundary
          onFailure={(error) => {
            toastFailure(error, 'جستجوی تنظیمات باز نشد')
            setOpen(false)
            setWanted(false)
          }}
        >
          <Suspense fallback={null}>
            <SettingsSearchDialog open={open} onClose={close} />
          </Suspense>
        </DialogBoundary>
      )}
    </SettingsSearchContext.Provider>
  )
}

/**
 * The dialog's file did not load (offline, a build gone): said in a toast, and the next opening asks for it again — a
 * stale build reloads first (lib/lazy-page).
 */
class DialogBoundary extends Component<{ children: ReactNode; onFailure: (error: unknown) => void }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  componentDidCatch(error: unknown): void {
    SettingsSearchDialog.preload()
    this.props.onFailure(error)
  }

  render(): ReactNode {
    return this.state.failed ? null : this.props.children
  }
}

/** The handlers that load the dialog's code and the favorites as the trigger is about to be pressed. */
function preparing(prepare: () => void) {
  return { onPointerEnter: prepare, onFocus: prepare }
}

/**
 * The topbar's trigger: on a desktop a field to press, with its shortcut; on a phone a search icon, a finger's 44px.
 */
export function SearchTrigger() {
  const { open, show, prepare } = useSettingsSearch()
  const { isMobile } = useShell()
  const common = { onClick: show, ...preparing(prepare), 'aria-haspopup': 'dialog', 'aria-expanded': open, 'aria-keyshortcuts': SHORTCUTS } as const

  if (isMobile) {
    return (
      <IconButton aria-label={SEARCH_LABEL} {...common} className="size-11">
        <Search className="size-[18px]" aria-hidden />
      </IconButton>
    )
  }

  return (
    <button
      type="button"
      aria-label={SEARCH_LABEL}
      {...common}
      className="flex h-8 w-56 shrink-0 items-center gap-2 rounded-lg border border-field-line bg-card ps-2.5 pe-1.5 text-body text-faint transition-[border-color,color] duration-150 outline-none hover:border-field-line-hover hover:text-muted-foreground focus-visible:focus-field lg:w-72 dark:bg-fill"
    >
      <Search className="size-4 shrink-0" aria-hidden />
      <span className="min-w-0 flex-1 truncate text-start">{SEARCH_LABEL}…</span>
      <Kbd>{MOD_KEY} K</Kbd>
    </button>
  )
}

/**
 * The search at the top of the settings' column: a field to press, with its shortcut on a desktop — on the rail its
 * icon, named by its tooltip (`className` draws it as the column's items are).
 */
export function SettingsColumnSearch({ className }: { className?: string }) {
  const { open, show, prepare } = useSettingsSearch()
  const { isMobile } = useShell()
  const rail = useRail()
  const common = { type: 'button', onClick: show, ...preparing(prepare), 'aria-haspopup': 'dialog', 'aria-expanded': open, 'aria-keyshortcuts': SHORTCUTS, 'aria-label': SEARCH_LABEL } as const

  if (rail) {
    return (
      <RailTooltip label={SEARCH_LABEL}>
        <button {...common} className={className}>
          <Search className="size-[18px] shrink-0" strokeWidth={1.75} aria-hidden />
        </button>
      </RailTooltip>
    )
  }

  return (
    <button
      {...common}
      className={cn(
        'flex h-8 w-full min-w-0 items-center gap-2 rounded-lg border border-sidebar-border bg-background px-2 text-body text-faint transition-[border-color,color] duration-150 outline-none hover:border-border-strong hover:text-muted-foreground focus-visible:focus-ring max-md:h-11',
        className,
      )}
    >
      <Search className="size-4 shrink-0" aria-hidden />
      <span className="min-w-0 flex-1 truncate text-start">{SEARCH_LABEL}</span>
      {!isMobile && <Kbd>{MOD_KEY} K</Kbd>}
    </button>
  )
}
