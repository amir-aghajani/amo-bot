import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ConfirmModal, type NoteSpec } from '@/components/confirm-modal'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgencyRequestRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'

/** The note a decision about an agency carries to the customer — rejecting a request, ending one: they read it as «توضیح پشتیبانی». */
export const CUSTOMER_NOTE: NoteSpec = { label: 'توضیح برای مشتری (اختیاری)', placeholder: 'مشتری این متن را به عنوان توضیح پشتیبانی می‌بیند.' }

/**
 * Rejecting a request, with a note the customer reads; refused (a note too long, decided meanwhile), the dialog says why.
 * The program's numbers count one request fewer.
 */
export function RejectModal({ request, onClose, onDone }: { request: AgencyRequestRow | null; onClose: () => void; onDone: (request: AgencyRequestRow) => void }) {
  const reject = useMutation({
    mutationFn: ({ row, note }: { row: AgencyRequestRow; note: string }) => api.post(`/agency/requests/${row.id}/reject`, { note }),
    onSuccess: (data) => {
      toast.success('درخواست رد شد')
      onDone(data.request)
      onClose()
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
      title="رد درخواست نمایندگی"
      description={request ? `درخواست ${userLabel(request.user)} رد می‌شود و به او خبر داده می‌شود.` : undefined}
      confirmLabel="رد درخواست"
      destructive
      pending={reject.isPending}
      note={CUSTOMER_NOTE}
      error={reject.error && messageOf(reject.error, 'note', 'status')}
      onConfirm={(note) => request && reject.mutate({ row: request, note })}
    />
  )
}
