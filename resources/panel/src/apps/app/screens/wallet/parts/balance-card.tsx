import { Crown, Wallet } from 'lucide-react'
import { IconTile } from '@/apps/app/ui/visuals'
import { formatBalance, formatMoney } from '@/lib/format'

/*
 * The wallet's card: the balance large on a gradient of the shop's colour, lit from its top corner as a bank card is,
 * with the shop's name over it and the customer's under it, their loyalty tier beside it. Below zero it is an agent's
 * debt, worded as the bot words it; an agent's credit and what the wallet can spend now are said under it. Its marks are
 * washes of the card's own ink, so they hold on a light shop colour as on a dark one.
 */

interface BalanceCardProps {
  /** The shop's name on the card. */
  shop: string
  /** The customer's name at its foot. */
  holder: string | null
  balance: string
  /** An agent's credit ('0.00' for anyone else) and what the wallet can pay now. */
  credit?: string
  spendable?: string
  /** Beside the holder: their loyalty tier's name. */
  tier?: string | null
}

export function BalanceCard({ shop, holder, balance, credit, spendable, tier = null }: BalanceCardProps) {
  // formatBalance words it as the bot does («۲۰٬۰۰۰ تومان بدهی»): the figure first, then its unit (and «بدهی»).
  const [figure = '', ...unit] = formatBalance(balance).split(' ')
  const owes = Number(credit ?? 0) > 0

  return (
    <section
      aria-label="موجودی کیف پول"
      className="text-on-accent animate-rise rounded-card relative isolate flex flex-col overflow-hidden p-5 shadow-[0_16px_40px_-20px_var(--app-accent)]"
      style={{
        background: 'linear-gradient(135deg, color-mix(in srgb, var(--app-accent) 78%, white) 0%, var(--app-accent) 42%, color-mix(in srgb, var(--app-accent) 58%, black) 100%)',
      }}
    >
      {/* The light: a glow from the top corner, a softer one under it, and rings like a card's guilloche. */}
      <span aria-hidden className="absolute -top-20 -right-16 -z-10 size-56 rounded-full bg-white/25 blur-2xl" />
      <span aria-hidden className="absolute -bottom-24 -left-10 -z-10 size-56 rounded-full bg-black/20 blur-3xl" />
      <svg aria-hidden className="absolute -bottom-16 -left-16 -z-10 size-64 opacity-15" viewBox="0 0 256 256" fill="none" stroke="currentColor">
        {[38, 60, 82, 104, 126].map((radius) => (
          <circle key={radius} cx="128" cy="128" r={radius} strokeWidth="1" />
        ))}
      </svg>

      <div className="flex items-center justify-between gap-3">
        <span className="min-w-0 truncate text-footnote font-semibold opacity-90">{shop}</span>
        <IconTile icon={Wallet} color="var(--app-on-accent)" look="soft" round />
      </div>

      <div className="mt-6 flex flex-col gap-1">
        <p className="text-footnote opacity-80">موجودی کیف پول</p>
        <p className="flex flex-wrap items-baseline gap-x-2">
          <span className="text-display font-extrabold">{figure}</span>
          <span className="text-heading font-semibold opacity-85">{unit.join(' ')}</span>
        </p>
        {owes && spendable !== undefined && credit !== undefined && (
          <p className="text-caption opacity-85">
            قابل خرج: {formatMoney(spendable)} · اعتبار نمایندگی: {formatMoney(credit)}
          </p>
        )}
      </div>

      <div className="mt-6 flex h-6 items-center justify-between gap-3">
        <span className="min-w-0 truncate text-footnote font-medium opacity-90">{holder}</span>
        {tier !== null && (
          <span className="inline-flex h-6 shrink-0 items-center gap-1 rounded-full bg-[color-mix(in_srgb,var(--app-on-accent)_18%,transparent)] px-2.5 text-caption leading-none font-semibold">
            <Crown aria-hidden className="size-3.5" />
            {tier}
          </span>
        )}
      </div>
    </section>
  )
}
