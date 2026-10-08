import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * A keyboard key cap for shortcut hints: <Kbd>Ctrl</Kbd> <Kbd>B</Kbd>. Its Latin letters sit a pixel high in Vazirmatn
 * (see index.css's optical centring), so the cap's padding lowers them back to its middle.
 */
export function Kbd({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <kbd
      className={cn(
        'inline-flex h-5 min-w-5 items-center justify-center rounded-[5px] border border-border bg-fill px-1 pt-0.5 text-caption leading-none font-medium text-muted-foreground',
        className,
      )}
      dir="ltr"
    >
      {children}
    </kbd>
  )
}
