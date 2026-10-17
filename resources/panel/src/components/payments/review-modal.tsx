import { useState } from 'react'
import { Ban, BellRing, Check, ChevronRight, RotateCcw, SearchCheck, Undo2, X } from 'lucide-react'
import { toast } from 'sonner'
import { ApiPicture, PictureNote } from '@/components/api-picture'
import { DetailFooter, DetailModal } from '@/components/detail-modal'
import { Fact, FactList } from '@/components/fact-list'
import { LEDGER_NOTE_MAX } from '@/components/note-input'
import { OperationsSection, useOperations, type OperationSpec } from '@/components/operations'
import { DiscountFact } from '@/components/orders/order-discounts'
import { orderBrings } from '@/components/orders/order-subject'
import { MethodSummary } from '@/components/payments/method-summary'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { CustomerFacts } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { OnlineState, OrderType, PaymentRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatDate, formatMoney, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { EMAILED, HELD, isDelivered, NOT_DELIVERED, ORDER_STATUS, ORDER_TYPE, PAYMENT_STATUS } from '@/lib/statuses'
import type { NextRow } from '@/lib/use-next-row'

type Action = keyof PaymentRow['actions']

/** What each operation is called, what it does, and whether it asks first. */
const ACTIONS: Record<Action, OperationSpec> = {
  approve: { label: 'تایید و تحویل', icon: Check, tone: 'primary', done: 'تایید شد و تحویل داده شد' },
  // An online payment's, in place of an approval: its outcome — and its toast — is the gateway's word (checked()), not the admin's.
  check: { label: 'استعلام از درگاه', icon: SearchCheck, tone: 'primary' },
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
    // A purchase's: its service goes with it (refused while its panel fails, or another order holds the service).
    confirm:
      'مبلغ به کیف پول مشتری برمی‌گردد و سرویسی که با این خرید ساخته شده از پنل و فروشگاه حذف می‌شود. اگر پنل جواب ندهد یا سفارش دیگری (تمدید، حجم، زمان یا کاربر اضافه) روی سرویس باشد، بازپرداخت انجام نمی‌شود.',
    // Its wallet line carries it: held to a ledger line's note.
    note: { label: 'توضیح', placeholder: 'اختیاری؛ در تراکنش کیف پول مشتری ثبت می‌شود', max: LEDGER_NOTE_MAX },
  },
}

/** What approving a payment brings, by what it paid for: the service, the wallet's charge, the agent's traffic, a service's extra traffic, time or devices. */
const BRINGS: Record<OrderType, { does: string; done: string }> = {
  purchase: { does: 'تحویل', done: 'تحویل داده شد' },
  renewal: { does: 'تحویل', done: 'تحویل داده شد' },
  wallet_topup: { does: 'شارژ کیف پول', done: 'کیف پول شارژ شد' },
  traffic: { does: 'افزودن حجم', done: 'حجم به ربات نماینده اضافه شد' },
  extra_traffic: { does: 'افزودن حجم', done: 'حجم به سرویس اضافه شد' },
  extra_time: { does: 'افزودن زمان', done: 'زمان به سرویس اضافه شد' },
  extra_devices: { does: 'افزودن کاربر', done: 'کاربر به سرویس اضافه شد' },
}

/**
 * What an online payment's gateway said when asked («استعلام از درگاه»), after the payment's number, and how it is told:
 * paid — into its order, or, the order closed meanwhile, into the customer's wallet —, still waiting, or its page closed.
 */
function checked(payment: PaymentRow, state: OnlineState): { tone: 'success' | 'info' | 'warning'; words: string } {
  switch (state) {
    case 'paid':
      return {
        tone: 'success',
        words: payment.to_wallet ? 'در درگاه پرداخت شده بود؛ سفارشش بسته بود و مبلغ به کیف پول مشتری رفت' : `در درگاه پرداخت شده بود؛ تایید شد و ${BRINGS[payment.order.type].done}`,
      }
    case 'waiting':
      return { tone: 'info', words: 'هنوز در درگاه پرداخت نشده است' }
    case 'closed':
      return { tone: 'warning', words: 'در درگاه پرداخت نشد و مهلت صفحه پرداخت تمام شده است' }
  }
}

