import { Ban, RotateCcw } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DetailFooter, DetailModal } from '@/components/detail-modal'
import { Fact, FactList } from '@/components/fact-list'
import { OperationsSection, useOperations, type OperationSpec } from '@/components/operations'
import { StatusBadge } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { CustomerFacts } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { OrderRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatDate, formatMoney, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { ORDER_STATUS, ORDER_TYPE, PAYMENT_STATUS, SUBSCRIPTION_STATUS } from '@/lib/statuses'

type Action = keyof OrderRow['actions']

/** What each operation is called, what it does, and whether it asks first. */
const ACTIONS: Record<Action, OperationSpec> = {
  retry: { label: 'تلاش دوباره برای تحویل', icon: RotateCcw, tone: 'primary', done: 'تحویل داده شد' },
  cancel: {
    label: 'لغو سفارش',
    icon: Ban,
    tone: 'danger',
    done: 'لغو شد',
    confirm: 'سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند؛ اگر مشتری پرداختی را شروع کرده بود، خبردار می‌شود.',
    note: { label: 'توضیح برای مشتری', placeholder: 'اختیاری؛ مثلا: سفارش تکراری بود' },
  },
}

/** Cancelling drops every payment still open — a receipt in review too: the confirmation counts those, for support to see what goes. */
function cancelOf(order: OrderRow): OperationSpec {
  const inReview = order.payments.filter((payment) => payment.status === 'awaiting_review').length
  if (inReview === 0) return ACTIONS.cancel
  return { ...ACTIONS.cancel, confirm: `سفارش و پرداخت‌های انجام‌نشده‌اش لغو می‌شوند، از جمله ${formatNumber(inReview)} رسید در انتظار بررسی، و مشتری خبردار می‌شود.` }
}

interface OrderModalProps {
  order: OrderRow | null
  /** The order was deleted meanwhile (an unpaid one that expired): nothing more can be done to it. */
  gone?: boolean
  onClose: () => void
  /** The order came back in a new state: into its list (what else it moved is read again — the operations' `invalidates`). */
  onChanged: (order: OrderRow) => void
  /** The server says the row is out of date (paid or cancelled meanwhile): reload it and the list. */
  onStale: () => void
}

/** An order as the admin works on it: what it was for, how far it got, its payments, and what its state allows. */
export function OrderModal({ order, gone, onClose, onChanged, onStale }: OrderModalProps) {
  return (
    <DetailModal subject={order} gone={gone} title={(subject) => `سفارش ${idLabel(subject.id)}`} onClose={onClose}>
      {(subject) => <OrderBody order={subject} onClose={onClose} onChanged={onChanged} onStale={onStale} />}
    </DetailModal>
  )
}

/**
 * What an order's operation changes beside the order: its payments, the service it delivered, the dashboard's counts, and
 * the customer's page — their counts, the wallet a top-up charged.
 */
const CHANGES = [queryKeys.payments, queryKeys.subscriptions, queryKeys.dashboards, queryKeys.everyUser, queryKeys.everyUserWallet]

function OrderBody({ order, onClose, onChanged, onStale }: { order: OrderRow; onClose: () => void; onChanged: (order: OrderRow) => void; onStale: () => void }) {
  const specs = { ...ACTIONS, cancel: cancelOf(order) }
  const ops = useOperations({
    specs,
    allowed: order.actions,
    perform: ({ action, note }) => api.post(`/orders/${order.id}/${action}`, note === undefined ? undefined : { note }),
    invalidates: CHANGES,
    onDone: ({ order: next }, { action }) => {
      onChanged(next)
      // A delivery that failed again keeps the modal open: the retry is right there.
      if (action === 'retry' && next.status !== 'fulfilled') {
        toast.error(`سفارش ${idLabel(next.id)} باز هم تحویل نشد`, { description: next.notes ?? undefined })
        return
      }
      toast.success(`سفارش ${idLabel(next.id)} ${specs[action].done}`)
      onClose()
    },
    onStale,
  })

  return (
    <div className="grid gap-5">
      <div className="grid gap-5 lg:grid-cols-[1.2fr_1fr]">
        <Facts order={order} />
        <Payments order={order} />
      </div>
      <OperationsSection ops={ops} specs={specs} subject="این سفارش" />
      <DetailFooter user={order.user} busy={ops.running !== null} onClose={onClose} />
    </div>
  )
}

/** Everything known about the order. */
function Facts({ order }: { order: OrderRow }) {
  return (
    <FactList>
      <Fact label="وضعیت">
        <StatusBadge status={ORDER_STATUS[order.status]} />
      </Fact>
      <Fact label="نوع">{ORDER_TYPE[order.type]}</Fact>
      <Fact label="مبلغ">
        <span className="font-medium tabular">{formatMoney(order.amount)}</span>
      </Fact>
      <CustomerFacts user={order.user} />
      {order.plan && <Fact label="پلن">{order.plan.name}</Fact>}
      {order.server && <Fact label="سرور">{order.server.name}</Fact>}
      {order.subscription && (
        <Fact label="سرویس">
          <TextLink to={searchLink('/subscriptions', order.subscription.id)}>
            <bdi dir="ltr" className="text-footnote">
              {order.subscription.name}
            </bdi>
          </TextLink>{' '}
          <StatusBadge status={SUBSCRIPTION_STATUS[order.subscription.status]} className="ms-1" />
        </Fact>
      )}
      {order.notes && (order.status === 'failed' || order.status === 'cancelled') && (
        <Fact label={order.status === 'failed' ? 'خطای تحویل' : 'دلیل لغو'}>
          <span className={order.status === 'failed' ? 'text-danger' : undefined}>{order.notes}</span>
        </Fact>
      )}
      <Fact label="ثبت">
        <span className="text-muted-foreground">{formatDate(order.created_at)}</span>
      </Fact>
      {order.paid_at && (
        <Fact label="پرداخت">
          <span className="text-muted-foreground">{formatDate(order.paid_at)}</span>
        </Fact>
      )}
      {order.fulfilled_at && (
        <Fact label="تحویل">
          <span className="text-muted-foreground">{formatDate(order.fulfilled_at)}</span>
        </Fact>
      )}
    </FactList>
  )
}

/** The attempts to pay the order, each a link to the payments screen, where receipts, refunds and reminders are. */
function Payments({ order }: { order: OrderRow }) {
  return (
    <section className="grid content-start gap-2">
      <h3 className="text-body font-medium">پرداخت‌ها</h3>
      {order.payments.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border px-3 py-4 text-center text-body text-muted-foreground">مشتری هنوز روش پرداختی انتخاب نکرده است.</p>
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-xl border border-border">
          {order.payments.map((payment) => (
            <li key={payment.id}>
              <Link to={searchLink('/payments', payment.id)} className="flex items-center gap-3 px-3 py-2 text-body outline-none hover:bg-fill focus-visible:bg-fill focus-visible:focus-ring">
                <span className="grid min-w-0 flex-1 gap-0.5">
                  <span className="truncate">
                    <span dir="ltr">#{payment.id}</span> · {payment.method}
                  </span>
                  <span className="text-footnote text-muted-foreground">{formatDate(payment.paid_at ?? payment.created_at)}</span>
                </span>
                <StatusBadge status={PAYMENT_STATUS[payment.status]} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
