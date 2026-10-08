import { formatBalance } from '@/lib/format'
import { cn } from '@/lib/utils'

/** A wallet's balance as the bot words it — below zero, the debt an agent's credit carries, in red. */
export function Balance({ balance, className }: { balance: string; className?: string }) {
  return <span className={cn('tabular', Number(balance) < 0 && 'font-medium text-danger', className)}>{formatBalance(balance)}</span>
}
