import { toast } from 'sonner'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { DriverDescription, DriverValues, PaymentMethodCreateRequest, PaymentMethodRow, PaymentMethodUpdateRequest } from '@/lib/api-types'
import { trimmed, useForm } from '@/lib/use-form'

interface MethodFormProps {
  driver: DriverDescription
  /** Present when editing: a method of that driver. */
  method?: PaymentMethodRow
  onSaved: (method: PaymentMethodRow) => void
  onCancel: () => void
}

/**
 * A payment method's form, whatever its driver: the label the customer picks it by, the driver's own form drawn from its
 * description (components/driver-form) — a card's number, its holder, the note under it, how long its receipts wait —,
 * and the switch. A new method names its driver; a method's form again keeps the one it has.
 */
export function MethodForm({ driver, method, onSaved, onCancel }: MethodFormProps) {
  // What the row keeps (its `config`, closed per driver): its form's fields by name.
  const stored: DriverValues | undefined = method ? { ...method.config } : undefined
  // The fields are the driver's own form's — the API's closed request of that driver (tests/Unit/Drivers/DriverFormsTest
  // holds the two together) —, with the label and the switch: what a save sends, and what is unsaved of it.
  const formOf = (draft: DriverDraft) => ({ ...driverPayload(driver, draft), label: String(draft.label), enabled: draft.enabled === true })
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<DriverDraft>(
    { ...driverDraft(driver, stored), label: method?.label ?? driver.label, enabled: method?.enabled ?? true },
    { reads: (draft) => trimmed(formOf(draft)) },
  )

  const save = handleSubmit(async () => {
    const form = formOf(values)
    const data = await submit(() =>
      method ? api.put(`/payment-methods/${method.id}`, form as PaymentMethodUpdateRequest) : api.post('/payment-methods', { ...form, driver: driver.key } as PaymentMethodCreateRequest),
    )
    if (data) {
      toast.success(method ? 'روش پرداخت ذخیره شد' : 'روش پرداخت اضافه شد')
      onSaved(data.method)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="method_label" label="نام روش" error={error('label')} hint="روی دکمه پرداخت نوشته می‌شود؛ با چند روش از یک نوع، مثلا چند کارت، فرقشان را در نام بیاورید.">
        <Input value={String(values.label)} onChange={(e) => set('label', e.target.value)} placeholder="کارت به کارت (ملت)" autoFocus={!method} />
      </Field>

      <DriverFields driver={driver} stored={stored} draft={values} set={set} error={error} idPrefix="method" />

      <SwitchRow
        label="فعال"
        hint="مشتری این روش را هنگام پرداخت می‌بیند."
        checked={values.enabled === true}
        onCheckedChange={(checked) => set('enabled', checked)}
        error={error('enabled')}
        bordered={false}
      />

      <FormActions error={formError} onCancel={onCancel} submitLabel={method ? 'ذخیره تغییرات' : 'افزودن روش'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
