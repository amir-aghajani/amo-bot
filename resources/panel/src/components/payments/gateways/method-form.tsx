import { toast } from 'sonner'
import { driverDraft, driverPayload, type DriverDraft } from '@/components/driver-form/draft'
import { DriverFields } from '@/components/driver-form/driver-fields'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { MoneyField } from '@/components/money-field'
import { cardsDraft, cardsPayload, newCard, type CardDraft } from '@/components/payments/cards'
import { CardsEditor } from '@/components/payments/cards-editor'
import { gatewayKind } from '@/components/payments/gateways'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { DriverDescription, DriverValues, PaymentMethodCreateRequest, PaymentMethodRow, PaymentMethodUpdateRequest } from '@/lib/api-types'
import { amountDraft } from '@/lib/format'
import { trimmed, useForm } from '@/lib/use-form'

interface MethodFormProps {
  driver: DriverDescription
  /** Present when editing: a method of that driver. */
  method?: PaymentMethodRow
  onSaved: (method: PaymentMethodRow) => void
  onCancel: () => void
}

/**
 * The form as typed, one field a key — the 422's refusals land under the same names —: the driver's own fields, the
 * label, the switch, the bounds, and a card method's cards.
 */
type MethodDraft = Record<string, string | boolean | CardDraft[]>

/** The driver's own fields of the draft (DriverFields', driverPayload()'s): everything but the cards. */
function driverPart(values: MethodDraft): DriverDraft {
  const draft: DriverDraft = {}
  for (const [name, value] of Object.entries(values)) {
    if (!Array.isArray(value)) draft[name] = value
  }
  return draft
}

const cardsOf = (values: MethodDraft): CardDraft[] => (Array.isArray(values.cards) ? values.cards : [])

const text = (values: MethodDraft, name: string): string => {
  const value = values[name]
  return typeof value === 'string' ? value : ''
}

/**
 * A payment method's form for any driver: the label customers see, the amounts it is offered for, the driver's own
 * fields (drawn from its description), a card method's cards, and the switch. A new method names its driver; an
 * existing one keeps it. The wallet has nothing of its own: its label, bounds and switch.
 */
export function MethodForm({ driver, method, onSaved, onCancel }: MethodFormProps) {
  // What the row keeps (its `config`, closed per driver): its form's fields by name.
  const stored: DriverValues | undefined = method ? { ...method.config } : undefined
  const cards = gatewayKind(driver) === 'manual'
  // The fields are the driver's own form's — the API's closed request of that driver (tests/Unit/Drivers/DriverFormsTest
  // holds the two together) —, with the label, the bounds, the switch and a card method's cards: what a save sends, and
  // what is unsaved of it.
  const formOf = (draft: MethodDraft) => ({
    ...driverPayload(driver, driverPart(draft)),
    label: text(draft, 'label'),
    enabled: draft.enabled === true,
    min_amount: text(draft, 'min_amount'),
    max_amount: text(draft, 'max_amount'),
    ...(cards ? { cards: cardsPayload(cardsOf(draft)) } : {}),
  })
  const { values, set, revert, error, errors, formError, busy, dirty, submit, handleSubmit } = useForm<MethodDraft>(
    {
      ...driverDraft(driver, stored),
      label: method?.label ?? driver.label,
      enabled: method?.enabled ?? true,
      min_amount: method?.min_amount ? amountDraft(method.min_amount) : '',
      max_amount: method?.max_amount ? amountDraft(method.max_amount) : '',
      // A new card method starts with one blank card to fill in.
      ...(cards ? { cards: method && 'cards' in method ? cardsDraft(method.cards) : [newCard([])] } : {}),
    },
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
      <Field id="method_label" label="نام روش" error={error('label')} hint="روی دکمه پرداخت نوشته می‌شود؛ با چند روش از یک نوع، فرقشان را در نام بیاورید.">
        <Input value={text(values, 'label')} onChange={(e) => set('label', e.target.value)} placeholder="کارت به کارت (ملت)" autoFocus={!method} />
      </Field>

      <div className="grid gap-5 sm:grid-cols-2">
        <MoneyField
          id="method_min"
          label="کمترین مبلغ (تومان)"
          optional
          value={text(values, 'min_amount')}
          onChange={(amount) => set('min_amount', amount)}
          error={error('min_amount')}
          says={(money) => `= ${money}؛ مبلغ‌های کمتر این روش را نمی‌بینند.`}
          hint="مبلغ‌های کمتر این روش را نمی‌بینند؛ خالی یعنی بدون محدودیت."
        />
        <MoneyField
          id="method_max"
          label="بیشترین مبلغ (تومان)"
          optional
          value={text(values, 'max_amount')}
          onChange={(amount) => set('max_amount', amount)}
          error={error('max_amount')}
          says={(money) => `= ${money}؛ مبلغ‌های بیشتر این روش را نمی‌بینند.`}
          hint="مبلغ‌های بیشتر این روش را نمی‌بینند؛ خالی یعنی بدون محدودیت."
        />
      </div>

      <DriverFields driver={driver} stored={stored} draft={driverPart(values)} set={set} error={error} idPrefix="method" />

      {cards && <CardsEditor cards={cardsOf(values)} onChange={(next) => set('cards', next)} errors={errors.cards} idPrefix="method" />}

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
