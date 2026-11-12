import type { CSSProperties } from 'react'
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreWalletTransaction } from '@/lib/api-types'
import { formatAmount, formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * A line of the wallet's ledger as a bank's statement shows it: money in (green, an arrow in) or out, what it was for in
 * the shop's words beside the amount signed, and under them when it was beside the balance it left.
 */

/** The hairline under a row starts where its words do: past the 16px edge, the 40px circle and its 12px gap. */
export const LEDGER_INSET = { '--row-inset': '4.25rem' } as CSSProperties

interface LedgerLineProps {
  line: StoreWalletTransaction
  /** The moment as a time of day alone — under a day's heading — or as a whole date. */
  time?: 'time' | 'date'
}

export function LedgerLine({ line, time = 'date' }: LedgerLineProps) {
  const credit = line.type === 'credit'
  const when = time === 'time' ? formatDate(line.created_at, { timeStyle: 'short' }) : formatDate(line.created_at)

  return (
    <div className="list-row flex items-center gap-3 px-4 py-3" style={LEDGER_INSET}>
      <IconTile icon={credit ? ArrowDownLeft : ArrowUpRight} size="lg" color={credit ? 'var(--app-success)' : 'var(--app-hint)'} look="soft" round />
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="flex items-start justify-between gap-3">
          <span className="line-clamp-2 min-w-0 text-body">{line.description ?? (credit ? 'واریز به کیف پول' : 'برداشت از کیف پول')}</span>
          <span className={cn('ltr shrink-0 text-body font-bold', credit ? 'text-success' : 'text-text')}>
            {credit ? '+' : '−'}
            {formatAmount(line.amount)}
          </span>
        </span>
        <span className="text-hint flex flex-wrap justify-between gap-x-3 text-footnote">
          <span className="whitespace-nowrap">{when}</span>
          <span className="whitespace-nowrap">موجودی {formatAmount(line.balance_after)}</span>
        </span>
      </span>
    </div>
  )
}
