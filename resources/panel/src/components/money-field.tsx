import { Field } from '@/components/field'
import { Input } from '@/components/ui/input'
import { formatMoney, tomanOf } from '@/lib/format'

/** What an amount field says under it besides the amount typed (amountHint). */
interface AmountWords {
  /** The line while no amount is typed — blank, unreadable — or 0 where `zero` says nothing of its own. */
  hint?: string
  /** What a 0 typed means, where it means something of its own («بدون هدیه», «بدون سقف روزانه»). */
  zero?: string
  /** The amount in words («۱۲۰٬۰۰۰ تومان») put in a line of its own; by default «= ۱۲۰٬۰۰۰ تومان». */
  says?: (money: string) => string
}

/**
 * The line under an amount field: what the amount read of it comes to, «= ۱۲۰٬۰۰۰ تومان» (or `says`' words) — else what
 * 0 means, or the field's hint. Read of the field as the server reads it, so nothing is said of what it would refuse.
 */
export function amountHint(amount: number | string | null, { hint, zero, says = (money) => `= ${money}` }: AmountWords = {}): string | undefined {
  if (amount === null) return hint
  return Number(amount) > 0 ? says(formatMoney(amount)) : (zero ?? hint)
}

interface MoneyFieldProps extends AmountWords {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  error?: string
  optional?: boolean
  disabled?: boolean
  placeholder?: string
  autoFocus?: boolean
  /** The input's own classes: a narrower box. */
  inputClassName?: string
  /**
   * How the server reads it: as Input::amountOf() reads money an admin types (`tomanOf`, by default) — or, a setting's
   * whole number, as Input::wholeOf() (`wholeNumberOf`).
   */
  reads?: (typed: string) => number | null
}

/**
 * An amount of Toman in a form: its label, a numeric input written left to right, and under it what the typed amount
 * comes to — or what 0 means, or the field's hint (amountHint).
 */
export function MoneyField({ id, label, value, onChange, error, optional, disabled, placeholder, autoFocus, inputClassName, reads = tomanOf, ...words }: MoneyFieldProps) {
  return (
    <Field id={id} label={label} optional={optional} error={error} hint={amountHint(reads(value), words)}>
      <Input
        dir="ltr"
        inputMode="numeric"
        autoComplete="off"
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        autoFocus={autoFocus}
        className={inputClassName}
      />
    </Field>
  )
}
