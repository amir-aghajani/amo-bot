import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { LoyaltyTierRow } from '@/lib/api-types'
import { amountDraft, decimalInput } from '@/lib/format'
import { useForm } from '@/lib/use-form'

interface TierFormProps {
  tier: LoyaltyTierRow | null
  /** Other reads to refresh once it is saved: the list itself (its order, each tier's customers) and customers' pages. */
  invalidates: readonly QueryKey[]
  onSaved: (tier: LoyaltyTierRow) => void
  onCancel: () => void
}

/** Create/edit form for one loyalty tier: its name, its spending threshold, its discount and its extra cashback. */
export function TierForm({ tier, invalidates, onSaved, onCancel }: TierFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({
    name: tier?.name ?? '',
    min_spent: tier ? amountDraft(tier.min_spent) : '',
    discount_percent: tier ? String(tier.discount_percent) : '0',
    cashback_percent: tier ? String(tier.cashback_percent) : '0',
  })

  const save = handleSubmit(async () => {
    const body = { ...values, min_spent: decimalInput(values.min_spent) }
    const data = await submit(() => (tier ? api.put(`/loyalty/tiers/${tier.id}`, body) : api.post('/loyalty/tiers', body)), { invalidates })
    if (data) {
      toast.success(tier ? 'سطح ذخیره شد' : 'سطح اضافه شد')
      onSaved(data.tier)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="tier_name" label="نام سطح" error={error('name')} hint="همان‌طور که مشتری در کیف پول ربات و در وب‌سایت می‌بیند؛ مثلا «برنزی»، «نقره‌ای» یا «طلایی».">
        <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="طلایی" autoFocus={!tier} />
      </Field>

      <Field
        id="tier_min_spent"
        label="حد خرید (تومان)"
        error={error('min_spent')}
        hint="مشتری‌ای که جمع خریدهایش (خرید، تمدید، حجم و زمان اضافه — پرداخت‌شده و برگشت‌نخورده) به این مبلغ برسد روی این سطح است؛ صفر یعنی همه مشتری‌ها از ابتدا."
      >
        <Input dir="ltr" inputMode="numeric" value={values.min_spent} onChange={(e) => set('min_spent', e.target.value)} placeholder="500000" className="sm:max-w-48" />
      </Field>

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="tier_discount" label="درصد تخفیف" error={error('discount_percent')} hint="روی هر خرید و تمدید؛ از تخفیف‌های خودکار فقط بزرگ‌ترین اعمال می‌شود.">
          <Input dir="ltr" inputMode="numeric" value={values.discount_percent} onChange={(e) => set('discount_percent', e.target.value)} className="sm:max-w-28" />
        </Field>
        <Field id="tier_cashback" label="درصد کش‌بک اضافه" error={error('cashback_percent')} hint="روی درصد قانون‌های کش‌بک اضافه می‌شود، تا وقتی کش‌بک روشن است.">
          <Input dir="ltr" inputMode="numeric" value={values.cashback_percent} onChange={(e) => set('cashback_percent', e.target.value)} className="sm:max-w-28" />
        </Field>
      </div>

      <FormActions error={formError} onCancel={onCancel} submitLabel={tier ? 'ذخیره تغییرات' : 'افزودن سطح'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
