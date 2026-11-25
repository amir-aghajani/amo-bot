import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ConfirmModal, type NoteSpec } from '@/components/confirm-modal'
import { userLabel } from '@/components/user-identity'
import { api, ApiError } from '@/lib/api'
import type { AgencyRequestRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'

/** The note a decision about an agency carries to the customer — rejecting a request, ending one: they read it as «توضیح پشتیبانی». */
export const CUSTOMER_NOTE: NoteSpec = { label: 'توضیح برای مشتری (اختیاری)', placeholder: 'مشتری این متن را به عنوان توضیح پشتیبانی می‌بیند.' }

/** The note a renewal's refusal carries to the agent, read as «توضیح پشتیبانی» too. */
const AGENT_NOTE: NoteSpec = { label: 'توضیح برای نماینده (اختیاری)', placeholder: 'نماینده این متن را به عنوان توضیح پشتیبانی می‌بیند.' }

/**
 * Rejects an agency request — to become an agent, or a renewal, whose agent keeps their end — with an optional note the
 * customer reads; a refusal is shown in the dialog.
 */
export function RejectModal({ request, onClose, onDone }: { request: AgencyRequestRow | null; onClose: () => void; onDone: (request: AgencyRequestRow) => void }) {
  const renewal = request?.kind === 'renewal'
  const queryClient = useQueryClient()
  const reject = useMutation({
    mutationFn: ({ row, note }: { row: AgencyRequestRow; note: string }) => api.post(`/agency/requests/${row.id}/reject`, { note }),
    onSuccess: (data) => {
      toast.success('درخواست رد شد')
      onDone(data.request)
      onClose()
    },
    // Decided meanwhile (in the report group, or the bot): the list is read again, so the row's buttons go.
    onError: (error) => {
      if (!(error instanceof ApiError) || !error.errors.status) return
      void queryClient.invalidateQueries({ queryKey: queryKeys.agencyRequests })
      void queryClient.invalidateQueries({ queryKey: queryKeys.agency })
    },
    meta: { quiet: true, invalidates: [queryKeys.agency] },
  })

  const close = () => {
    reject.reset()
    onClose()
  }

  return (
    <ConfirmModal
      open={request !== null}
      onClose={close}
      title={renewal ? 'رد درخواست تمدید نمایندگی' : 'رد درخواست نمایندگی'}
      description={
        request
          ? renewal
            ? `درخواست تمدید ${userLabel(request.user)} رد می‌شود و به او خبر داده می‌شود؛ پایان نمایندگی‌اش تغییری نمی‌کند.`
            : `درخواست ${userLabel(request.user)} رد می‌شود و به او خبر داده می‌شود.`
          : undefined
      }
      confirmLabel="رد درخواست"
      destructive
      pending={reject.isPending}
      note={renewal ? AGENT_NOTE : CUSTOMER_NOTE}
      error={reject.error && messageOf(reject.error, 'note', 'status')}
      onConfirm={(note) => request && reject.mutate({ row: request, note })}
    />
  )
}
