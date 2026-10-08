import { useState } from 'react'
import { Ban, BellRing, Check, ChevronRight, RotateCcw, Undo2, X } from 'lucide-react'
import { toast } from 'sonner'
import { ApiPicture, PictureNote } from '@/components/api-picture'
import { DetailFooter, DetailModal } from '@/components/detail-modal'
import { Fact, FactList } from '@/components/fact-list'
import { LEDGER_NOTE_MAX } from '@/components/note-input'
import { OperationsSection, useOperations, type OperationSpec } from '@/components/operations'
import { MethodSummary } from '@/components/payments/method-summary'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { CustomerFacts } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { OrderType, PaymentRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatDate, formatMoney } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { EMAILED, isDelivered, NOT_DELIVERED, ORDER_STATUS, ORDER_TYPE, PAYMENT_STATUS } from '@/lib/statuses'
import type { NextRow } from '@/lib/use-next-row'

type Action = keyof PaymentRow['actions']

/** What each operation is called, what it does, and whether it asks first. */
const ACTIONS: Record<Action, OperationSpec> = {
  approve: { label: 'تایید و تحویل', icon: Check, tone: 'primary', done: 'تایید شد و تحویل داده شد' },
  reject: { label: 'رد کردن', icon: X, tone: 'danger', done: 'رد شد', note: { label: 'دلیل رد کردن', placeholder: 'مثلا: مبلغ رسید با سفارش نمی‌خواند — مشتری این متن را می‌بیند' } },
  cancel: {
    label: 'لغو پرداخت',
    icon: Ban,
    tone: 'danger',
    done: 'لغو شد',
    // Not the order while another of its payments waits on its receipt's review: that one is still support's to decide.
    confirm: 'این پرداخت لغو می‌شود؛ سفارشش هم لغو می‌شود و مشتری خبردار می‌شود، مگر رسید پرداخت دیگری از همین سفارش در انتظار بررسی باشد.',
    note: { label: 'توضیح برای مشتری', placeholder: 'اختیاری؛ مثلا: سفارش تکراری بود' },
  },
  remind: { label: 'یادآوری به مشتری', icon: BellRing, tone: 'neutral', done: 'یادآوری فرستاده شد' },
  retry: { label: 'تلاش دوباره برای تحویل', icon: RotateCcw, tone: 'primary', done: 'تحویل دوباره انجام شد' },
  refund: {
    label: 'بازپرداخت به کیف پول',
    icon: Undo2,
    tone: 'danger',
    done: 'به کیف پول بازپرداخت شد',
    confirm: 'مبلغ به کیف پول مشتری برمی‌گردد و پرداخت «بازپرداخت‌شده» می‌شود؛ سرویسی که تحویل شده سر جایش می‌ماند.',
    // Its wallet line carries it: held to a ledger line's note.
    note: { label: 'توضیح', placeholder: 'اختیاری؛ در تراکنش کیف پول مشتری ثبت می‌شود', max: LEDGER_NOTE_MAX },
  },
}

/** What approving a payment brings, by what it paid for: the service, the wallet's charge, the agent's traffic. */
const BRINGS: Record<OrderType, { does: string; done: string }> = {
  purchase: { does: 'تحویل', done: 'تحویل داده شد' },
  renewal: { does: 'تحویل', done: 'تحویل داده شد' },
  wallet_topup: { does: 'شارژ کیف پول', done: 'کیف پول شارژ شد' },
  traffic: { does: 'افزودن حجم', done: 'حجم به ربات نماینده اضافه شد' },
}

/** Approving, worded by what it brings — and, without a receipt to approve, as the admin's own call («تایید دستی»). */
function approveOf(payment: PaymentRow): OperationSpec {
  const { does, done } = BRINGS[payment.order.type]
  return { ...ACTIONS.approve, label: `${payment.status === 'awaiting_review' ? 'تایید' : 'تایید دستی'} و ${does}`, done: `تایید شد و ${done}` }
}

/** A refund means a different thing for what the payment paid for: a service, an agent's traffic, a wallet top-up. */
function refundOf(payment: PaymentRow): OperationSpec {
  switch (payment.order.type) {
    case 'traffic':
      return {
        ...ACTIONS.refund,
        confirm: 'مبلغ به کیف پول نماینده برمی‌گردد و حجمی که با آن خریده بود از حجم رباتش کم می‌شود؛ اگر از آن حجم فروخته باشد، بازپرداخت انجام نمی‌شود.',
      }
    case 'wallet_topup':
      return {
        ...ACTIONS.refund,
        label: 'بازپرداخت شارژ',
        done: 'بازپرداخت شد',
        confirm: 'مبلغ را خودتان بیرون از فروشگاه به مشتری برگردانید؛ همین مبلغ از کیف پولش کم می‌شود. اگر خرجش کرده باشد، بازپرداخت انجام نمی‌شود.',
      }
    default:
      return ACTIONS.refund
  }
}

