import { useQuery, type QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CheckList } from '@/components/check-list'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { CashbackOrderType, CashbackRuleRow } from '@/lib/api-types'
import { customerGroupsQuery, paymentMethodsQuery } from '@/lib/queries'
import { ORDER_TYPE } from '@/lib/statuses'
import { asSet, useForm } from '@/lib/use-form'

/** The kinds of order a rule may be for, in their own order. */
const CASHBACK_TYPES = (['purchase', 'renewal', 'extra_traffic', 'extra_time'] satisfies CashbackOrderType[]).map((type) => ({ value: type, label: ORDER_TYPE[type] }))

/** The selects' value for "any" (the rule names no group or way to pay). */
const ANY = 'any'

interface RuleFormProps {
  rule: CashbackRuleRow | null
  /** Other reads to refresh once it is saved (useRows' `invalidates`). */
  invalidates: readonly QueryKey[]
  onSaved: (rule: CashbackRuleRow) => void
  onCancel: () => void
}

/** Create/edit form for one cashback rule: its percent, its order kinds (at least one), a customer group and a way to pay. */
export function RuleForm({ rule, invalidates, onSaved, onCancel }: RuleFormProps) {
  const groups = useQuery(customerGroupsQuery)
  const methods = useQuery(paymentMethodsQuery)
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm(
    {
      percent: rule ? String(rule.percent) : '',
      order_types: rule ? [...rule.order_types] : (['purchase', 'renewal'] as CashbackOrderType[]),
      customer_group_id: rule?.customer_group ? String(rule.customer_group.id) : ANY,
      payment_method_id: rule?.payment_method ? String(rule.payment_method.id) : ANY,
    },
    // The kinds are kept as a set: the order they were ticked in is no change.
    { reads: (draft) => ({ ...draft, order_types: asSet(draft.order_types) }) },
  )

  const save = handleSubmit(async () => {
    const body = {
      percent: values.percent,
      order_types: values.order_types,
      customer_group_id: values.customer_group_id === ANY ? null : Number(values.customer_group_id),
      payment_method_id: values.payment_method_id === ANY ? null : Number(values.payment_method_id),
    }
    const data = await submit(() => (rule ? api.put(`/cashback/rules/${rule.id}`, body) : api.post('/cashback/rules', body)), { invalidates })
    if (data) {
      toast.success(rule ? 'قانون کش‌بک ذخیره شد' : 'قانون کش‌بک اضافه شد')
      onSaved(data.rule)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="rule_percent" label="درصد کش‌بک" error={error('percent')} hint="از مبلغی که سفارش پرداخت کرده، بعد از تحویل به کیف پول مشتری برمی‌گردد؛ رقم به تومان پایین گرد می‌شود.">
        <Input dir="ltr" inputMode="numeric" value={values.percent} onChange={(e) => set('percent', e.target.value)} placeholder="5" className="sm:max-w-28" autoFocus={!rule} />
      </Field>

      <CheckList
        label="نوع سفارش"
        options={CASHBACK_TYPES}
        value={values.order_types}
        onChange={(types) => set('order_types', types)}
        hint="شارژ کیف پول و خرید حجم نمایندگی کش‌بک نمی‌گیرند."
        error={error('order_types')}
      />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="rule_group" label="گروه مشتری" error={error('customer_group_id')} hint="فقط مشتری‌های این گروه؛ گروه‌ها در صفحه کاربران ساخته می‌شوند.">
          {(control) => (
            <Select value={values.customer_group_id} onValueChange={(value) => set('customer_group_id', value)}>
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ANY}>همه مشتری‌ها</SelectItem>
                {(groups.data?.groups ?? []).map((group) => (
                  <SelectItem key={group.id} value={String(group.id)}>
                    {group.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
        <Field id="rule_method" label="روش پرداخت" error={error('payment_method_id')} hint="روشی که سفارش با آن پرداخت شده است.">
          {(control) => (
            <Select value={values.payment_method_id} onValueChange={(value) => set('payment_method_id', value)}>
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ANY}>همه روش‌ها</SelectItem>
                {(methods.data?.methods ?? []).map((method) => (
                  <SelectItem key={method.id} value={String(method.id)}>
                    {method.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      </div>

      <FormActions error={formError} onCancel={onCancel} submitLabel={rule ? 'ذخیره تغییرات' : 'افزودن قانون'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
