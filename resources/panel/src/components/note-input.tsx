import { useId, type ComponentProps, type ReactNode } from 'react'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** The longest note the server takes — a decision's word to the customer, a gift's (App\Support\Input::NOTE_MAX). */
const NOTE_MAX = 300

/**
 * The longest note on a ledger's line — a wallet or an agent's traffic set right by hand, a refund's on the wallet line it
 * writes (App\Core\Database\Ledger::NOTE_MAX).
 */
export const LEDGER_NOTE_MAX = 190

/** What the field is held to: `max` characters, and the count read out with it. */
interface Held {
  maxLength: number
  'aria-describedby': string
}

/**
 * A field held to what the server takes, with how much of it is used under it. The browser stops it at the limit and
 * counts as it stops (UTF-16 units: an emoji is two, where the server counts one), so a note is never refused for its
 * length after it is sent.
 */
function Counted({ value, max, describedBy, field }: { value: string; max: number; describedBy?: string; field: (held: Held) => ReactNode }) {
  const count = useId()
  const full = value.length >= max

  return (
    <div className="grid gap-1">
      {field({ maxLength: max, 'aria-describedby': [describedBy, count].filter(Boolean).join(' ') })}
      <p id={count} className={cn('text-end text-caption tabular', full ? 'text-warning' : 'text-muted-foreground')}>
        {formatNumber(value.length)} از {formatNumber(max)} کاراکتر
      </p>
    </div>
  )
}

type NoteInputProps = Omit<ComponentProps<'textarea'>, 'maxLength' | 'value' | 'className'> & {
  value: string
  /** What the server takes of this text, when it is not a decision's note's: a ticket's answer (Support\Services\Tickets::BODY_MAX), a refund's note (LEDGER_NOTE_MAX). */
  max?: number
}

/**
 * A note the customer reads — why a payment was rejected, an order cancelled, a service switched off, what a gift is
 * for, support's answer to a ticket: a textarea held to what the server takes, NOTE_MAX unless it is told another
 * (Counted).
 */
export function NoteInput({ value, max = NOTE_MAX, 'aria-describedby': describedBy, ...props }: NoteInputProps) {
  return <Counted value={value} max={max} describedBy={describedBy} field={(held) => <Textarea {...props} value={value} {...held} />} />
}

type NoteLineProps = Omit<ComponentProps<'input'>, 'maxLength' | 'value' | 'className'> & {
  value: string
  max: number
}

/** A note of one line — a ledger's —, held and counted as NoteInput is. */
export function NoteLine({ value, max, 'aria-describedby': describedBy, ...props }: NoteLineProps) {
  return <Counted value={value} max={max} describedBy={describedBy} field={(held) => <Input {...props} value={value} {...held} />} />
}
