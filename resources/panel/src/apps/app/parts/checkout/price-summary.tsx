import type { ReactNode } from 'react'
import { Fact, Section } from '@/apps/app/ui/section'
import { Price } from '@/apps/app/ui/visuals'
import type { DiscountLine } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * A checkout's bill — a plan's, a built service's, a renewal's, an extra's —, as the bot's checkout words it: the price,
 * each discount it took («فروش ویژه نوروز ۲۰٪: ۲۴٬۰۰۰ تومان تخفیف») and what is left to pay.
 */

export interface BillLine {
  label: ReactNode
  amount: string
  /** `off`: a discount taken off; `struck`: a price the customer does not pay (their own is lower). */
  kind?: 'off' | 'struck'
}

/** A quote's discounts as lines of the bill. */
export function discountLines(discounts: readonly Pick<DiscountLine, 'label' | 'amount'>[]): BillLine[] {
  return discounts.map(({ label, amount }) => ({ label, amount, kind: 'off' }))
}

/** The bill: its lines, then the amount to pay, set apart — what it was before its discounts struck through beside it, smaller. */
export function PriceSummary({ lines, total, before }: { lines: readonly BillLine[]; total: string; before?: string }) {
  return (
    <Section title="صورتحساب">
      {lines.map(({ label, amount, kind }, index) => (
        <Fact key={index} label={label}>
          {kind === 'off' ? (
            <span className="text-success">{formatMoney(amount)} تخفیف</span>
          ) : kind === 'struck' ? (
            <s className="text-hint decoration-danger/70">{formatMoney(amount)}</s>
          ) : (
            formatMoney(amount)
          )}
        </Fact>
      ))}
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-3.5">
        <span className="text-body font-semibold">مبلغ قابل پرداخت</span>
        <Price amount={total} before={before} className="text-heading" />
      </div>
    </Section>
  )
}