/** Approving, worded by what it brings — and, without a receipt to approve, as the admin's own call («تایید دستی»). */
function approveOf(payment: PaymentRow): OperationSpec {
  const { does, done } = BRINGS[payment.order.type]
  return { ...ACTIONS.approve, label: `${payment.status === 'awaiting_review' ? 'تایید' : 'تایید دستی'} و ${does}`, done: `تایید شد و ${done}` }
}

/** A refund's words where it takes back what the order gave a service — refused while its panel fails or it is disabled. */
const takesBack = (what: string) => `مبلغ به کیف پول مشتری برمی‌گردد و ${what} پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.`

/**
 * A refund means a different thing for what the payment paid for: a service (it goes), a renewal or a service's extra
 * (what it gave is taken back), an agent's traffic, a wallet top-up.
 */
function refundOf(payment: PaymentRow): OperationSpec {
  switch (payment.order.type) {
    case 'renewal':
      return { ...ACTIONS.refund, confirm: takesBack('زمان و حجمی که این تمدید به سرویس داده') }
    case 'traffic':
      return {
        ...ACTIONS.refund,
        confirm: 'مبلغ به کیف پول نماینده برمی‌گردد و حجمی که با آن خریده بود از حجم رباتش کم می‌شود؛ اگر از آن حجم فروخته باشد، بازپرداخت انجام نمی‌شود.',
      }
    case 'extra_traffic':
      return { ...ACTIONS.refund, confirm: takesBack('حجمی که به سرویس اضافه شده') }
    case 'extra_time':
      return { ...ACTIONS.refund, confirm: takesBack('زمانی که به سرویس اضافه شده') }
    case 'extra_devices':
      return { ...ACTIONS.refund, confirm: takesBack('کاربرهایی که به سرویس اضافه شده') }
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
 * A payment's dialog: the receipt, the facts and the operations its state allows. «بعدی» walks the list so a queue of
 * receipts is reviewed in a row: after a decision the dialog stays (with «بعدی» focused) while a next payment exists, and
 * closes after the last.
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

/** Reads a payment's operation changes besides the payment: orders, services, the dashboard and the customer's page and wallet. */
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
      // The gateway's word, when it was asked: paid settles the payment as an approval does.
      const state = 'state' in answer ? answer.state : null
      const settled = action === 'approve' || action === 'retry' || (state === 'paid' && !reviewed.to_wallet)
      const undelivered = settled && reviewed.order.status === 'failed'
      const delivery = 'delivery' in answer ? answer.delivery : null
      if (undelivered) {
        toast.error(`پرداخت ${idLabel(reviewed.id)} ${action === 'retry' ? 'باز هم تحویل نشد' : 'تایید شد ولی تحویل انجام نشد'}`, { description: reviewed.order.notes ?? undefined })
      } else if (state !== null) {
        const { tone, words } = checked(reviewed, state)
        toast[tone](`پرداخت ${idLabel(reviewed.id)} ${words}`)
      } else if (delivery !== null && !isDelivered(delivery)) {
        toast.warning('یادآوری فرستاده نشد', { description: NOT_DELIVERED[delivery]('مشتری', reviewed.user.telegram_id !== null) })
      } else {
        // A customer without Telegram was reminded by email.
        toast.success(`پرداخت ${idLabel(reviewed.id)} ${delivery === 'emailed' ? `یادآوری ${EMAILED}` : specs[action].done}`)
      }
      // A reminder, a gateway that has not been paid, or a delivery that failed (the retry is right there), keeps the
      // dialog as it is.
      if (action === 'remind' || (state !== null && state !== 'paid') || undelivered) ops.close()
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
      {/* The review is the dialog's business: on a phone, its decisions stay at the sheet's foot under a tall receipt. */}
      <OperationsSection ops={ops} specs={specs} subject="این پرداخت" foot />
      <DetailFooter user={payment.user} busy={ops.running !== null} onClose={onClose}>
        {/* Held (aria-disabled), not disabled: with no next payment it keeps the focus it was given, and a press does nothing. */}
        <Button variant="secondary" size="sm" busy={next.busy} aria-disabled={!onward} autoFocus={focusNext} onClick={next.go} className="max-md:h-11">
          بعدی
          <ChevronRight className="rtl:rotate-180" aria-hidden />
        </Button>
      </DetailFooter>
    </div>
  )
}

/**
 * Everything known about the payment — an online one's gateway, its id there, its page's end, when it was last asked, and
 * why it is held for support if it is.
 */
