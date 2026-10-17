import type { QueryKey } from '@tanstack/react-query'
import { useQuery } from '@tanstack/react-query'
import { Shuffle } from 'lucide-react'
import { toast } from 'sonner'
import { CheckList, optionsRead } from '@/components/check-list'
import { Disclosure } from '@/components/disclosure'
import { OrderKindsField, PlansField, targetsRead } from '@/components/discounts/targets'
import { windowEnd, windowRequest, type WindowEnd } from '@/components/discounts/window'
import { WindowField } from '@/components/discounts/window-field'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { MoneyField } from '@/components/money-field'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { SwitchRow } from '@/components/switch-row'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { DiscountCodeRequest, DiscountCodeRow, DiscountType, OwnOrderType } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { planOptionsQuery } from '@/lib/queries'
import { DISCOUNT_TYPE } from '@/lib/statuses'
import { asAmount, asWhole, trimmed, useForm } from '@/lib/use-form'

interface CodeFormProps {
  code: DiscountCodeRow | null
  /** Other reads to refresh once it is saved (the codes list). */
  invalidates: readonly QueryKey[]
  onSaved: (code: DiscountCodeRow) => void
  onCancel: () => void
}

/** The form's draft: amounts as typed, each end of the window a Jalali day and an hour. */
interface Draft {
  code: string
  note: string
  type: DiscountType
  value: string
  max_discount: string
  min_amount: string
  order_types: OwnOrderType[] | null
  plan_ids: number[] | null
  server_ids: number[] | null
  first_purchase_only: boolean
  starts: WindowEnd
  ends: WindowEnd
  max_uses: string
  per_user: string
  enabled: boolean
}

const TYPES: PageTab<DiscountType>[] = (Object.keys(DISCOUNT_TYPE) as DiscountType[]).map((type) => ({ value: type, label: DISCOUNT_TYPE[type] }))

/** Letters and digits nobody misreads (no 0/O, 1/I/L). */
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'

/** «ساخت کد تصادفی»: eight such characters from the browser's crypto randomness. */
function randomCode(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(8))
  return Array.from(bytes, (byte) => ALPHABET[byte % ALPHABET.length]).join('')
}

/** A saved number as the admin would type it: "50000.00" → "50000"; none → ''. */
const typed = (amount: string | null) => (amount === null ? '' : String(Number(amount)))

/** The draft as the API takes it: only the fields its type reads. */
function requestOf(draft: Draft): DiscountCodeRequest {
  return {
    code: draft.code,
    note: draft.note,
    type: draft.type,
    ...(draft.type === 'free' ? {} : { value: draft.value }),
    ...(draft.type === 'percent' ? { max_discount: draft.max_discount } : {}),
    min_amount: draft.min_amount,
    order_types: draft.order_types,
    plan_ids: draft.plan_ids,
    server_ids: draft.server_ids,
    first_purchase_only: draft.first_purchase_only,
    starts_at: windowRequest(draft.starts),
    ends_at: windowRequest(draft.ends),
    max_uses: draft.max_uses,
    per_user: draft.per_user,
    enabled: draft.enabled,
  }
}

/**
 * The draft as the server reads it, for `dirty`: its text trimmed, its amounts and counts by their digits («۵۰٬۰۰۰» is
 * 50000), what it applies to as sets — a value typed back as it was, or ticked in another order, is no change.
 */
function readOf(draft: Draft): unknown {
  return trimmed({
    ...requestOf(draft),
    ...(draft.type === 'free' ? {} : { value: asAmount(draft.value) }),
    ...(draft.type === 'percent' ? { max_discount: asAmount(draft.max_discount) } : {}),
    min_amount: asAmount(draft.min_amount),
    order_types: targetsRead(draft.order_types),
    plan_ids: targetsRead(draft.plan_ids),
    server_ids: targetsRead(draft.server_ids),
    max_uses: asWhole(draft.max_uses),
    per_user: asWhole(draft.per_user),
  })
}

