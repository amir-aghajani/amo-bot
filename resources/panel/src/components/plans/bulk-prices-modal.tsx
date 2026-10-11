import { useId } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { ArrowRight, Check, Eye } from 'lucide-react'
import { toast } from 'sonner'
import { Field, FieldGroup } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Dash } from '@/components/list-view'
import { Modal } from '@/components/modal'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api, ApiError } from '@/lib/api'
import type { PlanPrices, PlanPricesPreviewResponse, PlanPricesRequest, PlanRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { decimalInput, formatMoney, formatNumber, MONEY_UNIT } from '@/lib/format'
import { useForm } from '@/lib/use-form'
import { useProbe } from '@/lib/use-probe'
import { cn } from '@/lib/utils'

type Direction = PlanPricesRequest['direction']
type Mode = PlanPricesRequest['mode']
type Rounding = PlanPricesRequest['round_to']
type PriceField = PlanPricesRequest['prices'][number]

/** The change as the form types it: the request's own fields, its value as typed. */
type Values = Omit<PlanPricesRequest, 'value'> & { value: string }

const DIRECTIONS: PageTab<Direction>[] = [
  { value: 'increase', label: 'افزایش' },
  { value: 'decrease', label: 'کاهش' },
]

const MODES: PageTab<Mode>[] = [
  { value: 'percent', label: 'درصد' },
  { value: 'amount', label: 'مبلغ ثابت' },
]

const ROUNDINGS: { value: Rounding; label: string }[] = [
  { value: 1, label: `بدون گرد کردن (${formatMoney(1)})` },
  { value: 100, label: formatMoney(100) },
  { value: 1000, label: formatMoney(1000) },
  { value: 10000, label: formatMoney(10000) },
]

/** The prices a change may move, in the request's order — each the words a plan's form calls it by, and a column's. */
const PRICES: { field: PriceField; label: string; column: string }[] = [
  { field: 'price', label: 'قیمت', column: 'قیمت' },
  { field: 'first_purchase_price', label: 'قیمت خرید اول', column: 'خرید اول' },
  { field: 'agent_price', label: 'قیمت نماینده', column: 'نماینده' },
]

/** A tick-box group framed apart from the fields around it. */
const FRAMED = 'gap-2 rounded-xl border border-border p-4'

/** The change as the request carries it: its value in the API's form of a number when it reads as one (decimalInput). */
function requestOf(values: Values): PlanPricesRequest {
  return { ...values, value: decimalInput(values.value) }
}

interface BulkPricesModalProps {
  open: boolean
  /** The shop's plans, in their order: those the change may reach. */
  plans: PlanRow[]
  /** Other reads to refresh once the change is made (useRows' `invalidates`). */
  invalidates: readonly QueryKey[]
  onClose: () => void
  /** The change made: every plan as the server now has it. */
  onSaved: (plans: PlanRow[]) => void
}

/**
 * «تغییر گروهی قیمت»: raises or lowers several plans' prices at once by a percent or an amount, rounded. The change must
 * be previewed first, and is applied exactly as previewed; any edit drops the preview.
 */
export function BulkPricesModal({ open, plans, invalidates, onClose, onSaved }: BulkPricesModalProps) {
  return (
    <Modal open={open} onClose={onClose} size="lg" title="تغییر گروهی قیمت" description="قیمت چند پلن را یک‌جا بالا یا پایین ببرید؛ اول پیش‌نمایش را ببینید، بعد اعمال کنید.">
      {open && <BulkPricesForm plans={plans} invalidates={invalidates} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  )
}

/** What a preview found: the change it was asked of, and each plan's prices before and after it. */
interface Preview {
  body: PlanPricesRequest
  changes: PlanPricesPreviewResponse['changes']
}

function BulkPricesForm({ plans, invalidates, onClose, onSaved }: Omit<BulkPricesModalProps, 'open'>) {
  const ids = useId()
  const form = useForm<Values>(() => ({
    plan_ids: plans.map((plan) => plan.id),
    direction: 'increase',
    mode: 'percent',
    value: '',
    round_to: 1000,
    prices: ['price'],
  }))
  const { values, error, errors, formError, busy, dirty, submit, handleSubmit } = form

  // A refused preview is about the change: its field messages land where a refused change's would.
  const preview = useProbe(
    async (): Promise<Preview> => {
      const body = requestOf(values)
      const { changes } = await api.post('/plans/prices/preview', body)
      return { body, changes }
    },
    {
      onFailure: (refusal) => {
        form.setErrors(refusal.errors)
        form.setFormError(refusal.status === 422 && Object.keys(refusal.errors).length > 0 ? null : messageOf(refusal))
      },
    },
  )
  const shown = preview.result
  const changed = shown?.changes.filter((change) => change.problem === null && PRICES.some(({ field }) => change.before[field] !== change.after[field])).length ?? 0
  const blocked = shown?.changes.filter((change) => change.problem !== null).length ?? 0
  // The change goes only as it was previewed, and only while every plan can take it and some plan's price moves.
  const ready = shown !== null && blocked === 0 && changed > 0

  /** Any edit drops the preview: it showed another change. */
  const set = <K extends keyof Values>(key: K, value: Values[K]) => {
    form.set(key, value)
    preview.clear()
  }

  const ask = () => {
    form.setErrors({})
    form.setFormError(null)
    void preview.run()
  }

  const apply = handleSubmit(async () => {
    if (!ready) return
    // Refused — a plan's prices moved since the preview —, the preview is no longer the server's word on the change: it is
    // asked again, the plans that cannot take it named under the plans meanwhile.
    const refused = (failure: unknown) => {
      if (failure instanceof ApiError && failure.status === 422) preview.clear()
      throw failure
    }
    const data = await submit(() => api.post('/plans/prices', shown.body).catch(refused), { invalidates })
    if (data) {
      toast.success(`قیمت ${formatNumber(changed)} پلن تغییر کرد`)
      onSaved(data.plans)
      onClose()
    }
  })

  const ticked = new Set(values.plan_ids)
  const allTicked = plans.length > 0 && plans.every((plan) => ticked.has(plan.id))
  const tickPlan = (id: number, on: boolean) =>
    set(
      'plan_ids',
      plans.map((plan) => plan.id).filter((planId) => (planId === id ? on : ticked.has(planId))),
    )
  const tickPrice = (field: PriceField, on: boolean) =>
    set(
      'prices',
      PRICES.map((price) => price.field).filter((price) => (price === field ? on : values.prices.includes(price))),
    )

  const percent = values.mode === 'percent'

  return (
    <form onSubmit={apply} noValidate className="grid gap-5">
      <FieldGroup label="پلن‌ها" error={errors.plan_ids} className={FRAMED}>
        <label className="flex cursor-pointer items-center gap-2.5 border-b border-border pb-2 text-body font-medium">
          <Checkbox checked={allTicked ? true : values.plan_ids.length === 0 ? false : 'indeterminate'} onCheckedChange={(on) => set('plan_ids', on === true ? plans.map((plan) => plan.id) : [])} />
          همه پلن‌ها
        </label>
        <ul className="grid max-h-56 gap-1 overflow-y-auto">
          {plans.map((plan) => (
            <li key={plan.id}>
              <label className="flex cursor-pointer items-center gap-2.5 rounded-lg px-1 py-1 hover:bg-fill">
                <Checkbox checked={ticked.has(plan.id)} onCheckedChange={(on) => tickPlan(plan.id, on === true)} />
                <span className="min-w-0 flex-1">{plan.name}</span>
                <span className="text-footnote text-muted-foreground tabular">{formatMoney(plan.price)}</span>
              </label>
            </li>
          ))}
        </ul>
      </FieldGroup>

      <div className="grid gap-5 sm:grid-cols-2">
        <FieldGroup label="جهت تغییر" error={error('direction')}>
          <PageTabs as="choice" size="sm" className="w-fit" aria-label="جهت تغییر" value={values.direction} tabs={DIRECTIONS} onChange={(direction) => set('direction', direction)} />
        </FieldGroup>
        <FieldGroup label="نوع تغییر" error={error('mode')}>
          <PageTabs as="choice" size="sm" className="w-fit" aria-label="نوع تغییر" value={values.mode} tabs={MODES} onChange={(mode) => set('mode', mode)} />
        </FieldGroup>

        <Field
          id={`${ids}-value`}
          label={percent ? 'درصد تغییر' : `مبلغ تغییر (${MONEY_UNIT})`}
          error={error('value')}
          hint={percent ? 'اعشار هم می‌شود؛ حداکثر 1000 درصد، در کاهش حداکثر 100.' : 'به تومان و بدون اعشار.'}
        >
          {(control) => (
            <div className="grid grid-cols-[1fr_auto] items-center gap-2">
              <Input {...control} dir="ltr" inputMode="decimal" value={values.value} onChange={(e) => set('value', e.target.value)} placeholder={percent ? '10' : '5000'} />
              <span className="text-body text-muted-foreground">{percent ? '٪' : MONEY_UNIT}</span>
            </div>
          )}
        </Field>

        <Field id={`${ids}-rounding`} label="گرد کردن به" error={error('round_to')} hint="هر قیمت تازه به نزدیک‌ترین مضرب این مبلغ گرد می‌شود.">
          {(control) => (
            <Select value={String(values.round_to)} onValueChange={(picked) => set('round_to', ROUNDINGS.find((rounding) => String(rounding.value) === picked)?.value ?? values.round_to)}>
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROUNDINGS.map((rounding) => (
                  <SelectItem key={rounding.value} value={String(rounding.value)}>
                    {rounding.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      </div>

      <FieldGroup label="قیمت‌هایی که تغییر می‌کنند" error={errors.prices} hint="پلنی که قیمت خرید اول یا قیمت نماینده ندارد، بعد از تغییر هم ندارد." className={FRAMED}>
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          {PRICES.map(({ field, label }) => (
            <label key={field} className="flex cursor-pointer items-center gap-2 text-body">
              <Checkbox checked={values.prices.includes(field)} onCheckedChange={(on) => tickPrice(field, on === true)} />
              {label}
            </label>
          ))}
        </div>
      </FieldGroup>

      {shown ? (
        <PreviewTable preview={shown} changed={changed} blocked={blocked} />
      ) : (
        <p className="text-footnote text-muted-foreground">پیش از اعمال، «پیش‌نمایش» را بزنید تا قیمت‌های تازه هر پلن را ببینید.</p>
      )}

      <FormActions
        error={formError}
        onCancel={onClose}
        submitLabel="اعمال تغییر"
        icon={Check}
        busy={busy}
        held={!ready || preview.busy}
        dirty={dirty}
        start={
          <Button variant="secondary" icon={Eye} busy={preview.busy} onClick={ask} aria-disabled={busy}>
            پیش‌نمایش
          </Button>
        }
      />
    </form>
  )
}

/** The preview: each plan's prices before and after (a dash for a price it does not have) and why a plan cannot take it. */
function PreviewTable({ preview, changed, blocked }: { preview: Preview; changed: number; blocked: number }) {
  const columns = PRICES.filter(({ field }) => preview.body.prices.includes(field))

  return (
    <section aria-label="پیش‌نمایش تغییر" className="grid gap-3">
      <p className={cn('text-footnote', blocked > 0 ? 'text-danger' : 'text-muted-foreground')}>
        {changed === 0 && blocked === 0
          ? 'با این تغییر قیمت هیچ پلنی عوض نمی‌شود.'
          : `قیمت ${formatNumber(changed)} پلن تغییر می‌کند${blocked > 0 ? `؛ ${formatNumber(blocked)} پلن این تغییر را نمی‌پذیرد و تا درست نشود، هیچ قیمتی تغییر نمی‌کند.` : '.'}`}
      </p>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>پلن</TableHead>
            {columns.map(({ field, column }) => (
              <TableHead key={field}>{column}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {preview.changes.map((change) => (
            <TableRow key={change.id}>
              <TableCell>
                <div className="grid">
                  <span>{change.name}</span>
                  {change.problem && <span className="text-footnote text-danger">{change.problem}</span>}
                </div>
              </TableCell>
              {columns.map(({ field }) => (
                <TableCell key={field} className="tabular">
                  <PriceChange before={change.before} after={change.after} field={field} />
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </section>
  )
}

/** One price of a plan as the change leaves it: before, then after — the amount alone when it stays, a dash for none. */
function PriceChange({ before, after, field }: { before: PlanPrices; after: PlanPrices; field: PriceField }) {
  const from = before[field]
  const to = after[field]
  if (from === null || to === null) return <Dash />
  if (from === to) return <span className="text-muted-foreground">{formatMoney(from)}</span>

  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="text-muted-foreground">{formatMoney(from)}</span>
      <ArrowRight className="size-3.5 shrink-0 text-faint rtl:rotate-180" aria-hidden />
      <span className="sr-only"> به </span>
      <span className="font-medium">{formatMoney(to)}</span>
    </span>
  )
}
