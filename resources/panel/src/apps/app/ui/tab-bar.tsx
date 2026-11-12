import { House, ShoppingBag, UserRound, Wallet, Wifi } from 'lucide-react'
import { NavLink } from 'react-router'
import { selection } from '@/apps/app/telegram/haptics'
import { cn } from '@/lib/utils'

/* The app's tabs at the bottom of its main screens: home, buying, the customer's services, the wallet, their account. */

export const TABS = [
  { to: '/', label: 'خانه', icon: House, end: true },
  { to: '/buy', label: 'خرید', icon: ShoppingBag, end: false },
  { to: '/services', label: 'سرویس‌ها', icon: Wifi, end: false },
  { to: '/wallet', label: 'کیف پول', icon: Wallet, end: false },
  { to: '/account', label: 'حساب', icon: UserRound, end: false },
] as const

export function TabBar() {
  return (
    <nav className="glass fixed inset-x-0 bottom-0 z-30 shadow-[0_-0.5px_0_var(--app-separator)]" aria-label="بخش‌ها">
      <div className="mx-auto flex max-w-xl px-2 pt-2 pb-[calc(var(--app-bottom)+0.5rem)]">
        {TABS.map(({ to, label, icon: Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            onClick={() => selection()}
            className={({ isActive }) => cn('text-micro flex flex-1 flex-col items-center gap-1 font-semibold transition-colors', isActive ? 'text-accent-text' : 'text-hint')}
          >
            {({ isActive }) => (
              <>
                {/* The tab in front sits on a soft pill of its colour; the icon keeps its size, so it never shifts. */}
                <span className={cn('grid h-8 w-14 place-items-center rounded-full transition-colors', isActive && 'bg-[color-mix(in_srgb,var(--app-accent-text)_14%,transparent)]')}>
                  <Icon className="size-6" strokeWidth={isActive ? 2.2 : 1.8} />
                </span>
                {label}
              </>
            )}
          </NavLink>
        ))}
      </div>
    </nav>
  )
}
