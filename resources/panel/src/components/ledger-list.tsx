import type { ReactNode } from 'react'
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react'
import { cn } from '@/lib/utils'

/** One line of a ledger, as LedgerList draws it. */
export interface LedgerEntry {
  id: number
  /** Money or traffic in (true) or out. */
  credit: boolean
  /** What the line was: the ledger's own words, its note. */
  title: ReactNode
  /** When, and what was left after it. */
  detail: ReactNode
  /** How much, without its sign: "۵۰٬۰۰۰", "۱۰ گیگابایت". */
  amount: string
}

/** A ledger's lines, newest first — a customer's wallet, an agent's traffic: in green with a +, out in red with a −. */
export function LedgerList({ entries }: { entries: LedgerEntry[] }) {
  return (
    <ul className="max-h-72 scrollbar-thin divide-y divide-border overflow-y-auto rounded-xl border border-border">
      {entries.map((entry) => (
        <li key={entry.id} className="flex items-center gap-3 px-3 py-2.5">
          <span aria-hidden className={cn('flex size-7 shrink-0 items-center justify-center rounded-full', entry.credit ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger')}>
            {entry.credit ? <ArrowUpRight className="size-4" /> : <ArrowDownLeft className="size-4" />}
          </span>
          <div className="min-w-0 flex-1">
            <div className="truncate">{entry.title}</div>
            <div className="text-footnote text-faint">{entry.detail}</div>
          </div>
          <span dir="ltr" className={cn('font-medium whitespace-nowrap tabular', entry.credit ? 'text-success' : 'text-danger')}>
            <span className="sr-only">{entry.credit ? 'افزایش' : 'کاهش'} </span>
            <span aria-hidden>{entry.credit ? '+' : '−'}</span>
            {entry.amount}
          </span>
        </li>
      ))}
    </ul>
  )
}
