import { useId } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import { Gift } from 'lucide-react'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { GrantAmountFields, GrantOption, type GrantAmountHint } from '@/components/grants/grant-terms'
import { NoteInput } from '@/components/note-input'
import { api } from '@/lib/api'
import type { SubscriptionExtensionRequest, SubscriptionRow } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { decimalOf, formatBytes } from '@/lib/format'
import { useForm } from '@/lib/use-form'

/** The form as the admin fills it in: the request whole, the amounts as typed. */
const EXTENSION: Required<SubscriptionExtensionRequest> & { days: string; traffic_gb: string } = { days: '', traffic_gb: '', note: '', notify: true }

interface ExtendFormProps {
  subscription: SubscriptionRow
  /** The traffic the shop's bot may still sell, where the panel reads it: what the GB comes out of in an agent's shop. */
  traffic?: number
  /** What the extension changes beside the service — the dialog's word on it —, read again once it went through. */
  invalidates: readonly QueryKey[]
  onCancel: () => void
  /** It went through: the service as it is now, and what it got in a word («زمان و حجم», «حجم»). */
  onExtended: (subscription: SubscriptionRow, gift: string) => void
  /** The service moved on meanwhile (refused on its state): the list behind it is read again. */
  onStale: () => void
}

/**
 * «افزایش زمان و حجم» on one service: days and traffic on top of what it has — what a grant gives it —, each worded for
 * this service, or off when it cannot take it (it never ends, it has no quota); the note its customer reads, and
 * whether they hear it. In an agent's shop the GB comes out of the bot's traffic; the days cost nothing.
 */
export function ExtendForm({ subscription, traffic, invalidates, onCancel, onExtended, onStale }: ExtendFormProps) {
  const agentShop = !useMainShop()
  const id = useId()
  const { values, patch, error, formError, busy, dirty, submit, handleSubmit } = useForm(EXTENSION)

  const extend = handleSubmit(async () => {
    const result = await submit(() => api.post(`/subscriptions/${subscription.id}/extend`, values), {
      onInvalid: (errors) => {
        if (errors.status) onStale()
      },
      invalidates,
    })
    if (result) onExtended(result.subscription, giftWords(values))
  })

  return (
    <form onSubmit={extend} noValidate className="grid gap-4">
      <GrantAmountFields values={values} change={patch} error={error} days={daysHint(subscription)} traffic={trafficHint(subscription, agentShop, traffic)} />
      <Field id={`${id}-note`} label="توضیح برای مشتری" optional error={error('note')} hint="در پیام ربات به مشتری نشان داده می‌شود.">
        <NoteInput rows={2} value={values.note} onChange={(e) => patch({ note: e.target.value })} placeholder="مثلا: جبران قطعی دیروز" />
      </Field>
      <GrantOption label="پیام به مشتری" hint="مشتری در ربات می‌بیند چه چیزی به سرویسش اضافه شد." checked={values.notify} onCheckedChange={(notify) => patch({ notify })} />
      <FormActions error={formError} onCancel={onCancel} submitLabel="افزایش زمان و حجم" busy={busy} icon={Gift} dirty={dirty} />
    </form>
  )
}

/** What the service got, in a word, by what was typed: «زمان و حجم», «زمان» or «حجم». */
function giftWords(values: { days: string; traffic_gb: string }): string {
  return [above(values.days) && 'زمان', above(values.traffic_gb) && 'حجم'].filter(Boolean).join(' و ')
}

/** An amount typed above zero — Persian digits, a decimal sign. */
function above(typed: string): boolean {
  return Number(decimalOf(typed) ?? 0) > 0
}

/** What days do to the service — onto its deadline, or onto its term while it waits for its first connection —, or that it never ends. */
function daysHint(subscription: SubscriptionRow): GrantAmountHint {
  if (subscription.expires_at !== null) return { hint: 'به تاریخ پایان سرویس اضافه می‌شود.' }
  if (subscription.duration_days > 0) return { hint: 'به مدت سرویس اضافه می‌شود؛ زمانش از اولین اتصال مشتری شروع می‌شود.' }
  return { hint: 'این سرویس تاریخ پایان ندارد.', off: true }
}

/** What traffic does to the service — and, in an agent's shop, what it comes out of —, or that it has no quota. */
function trafficHint(subscription: SubscriptionRow, agentShop: boolean, left: number | undefined): GrantAmountHint {
  if (subscription.traffic.limit === 0) return { hint: 'حجم این سرویس نامحدود است.', off: true }
  if (!agentShop) return { hint: 'روی حجم سرویس اضافه می‌شود.' }
  return { hint: `روی حجم سرویس اضافه و از حجم ربات این فروشگاه کم می‌شود${left === undefined ? '' : ` (${formatBytes(left)} مانده)`}.` }
}
