import { useId } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CheckList, optionsRead } from '@/components/check-list'
import { FieldGroup } from '@/components/field'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import type { NamedRef, OwnOrderType } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { planOptionsQuery, plansQuery } from '@/lib/queries'
import { OWN_ORDER_TYPE } from '@/lib/statuses'
import { asSet } from '@/lib/use-form'

/*
 * What a discount code or a sale holds for, shared by both forms and both lists: the kinds of order — every one (null) or
 * a list — and the plans and plan categories (PlanReachField).
 */

const KINDS = (Object.keys(OWN_ORDER_TYPE) as OwnOrderType[]).map((kind) => ({ value: kind, label: OWN_ORDER_TYPE[kind] }))

interface TargetFieldProps<V> {
  value: V[] | null
  onChange: (value: V[] | null) => void
  error?: string
}

/** «برای چه سفارش‌هایی»: every kind of order a discount may take, or the ones ticked. */
export function OrderKindsField({ value, onChange, error }: TargetFieldProps<OwnOrderType>) {
  return (
    <CheckList
      label="برای چه سفارش‌هایی"
      all="همه: خرید، تمدید، حجم، زمان و کاربر اضافه"
      options={KINDS}
      value={value}
      onChange={onChange}
      hint="شارژ کیف پول و خرید حجم نمایندگی هیچ‌وقت تخفیف نمی‌گیرند."
      error={error}
    />
  )
}

/** A code's or a sale's plans: those named one by one and the categories whose plans it holds for. Both null: every plan. */
export interface PlanReach {
  plan_ids: number[] | null
  category_ids: number[] | null
}

interface TickOption {
  value: number
  label: string
  hint?: string
}

interface TicksProps {
  heading: string
  options: TickOption[]
  value: number[]
  onChange: (value: number[]) => void
  /** What it says in place of options still being read. */
  pending?: string
  /** Why its options could not be read, said in their place as CheckList says it. */
  failure?: string
  /** What it says when there is nothing to tick. */
  none: string
}

/**
 * One list of tick boxes under a small heading that names it, inside PlanReachField. The values ticked come back in the
 * options' order.
 */
function Ticks({ heading, options, value, onChange, pending, failure, none }: TicksProps) {
  const id = useId()
  const toggle = (option: number, on: boolean) => onChange(options.map((candidate) => candidate.value).filter((candidate) => (candidate === option ? on : value.includes(candidate))))

  return (
    <div role="group" aria-labelledby={`${id}-heading`} className="grid gap-1.5">
      <span id={`${id}-heading`} className="text-footnote font-medium text-muted-foreground">
        {heading}
      </span>
      <div className="flex max-h-48 flex-wrap gap-x-5 gap-y-2 overflow-y-auto">
        {options.length === 0 && (failure ? <span className="text-footnote text-danger">{failure}</span> : <span className="text-footnote text-muted-foreground">{pending ?? none}</span>)}
        {options.map((option) => (
          <div key={option.value} className="flex items-center gap-2">
            <Checkbox id={`${id}-${option.value}`} checked={value.includes(option.value)} onCheckedChange={(checked) => toggle(option.value, checked === true)} />
            <Label htmlFor={`${id}-${option.value}`} className="font-normal">
              {option.label}
              {option.hint && <span className="ms-1.5 text-footnote text-faint">({option.hint})</span>}
            </Label>
          </div>
        ))}
      </div>
    </div>
  )
}

/** What the server answers a code or a sale with nothing ticked (DiscountForm::NO_PLAN_CHOSEN), asked for before it. */
const NOTHING_TICKED = 'دست‌کم یک دسته یا پلن انتخاب کنید؛ برای همه پلن‌ها، «همه پلن‌ها» را انتخاب کنید.'

/**
 * A code's or a sale's «پلن‌ها»: every plan, or the categories and plans ticked — a category stands for every plan filed
 * under it at checkout, one moved there later too. While the lists are read, or could not be, they say so; while nothing
 * is ticked it asks for something, unless the server's refusal says it already.
 */
