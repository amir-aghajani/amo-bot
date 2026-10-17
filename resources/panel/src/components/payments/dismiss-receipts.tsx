import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ConfirmModal, type NoteSpec } from '@/components/confirm-modal'
import { api } from '@/lib/api'
import type { DismissReceiptsResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatNumber } from '@/lib/format'
import { RECEIPT_DISMISSALS } from '@/lib/limits'
import { queryKeys } from '@/lib/query-keys'

/** What a dismissal takes: the receipts ticked, or every one waiting for review (`waiting`: how many the list counts). */
export type DismissScope = { ids: number[] } | { waiting: number }

/** The most rounds of a hundred «همه» goes through: a queue that keeps filling is not chased for ever. */
const ROUNDS_MOST = 50

/** What the dismissal changes elsewhere, read again once it is over. */
const CHANGES = [queryKeys.payments, queryKeys.everyPayment, queryKeys.orders, queryKeys.dashboards, queryKeys.queues]

/** Two rounds' answers as one: their counts summed, the later one's `left` and `until`. */
const summed = (total: DismissReceiptsResponse, answer: DismissReceiptsResponse): DismissReceiptsResponse => ({
  dismissed: total.dismissed + answer.dismissed,
  skipped: total.skipped + answer.skipped,
  left: answer.left,
  until: answer.until,
})

const NONE: DismissReceiptsResponse = { dismissed: 0, skipped: 0, left: 0, until: null }

/** The receipts ticked, sent a hundred a request (the most one names): ticks kept across a list's pages may be more. */
async function dismissTicked(ids: readonly number[], note: string): Promise<DismissReceiptsResponse> {
  let total = NONE
  for (let start = 0; start < ids.length; start += RECEIPT_DISMISSALS.MOST) {
    total = summed(total, await api.post('/payments/dismiss', { ids: ids.slice(start, start + RECEIPT_DISMISSALS.MOST), note }))
  }
  return total
}

/** Every receipt waiting, a hundred a round, until none is left of those that had come when it began. */
async function dismissAll(note: string): Promise<DismissReceiptsResponse> {
  let total = NONE
  for (let round = 0; round < ROUNDS_MOST; round++) {
    const answer: DismissReceiptsResponse = await api.post('/payments/dismiss', { all: true, note, ...(total.until ? { until: total.until } : {}) })
    total = summed(total, answer)
    if (answer.left === 0 || answer.dismissed === 0) break
  }
  return total
}

/** What the customers read with it. */
const REASON: NoteSpec = { label: 'دلیل رد برای مشتری (اختیاری)', placeholder: 'مثلا: رسید تکراری بود — مشتری این متن را می‌بیند' }

/** What a dismissal does to each receipt, said before it is confirmed. */
const DOES =
  'هر رسید رد می‌شود، همان‌طور که «رد کردن» یک رسید را رد می‌کند: پرداختش ناموفق می‌شود و سفارشش باز می‌ماند تا مشتری دوباره بپردازد یا خودش منقضی شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود، عکس رسیدی که از سایت فرستاده شده پاک می‌شود و در گروه گزارش‌ها یک خط برای همه ثبت می‌شود.'

/**
 * «رد رسیدها»: the receipts waiting for review that the list's selection ticked — or every one waiting — rejected at
 * once (POST /payments/dismiss), with a note for their customers, after a confirmation; both a hundred a request. «همه»
 * goes until none is left of those that had come when it began (each round sends back the first answer's `until`): a
 * receipt sent meanwhile is left to be seen. What it changed elsewhere is read again — after a failure too, which may
 * come once some went through; `onDone` once it all went through.
 */
export function DismissReceiptsModal({ scope, onClose, onDone }: { scope: DismissScope | null; onClose: () => void; onDone: () => void }) {
  const queryClient = useQueryClient()
  const dismiss = useMutation({
    mutationFn: ({ taken, note }: { taken: DismissScope; note: string }) => ('ids' in taken ? dismissTicked(taken.ids, note) : dismissAll(note)),
    onSuccess: ({ dismissed, skipped }) => {
      const decided = skipped > 0 ? `${formatNumber(skipped)} رسید پیش‌تر تعیین تکلیف شده بود.` : undefined
      if (dismissed > 0) toast.success(`${formatNumber(dismissed)} رسید رد شد`, { description: decided })
      else toast.warning('رسیدی رد نشد', { description: decided })
      onDone()
    },
    onError: () => {
      for (const queryKey of CHANGES) void queryClient.invalidateQueries({ queryKey })
    },
    meta: { quiet: true, invalidates: CHANGES },
  })

  const all = scope !== null && 'waiting' in scope

  return (
    <ConfirmModal
      open={scope !== null}
      onClose={() => {
        dismiss.reset()
        onClose()
      }}
      title={scope === null ? '' : all ? 'رد همه رسیدهای در انتظار' : `رد ${formatNumber(scope.ids.length)} رسید`}
      description={scope !== null && 'waiting' in scope ? `همه ${formatNumber(scope.waiting)} رسیدی که در انتظار بررسی است رد می‌شود.` : 'رسیدهای انتخاب‌شده رد می‌شوند.'}
      confirmLabel="رد رسیدها"
      destructive
      pending={dismiss.isPending}
      note={REASON}
      error={dismiss.error && messageOf(dismiss.error, 'ids', 'note')}
      onConfirm={(note) => scope && dismiss.mutate({ taken: scope, note })}
    >
      {DOES}
    </ConfirmModal>
  )
}