interface ReviewModalProps {
  payment: PaymentRow | null
  /** The payment was deleted meanwhile (an unpaid one went with its expired order): nothing more can be done to it. */
  gone?: boolean
  onClose: () => void
  /** The payment came back in a new state: into its list (what else it moved is read again — the operations' `invalidates`). */
  onReviewed: (payment: PaymentRow) => void
  /** The server says the row is out of date (someone — or the timer — got there first): reload it and the list. */
  onStale: () => void
  /** «بعدی»: the next payment of the list the admin is working through (useNextRow). */
  next: NextRow
}

/**
 * A payment as the admin works on it: the receipt, the facts, and every operation its state allows — and «بعدی», so a
 * queue of receipts is reviewed in a row: a decision keeps the dialog on the decided payment while a next one waits
 * (the dialog closes after the last), «بعدی» in focus, as it is on every payment «بعدی» brings.
 */
export function ReviewModal({ payment, gone, onClose, onReviewed, onStale, next }: ReviewModalProps) {
  // A decision draws the payment afresh (`round`): its operations as for a payment just opened.
  const [round, setRound] = useState(0)
  // Moving on through the list: the next payment's «بعدی» takes the focus.
  const [onward, setOnward] = useState(false)
  // Closed, the dialog opens next time as it did the first (state adjusted during render).
  if (payment === null && (round !== 0 || onward)) {
    setRound(0)
    setOnward(false)
  }

  const decided = () => {
    if (!next.available) {
      onClose()
      return
    }
    setOnward(true)
    setRound((current) => current + 1)
  }
  const onwards: NextRow = {
    ...next,
    go: () => {
      setOnward(true)
      next.go()
    },
  }

  return (
    <DetailModal subject={payment} gone={gone} title={(subject) => `پرداخت ${idLabel(subject.id)}`} onClose={onClose}>
      {(subject) => <ReviewBody key={round} payment={subject} onClose={onClose} onReviewed={onReviewed} onDecided={decided} onStale={onStale} next={onwards} focusNext={onward} />}
    </DetailModal>
  )
}

interface ReviewBodyProps {
  payment: PaymentRow
  onClose: () => void
  onReviewed: (payment: PaymentRow) => void
  /** A decision was made (approved, rejected, cancelled, refunded, delivered): the dialog moves on or closes. */
  onDecided: () => void
  onStale: () => void
  next: NextRow
  /** «بعدی» takes the focus as the body is drawn. */
  focusNext: boolean
}

/**
 * What a payment's operation changes beside the payment: its order, the service it delivered, the dashboard's counts, and
 * the customer's page — their counts, the wallet a refund or a top-up moved.
 */
const CHANGES = [queryKeys.orders, queryKeys.subscriptions, queryKeys.dashboards, queryKeys.everyUser, queryKeys.everyUserWallet]

function ReviewBody({ payment, onClose, onReviewed, onDecided, onStale, next, focusNext }: ReviewBodyProps) {
  const specs = { ...ACTIONS, approve: approveOf(payment), refund: refundOf(payment) }
  const ops = useOperations({
    specs,
    allowed: payment.actions,
    // A reminder's answer says whether the customer was told, too (`delivery`).
    perform: ({ action, note }) => api.post(`/payments/${payment.id}/${action}`, note === undefined ? undefined : { note }),
    invalidates: CHANGES,
    onDone: (answer, { action }) => {
      const reviewed = answer.payment
      onReviewed(reviewed)
      const undelivered = (action === 'approve' || action === 'retry') && reviewed.order.status === 'failed'
      const delivery = 'delivery' in answer ? answer.delivery : null
      if (undelivered) {
        toast.error(`پرداخت ${idLabel(reviewed.id)} ${action === 'approve' ? 'تایید شد ولی تحویل انجام نشد' : 'باز هم تحویل نشد'}`, { description: reviewed.order.notes ?? undefined })
      } else if (delivery !== null && !isDelivered(delivery)) {
        toast.warning('یادآوری فرستاده نشد', { description: NOT_DELIVERED[delivery]('مشتری', reviewed.user.telegram_id !== null) })
      } else {
        // A customer without Telegram was reminded by email.
        toast.success(`پرداخت ${idLabel(reviewed.id)} ${delivery === 'emailed' ? `یادآوری ${EMAILED}` : specs[action].done}`)
      }
      // A reminder, or a delivery that failed (the retry is right there), keeps the dialog as it is.
      if (action === 'remind' || undelivered) ops.close()
      else onDecided()
    },
    onStale,
  })
  // Not while an operation runs: its answer belongs to this payment.
  const onward = next.available && ops.running === null

  return (
    <div className="grid gap-5">
      <div className="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
        <ReceiptView payment={payment} />
        <Facts payment={payment} />
      </div>
      <OperationsSection ops={ops} specs={specs} subject="این پرداخت" />
      <DetailFooter user={payment.user} busy={ops.running !== null} onClose={onClose}>
        {/* aria-disabled, not disabled: with no next payment it keeps the focus it was given, and says why it does nothing. */}
        <Button variant="secondary" size="sm" busy={next.busy} aria-disabled={!onward} autoFocus={focusNext} className="aria-disabled:opacity-45" onClick={() => onward && next.go()}>
          بعدی
          <ChevronRight className="rtl:rotate-180" aria-hidden />
        </Button>
      </DetailFooter>
    </div>
  )
}

