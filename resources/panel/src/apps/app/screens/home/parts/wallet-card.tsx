import { ChevronLeft, Plus, Wallet } from 'lucide-react'
import { Link } from 'react-router'
import { tap } from '@/apps/app/telegram/haptics'
import { Skeleton } from '@/apps/app/ui/states'
import { formatBalance } from '@/lib/format'

/*
 * The wallet on the home screen: the balance large on the shop's colour, lit from a corner, the card itself leading to
 * the wallet and «شارژ» beside it straight to a top-up.
 */

export function WalletCard({ balance }: { balance: string | undefined }) {
  if (balance === undefined) return <Skeleton className="h-26" />
  // As the bot words it («۲۰٬۰۰۰ تومان بدهی» below zero): the figure, then its unit.
  const [figure = '', ...unit] = formatBalance(balance).split(' ')

  return (
    <section
      aria-label="کیف پول"
      className="text-on-accent animate-rise rounded-card relative isolate flex min-h-26 items-center gap-4 overflow-hidden p-5 shadow-[0_16px_36px_-20px_var(--app-accent)]"
      style={{ background: 'linear-gradient(130deg, color-mix(in srgb, var(--app-accent) 80%, white) 0%, var(--app-accent) 45%, color-mix(in srgb, var(--app-accent) 62%, black) 100%)' }}
    >
      <span aria-hidden className="absolute -start-10 -top-16 -z-10 size-44 rounded-full bg-white/25 blur-2xl" />
      <span aria-hidden className="absolute -end-8 -bottom-20 -z-10 size-44 rounded-full bg-black/20 blur-3xl" />

      <Link to="/wallet" onClick={() => tap()} className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex items-center gap-1.5 text-footnote font-medium opacity-90">
          <Wallet aria-hidden className="size-4" />
          موجودی کیف پول
          <ChevronLeft aria-hidden className="size-4 opacity-70" />
        </span>
        <span className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-display font-extrabold">{figure}</span>
          <span className="text-body font-semibold opacity-85">{unit.join(' ')}</span>
        </span>
      </Link>

      <Link
        to="/wallet/top-up"
        onClick={() => tap('medium')}
        className="press text-accent bg-on-accent inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-full px-4 text-body leading-none font-bold shadow-[0_6px_16px_-8px_rgb(0_0_0/0.45)]"
      >
        <Plus aria-hidden className="size-5" />
        شارژ
      </Link>
    </section>
  )
}
