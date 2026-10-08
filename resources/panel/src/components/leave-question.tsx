import { ConfirmModal } from '@/components/confirm-modal'
import { useLeaveQuestion } from '@/lib/use-unsaved-guard'

/**
 * The unsaved-changes question (lib/use-unsaved-guard) — before a navigation to another page, signing out, opening
 * another shop, a dialog's ✕ —, drawn once by the panels' root route: the panel's own dialog, as every "are you sure",
 * in its words and its direction — over a dialog it is asked from too. Staying is the default.
 */
export function LeaveQuestion() {
  const { asking, answer } = useLeaveQuestion()

  return (
    <ConfirmModal open={asking} onClose={() => answer(false)} title="تغییرات ذخیره نشده‌اند" confirmLabel="رها کردن تغییرات" cancelLabel="ماندن" destructive onConfirm={() => answer(true)}>
      تغییراتی که ذخیره نکرده‌اید از بین می‌روند.
    </ConfirmModal>
  )
}