/**
 * A discount code created or edited: its text, what it takes off (a percent with an optional cap, Toman, or everything),
 * what it applies to (order kinds, plans, servers, first purchases, a minimum amount), its window and its use limits.
 */
export function CodeForm({ code, invalidates, onSaved, onCancel }: CodeFormProps) {
  const options = useQuery(planOptionsQuery)
  const { values, set, revert, error, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(
    {
      code: code?.code ?? '',
      note: code?.note ?? '',
      type: code?.type ?? 'percent',
      value: typed(code?.value ?? null),
      max_discount: typed(code?.max_discount ?? null),
      min_amount: typed(code?.min_amount ?? null),
      order_types: code ? code.order_types : null,
      plan_ids: code?.plans ? code.plans.map((plan) => plan.id) : null,
      server_ids: code?.servers ? code.servers.map((server) => server.id) : null,
      first_purchase_only: code?.first_purchase_only ?? false,
      starts: windowEnd(code?.starts_at ?? null),
      ends: windowEnd(code?.ends_at ?? null, '23'),
      max_uses: code?.max_uses === null || code === null ? '' : String(code.max_uses),
      per_user: code?.per_user === null || code === null ? '' : String(code.per_user),
      enabled: code?.enabled ?? true,
    },
    { reads: readOf },
  )

  const save = handleSubmit(async () => {
    const body = requestOf(values)
    const data = await submit(() => (code ? api.put(`/discount-codes/${code.id}`, body) : api.post('/discount-codes', body)), { invalidates })
    if (data) {
      toast.success(code ? 'کد تخفیف ذخیره شد' : 'کد تخفیف ساخته شد')
      onSaved(data.code)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <Field id="code_text" label="کد" error={error('code')} hint="همان چیزی که مشتری می‌نویسد؛ 3 تا 32 حرف، رقم، خط تیره یا زیرخط. بزرگی و کوچکی حروف فرقی ندارد.">
        {(control) => (
          <div className="flex gap-2">
            <Input
              {...control}
              dir="ltr"
              autoComplete="off"
              placeholder="NOWRUZ"
              value={values.code}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
              className="min-w-0 flex-1"
              autoFocus={!code}
            />
            <Button variant="secondary" icon={Shuffle} onClick={() => set('code', randomCode())}>
              ساخت کد تصادفی
            </Button>
          </div>
        )}
      </Field>

      <div className="grid gap-3">
        <PageTabs as="choice" size="sm" className="w-fit" aria-label="نوع تخفیف" value={values.type} tabs={TYPES} onChange={(type) => set('type', type)} />
        {values.type === 'percent' && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="code_value" label="درصد تخفیف" error={error('value')} hint="بین 1 تا 100">
              <Input dir="ltr" inputMode="numeric" autoComplete="off" placeholder="20" value={values.value} onChange={(e) => set('value', e.target.value)} />
            </Field>
            <MoneyField
              id="code_cap"
              label="سقف تخفیف (تومان)"
              optional
              value={values.max_discount}
              onChange={(cap) => set('max_discount', cap)}
              error={error('max_discount')}
              says={(money) => `بیشتر از ${money} کم نمی‌کند.`}
              hint="خالی یعنی بدون سقف."
            />
          </div>
        )}
        {values.type === 'fixed' && (
          <MoneyField
            id="code_value"
            label="مبلغ تخفیف (تومان)"
            placeholder="20000"
            value={values.value}
            onChange={(value) => set('value', value)}
            error={error('value')}
            says={(money) => `${money} از مبلغ سفارش کم می‌شود، نه بیشتر از خود مبلغ.`}
            inputClassName="sm:max-w-48"
          />
        )}
        {values.type === 'free' && <p className="text-footnote text-muted-foreground">کل مبلغ سفارش را کم می‌کند و سفارش رایگان تحویل می‌شود.</p>}
      </div>

      <OrderKindsField value={values.order_types} onChange={(kinds) => set('order_types', kinds)} error={error('order_types')} />

      <div className="grid gap-4 sm:grid-cols-2">
        <WindowField id="code_starts" label="شروع" value={values.starts} onChange={(starts) => set('starts', starts)} error={error('starts_at')} open="از همین حالا" />
        <WindowField id="code_ends" label="پایان" value={values.ends} onChange={(ends) => set('ends', ends)} error={error('ends_at')} open="بدون پایان؛ برای کد ساعتی، روز و ساعت پایانش را بنویسید." />
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="code_max_uses" label="سقف کل استفاده" optional error={error('max_uses')} hint="خالی یعنی بدون سقف؛ سفارشی که پرداخت نشود و لغو شود، سهمش را پس می‌دهد.">
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.max_uses} onChange={(e) => set('max_uses', e.target.value)} />
        </Field>
        <Field id="code_per_user" label="سقف استفاده هر مشتری" optional error={error('per_user')} hint={values.per_user.trim() === '1' ? 'یک‌بار مصرف برای هر مشتری.' : 'خالی یعنی بدون سقف.'}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.per_user} onChange={(e) => set('per_user', e.target.value)} />
        </Field>
      </div>

      <Disclosure label="محدودیت‌های بیشتر" defaultOpen={code !== null && (code.plans !== null || code.servers !== null || code.min_amount !== null || code.first_purchase_only || code.note !== null)}>
        <div className="grid gap-5">
          <PlansField value={values.plan_ids} onChange={(ids) => set('plan_ids', ids)} error={error('plan_ids')} />
          <CheckList
            label="سرورها"
            all="همه سرورها"
            options={(options.data?.servers ?? []).map((server) => ({ value: server.id, label: server.name, hint: server.is_active ? undefined : 'غیرفعال' }))}
            value={values.server_ids}
            onChange={(ids) => set('server_ids', ids)}
            hint="برای خرید، سروری که مشتری انتخاب می‌کند؛ برای تمدید و حجم، زمان و کاربر اضافه، سرور سرویس."
            error={error('server_ids')}
            {...optionsRead(options, 'سرورها')}
          />
          <MoneyField
            id="code_min"
            label="حداقل مبلغ سفارش (تومان)"
            optional
            value={values.min_amount}
            onChange={(least) => set('min_amount', least)}
            error={error('min_amount')}
            says={(money) => `دست‌کم ${money}، مبلغ سفارش بعد از تخفیف خودکار.`}
            hint="مبلغ سفارش بعد از تخفیف خودکار؛ خالی یعنی هر مبلغی."
            inputClassName="sm:max-w-48"
          />
          <SwitchRow
            label="فقط برای خرید اول"
            hint="فقط مشتری‌ای که هنوز سرویسی نخریده از این کد استفاده می‌کند."
            checked={values.first_purchase_only}
            onCheckedChange={(checked) => set('first_purchase_only', checked)}
            error={error('first_purchase_only')}
            bordered={false}
          />
          <Field id="code_note" label="یادداشت" optional error={error('note')} hint="فقط برای خودتان؛ مشتری آن را نمی‌بیند. مثلا: کمپین اینستاگرام">
            <Input value={values.note} onChange={(e) => set('note', e.target.value)} />
          </Field>
        </div>
      </Disclosure>

      <SwitchRow
        label="فعال"
        hint="کد غیرفعال از هیچ مشتری‌ای پذیرفته نمی‌شود."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        error={error('enabled')}
        bordered={false}
      />
      {code && code.uses > 0 && <p className="text-footnote text-muted-foreground">این کد تا حالا {formatNumber(code.uses)} بار استفاده شده است؛ تغییرها روی سفارش‌های قبلی اثری ندارند.</p>}
      <FormActions error={formError} onCancel={onCancel} submitLabel={code ? 'ذخیره تغییرات' : 'ساخت کد'} busy={busy} dirty={dirty} onRevert={revert} />
    </form>
  )
}
