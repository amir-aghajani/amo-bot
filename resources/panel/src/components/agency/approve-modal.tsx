import { useId } from 'react'
import { useQueryClient, type QueryClient } from '@tanstack/react-query'
import { Check, UserX } from 'lucide-react'
import { toast } from 'sonner'
import { agencyEndWords } from '@/components/agency/agency-standing'
import { AgencyTermsFields, termsBody, termsRead, type AgencyTerms } from '@/components/agency/agency-terms-fields'
import { sellingTermsBody, sellingTermsDraft, SellingTermsFields, sellingTermsRead, type SellingTermsDraft } from '@/components/agency/selling-terms-fields'
import { Callout } from '@/components/callout'
import { windowEnd, windowRequest, type WindowEnd } from '@/components/discounts/window'
import { WindowField } from '@/components/discounts/window-field'
import { Fact, FactList } from '@/components/fact-list'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { Checkbox } from '@/components/ui/checkbox'
import { userLabel } from '@/components/user-identity'
import { api, type FieldErrors } from '@/lib/api'
import type { AgencyRequestRow } from '@/lib/api-types'
import { amountDraft, formatDate, timeAgo } from '@/lib/format'
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

/**
 * Approving a request: to become an agent, the level the customer becomes an agent on, the credit they start with, and
 * their terms of selling; a renewal, the agent's new end (RenewalForm).
 */
export function ApproveModal({ request, onClose, onDone, ...defaults }: ApproveModalProps) {
  const renewal = request?.kind === 'renewal'

  return (
    <Modal open={request !== null} onClose={onClose} size="md" title={request ? `${renewal ? 'تمدید نمایندگی' : 'نمایندگی'} ${userLabel(request.user)}` : 'تایید درخواست'}>
      {request &&
        (renewal ? (
          <RenewalForm key={request.id} request={request} onClose={onClose} onDone={onDone} />
        ) : (
          <ApproveForm key={request.id} request={request} onClose={onClose} onDone={onDone} {...defaults} />
        ))}
    </Modal>
  )
}

/**
 * A request decided meanwhile (in the report group): the refusal on `status` is the form's error, and the requests list
 * is read again — the row's buttons go —, with the program's count of those waiting.
 */
function decidedElsewhere(queryClient: QueryClient) {
  return (errors: FieldErrors) => {
    if (!errors.status) return
    void queryClient.invalidateQueries({ queryKey: queryKeys.agencyRequests })
    void queryClient.invalidateQueries({ queryKey: queryKeys.agency })
  }
}

/** The program's end for an agency approved now — its days from now —; null for none. */
function defaultEnd(days: number): string | null {
  return days > 0 ? new Date(Date.now() + days * 86_400_000).toISOString() : null
}

