import { Check } from 'lucide-react'
import { toast } from 'sonner'
import { AgencyTermsFields, termsBody, type AgencyTerms } from '@/components/agency/agency-terms-fields'
import { sellingTermsBody, sellingTermsDraft, SellingTermsFields, type SellingTermsDraft } from '@/components/agency/selling-terms-fields'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgencyRequestRow } from '@/lib/api-types'
import { amountDraft } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'

interface ApproveModalProps {
  request: AgencyRequestRow | null
  /** The credit an approved agent starts with (the program's), and the level offered first. */
  defaultCredit: string
  defaultLevel: number | null
  /** The program's terms of selling: the days an agency lasts (0: no end) and the monthly minimum (0: none). */
  defaultTermDays: number
  defaultMinimum: string
  onClose: () => void
  onDone: (request: AgencyRequestRow) => void
}

/** Approving a request: the level the customer becomes an agent on, the credit they start with, and their terms of selling. */
export function ApproveModal({ request, onClose, onDone, ...defaults }: ApproveModalProps) {
  return (
    <Modal open={request !== null} onClose={onClose} size="md" title={request ? `نمایندگی ${userLabel(request.user)}` : 'تایید درخواست'}>
      {request && <ApproveForm key={request.id} request={request} onClose={onClose} onDone={onDone} {...defaults} />}
    </Modal>
  )
}

/** The program's end for an agency approved now — its days from now —; null for none. */
function defaultEnd(days: number): string | null {
  return days > 0 ? new Date(Date.now() + days * 86_400_000).toISOString() : null
}

function ApproveForm({ request, defaultCredit, defaultLevel, defaultTermDays, defaultMinimum, onClose, onDone }: Omit<ApproveModalProps, 'request'> & { request: AgencyRequestRow }) {
  const { values, set, error, formError, busy, submit, handleSubmit } = useForm<AgencyTerms & SellingTermsDraft>({
    level_id: defaultLevel === null ? '' : String(defaultLevel),
    credit_limit: amountDraft(defaultCredit),
    ...sellingTermsDraft(defaultEnd(defaultTermDays), defaultMinimum),
  })

  // Decided meanwhile (in the report group), the refusal on `status` is the form's error. A new agent is on the agents
  // list and a level's count, and the program's numbers count one request fewer and one agent more.
  const approve = handleSubmit(async () => {
    const data = await submit(() => api.post(`/agency/requests/${request.id}/approve`, { ...termsBody(values), ...sellingTermsBody(values) }), {
      invalidates: [queryKeys.agency, queryKeys.agents, queryKeys.agencyLevels],
    })
    if (data) {
      toast.success(`${userLabel(request.user)} نماینده شد`)
      onDone(data.request)
    }
  })

  return (
    <form onSubmit={approve} noValidate className="grid gap-5">
      {request.note && (
        <blockquote className="rounded-xl bg-fill px-4 py-3 whitespace-pre-line">
          <span className="mb-1 block text-footnote text-muted-foreground">توضیح مشتری</span>
          {request.note}
        </blockquote>
      )}
      <AgencyTermsFields values={values} set={set} error={error} />
      <SellingTermsFields values={values} onExpires={(expires) => set('expires', expires)} onMinimum={(minimum) => set('monthly_minimum', minimum)} error={error} />
      <p className="text-footnote leading-relaxed text-muted-foreground">پیام تایید، با سطح، قیمت هر گیگابایت، اعتبار و شرایط فروش، در ربات برای مشتری فرستاده می‌شود.</p>
      <FormActions error={formError} onCancel={onClose} submitLabel="تایید و نماینده کردن" busy={busy} icon={Check} />
    </form>
  )
}
