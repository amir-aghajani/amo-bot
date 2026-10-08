import type { ReactNode } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { ArrowDownLeft, ArrowUpRight, Check } from 'lucide-react'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { LEDGER_NOTE_MAX, NoteLine } from '@/components/note-input'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Input } from '@/components/ui/input'
import { decimalOf, wholeOf } from '@/lib/format'
import { trimmed, useForm } from '@/lib/use-form'

export type Direction = 'add' | 'remove'

/** The form's word on an amount that is no number the API takes — words, two decimal points, three decimals. */
const NOT_AN_AMOUNT = 'یک عدد بنویسید، با حداکثر دو رقم اعشار.'

/** …and on one that must be whole — Toman, the shop's money, has no fraction. */
const NOT_A_WHOLE_AMOUNT = 'یک عدد بدون اعشار بنویسید.'

const DIRECTIONS: PageTab<Direction>[] = [
  { value: 'add', label: 'افزایش', icon: ArrowUpRight },
  { value: 'remove', label: 'کاهش', icon: ArrowDownLeft },
]

/** The form's values, under the names the API gives their errors (a customer's wallet: amount, description; an agent's traffic: gb, note). */
type Values<A extends string, N extends string> = { direction: Direction } & Record<A | N, string>

interface BalanceAdjustProps<A extends string, N extends string, R> {
  /** What the balance is: «موجودی فعلی», «حجم باقی‌مانده ربات» — and how much it is, worded. */
  balanceLabel: string
  balance: ReactNode
  /** The API's names of the amount and the note, under which a refusal comes back. */
  fields: { amount: A; note: N }
  amountLabel: string
  amountPlaceholder: string
  /** A whole number alone — Toman, a wallet's —; else one with up to two decimals (an agent's gigabytes). */
  whole?: boolean
  /** Under the amount as it is typed: «= ۵۰٬۰۰۰ تومان». */
  amountHint?: (amount: string) => string | undefined
  noteHint: string
  notePlaceholders: Record<Direction, string>
  submitLabels: Record<Direction, string>
  /** The change: the amount in the API's form ("150000", "1.5" however it was typed — wholeOf, decimalOf), in its direction, with the note. */
  send: (direction: Direction, amount: string, note: string) => Promise<R>
  /**
   * What else the change shows in — what its answer does not bring (the ledger beside it, another screen's copy) —, read
   * again once it went through.
   */
  invalidates?: readonly QueryKey[]
  /** It went through — the new balance came back. */
  onDone: (result: R, direction: Direction) => void
  /** Leave without a change — a dialog's «انصراف»; a card on a page has none. */
  onCancel?: () => void
}

/**
 * A balance changed by hand — a customer's wallet, an agent's traffic: the balance now, the direction, how much (in Toman
 * a whole number, «۱۵۰٬۰۰۰» going as "150000"; in gigabytes decimals too, «۱٫۵» or «1.5» going as "1.5"; what is no such
 * number is the form's to refuse, never sent as another), a note for their ledger (held to a ledger line's limit,
 * counted). After a change the form is ready for the next, in the same direction. The amount and the note sit side by
 * side where the form has the room (a dialog, a wide card), one under the other in a narrow column.
 */
export function BalanceAdjust<A extends string, N extends string, R>(props: BalanceAdjustProps<A, N, R>) {
  const { balanceLabel, balance, fields, amountLabel, amountPlaceholder, whole = false, amountHint, noteHint, notePlaceholders, submitLabels, send, invalidates, onDone, onCancel } = props
  const blank = (direction: Direction) => ({ direction, [fields.amount]: '', [fields.note]: '' }) as Values<A, N>
  // What would be lost is what was typed: a direction picked and nothing more changes no balance.
  const { values, set, reset, error, setErrors, formError, busy, dirty, submit, handleSubmit } = useForm<Values<A, N>>(() => blank('add'), {
    reads: (draft) => trimmed([draft[fields.amount], draft[fields.note]]),
  })
  const typed = values[fields.amount]
  const amount = whole ? wholeOf(typed) : decimalOf(typed)

  const save = handleSubmit(async () => {
    if (amount === null) {
      setErrors({ [fields.amount]: [whole ? NOT_A_WHOLE_AMOUNT : NOT_AN_AMOUNT] })
      return
    }
    const result = await submit(() => send(values.direction, amount, values[fields.note]), { invalidates })
    if (result === undefined) return
    reset(blank(values.direction))
    onDone(result, values.direction)
  })

  return (
    <div className="grid gap-5">
      <div className="flex items-baseline justify-between gap-3 rounded-xl bg-fill px-4 py-3">
        <span className="text-body text-muted-foreground">{balanceLabel}</span>
        <span className="text-title font-medium tabular">{balance}</span>
      </div>

      <form onSubmit={save} noValidate className="@container grid gap-4">
        <PageTabs as="choice" value={values.direction} onChange={(direction) => set('direction', direction)} tabs={DIRECTIONS} aria-label="نوع تغییر" size="sm" className="w-fit" />
        <div className="grid gap-4 @md:grid-cols-[1fr_1.4fr]">
          <Field id="adjust_amount" label={amountLabel} error={error(fields.amount)} hint={amount !== null ? amountHint?.(amount) : undefined}>
            <Input
              dir="ltr"
              inputMode={whole ? 'numeric' : 'decimal'}
              autoComplete="off"
              placeholder={amountPlaceholder}
              value={typed}
              onChange={(e) => set(fields.amount, e.target.value as Values<A, N>[A])}
            />
          </Field>
          <Field id="adjust_note" label="توضیح" optional error={error(fields.note)} hint={noteHint}>
            <NoteLine max={LEDGER_NOTE_MAX} value={values[fields.note]} onChange={(e) => set(fields.note, e.target.value as Values<A, N>[N])} placeholder={notePlaceholders[values.direction]} />
          </Field>
        </div>
        <FormActions error={formError} onCancel={onCancel} submitLabel={submitLabels[values.direction]} busy={busy} disabled={typed.trim() === ''} icon={Check} dirty={dirty} />
      </form>
    </div>
  )
}
