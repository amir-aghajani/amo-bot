import type { ReactNode } from 'react'
import { BrandMark } from '@/components/brand-mark'
import { cn } from '@/lib/utils'

interface PublicScreenProps {
  /** At the display size, under the brand mark. */
  title: ReactNode
  description?: ReactNode
  /** The installer's wider column, from the top; a sign-in's narrow one sits in the middle of the screen. */
  wide?: boolean
  children: ReactNode
}

/** A screen outside the panel's shell — a sign-in, the installer: the brand, a title that speaks, then the screen's panels. */
export function PublicScreen({ title, description, wide = false, children }: PublicScreenProps) {
  return (
    <div className={cn('login-backdrop flex min-h-svh flex-col items-center px-4 py-10', !wide && 'justify-center')}>
      <main className={cn('w-full', wide ? 'max-w-xl' : 'max-w-[24rem]')}>
        <div className="mb-7 flex flex-col items-center gap-4 text-center">
          <BrandMark className="size-10 rounded-lg" />
          <div className="grid gap-1.5">
            <h1 className="text-display font-medium">{title}</h1>
            {description && <p className="text-body text-muted-foreground">{description}</p>}
          </div>
        </div>
        {children}
      </main>
    </div>
  )
}

/** One panel of such a screen — the sign-in form, an installer step —, with its own title when it has one. */
export function PublicPanel({ title, description, children }: { title?: string; description?: ReactNode; children: ReactNode }) {
  return (
    <section className="grid gap-5 rounded-2xl border border-border bg-card p-6 shadow-card">
      {title && (
        <div className="grid gap-1">
          <h2 className="text-subtitle font-semibold">{title}</h2>
          {description && <p className="text-body text-muted-foreground">{description}</p>}
        </div>
      )}
      {children}
    </section>
  )
}
