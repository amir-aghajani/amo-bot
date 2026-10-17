import { useId } from 'react'
import { useQuery, type QueryKey } from '@tanstack/react-query'
import { Forward } from 'lucide-react'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Input } from '@/components/ui/input'
import { UserIdentity, userLabel } from '@/components/user-identity'
import { api, ApiError } from '@/lib/api'
import type { SubscriptionRow, SubscriptionTransferRecipientResponse, UserRef } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { toLatinDigits } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'
import { useSettled } from '@/lib/use-settled'

interface TransferFormProps {
  subscription: SubscriptionRow
  /** What the transfer changes beside the service — the customers' pages and counts —, read again once it went through. */
  invalidates: readonly QueryKey[]
  onCancel: () => void
  /** It went through: the service as it is now, its new customer on it. */
  onTransferred: (subscription: SubscriptionRow) => void
  /** The service moved on meanwhile (refused on its state): the list behind it is read again. */
  onStale: () => void
}

/**
 * «انتقال به مشتری دیگر» for one service: the new customer's numeric Telegram id, and the customer of this shop it
 * names shown as it is typed — the server's own lookup (`GET …/transfer`), which refuses as the transfer would, its
 * words under the id — before anything is sent.
 */
export function TransferForm({ subscription, invalidates, onCancel, onTransferred, onStale }: TransferFormProps) {
  const id = useId()
  const { values, patch, error, formError, busy, dirty, submit, handleSubmit } = useForm({ telegram_id: '' })
  const typed = idOf(values.telegram_id)
  const lookup = useRecipient(subscription.id, typed)
  // Sent only once the server named the customer the id would hand it to.
  const ready = lookup.recipient !== null

  const transfer = handleSubmit(async () => {
    if (!ready) return
    const result = await submit(() => api.post(`/subscriptions/${subscription.id}/transfer`, { telegram_id: typed }), {
      onInvalid: (errors) => {
        if (errors.status) onStale()
      },
      invalidates,
    })
    if (result) onTransferred(result.subscription)
  })

  return (
    <form onSubmit={transfer} noValidate className="grid gap-4">
      <Field
        id={`${id}-telegram`}
        label="شناسه عددی تلگرام مشتری جدید"
        error={error('telegram_id') ?? lookup.refusal?.field('telegram_id')}
        hint="مشتری باید یک بار ربات این فروشگاه را استارت کرده باشد."
      >
        <Input dir="ltr" inputMode="numeric" autoComplete="off" autoFocus value={values.telegram_id} onChange={(e) => patch({ telegram_id: e.target.value })} placeholder="123456789" />
      </Field>
      <Recipient typed={typed} {...lookup} />
      <FormActions error={formError} onCancel={onCancel} submitLabel="انتقال به مشتری دیگر" busy={busy} held={!ready} icon={Forward} dirty={dirty} />
    </form>
  )
}

/**
 * The id as the server is asked it: Persian digits as Latin ones, spaces dropped — and anything else kept, so a handle
 * typed by mistake («@sara1990») is refused by the server as no id, never read as the digits inside it (1990).
 */
function idOf(typed: string): string {
  return toLatinDigits(typed).replace(/\s+/g, '')
}

interface Lookup {
  /** The customer the server would hand the service to; null until it named one. */
  recipient: UserRef | null
  /** Why the server would not: under the id when it is about the id, else beside it. */
  refusal: ApiError | null
  looking: boolean
}

/** The server's answer for the typed id — asked once typing pauses —, about the id on screen only. */
function useRecipient(subscription: number, typed: string): Lookup {
  const settled = useSettled(typed)

  const found = useQuery({
    queryKey: queryKeys.transferRecipient(subscription, settled),
    queryFn: () => api.get<SubscriptionTransferRecipientResponse>(`/subscriptions/${subscription}/transfer`, new URLSearchParams({ telegram_id: settled })),
    enabled: settled !== '',
    select: (data) => data.recipient,
  })
  const current = settled === typed && typed !== ''

  return {
    recipient: current ? (found.data ?? null) : null,
    refusal: current && found.error instanceof ApiError ? found.error : null,
    looking: typed !== '' && (!current || found.isPending),
  }
}

/** Who the typed id names, before the admin confirms; a refusal not about the id (the service's own state) in its place. */
function Recipient({ typed, recipient, refusal, looking }: Lookup & { typed: string }) {
  if (typed === '' || refusal?.field('telegram_id') !== undefined) return null

  return (
    <div role="status" className="grid gap-2 rounded-lg border border-border bg-card p-3">
      {looking ? (
        <span className="text-footnote text-muted-foreground">در حال پیدا کردن مشتری…</span>
      ) : refusal ? (
        <span className="text-footnote text-danger">{messageOf(refusal)}</span>
      ) : (
        recipient && (
          <>
            <UserIdentity user={recipient} avatar />
            <span className="text-footnote text-muted-foreground">{`سرویس به ${userLabel(recipient)} منتقل می‌شود.`}</span>
          </>
        )
      )}
    </div>
  )
}
