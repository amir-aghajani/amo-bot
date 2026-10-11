import { useQuery } from '@tanstack/react-query'
import { Field } from '@/components/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { LotteryPrizeKind } from '@/lib/api-types'
import { plansQuery } from '@/lib/queries'

/*
 * The pieces every reward is picked with — a lucky wheel's slice, the nightly draw's prize, a gift code, the referral
 * reward: the plan whose service is given free, and the amount of a wallet, traffic or days prize.
 */

interface PlanSelectProps {
  /** The field's wiring (Field's render function), for the trigger. */
  control: { id: string; 'aria-invalid': boolean; 'aria-describedby': string | undefined }
  /** The plan's id as text; '' for none yet. */
  value: string
  onChange: (value: string) => void
  disabled?: boolean
}

/**
 * The plan whose service a reward gives free. Any plan may be given, one off sale too (kept for gifts); a plan that
 * cannot be delivered now says why beside its name.
 */
export function PlanSelect({ control, value, onChange, disabled }: PlanSelectProps) {
  const plans = useQuery(plansQuery)

  return (
    <Select value={value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger {...control} className="w-full">
        <SelectValue placeholder={plans.isPending ? 'در حال خواندن پلن‌ها…' : plans.isError ? 'لیست پلن‌ها خوانده نشد' : 'انتخاب پلن'} />
      </SelectTrigger>
      <SelectContent>
        {(plans.data?.plans ?? []).map((plan) => (
          <SelectItem key={plan.id} value={String(plan.id)} hint={plan.is_active ? (plan.unsellable_reason ?? undefined) : 'غیرفعال؛ فقط برای هدیه'}>
            {plan.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

/** A prize that gives something: Toman, GB, days or a plan's service (the wheel's «پوچ» gives nothing). */
type PrizeKind = LotteryPrizeKind

const AMOUNT_FIELDS: Record<Exclude<PrizeKind, 'plan'>, { label: string; placeholder: string }> = {
  wallet: { label: 'مبلغ جایزه (تومان)', placeholder: '10000' },
  traffic: { label: 'حجم جایزه (گیگابایت)', placeholder: '5' },
  days: { label: 'زمان جایزه (روز)', placeholder: '7' },
}

interface PrizeAmountFieldProps {
  id: string
  kind: PrizeKind
  /** The amount as typed — or, for a plan, its id as text ('' none). */
  value: string
  onChange: (value: string) => void
  error?: string
  hint?: string
  disabled?: boolean
  /** The amount may have a fraction (the wheel's GB): the keyboard with a decimal point. */
  fraction?: boolean
}

/** A prize's amount by its kind — Toman, GB or days — or, for a free service, its plan. */
export function PrizeAmountField({ id, kind, value, onChange, error, hint, disabled, fraction = false }: PrizeAmountFieldProps) {
  if (kind === 'plan') {
    return (
      <Field id={id} label="پلن جایزه" error={error} hint={hint}>
        {(control) => <PlanSelect control={control} value={value} onChange={onChange} disabled={disabled} />}
      </Field>
    )
  }

  const field = AMOUNT_FIELDS[kind]
  return (
    <Field id={id} label={field.label} error={error} hint={hint}>
      <Input
        dir="ltr"
        inputMode={fraction ? 'decimal' : 'numeric'}
        autoComplete="off"
        placeholder={field.placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        className="sm:max-w-48"
      />
    </Field>
  )
}
