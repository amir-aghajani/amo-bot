import { formatBalance, isDebt } from '@/lib/format'
import { cn } from '@/lib/utils'

/** A wallet's balance as the bot words it — below zero, a debt (an agent's credit in use, or support's debit by hand), in red. */
export function Balance({ balance, className }: { balance: string; className?: string }) {
  return <span className={cn('tabular', isDebt(balance) && 'font-medium text-danger', className)}>{formatBalance(balance)}</span>
}
