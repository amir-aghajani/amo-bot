import { useState } from 'react'
import { Bell, BellRing } from 'lucide-react'
import { Link } from 'react-router'
import { tap } from '@/apps/app/telegram/haptics'
import { formatNumber, initials } from '@/lib/format'

/*
 * The home screen's head: the shop's logo (or its initial on its colour), a hello by the customer's first name under the
 * shop's name, and the bell with how many notices wait unread.
 */

/** The shop's logo; its initial on a square of its colour while it has none, or the picture does not load. */
function Logo({ title, url }: { title: string; url: string | null }) {
  const [broken, setBroken] = useState(false)

  if (url !== null && !broken) {
    return <img src={url} alt="" onError={() => setBroken(true)} className="bg-section rounded-control size-12 shrink-0 object-cover shadow-card" />
  }

  return (
    <span
      aria-hidden
      className="text-on-accent rounded-control grid size-12 shrink-0 place-items-center text-heading leading-none font-bold shadow-[0_8px_20px_-10px_var(--app-accent)]"
      style={{ background: 'linear-gradient(140deg, color-mix(in srgb, var(--app-accent) 72%, white), color-mix(in srgb, var(--app-accent) 75%, black))' }}
    >
      {initials(title).slice(0, 1) || '؟'}
    </span>
  )
}

/** The bell: to the notices, with a red count while some are unread. */
function BellButton({ unread }: { unread: number }) {
  const Icon = unread > 0 ? BellRing : Bell

  return (
    <Link
      to="/notifications"
      onClick={() => tap()}
      aria-label={unread > 0 ? `اعلان‌ها، ${formatNumber(unread)} خوانده‌نشده` : 'اعلان‌ها'}
      className="press bg-section relative grid size-11 shrink-0 place-items-center rounded-full shadow-card"
    >
      <Icon aria-hidden className="size-6" />
      {unread > 0 && (
        <span
          aria-hidden
          className="ring-page text-micro absolute -end-0.5 -top-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 leading-none font-bold text-white ring-2"
        >
          {unread > 99 ? `${formatNumber(99)}+` : formatNumber(unread)}
        </span>
      )}
    </Link>
  )
}

interface GreetingProps {
  /** The app's name (`GET /` app.title, else the shop's). */
  title: string
  logo: string | null
  firstName: string | null
  unread: number
}

export function Greeting({ title, logo, firstName, unread }: GreetingProps) {
  return (
    <header className="animate-rise flex items-center gap-3">
      <Logo title={title} url={logo} />
      <div className="flex min-w-0 flex-1 flex-col">
        <p className="text-hint truncate text-footnote">{title}</p>
        <h1 className="truncate text-title font-extrabold">{firstName ? `سلام، ${firstName}` : 'سلام'}</h1>
      </div>
      <BellButton unread={unread} />
    </header>
  )
}
