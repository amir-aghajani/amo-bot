import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Moon, Sun, type LucideIcon } from 'lucide-react'
import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

export type Theme = 'light' | 'dark'

interface ThemeState {
  theme: Theme
  /** This browser's theme from now on, kept for the next visit. */
  choose: (theme: Theme) => void
  toggle: () => void
}

const ThemeContext = createContext<ThemeState | null>(null)

/**
 * Apply a theme: the `dark` class index.css's tokens hang on, the browser's own controls (color-scheme), and the phone's
 * browser chrome (theme-color, the page background). Each index.html's inline script does the same before first paint.
 */
function apply(theme: Theme) {
  const root = document.documentElement
  root.classList.toggle('dark', theme === 'dark')
  root.style.colorScheme = theme
  document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute('content', getComputedStyle(root).getPropertyValue('--surface-1').trim())
}

/** Dark unless this browser chose light. There is deliberately no "follow the system" theme. */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<Theme>(() => (readStored(STORAGE_KEYS.theme) === 'light' ? 'light' : 'dark'))

  useEffect(() => apply(theme), [theme])

  const choose = useCallback((next: Theme) => {
    setTheme(next)
    writeStored(STORAGE_KEYS.theme, next)
  }, [])

  const toggle = useCallback(() => choose(theme === 'dark' ? 'light' : 'dark'), [choose, theme])

  const value = useMemo(() => ({ theme, choose, toggle }), [theme, choose, toggle])

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme(): ThemeState {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used inside <ThemeProvider>')
  return context
}

/** The one-tap switch to the other theme, as every screen's header offers it: what it does and its icon. */
export function useThemeSwitch(): { toggle: () => void; label: string; icon: LucideIcon } {
  const { theme, toggle } = useTheme()

  return theme === 'dark' ? { toggle, label: 'تم روشن', icon: Sun } : { toggle, label: 'تم تاریک', icon: Moon }
}
