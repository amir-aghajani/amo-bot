import type { ComponentType } from 'react'
import { BookOpen, Crown, FlaskConical, Headset, MessageSquareQuote, ShoppingBag, Wallet, Wifi } from 'lucide-react'
import { Link } from 'react-router'
import { clubOpen, reviewsOpen } from '@/apps/app/screens/home/parts/programs'
import { tap } from '@/apps/app/telegram/haptics'
import { Section } from '@/apps/app/ui/section'
import { ACCENT_FILL, IconTile } from '@/apps/app/ui/visuals'
import type { StoreResponse } from '@/lib/api-types'

/*
 * The home screen's shortcuts: a grid of the app's places, each an icon in a square tinted its own colour and its name
 * under it — the free test account, the customer club and the reviews only while the shop runs them.
 */

interface Shortcut {
  to: string
  label: string
  icon: ComponentType<{ className?: string }>
  /** Its colour: a CSS colour. */
  tint: string
}

/** A place: its icon in a 48px square tinted its colour, its name on one line centred under it. */
function Tile({ to, label, icon, tint }: Shortcut) {
  return (
    <Link to={to} onClick={() => tap()} className="press flex min-w-0 flex-col items-center gap-2 text-center">
      <IconTile icon={icon} size="xl" color={tint} look="soft" />
      <span className="w-full truncate text-caption font-medium">{label}</span>
    </Link>
  )
}

export function Shortcuts({ shop }: { shop: StoreResponse }) {
  const shortcuts: Shortcut[] = [
    { to: '/buy', label: 'خرید سرویس', icon: ShoppingBag, tint: ACCENT_FILL },
    { to: '/services', label: 'سرویس‌های من', icon: Wifi, tint: '#5856d6' },
    { to: '/wallet', label: 'کیف پول', icon: Wallet, tint: '#30b350' },
    ...(shop.shop.programs.test_accounts ? [{ to: '/test', label: 'اکانت تست', icon: FlaskConical, tint: '#ff9500' }] : []),
    ...(clubOpen(shop) ? [{ to: '/club', label: 'باشگاه مشتریان', icon: Crown, tint: '#ff2d55' }] : []),
    { to: '/support', label: 'پشتیبانی', icon: Headset, tint: '#1fa5e0' },
    { to: '/tutorial', label: 'آموزش', icon: BookOpen, tint: '#af52de' },
    ...(reviewsOpen(shop) ? [{ to: '/reviews', label: 'نظرات', icon: MessageSquareQuote, tint: '#e6a100' }] : []),
  ]

  return (
    <Section title="دسترسی سریع">
      <nav aria-label="دسترسی سریع" className="grid grid-cols-4 gap-x-1 gap-y-5 px-2 py-5">
        {shortcuts.map((shortcut) => (
          <Tile key={shortcut.to} {...shortcut} />
        ))}
      </nav>
    </Section>
  )
}
