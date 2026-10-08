import { useId } from 'react'
import { Field } from '@/components/field'
import { NoteInput } from '@/components/note-input'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import type { GrantReach, ServerGrantRequest } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * What every grant is issued with — the days and traffic, the reason the customers read, whom else it reaches, whether
 * they hear —, as its form holds it: the request whole (ServerGrantRequest), the amounts as typed.
 */
export type GrantTerms = Required<ServerGrantRequest> & { days: string; traffic_gb: string }

export const GRANT_TERMS: GrantTerms = { days: '', traffic_gb: '', reason: '', include_unstarted: false, notify: true }

/** How many services a grant would reach as its form stands: the running ones, and those waiting when ticked in. */
export function reachOf(reach: GrantReach, terms: Pick<GrantTerms, 'include_unstarted'>): number {
  return reach.running + (terms.include_unstarted ? reach.unstarted : 0)
}

interface GrantTermsFieldsProps {
  values: GrantTerms
  /** The form's `patch`: these fields are part of a larger form (the mass gift's audience). */
  change: (changes: Partial<GrantTerms>) => void
  error: (field: string) => string | undefined
  /** Whom it would reach as the form stands — by what the shop last knew; null while that is not chosen yet (no server picked). */
  reach: GrantReach | null
  /** Where those services are, in the count's sentence («این سرور»); absent for wherever the form chose. */
  where?: string
  reasonPlaceholder: string
}

/** Whom the form reaches before its audience is chosen: nobody, which says nothing yet. */
const NOBODY: GrantReach = { running: 0, unstarted: 0 }

/**
 * The terms of a grant, as both grants forms ask for them: the count it would reach — of every active service it goes
 * through, the number its card then counts as checked — or that it would reach none, which its form does not start
 * (reachOf()) —, the amounts, the reason, the options.
 */
export function GrantTermsFields({ values, change, error, reach, where, reasonPlaceholder }: GrantTermsFieldsProps) {
  const id = useId()
  const known = reach ?? NOBODY
  const total = reachOf(known, values)
  const active = known.running + known.unstarted
  const place = where ? ` ${where}` : ''
  const untouched = 'سرویس‌های تمام‌شده و غیرفعال‌شده دست نمی‌خورند.'

  return (
    <>
      <p className="text-body leading-relaxed text-muted-foreground">
        {reach === null
          ? 'اول سروری انتخاب کنید که به سرویس‌هایش اضافه شود.'
          : total > 0
            ? `${active > total ? `از ${formatNumber(active)} سرویس فعال${place}، به ${formatNumber(total)} سرویس` : `به ${formatNumber(total)} سرویس${place}`} اضافه می‌شود؛ ${untouched}`
            : `فعلا سرویسی${place} نیست که این به آن اضافه شود؛ ${untouched}${known.unstarted > 0 ? ' سرویس‌های در انتظار اولین اتصال را پایین‌تر می‌شود شامل کرد.' : ''}`}
      </p>
      <GrantAmountFields values={values} change={change} error={error} days={{ hint: 'به تاریخ پایان هر سرویس اضافه می‌شود.' }} traffic={{ hint: 'روی حجم هر سرویس اضافه می‌شود.' }} />
      <Field id={`${id}-reason`} label="دلیل" optional error={error('reason')} hint="در پیام ربات به مشتری نشان داده می‌شود.">
        <NoteInput rows={2} value={values.reason} onChange={(e) => change({ reason: e.target.value })} placeholder={reasonPlaceholder} />
      </Field>
      <div className="grid gap-3">
        <GrantOption
          label="سرویس‌های در انتظار اولین اتصال هم شامل شوند"
          hint={
            known.unstarted > 0 ? `سرویس‌هایی که مشتری هنوز به آن‌ها وصل نشده و زمانشان شروع نشده است (${formatNumber(known.unstarted)} سرویس${place}).` : `سرویسی${place} در انتظار اولین اتصال نیست.`
          }
          checked={values.include_unstarted}
          onCheckedChange={(include_unstarted) => change({ include_unstarted })}
          disabled={known.unstarted === 0}
        />
        <GrantOption label="پیام به مشتری‌ها" hint="هر مشتری در ربات می‌بیند چه چیزی به سرویسش اضافه شد." checked={values.notify} onCheckedChange={(notify) => change({ notify })} />
      </div>
    </>
  )
}

/** What an amount does to what it is given to — or, `off`, why it cannot be given at all. */
export interface GrantAmountHint {
  hint: string
  off?: boolean
}

interface GrantAmountFieldsProps {
  values: { days: string; traffic_gb: string }
  change: (changes: { days?: string; traffic_gb?: string }) => void
  error: (field: string) => string | undefined
  days: GrantAmountHint
  traffic: GrantAmountHint
}

/**
 * The days and the traffic a grant gives, side by side — a grant's to many services, one service's extension. The first
 * that can be filled in takes the focus: as its dialog opens (`data-autofocus`), or as it appears in one already open.
 */
export function GrantAmountFields({ values, change, error, days, traffic }: GrantAmountFieldsProps) {
  const id = useId()
  const daysFirst = !days.off
  const trafficFirst = !daysFirst && !traffic.off

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Field id={`${id}-days`} label="تعداد روز" error={error('days')} hint={days.hint}>
        <Input
          dir="ltr"
          inputMode="numeric"
          autoComplete="off"
          placeholder="0"
          value={values.days}
          onChange={(e) => change({ days: e.target.value })}
          disabled={days.off}
          autoFocus={daysFirst}
          data-autofocus={daysFirst || undefined}
        />
      </Field>
      <Field id={`${id}-traffic`} label="حجم (گیگابایت)" error={error('traffic_gb')} hint={traffic.hint}>
        <Input
          dir="ltr"
          inputMode="decimal"
          autoComplete="off"
          placeholder="0"
          value={values.traffic_gb}
          onChange={(e) => change({ traffic_gb: e.target.value })}
          disabled={traffic.off}
          autoFocus={trafficFirst}
          data-autofocus={trafficFirst || undefined}
        />
      </Field>
    </div>
  )
}

interface GrantOptionProps {
  label: string
  hint: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
}

/** One of a grant's options: the tick box, then its label and what ticking it does — the whole row ticks. */
export function GrantOption({ label, hint, checked, onCheckedChange, disabled = false }: GrantOptionProps) {
  const id = useId()

  return (
    <label className={cn('flex items-start gap-3 text-body', disabled ? 'opacity-60' : 'cursor-pointer')}>
      <Checkbox checked={checked} onCheckedChange={(value) => onCheckedChange(value === true)} disabled={disabled} aria-label={label} aria-describedby={`${id}-hint`} className="mt-0.5" />
      <span className="grid gap-0.5">
        <span>{label}</span>
        <span id={`${id}-hint`} className="text-footnote leading-relaxed text-muted-foreground">
          {hint}
        </span>
      </span>
    </label>
  )
}
