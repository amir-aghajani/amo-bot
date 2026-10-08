import { formatMoney, formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

interface ListTotalProps {
  /** How many of the list's rows brought money, and how much — its meta's; undefined until it is read. */
  count: number | undefined
  amount: string | undefined
  /** What they are, after the number: «سفارش فروخته‌شده». */
  noun: string
  /** Said when none did while the list is narrowed to days — a day that sold nothing is an answer too. */
  none: string
  /** The list is narrowed to days. */
  dated: boolean
  /** The figures are the last view's while the next one loads: dimmed like the table. */
  stale?: boolean
}

/**
 * What a list adds up to, in one quiet line above it — «۱۲ سفارش فروخته‌شده · جمع ۳٬۶۰۰٬۰۰۰ تومان»: the rows of the view
 * (its tab, search, filters and days) that brought money, and the money. Nothing when none did, unless the list is
 * narrowed to days.
 */
export function ListTotal({ count, amount, noun, none, dated, stale = false }: ListTotalProps) {
  if (count === undefined || amount === undefined || (count === 0 && !dated)) return null

  return (
    <p className={cn('text-footnote text-muted-foreground transition-opacity duration-150', stale && 'opacity-60')}>
      {count === 0 ? (
        none
      ) : (
        <>
          {formatNumber(count)} {noun} · جمع <span className="tabular">{formatMoney(amount)}</span>
        </>
      )}
    </p>
  )
}
