import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { LoaderCircle } from 'lucide-react'
import { useMainButton } from '@/apps/app/telegram/buttons'
import { tap } from '@/apps/app/telegram/haptics'
import { cn } from '@/lib/utils'

/*
 * The app's buttons: a filled one in the accent for the action a card is for, a tinted one beside it, a plain one in
 * the accent's text, and one that warns. A tap gives the phone a light touch. `busy` shows a spinner and takes no tap. A
 * small one (36px) is tapped over 44px all the same (app.css's tap-target). An icon given takes the button's icon size.
 * MainAction is the one action a whole screen is for: Telegram's own main button at the bottom of its screen, or —
 * without Telegram, in the tests — a filled one fixed there.
 */

const VARIANTS = {
  primary: 'bg-accent text-on-accent',
  secondary: 'bg-[color-mix(in_srgb,var(--app-accent)_12%,transparent)] text-accent-text',
  plain: 'text-accent-text',
  danger: 'bg-[color-mix(in_srgb,var(--app-danger)_12%,transparent)] text-danger',
} as const

/** 48px (14px round, a 20px icon) and 36px (10px round, a 16px icon): words on the centre line, icons sized to the button. */
const SIZES = {
  md: 'h-12 rounded-control px-5 text-body font-semibold [&_svg]:size-5',
  sm: 'h-9 rounded-[10px] px-3.5 text-footnote font-semibold [&_svg]:size-4',
} as const

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: keyof typeof VARIANTS
  size?: keyof typeof SIZES
  busy?: boolean
  /** Full width. */
  block?: boolean
  icon?: ReactNode
}

export function Button({ variant = 'primary', size = 'md', busy = false, block = false, icon, className, children, disabled, onClick, type = 'button', ...rest }: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || busy}
      onClick={(event) => {
        tap(variant === 'primary' ? 'medium' : 'light')
        onClick?.(event)
      }}
      className={cn(
        'press tap-target relative inline-flex items-center justify-center gap-2 leading-none whitespace-nowrap select-none disabled:opacity-45 [&_svg]:shrink-0',
        VARIANTS[variant],
        SIZES[size],
        block && 'w-full',
        className,
      )}
      {...rest}
    >
      {busy ? <LoaderCircle className="animate-spin" /> : icon}
      {children}
    </button>
  )
}

interface MainActionProps {
  /** Its words; null shows none. */
  text: string | null
  onClick: () => void
  enabled?: boolean
  busy?: boolean
  /** Telegram's shine across it: a payment. */
  shine?: boolean
}

/** The screen's one action at the bottom: Telegram's main button, or one of the page's own outside Telegram. */
export function MainAction({ text, onClick, enabled = true, busy = false, shine = false }: MainActionProps) {
  const native = useMainButton({ text, onClick, enabled, busy, shine })
  if (native || text === null) return null

  return (
    <div className="glass fixed inset-x-0 bottom-0 z-30 px-4 pt-3 pb-[calc(var(--app-bottom)+0.75rem)]">
      <div className="mx-auto max-w-xl">
        <Button block busy={busy} disabled={!enabled} onClick={onClick}>
          {text}
        </Button>
      </div>
    </div>
  )
}
