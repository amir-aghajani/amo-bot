import type { QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { OrderKindsField, PlansField, targetsRead } from '@/components/discounts/targets'
import { windowEnd, windowRequest, type WindowEnd } from '@/components/discounts/window'
import { WindowField } from '@/components/discounts/window-field'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { OwnOrderType, SaleRequest, SaleRow } from '@/lib/api-types'
import { asWhole, trimmed, useForm } from '@/lib/use-form'

interface SaleFormProps {
  sale: SaleRow | null
  /** Other reads to refresh once it is saved (useRows' `invalidates`). */
  invalidates: readonly QueryKey[]
  onSaved: (sale: SaleRow) => void
  onCancel: () => void
}

interface Draft {
  name: string
  percent: string
  order_types: OwnOrderType[] | null
  plan_ids: number[] | null
  starts: WindowEnd
  ends: WindowEnd
  enabled: boolean
}

function requestOf(draft: Draft): SaleRequest {
  return {
    name: draft.name,
    percent: draft.percent,
    order_types: draft.order_types,
    plan_ids: draft.plan_ids,
    starts_at: windowRequest(draft.starts),
    ends_at: windowRequest(draft.ends),
    enabled: draft.enabled,
  }
}

/** The draft as the server reads it, for `dirty`: its name trimmed, its percent by its digits, what it applies to as sets. */
function readOf(draft: Draft): unknown {
  return trimmed({ ...requestOf(draft), percent: asWhole(draft.percent), order_types: targetsRead(draft.order_types), plan_ids: targetsRead(draft.plan_ids) })
}

/** A timed sale created or edited: the name customers see, its percent off, the order kinds and plans, and its window. */
export function SaleForm({ sale, invalidates, onSaved, onCancel }: SaleFormProps) {
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(
    {
      name: sale?.name ?? '',
      percent: sale ? String(sale.percent) : '',
      order_types: sale ? sale.order_types : null,
      plan_ids: sale?.plans ? sale.plans.map((plan) => plan.id) : null,
      starts: windowEnd(sale?.starts_at ?? null),
      ends: windowEnd(sale?.ends_at ?? null, '23'),
      enabled: sale?.enabled ?? true,
    },
    { reads: readOf },
  )

  const save = handleSubmit(async () => {
    const body = requestOf(values)
    const data = await submit(() => (sale ? api.put(`/sales/${sale.id}`, body) : api.post('/sales', body)), { invalidates })
    if (data) {
      toast.success(sale ? 'فروش ویژه ذخیره شد' : 'فروش ویژه ساخته شد')
      onSaved(data.sale)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
        <Field id="sale_name" label="نام" error={error('name')} hint="همان چیزی که مشتری کنار قیمت می‌بیند؛ مثلا «فروش ویژه نوروز».">
          <Input value={values.name} onChange={(e) => set('name', e.target.value)} placeholder="فروش ویژه نوروز" autoFocus={!sale} />
        </Field>
        <Field id="sale_percent" label="درصد تخفیف" error={error('percent')} hint="1 تا 100">
          <Input dir="ltr" inputMode="numeric" autoComplete="off" placeholder="20" value={values.percent} onChange={(e) => set('percent', e.target.value)} />
        </Field>
      </div>

      <OrderKindsField value={values.order_types} onChange={(kinds) => set('order_types', kinds)} error={error('order_types')} />
      <PlansField value={values.plan_ids} onChange={(ids) => set('plan_ids', ids)} error={error('plan_ids')} />

      <div className="grid gap-4 sm:grid-cols-2">
        <WindowField id="sale_starts" label="شروع" value={values.starts} onChange={(starts) => set('starts', starts)} error={error('starts_at')} open="از همین حالا" />
        <WindowField id="sale_ends" label="پایان" value={values.ends} onChange={(ends) => set('ends', ends)} error={error('ends_at')} open="بدون پایان" />
      </div>

      <SwitchRow label="فعال" hint="فروش ویژه غیرفعال روی قیمتی اثر ندارد." checked={values.enabled} onCheckedChange={(checked) => set('enabled', checked)} error={error('enabled')} bordered={false} />
      <FormActions error={formError} onCancel={onCancel} submitLabel={sale ? 'ذخیره تغییرات' : 'ساخت فروش ویژه'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
