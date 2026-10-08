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
 * The theme on the page: the class the tokens of index.css hang on, the browser's own controls, and the phone's chrome
 * in the page's background (--surface-1 — each index.html's inline script sets all three before the first paint).
 */
function apply(theme: Theme) {
  const root = document.documentElement
  root.classList.toggle('dark', theme === 'dark')
  root.style.colorScheme = theme
  document.querySelector<HTMLMetaElement>('meta[name="theme-color"]')?.setAttribute('content', getComputedStyle(root).getPropertyValue('--surface-1').trim())
}

/** Dark unless the admin chose light — there is no "system" theme, by decision. */
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

/** The one-tap switch to the other theme, as the phone's topbar offers it: what it does and its icon. */
export function useThemeSwitch(): { toggle: () => void; label: string; icon: LucideIcon } {
  const { theme, toggle } = useTheme()

  return theme === 'dark' ? { toggle, label: 'تم روشن', icon: Sun } : { toggle, label: 'تم تاریک', icon: Moon }
}