export function PlanReachField({ value, onChange, error }: { value: PlanReach; onChange: (value: PlanReach) => void; error?: string | readonly string[] }) {
  const id = useId()
  const plans = useQuery(plansQuery)
  const options = useQuery(planOptionsQuery)
  const every = value.plan_ids === null && value.category_ids === null
  const ticked = (value.plan_ids?.length ?? 0) + (value.category_ids?.length ?? 0)
  const refused = error !== undefined && error.length > 0

  return (
    <FieldGroup label="پلن‌ها" hint="دسته یعنی همه پلن‌های آن، حتی پلنی که بعدا به آن اضافه شود. برای تمدید و حجم، زمان و کاربر اضافه، پلن سرویس حساب می‌شود." error={error} className="gap-2">
      <div className="flex items-center gap-2">
        <Checkbox id={`${id}-all`} checked={every} onCheckedChange={(checked) => onChange(checked === true ? { plan_ids: null, category_ids: null } : { plan_ids: [], category_ids: [] })} />
        <Label htmlFor={`${id}-all`} className="font-normal">
          همه پلن‌ها
        </Label>
      </div>
      {!every && (
        <div className="grid gap-3 ps-6">
          <Ticks
            heading="دسته‌ها"
            options={(options.data?.categories ?? []).map((category) => ({ value: category.id, label: category.name, hint: category.is_active ? undefined : 'غیرفعال' }))}
            value={value.category_ids ?? []}
            onChange={(ids) => onChange({ plan_ids: value.plan_ids ?? [], category_ids: ids })}
            {...optionsRead(options, 'دسته‌ها')}
            none="هنوز دسته‌ای ندارید؛ از بخش «دسته‌بندی‌ها» بسازید."
          />
          <Ticks
            heading="پلن‌ها، جدا از دسته‌ها"
            options={(plans.data?.plans ?? []).map((plan) => ({
              value: plan.id,
              label: plan.name,
              hint: [plan.category?.name, plan.is_active ? null : 'غیرفعال'].filter(Boolean).join('، ') || undefined,
            }))}
            value={value.plan_ids ?? []}
            onChange={(ids) => onChange({ plan_ids: ids, category_ids: value.category_ids ?? [] })}
            {...optionsRead(plans, 'پلن‌ها')}
            none="چیزی برای انتخاب نیست."
          />
          {ticked === 0 && !refused && <p className="text-footnote text-muted-foreground">{NOTHING_TICKED}</p>}
        </div>
      )}
    </FieldGroup>
  )
}

/**
 * The plans a code or a sale holds for in words, for its row: «دسته «ماهانه»», «۲ دسته و پلن «ویژه»»; «هیچ پلنی» once everything it
 * named is gone; null for every plan.
 */
export function planReachWords(plans: NamedRef[] | null, categories: NamedRef[] | null): string | null {
  if (plans === null && categories === null) return null
  const parts = [categories?.length ? namedWords('دسته', categories) : null, plans?.length ? namedWords('پلن', plans) : null].filter((part) => part !== null)
  return parts.length === 0 ? 'هیچ پلنی' : parts.join(' و ')
}

/** A target list as the server keeps it, for a form's `reads`: every one (null), or the ticked as a set — in no order. */
export function targetsRead(value: readonly (string | number)[] | null): Record<string, true> | null {
  return value === null ? null : asSet(value)
}

/** The kinds of order in words: «خرید، تمدید», or «همه سفارش‌ها». */
export function kindsWords(kinds: OwnOrderType[] | null): string {
  return kinds === null ? 'همه سفارش‌ها' : kinds.map((kind) => OWN_ORDER_TYPE[kind]).join('، ')
}

/** A few named rows in words: «پلن «یک‌ماهه»» for one, «۳ پلن» for more. */
export function namedWords(noun: string, rows: { name: string }[]): string {
  return rows.length === 1 ? `${noun} «${rows[0]?.name}»` : `${formatNumber(rows.length)} ${noun}`
}
