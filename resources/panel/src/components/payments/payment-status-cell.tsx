import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import type { PaymentRow } from '@/lib/api-types'
import { HELD, PAYMENT_STATUS, TO_WALLET } from '@/lib/statuses'

type PaymentState = Pick<PaymentRow, 'status' | 'to_wallet' | 'held' | 'auto_approved' | 'reviewer' | 'reviewer_name'>

/**
 * A payment's status in a list's cell: its badge and, under it, whether it went to the wallet or is held for support —
 * and, with `decided` (the payments screen), who decided it: the shop by itself, or the one who reviewed it.
 */
export function PaymentStatusCell({ payment, decided = false }: { payment: PaymentState; decided?: boolean }) {
  return (
    // The spaces between the lines keep them words apart for assistive tech («ناموفق توسط …»), not one.
    <div className="grid justify-items-start gap-0.5">
      <StatusBadge status={PAYMENT_STATUS[payment.status]} /> {decided && payment.auto_approved && <span className="text-caption text-faint">تایید خودکار</span>}{' '}
      {payment.to_wallet && <span className="text-caption text-warning">{TO_WALLET}</span>} {payment.held && <span className="text-caption text-warning">{HELD}</span>}{' '}
      {decided && payment.reviewer && !payment.auto_approved && (
        <span className="text-caption text-faint">
          توسط <Reviewer name={payment.reviewer} person={payment.reviewer_name} />
        </span>
      )}
    </div>
  )
}
