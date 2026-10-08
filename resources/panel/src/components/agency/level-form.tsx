import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { AgencyLevelRow } from '@/lib/api-types'
import { amountDraft, decimalInput, decimalOf, formatMoney } from '@/lib/format'
import { useForm } from '@/lib/use-form'

interface LevelFormProps {
  level: AgencyLevelRow | null
  /** What else shows the levels — its list's (useRows' `invalidates`) —, read again once it is saved. */
  invalidates: readonly QueryKey[]
  onSaved: (level: AgencyLevelRow) => void
  onCancel: () => void
}

/** Create/edit form for one level of the agents: its name and what a GB of the traffic their bots sell costs them. */
export function LevelForm({ level, invalidates, onSaved, onCancel }: LevelFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm({
    name: level?.name ?? '',
    price_per_gb: level ? amountDraft(level.price_per_gb) : '',
  })
  const price = Number(decimalOf(values.price_per_gb) ?? 0)

  const save = handleSubmit(async () => {
    const body = { name: values.name, price_per_gb: decimalInput(values.price_per_gb) }
    const data = await submit(() => (level ? api.put(`/agency/levels/${level.id}`, body) : api.post('/agency/levels', body)), { invalidates })
    if (data) {
      toast.success(level ? 'سطح ذخیره شد' : 'سطح اضافه شد')
      onSaved(data.level)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="level_name" label="نام سطح" error={error('name')} hint="همان‌طور که مشتری در معرفی نمایندگی و نماینده در حسابش می‌بیند؛ مثلا «برنزی» یا «طلایی».">
        <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="طلایی" autoFocus={!level} />
      </Field>

      <Field
        id="level_price"
        label="قیمت هر گیگابایت (تومان)"
        error={error('price_per_gb')}
        hint={price > 0 ? `۱۰۰ گیگابایت برای نماینده‌های این سطح ${formatMoney(price * 100)} است.` : 'نماینده‌ها حجمی را که رباتشان می‌فروشد با این قیمت از شما می‌خرند.'}
      >
        <Input dir="ltr" inputMode="decimal" value={values.price_per_gb} onChange={(e) => set('price_per_gb', e.target.value)} placeholder="3000" className="sm:max-w-40" />
      </Field>

      <FormActions error={formError} onCancel={onCancel} submitLabel={level ? 'ذخیره تغییرات' : 'افزودن سطح'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