function Facts({ payment }: { payment: PaymentRow }) {
  const brings = orderBrings(payment.order)
  const online = payment.kind === 'online'

  return (
    <FactList>
      {/* A space, not a margin alone, between the badge and the words beside it: two words for assistive tech, not one. */}
      <Fact label="وضعیت">
        <StatusBadge status={PAYMENT_STATUS[payment.status]} /> {payment.auto_approved && <span className="ms-1 text-footnote text-muted-foreground">خودکار، بعد از مهلت بررسی</span>}
        {payment.held && <span className="ms-1 text-footnote text-warning">{HELD}</span>}{' '}
        {payment.reviewer && !payment.auto_approved && (
          <span className="ms-1 text-footnote text-muted-foreground">
            توسط <Reviewer name={payment.reviewer} person={payment.reviewer_name} />
          </span>
        )}
      </Fact>
      <Fact label="مبلغ">
        <span className="font-medium tabular">{formatMoney(payment.amount)}</span>
      </Fact>
      <DiscountFact order={payment.order} />
      <CustomerFacts user={payment.user} />
      <Fact label="بابت">
        {ORDER_TYPE[payment.order.type]}
        {brings && ` · ${brings.amount}`}
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
        {online && payment.gateway_label && <span className="block text-footnote text-muted-foreground">درگاه {payment.gateway_label}</span>}
      </Fact>
      {payment.reference && (
        <Fact label={online ? 'شناسه در درگاه' : 'مرجع'}>
          <bdi dir="ltr" className="text-footnote">
            {payment.reference}
          </bdi>
        </Fact>
      )}
      {/* The page the customer pays on takes money until then («در ۲۵ دقیقه»): a pending payment's alone, what it waits on — a held one waits on support. */}
      {payment.status === 'pending' && !payment.held && payment.link_expires_at && (
        <Fact label="مهلت پرداخت در درگاه">
          <span className="text-muted-foreground">
            {formatDate(payment.link_expires_at)} ({timeAgo(payment.link_expires_at)})
          </span>
        </Fact>
      )}
      {payment.checked_at && (
        <Fact label="آخرین استعلام">
          <span className="text-muted-foreground">{timeAgo(payment.checked_at)}</span>
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
      {/* Held for support: why (its gateway's answer does not fit it, or it kept money on it unsettled for 48 hours) — or support's note once it was cancelled. */}
      {payment.held && payment.description && (
        <Fact label="بررسی پشتیبانی">
          <span className="text-warning">{payment.description}</span>
        </Fact>
      )}
      {!payment.held && (payment.status === 'failed' || payment.status === 'cancelled') && payment.description && <Fact label="دلیل">{payment.description}</Fact>}
      {/* Paid after its order closed: the money is the customer's, in their wallet — it paid nothing of the order. */}
      {payment.to_wallet && (
        <Fact label="کیف پول">
          <span className="text-warning">{payment.description}</span>
        </Fact>
      )}
      {payment.refund_note && <Fact label="توضیح بازپرداخت">{payment.refund_note}</Fact>}
    </FactList>
  )
}

/** A picture every browser draws, by its file name; a photo Telegram sent as a photo has none and is a JPEG. */
const DRAWABLE = /\.(jpe?g|png|webp|gif)$/i

/** Support's own record of money paid outside the shop, for an order it made by hand (Payments\Drivers\Outside). */
const OUTSIDE = 'outside'

/** The receipt picture (ApiPicture), or why the payment has none. */
function ReceiptView({ payment }: { payment: PaymentRow }) {
  if (!payment.receipt) {
    // The outside method settles at once as the wallet does — but no wallet paid it.
    const why =
      payment.gateway === OUTSIDE
        ? 'پرداخت خارج از فروشگاه که پشتیبانی ثبت کرده؛ رسیدی در کار نیست'
        : payment.kind === 'instant'
          ? 'پرداخت از کیف پول؛ رسیدی در کار نیست'
          : payment.kind === 'online'
            ? 'پرداخت آنلاین در صفحه درگاه؛ رسیدی در کار نیست و نتیجه‌اش را درگاه می‌گوید'
            : payment.status === 'pending'
              ? 'مشتری هنوز رسیدی نفرستاده است'
              : 'این پرداخت رسیدی ندارد'
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
