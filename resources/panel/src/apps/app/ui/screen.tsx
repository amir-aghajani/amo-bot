import type { ReactNode } from 'react'
import { ChevronRight } from 'lucide-react'
import { useNavigate } from 'react-router'
import { useBackButton } from '@/apps/app/telegram/buttons'
import { inTelegram } from '@/apps/app/telegram/webapp'
import { cn } from '@/lib/utils'

/*
 * A screen of the app: its title and what it holds, clear of Telegram's header and controls over a full-screen app and
 * of the phone's edges. A screen below a tab (`back`) gets Telegram's back button — or, outside Telegram, one of its own
 * (only the tests see it: app.tsx's gate shows a browser no screen) —, which goes back where the customer came from
 * (`back` true), or to the address it names.
 */

interface ScreenProps {
  title?: ReactNode
  /** A line under the title. */
  subtitle?: ReactNode
  /** Where the back button leads: true — back where they came from; an address — there; absent — a tab's own screen. */
  back?: true | string
  /** Beside the title: a button or two. */
  actions?: ReactNode
  /**
   * Room left under the content for the tab bar (a tab's screen) or a bottom action (a screen with one of its own:
   * MainAction). Inside Telegram the action is its main button, drawn below the page: no room is kept for it.
   */
  bottom?: 'tabs' | 'action' | 'none'
  className?: string
  children: ReactNode
}

export function Screen({ title, subtitle, back, actions, bottom = 'none', className, children }: ScreenProps) {
  const navigate = useNavigate()
  const goBack = back === undefined ? null : () => (back === true ? navigate(-1) : navigate(back))
  useBackButton(goBack)
  const room = bottom === 'action' && inTelegram() ? 'none' : bottom

  return (
    <main
      className={cn(
        'animate-fade mx-auto flex min-h-[var(--tg-viewport-height,100vh)] w-full max-w-xl flex-col gap-5 px-4 pt-[calc(var(--app-top)+1rem)]',
        room === 'tabs' && 'pb-[calc(var(--app-bottom)+5.5rem)]',
        room === 'action' && 'pb-[calc(var(--app-bottom)+6rem)]',
        room === 'none' && 'pb-[calc(var(--app-bottom)+1.5rem)]',
        className,
      )}
    >
      {(title !== undefined || actions !== undefined || (goBack !== null && !inTelegram())) && (
        <header className="flex min-h-10 items-center gap-3 px-1">
          {goBack !== null && !inTelegram() && (
            <button type="button" onClick={goBack} className="press text-accent-text -ms-2 grid size-10 place-items-center rounded-full" aria-label="بازگشت">
              <ChevronRight className="size-6" />
            </button>
          )}
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            {title !== undefined && <h1 className="truncate text-title font-extrabold">{title}</h1>}
            {subtitle !== undefined && <p className="text-hint text-footnote">{subtitle}</p>}
          </div>
          {actions}
        </header>
      )}
      {children}
    </main>
  )
}
