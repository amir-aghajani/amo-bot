import { useState } from 'react'
import { Check, Copy, Gift, Wallet } from 'lucide-react'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { MoneyField } from '@/components/money-field'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { PlanSelect } from '@/components/rewards/prize-fields'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { api } from '@/lib/api'
import type { GiftCodeBatchRequest, GiftCodeBatchResponse, GiftCodeKind } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { digitsOnly, formatDay, formatNumber } from '@/lib/format'
import { addDays, today } from '@/lib/jalali'
import { GIFT_CODES } from '@/lib/limits'
import { queryKeys } from '@/lib/query-keys'
import { GIFT_CODE_KIND } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'

const KINDS: PageTab<GiftCodeKind>[] = [
  { value: 'wallet', label: GIFT_CODE_KIND.wallet, icon: Wallet },
  { value: 'plan', label: GIFT_CODE_KIND.plan, icon: Gift },
]

/** The form's draft: numbers as typed, and the codes' life in days from today ('' — no end). */
interface Draft {
  count: string
  kind: GiftCodeKind
  amount: string
  plan_id: string
  batch: string
  prefix: string
  days: string
}

const BLANK: Draft = { count: '10', kind: 'wallet', amount: '', plan_id: '', batch: '', prefix: '', days: '' }

/** The reads a new batch changes: the code list and the batch list. */
const CHANGES = [queryKeys.giftCodes, queryKeys.giftCodeBatches]

/** The last day the codes can be redeemed, `days` from the shop's today; null for no end (or no number yet). */
function lastDay(days: string): string | null {
  const count = Number(digitsOnly(days))
  return days.trim() === '' || !Number.isInteger(count) ? null : addDays(today(), count)
}

/** The draft as the API takes it: only the fields its kind reads, the life as a last day. */
function requestOf(draft: Draft): GiftCodeBatchRequest {
  const expires = lastDay(draft.days)
  return {
    count: draft.count,
    kind: draft.kind,
    batch: draft.batch,
    ...(draft.kind === 'wallet' ? { amount: draft.amount } : draft.plan_id === '' ? {} : { plan_id: Number(draft.plan_id) }),
    ...(draft.prefix.trim() === '' ? {} : { prefix: draft.prefix }),
    ...(expires === null ? {} : { expires_on: expires }),
  }
}

/** «ساخت کدها»: makes a batch of gift codes, then shows the codes made so the admin can copy them. */
export function MakeCodesModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [made, setMade] = useState<GiftCodeBatchResponse | null>(null)
  const close = () => {
    onClose()
    setMade(null)
  }

  return (
    <Modal
      open={open}
      onClose={close}
      title={made ? `کدهای «${made.batch}»` : 'ساخت کد هدیه'}
      description={made ? `${formatNumber(made.codes.length)} کد ساخته شد؛ آن‌ها را کپی کنید و به مشتری‌ها بدهید.` : undefined}
      size="md"
    >
      {made ? <MadeCodes made={made} onClose={close} /> : <MakeCodesForm onMade={setMade} onCancel={close} />}
    </Modal>
  )
}

function MakeCodesForm({ onMade, onCancel }: { onMade: (made: GiftCodeBatchResponse) => void; onCancel: () => void }) {
  const { values, set, error, formError, busy, dirty, submit, handleSubmit } = useForm<Draft>(BLANK, { reads: requestOf })
  const expires = lastDay(values.days)

  const save = handleSubmit(async () => {
    const data = await submit(() => api.post('/gift-codes', requestOf(values)), { invalidates: CHANGES })
    if (data) {
      toast.success(`${formatNumber(data.codes.length)} کد هدیه ساخته شد`)
      onMade(data)
    }
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-5">
      <PageTabs as="choice" value={values.kind} onChange={(kind) => set('kind', kind)} tabs={KINDS} aria-label="ارزش هر کد" size="sm" className="w-fit" />

      {values.kind === 'wallet' ? (
        <MoneyField
          id="gift_amount"
          label="مبلغ هر کد (تومان)"
          placeholder="50000"
          value={values.amount}
          onChange={(amount) => set('amount', amount)}
          error={error('amount')}
          says={(money) => `${money} به کیف پول مشتری اضافه می‌شود.`}
          hint="مبلغی که هر کد به کیف پول مشتری اضافه می‌کند."
          autoFocus
        />
      ) : (
        <Field id="gift_plan" label="پلن" error={error('plan_id')} hint="هر کد یک سرویس رایگان از این پلن می‌دهد؛ روی اولین سروری که همان لحظه تحویل بدهد. پلن غیرفعال هم می‌شود — مخصوص هدیه.">
          {(control) => <PlanSelect control={control} value={values.plan_id} onChange={(value) => set('plan_id', value)} />}
        </Field>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="gift_count" label="تعداد کدها" error={error('count')} hint={`بین 1 تا ${GIFT_CODES.COUNT_MAX}`}>
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.count} onChange={(e) => set('count', e.target.value)} />
        </Field>
        <Field id="gift_batch" label="نام سری" error={error('batch')} hint="کدهای یک سری با هم دیده، کپی و حذف می‌شوند.">
          <Input value={values.batch} onChange={(e) => set('batch', e.target.value)} placeholder="نوروز ۱۴۰۵" />
        </Field>
        <Field id="gift_prefix" label="پیشوند" optional error={error('prefix')} hint={`حروف انگلیسی و عدد، حداکثر ${GIFT_CODES.PREFIX_MAX} کاراکتر؛ اول همه کدها می‌آید.`}>
          <Input dir="ltr" autoComplete="off" value={values.prefix} onChange={(e) => set('prefix', e.target.value)} placeholder="NOWRUZ" />
        </Field>
        <Field
          id="gift_days"
          label="مهلت استفاده (روز)"
          optional
          error={error('expires_on')}
          hint={expires !== null ? `تا پایان ${formatDay(expires, { dateStyle: 'long' })} استفاده می‌شوند.` : 'خالی بماند، کدها همیشه معتبرند.'}
        >
          <Input dir="ltr" inputMode="numeric" autoComplete="off" value={values.days} onChange={(e) => set('days', e.target.value)} placeholder="30" />
        </Field>
      </div>

      <FormActions error={formError} onCancel={onCancel} submitLabel="ساخت کدها" icon={Check} busy={busy} dirty={dirty} />
    </form>
  )
}

/** The codes just made, one a line, to copy whole. */
function MadeCodes({ made, onClose }: { made: GiftCodeBatchResponse; onClose: () => void }) {
  const text = made.codes.join('\n')

  return (
    <div className="grid gap-4">
      <Textarea dir="ltr" readOnly rows={Math.min(10, made.codes.length)} value={text} aria-label="کدهای ساخته‌شده" onFocus={(e) => e.currentTarget.select()} />
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>
          بستن
        </Button>
        <Button icon={Copy} onClick={() => void copyText(text, 'کدها کپی شدند')}>
          کپی همه کدها
        </Button>
      </div>
    </div>
  )
}