/** Everything known about the payment. */
function Facts({ payment }: { payment: PaymentRow }) {
  return (
    <FactList>
      {/* A space, not a margin alone, between the badge and the words beside it: two words for assistive tech, not one. */}
      <Fact label="وضعیت">
        <StatusBadge status={PAYMENT_STATUS[payment.status]} /> {payment.auto_approved && <span className="ms-1 text-footnote text-muted-foreground">خودکار، بعد از مهلت بررسی</span>}
        {payment.reviewer && !payment.auto_approved && (
          <span className="ms-1 text-footnote text-muted-foreground">
            توسط <Reviewer name={payment.reviewer} />
          </span>
        )}
      </Fact>
      <Fact label="مبلغ">
        <span className="font-medium tabular">{formatMoney(payment.amount)}</span>
      </Fact>
      <CustomerFacts user={payment.user} />
      <Fact label="بابت">
        {ORDER_TYPE[payment.order.type]}
        {payment.order.plan && ` · ${payment.order.plan}`}
        {payment.order.server && ` · ${payment.order.server}`}
      </Fact>
      <Fact label="سفارش">
        <TextLink to={searchLink('/orders', payment.order.id)}>
          <span dir="ltr">#{payment.order.id}</span>
        </TextLink>{' '}
        <StatusBadge status={ORDER_STATUS[payment.order.status]} className="ms-1" />{' '}
        {payment.order.subscription_id && (
          <TextLink to={searchLink('/subscriptions', payment.order.subscription_id)} className="ms-1 text-footnote">
            اشتراک <span dir="ltr">#{payment.order.subscription_id}</span>
          </TextLink>
        )}
      </Fact>
      {payment.order.notes && payment.order.status === 'failed' && (
        <Fact label="خطای تحویل">
          <span className="text-danger">{payment.order.notes}</span>
        </Fact>
      )}
      <Fact label="روش">
        {payment.method}
        {payment.summary && <MethodSummary summary={payment.summary} className="block text-footnote text-muted-foreground" />}
      </Fact>
      {payment.reference && (
        <Fact label="مرجع">
          <bdi dir="ltr" className="text-footnote">
            {payment.reference}
          </bdi>
        </Fact>
      )}
      <Fact label="ثبت">
        <span className="text-muted-foreground">{formatDate(payment.created_at)}</span>
      </Fact>
      {payment.receipt?.sent_at && (
        <Fact label="رسید">
          <span className="text-muted-foreground">{formatDate(payment.receipt.sent_at)}</span>
        </Fact>
      )}
      {payment.paid_at && (
        <Fact label="پرداخت">
          <span className="text-muted-foreground">{formatDate(payment.paid_at)}</span>
        </Fact>
      )}
      {payment.receipt?.note && (
        <Fact label="پیام مشتری">
          {/* One box around however many lines, each in its own direction. */}
          <p dir="auto" className="rounded-lg border border-border bg-fill px-2.5 py-1.5 whitespace-pre-line">
            {payment.receipt.note}
          </p>
        </Fact>
      )}
      {(payment.status === 'failed' || payment.status === 'cancelled') && payment.description && <Fact label="دلیل">{payment.description}</Fact>}
      {payment.refund_note && <Fact label="توضیح بازپرداخت">{payment.refund_note}</Fact>}
    </FactList>
  )
}

/** A picture every browser draws, by its file name; a photo Telegram sent as a photo has none and is a JPEG. */
const DRAWABLE = /\.(jpe?g|png|webp|gif)$/i

/**
 * The receipt itself (components/api-picture: drawn when the browser can, else what there is — the file to download, or
 * the server's word on why there is none) — or, without one, why there is none.
 */
function ReceiptView({ payment }: { payment: PaymentRow }) {
  if (!payment.receipt) {
    const why = payment.kind === 'instant' ? 'پرداخت از کیف پول؛ رسیدی در کار نیست' : payment.status === 'pending' ? 'مشتری هنوز رسیدی نفرستاده است' : 'این پرداخت رسیدی ندارد'
    return <PictureNote place="column">{why}</PictureNote>
  }

  const { name } = payment.receipt
  return (
    <ApiPicture
      path={`/payments/${payment.id}/receipt`}
      alt={`رسید پرداخت ${idLabel(payment.id)}`}
      name={name ?? `receipt-${payment.id}.jpg`}
      what="رسید"
      undrawable={name !== null && !DRAWABLE.test(name)}
      place="column"
    />
  )
}
