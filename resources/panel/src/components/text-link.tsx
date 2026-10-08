import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { cn } from '@/lib/utils'

type TextLinkProps = {
  /**
   * Inside a sentence (a notice, a hint) a link is underlined — told apart from the words around it by more than its
   * colour; on its own (a cell, a fact, a card's «مشاهده همه»… without `inline`) it is underlined on hover.
   */
  inline?: boolean
  className?: string
  children: ReactNode
} & ({ to: string; href?: never } | { href: string; to?: never })

/** The schemes a link out of the panel may lead to: the web, and Telegram's apps. */
const SCHEMES = new Set(['https:', 'http:', 'tg:'])

/**
 * A link out of the panel built from data — a channel's, a bot's, a customer's chat, a connector's documents —: the
 * address while it leads to the web or to Telegram, null for anything else (a `javascript:` or `data:` address, no
 * address at all), which is no link.
 */
export function externalHref(address: string): string | null {
  try {
    return SCHEMES.has(new URL(address).protocol) ? address : null
  } catch {
    return null
  }
}

/**
 * A link in the panel's link blue: `to` another screen of the panel, or `href` out of it (in a new tab) — an address
 * that leads nowhere it may (externalHref) is its words alone.
 */
export function TextLink({ to, href, inline = false, className, children }: TextLinkProps) {
  const classes = cn(
    'rounded-sm text-link underline-offset-4 outline-none focus-visible:focus-ring',
    inline ? 'underline decoration-link/40 transition-colors hover:decoration-link' : 'hover:underline',
    className,
  )

  if (to !== undefined) {
    return (
      <Link to={to} className={classes}>
        {children}
      </Link>
    )
  }
  const out = externalHref(href)

  return out === null ? (
    <span className={className}>{children}</span>
  ) : (
    <a href={out} target="_blank" rel="noreferrer" className={classes}>
      {children}
    </a>
  )
}

/** A link to another screen searched for one row: `/payments?search=%2312` opens the payments on payment #12. */
export function searchLink(path: string, id: number): string {
  return `${path}?search=${encodeURIComponent(`#${id}`)}`
}