function ApproveForm({ request, defaultCredit, defaultLevel, defaultTermDays, defaultMinimum, onClose, onDone }: Omit<ApproveModalProps, 'request'> & { request: AgencyRequestRow }) {
  const queryClient = useQueryClient()
  // What was typed over the program's terms is asked about before the dialog closes on it (`dirty`, as the server reads it).
  const { values, set, error, formError, busy, dirty, submit, handleSubmit } = useForm<AgencyTerms & SellingTermsDraft>(
    () => ({
      level_id: defaultLevel === null ? '' : String(defaultLevel),
      credit_limit: amountDraft(defaultCredit),
      ...sellingTermsDraft(defaultEnd(defaultTermDays), defaultMinimum),
    }),
    { reads: (draft) => ({ ...termsRead(draft), ...sellingTermsRead(draft) }) },
  )

  // A new agent is on the agents list and a level's count, and the program's numbers count one request fewer and one
  // agent more.
  const approve = handleSubmit(async () => {
    const data = await submit(() => api.post(`/agency/requests/${request.id}/approve`, { ...termsBody(values), ...sellingTermsBody(values) }), {
      onInvalid: decidedElsewhere(queryClient),
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
      <FormActions error={formError} onCancel={onClose} submitLabel="تایید و نماینده کردن" busy={busy} dirty={dirty} icon={Check} />
    </form>
  )
}

/**
 * A renewal's new end as the form holds it: a Jalali day and an hour on the shop's clock — a blank day is nothing chosen
 * yet —, or «بدون پایان» ticked, the one way to give none. Keyed `expires_at`, so the server's refusal of it stays under
 * its field.
 */
interface RenewalDraft {
  expires_at: WindowEnd
  endless: boolean
}

/** The end an approval gives: as the API takes it, null for none at all — or undefined while nothing is chosen (a blank day). */
function renewalEnd(values: RenewalDraft): string | null | undefined {
  if (values.endless) return null
  return values.expires_at.day.trim() === '' ? undefined : windowRequest(values.expires_at)
}

/**
 * Approving an agent's renewal request: their new end, starting from the one the program's term gives (`term_end`), or
 * none at all — only with «بدون پایان» ticked: a blank day holds the submit until a day is typed. The end goes as the form
 * shows it, to the hour; the submit says when it gives no end. An agent no more (their agency ended since) has nothing to
 * renew: said, and the submit held.
 */
function RenewalForm({ request, onClose, onDone }: { request: AgencyRequestRow; onClose: () => void; onDone: (request: AgencyRequestRow) => void }) {
  const hint = useId()
  const queryClient = useQueryClient()
  const { values, set, error, formError, busy, dirty, submit, handleSubmit } = useForm<RenewalDraft>(() => ({ expires_at: windowEnd(request.term_end, '23'), endless: false }), {
    // «بدون پایان» ticked over a blank day is a change too (null and nothing chosen read alike).
    reads: (draft) => ({ endless: draft.endless, expires_at: renewalEnd(draft) }),
  })
  const end = renewalEnd(values)
  const ready = end !== undefined && request.agent

  // The agent's end is on the agents list, and the program's numbers count one request fewer.
  const approve = handleSubmit(async () => {
    if (!ready) return
    const data = await submit(() => api.post(`/agency/requests/${request.id}/approve`, { expires_at: end }), {
      onInvalid: decidedElsewhere(queryClient),
      invalidates: [queryKeys.agency, queryKeys.agents],
    })
    if (data) {
      const given = data.request.extended_to
      toast.success(given === null ? `نمایندگی ${userLabel(request.user)} بدون پایان شد` : `نمایندگی ${userLabel(request.user)} تا ${formatDate(given)} تمدید شد`)
      onDone(data.request)
    }
  })

  return (
    <form onSubmit={approve} noValidate className="grid gap-5">
      {!request.agent && (
        <Callout tone="warning" icon={UserX}>
          {userLabel(request.user)} دیگر نماینده نیست و نمایندگی‌ای برای تمدید ندارد؛ درخواست را رد کنید.
        </Callout>
      )}
      <FactList>
        {/* An agent no more has no end to show, as on the requests list. */}
        {request.agent && <Fact label="پایان فعلی">{agencyEndWords(request.expires_at)}</Fact>}
        <Fact label="زمان درخواست">{timeAgo(request.created_at)}</Fact>
      </FactList>
      <div className="grid gap-3">
        {!values.endless && (
          <WindowField
            id="renewal_expires"
            label="پایان تازه نمایندگی"
            value={values.expires_at}
            onChange={(expires) => set('expires_at', expires)}
            error={error('expires_at')}
            open="روز پایان تازه را بنویسید، یا «بدون پایان» را بزنید."
          />
        )}
        <label className="flex cursor-pointer items-start gap-3 text-body">
          <Checkbox checked={values.endless} onCheckedChange={(on) => set('endless', on === true)} aria-label="بدون پایان" aria-describedby={hint} className="mt-0.5" />
          <span className="grid gap-0.5">
            <span>بدون پایان</span>
            <span id={hint} className="text-footnote leading-relaxed text-muted-foreground">
              نمایندگی دیگر تاریخ پایان ندارد.
            </span>
          </span>
        </label>
      </div>
      <p className="text-footnote leading-relaxed text-muted-foreground">
        {request.term_end !== null
          ? 'روز پیشنهادی از «مدت نمایندگی» در تنظیمات نمایندگی است: از پایان فعلی اگر هنوز نرسیده، وگرنه از همین حالا. '
          : request.agent && request.expires_at !== null && 'در تنظیمات نمایندگی مدتی برای نمایندگی تعیین نشده است؛ روز پایان تازه را خودتان بنویسید. '}
        پیام تایید، با پایان تازه، در ربات اصلی برای نماینده فرستاده می‌شود؛ اگر فروش رباتش به خاطر پایان نمایندگی متوقف شده باشد، باز می‌شود.
      </p>
      {/* Held — not disabled — while no day is typed and «بدون پایان» is not ticked (the field's line says what it waits
          for), or for an agent no more (the callout says why). */}
      <FormActions error={formError} onCancel={onClose} submitLabel={end === null ? 'تایید بدون پایان' : 'تایید تمدید'} busy={busy} held={!ready} dirty={dirty} icon={Check} />
    </form>
  )
}
