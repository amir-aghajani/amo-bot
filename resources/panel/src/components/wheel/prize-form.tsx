import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { PrizeAmountField } from '@/components/rewards/prize-fields'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { WheelPrizeKind, WheelPrizeRequest, WheelPrizeRow } from '@/lib/api-types'
import { decimalOf, formatGb, formatMoney, formatNumber, wholeOf } from '@/lib/format'
import { WHEEL_PRIZE_KIND } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'

interface PrizeFormProps {
  prize: WheelPrizeRow | null
  /** Other reads to refresh once it is saved (useRows' `invalidates`). */
  invalidates: readonly QueryKey[]
  onSaved: (prize: WheelPrizeRow) => void
  onCancel: () => void
}

/** The form's draft: the amount as typed, the plan's id as text. */
interface Draft {
  label: string
  kind: WheelPrizeKind
  amount: string
  plan_id: string
  weight: string
  enabled: boolean
}

/** The kinds with an amount: how it is read (GB may have a fraction) and what it comes to, under the field. */
const AMOUNTS: Partial<Record<WheelPrizeKind, { decimal: boolean; reads: (amount: string) => string }>> = {
  wallet: { decimal: false, reads: (amount) => `${formatMoney(amount)} به کیف پول مشتری اضافه می‌شود.` },
  traffic: { decimal: true, reads: (amount) => `${formatGb(Number(amount))} به سرویس فعال مشتری اضافه می‌شود.` },
  days: { decimal: false, reads: (amount) => `${formatNumber(amount)} روز به سرویس فعال مشتری اضافه می‌شود.` },
}

/** The draft as the API takes it: only the fields its kind reads. */
function requestOf(draft: Draft): WheelPrizeRequest {
  return {
    label: draft.label,
    kind: draft.kind,
    weight: draft.weight,
    enabled: draft.enabled,
    ...(AMOUNTS[draft.kind] ? { amount: draft.amount } : {}),
    ...(draft.kind === 'plan' && draft.plan_id !== '' ? { plan_id: Number(draft.plan_id) } : {}),
  }
}

/** A wheel slice created or edited: its label, what it gives (nothing, Toman, GB, days or a free service) and its weight. */
export function PrizeForm({ prize, invalidates, onSaved, onCancel }: PrizeFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(
    {
      label: prize?.label ?? '',
      kind: prize?.kind ?? 'wallet',
      // "10000.00", "2.50", "7.00" as the admin would type them.
      amount: prize?.amount ? String(Number(prize.amount)) : '',
      plan_id: prize?.plan ? String(prize.plan.id) : '',
      weight: prize ? String(prize.weight) : '1',
      enabled: prize?.enabled ?? true,
    },
    { reads: requestOf },
  )
  const amountField = AMOUNTS[values.kind]
  const amount = amountField?.decimal ? decimalOf(values.amount) : wholeOf(values.amount)

  const save = handleSubmit(async () => {
    const body = requestOf(values)
    const data = await submit(() => (prize ? api.put(`/wheel/prizes/${prize.id}`, body) : api.post('/wheel/prizes', body)), { invalidates })
    if (data) {
      toast.success(prize ? 'جایزه ذخیره شد' : 'جایزه اضافه شد')
      onSaved(data.prize)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="prize_label" label="متن روی گردونه" error={error('label')} hint="همان چیزی که مشتری روی گردونه و در نتیجه چرخش می‌بیند؛ حداکثر 32 کاراکتر.">
        <Input value={values.label} onChange={(e) => set('label', e.target.value)} placeholder="۱۰ گیگ حجم" autoFocus={!prize} />
      </Field>

      <Field id="prize_kind" label="جایزه" error={error('kind')} hint={values.kind === 'nothing' ? '«پوچ» چیزی نمی‌دهد؛ برای این‌که هر چرخشی برنده نباشد.' : undefined}>
        {(control) => (
          <Select value={values.kind} onValueChange={(kind) => set('kind', kind as WheelPrizeKind)}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(WHEEL_PRIZE_KIND) as WheelPrizeKind[]).map((kind) => (
                <SelectItem key={kind} value={kind}>
                  {WHEEL_PRIZE_KIND[kind]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>

      {values.kind === 'plan' ? (
        <PrizeAmountField
          id="prize_plan"
          kind="plan"
          value={values.plan_id}
          onChange={(value) => set('plan_id', value)}
          error={error('plan_id')}
          hint="یک سرویس رایگان از این پلن، روی اولین سروری که همان لحظه تحویل بدهد."
        />
      ) : (
        values.kind !== 'nothing' && (
          <PrizeAmountField
            id="prize_amount"
            kind={values.kind}
            value={values.amount}
            onChange={(value) => set('amount', value)}
            error={error('amount')}
            hint={amount !== null && Number(amount) > 0 ? amountField?.reads(amount) : undefined}
            fraction={amountField?.decimal}
          />
        )
      )}

      <Field id="prize_weight" label="وزن" error={error('weight')} hint="شانس هر جایزه، وزن آن تقسیم بر جمع وزن جایزه‌های فعال است؛ بین 1 تا 10000.">
        <Input dir="ltr" inputMode="numeric" className="sm:max-w-40" value={values.weight} onChange={(e) => set('weight', e.target.value)} />
      </Field>

      <SwitchRow label="فعال" hint="جایزه غیرفعال روی گردونه نمی‌آید." checked={values.enabled} onCheckedChange={(checked) => set('enabled', checked)} error={error('enabled')} bordered={false} />
      <FormActions error={formError} onCancel={onCancel} submitLabel={prize ? 'ذخیره تغییرات' : 'افزودن جایزه'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
