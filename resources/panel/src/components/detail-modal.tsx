import { createContext, Fragment, useContext, type ReactNode } from 'react'
import { TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Modal, useHoldOpen } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { TelegramChatLink } from '@/components/user-identity'
import type { UserRef } from '@/lib/api-types'

interface DetailModalProps<T extends { id: number }> {
  /** The row open; null = closed (the dialog keeps the last one on it as it fades out). */
  subject: T | null
  /** The row was deleted meanwhile (its own read answered 404 — useOpenRow's `gone`). */
  gone?: boolean
  title: (subject: T) => ReactNode
  description?: (subject: T) => ReactNode
  onClose: () => void
  /** The body for the row — mounted afresh for each row, so nothing of one is left on another. */
  children: (subject: T) => ReactNode
}

const SubjectGone = createContext(false)

/** Whether the detail dialog this is in shows a row deleted meanwhile: nothing can be done to it any more. */
export function useSubjectGone(): boolean {
  return useContext(SubjectGone)
}

/**
 * A list page's row in a dialog of its own (an order, a payment, a service): its facts, its operations, its footer. A row
 * deleted meanwhile stays on screen as it was last read, under a word that it is gone, its operations withdrawn.
 */
export function DetailModal<T extends { id: number }>({ subject, gone = false, title, description, onClose, children }: DetailModalProps<T>) {
  return (
    <Modal open={subject !== null} onClose={onClose} size="lg" title={subject && title(subject)} description={subject && description?.(subject)}>
      {subject && (
        <SubjectGone value={gone}>
          {gone && (
            <Callout tone="warning" icon={TriangleAlert} role="status" className="mb-4">
              این مورد دیگر وجود ندارد؛ جای دیگری حذف شده است. آنچه این‌جا می‌بینید آخرین وضعیتی است که از آن خوانده شد.
            </Callout>
          )}
          <Fragment key={subject.id}>{children(subject)}</Fragment>
        </SubjectGone>
      )}
    </Modal>
  )
}

interface DetailFooterProps {
  user: UserRef | null
  busy: boolean
  onClose: () => void
  /** More of the foot, before «بستن» (the payments' «بعدی»). */
  children?: ReactNode
}

/**
 * The foot of a detail dialog: a way to the customer in Telegram (when there is a customer, and they have a Telegram
 * account), and closing it — which, as the dialog's ✕ and Escape, waits while an operation runs (`busy`).
 */
export function DetailFooter({ user, busy, onClose, children }: DetailFooterProps) {
  useHoldOpen(busy)

  return (
    <div className="flex items-center gap-2 border-t border-border pt-4">
      {user && (
        <Button asChild variant="ghost" size="sm">
          <TelegramChatLink user={user} />
        </Button>
      )}
      <div className="ms-auto flex items-center gap-2">
        {children}
        <Button variant="ghost" size="sm" onClick={onClose} disabled={busy}>
          بستن
        </Button>
      </div>
    </div>
  )
}
