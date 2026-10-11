import { ConfirmModal } from '@/components/confirm-modal'
import { useLeaveQuestion } from '@/lib/use-unsaved-guard'

/**
 * The unsaved-changes question (lib/use-unsaved-guard), drawn once by the root route: the panel's own dialog rather than
 * `window.confirm`, so it is Persian, right to left, and shows over a dialog it is asked from. Staying is the default.
 */
export function LeaveQuestion() {
  const { asking, answer } = useLeaveQuestion()

  return (
    <ConfirmModal open={asking} onClose={() => answer(false)} title="تغییرات ذخیره نشده‌اند" confirmLabel="رها کردن تغییرات" cancelLabel="ماندن" destructive onConfirm={() => answer(true)}>
      تغییراتی که ذخیره نکرده‌اید از بین می‌روند.
    </ConfirmModal>
  )
}
