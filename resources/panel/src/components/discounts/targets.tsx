import { useQuery } from '@tanstack/react-query'
import { CheckList } from '@/components/check-list'
import type { DiscountKind } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { plansQuery } from '@/lib/queries'
import { DISCOUNT_KIND } from '@/lib/statuses'

/*
 * What a discount code or a sale holds for, shared by both forms and both lists: the kinds of order and the plans, each
 * "every one" (null) or a list.
 */

const KINDS = (Object.keys(DISCOUNT_KIND) as DiscountKind[]).map((kind) => ({ value: kind, label: DISCOUNT_KIND[kind] }))

interface TargetFieldProps<V> {
  value: V[] | null
  onChange: (value: V[] | null) => void
  error?: string
}

/** «برای چه سفارش‌هایی»: every kind of order a discount may take, or the ones ticked. */
export function OrderKindsField({ value, onChange, error }: TargetFieldProps<DiscountKind>) {
  return (
    <CheckList
      label="برای چه سفارش‌هایی"
      all="همه: خرید، تمدید، حجم و زمان اضافه"
      options={KINDS}
      value={value}
      onChange={onChange}
      hint="شارژ کیف پول و خرید حجم نمایندگی هیچ‌وقت تخفیف نمی‌گیرند."
      error={error}
    />
  )
}

/** «پلن‌ها»: every plan, or the ones ticked (a plan off sale marked so). */
export function PlansField({ value, onChange, error }: TargetFieldProps<number>) {
  const plans = useQuery(plansQuery)

  return (
    <CheckList
      label="پلن‌ها"
      all="همه پلن‌ها"
      options={(plans.data?.plans ?? []).map((plan) => ({ value: plan.id, label: plan.name, hint: plan.is_active ? undefined : 'غیرفعال' }))}
      value={value}
      onChange={onChange}
      hint="برای تمدید و حجم و زمان اضافه، پلن سرویس حساب می‌شود."
      error={error}
    />
  )
}

/** The kinds of order in words: «خرید، تمدید», or «همه سفارش‌ها». */
export function kindsWords(kinds: DiscountKind[] | null): string {
  return kinds === null ? 'همه سفارش‌ها' : kinds.map((kind) => DISCOUNT_KIND[kind]).join('، ')
}

/** A few named rows in words: «پلن «یک‌ماهه»» for one, «۳ پلن» for more. */
export function namedWords(noun: string, rows: { name: string }[]): string {
  return rows.length === 1 ? `${noun} «${rows[0]?.name}»` : `${formatNumber(rows.length)} ${noun}`
}
