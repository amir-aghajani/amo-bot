import type { ComponentType, ReactNode } from 'react'
import { CircleCheckBig, CircleX, LoaderCircle, PackageX, TriangleAlert, Undo2 } from 'lucide-react'
import { useNavigate } from 'react-router'
import { useAccount, useShop } from '@/apps/app/lib/session'
import { openContact, orderAgain, orderNumber, orderSubject } from '@/apps/app/screens/pay/parts/order-words'
import { Button, MainAction } from '@/apps/app/ui/button'
import { CopyValue } from '@/apps/app/ui/fields'
import { Fact, Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import { ACCENT_FILL, IconTile } from '@/apps/app/ui/visuals'
import type { OrderType, StoreOrder } from '@/lib/api-types'
import { formatBalance, formatMoney } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * Where an order ended up, as its payment screen says it: delivered (the service to open, or the wallet's new balance),
 * paid and being delivered, paid but not delivered — support finishes it, or settles the money —, cancelled or
 * refunded. Each with the order's facts and the one thing to do next.
 */

/** Each tone's colour, for its icon's circle (ui/visuals IconTile: the shop's colour as a wash takes its readable shade). */
const TONES = { accent: ACCENT_FILL, success: 'var(--app-success)', warning: 'var(--app-warning)', danger: 'var(--app-danger)' } as const

interface OutcomeProps {
  tone: keyof typeof TONES
  icon: ComponentType<{ className?: string }>
  title: ReactNode
  text?: ReactNode
}

/** A payment's state, large: its icon in a 64px circle of its tone, a title and a line — centred as one block. */
export function Outcome({ tone, icon, title, text }: OutcomeProps) {
  return (
    <section className="animate-rise flex flex-col items-center gap-2 px-6 pt-2 text-center" role="status">
      <IconTile icon={icon} size="2xl" color={TONES[tone]} look="soft" round className="mb-2" />
      <h2 className="text-title font-bold">{title}</h2>
      {text !== undefined && <p className="text-hint max-w-sm text-body">{text}</p>}
    </section>
  )
}

/** A spinner turning: something is under way (Outcome's icon while a delivery runs). */
function Turning({ className }: { className?: string }) {
  return <LoaderCircle className={cn(className, 'animate-spin')} />
}

/** The order as a receipt lists it: its number, what it is for, where, its discount and its amount. */
export function OrderFacts({ order }: { order: StoreOrder }) {
  return (
    <Section>
      <Fact label="سفارش">{orderNumber(order.id)}</Fact>
      <Fact label="شرح">{orderSubject(order)}</Fact>
      {order.server !== null && <Fact label="لوکیشن">{order.server.name}</Fact>}
      {order.subscription !== null && (
        <Fact label="سرویس">
          <span className="ltr">{order.subscription.name}</span>
        </Fact>
      )}
      {Number(order.discount_amount) > 0 && <Fact label="تخفیف">{formatMoney(order.discount_amount)}</Fact>}
      <Fact label="مبلغ">{formatMoney(order.amount)}</Fact>
    </Section>
  )
}

/**
 * Order the same again — a new attempt —: the screen's main action, or (`inline`) a button in place. Nothing for a kind
 * the app does not sell.
 */
export function PayAgain({ order, inline = false, label = 'پرداخت دوباره', className }: { order: StoreOrder; inline?: boolean; label?: string; className?: string }) {
  const navigate = useNavigate()
  const again = orderAgain(order)
  if (again === null) return null
  const go = () => navigate(again.to, { replace: true, state: again.state })

  return inline ? (
    <Button variant="secondary" size="sm" onClick={go} className={className}>
      {label}
    </Button>
  ) : (
    <MainAction text={label} onClick={go} />
  )
}

/** What each kind of order delivered says. */
const DELIVERED: Record<OrderType, { title: string; text?: string }> = {
  purchase: { title: 'سرویس شما آماده است', text: 'لینک اتصال در صفحه سرویس است؛ آن را در برنامه خود وارد کنید.' },
  renewal: { title: 'سرویس تمدید شد' },
  wallet_topup: { title: 'کیف پول شارژ شد' },
  traffic: { title: 'حجم نمایندگی اضافه شد' },
  extra_traffic: { title: 'حجم اضافه شد' },
  extra_time: { title: 'زمان اضافه شد' },
  extra_devices: { title: 'کاربر اضافه شد' },
}

/** A delivered order: its service to open (and its link, when the checkout answered it), or the wallet's balance now. */
export function Delivered({ order, link }: { order: StoreOrder; link: string | null }) {
  const navigate = useNavigate()
  const words = DELIVERED[order.type]
  const service = order.subscription

  return (
    <>
      <Outcome tone="success" icon={CircleCheckBig} title={words.title} text={order.type === 'wallet_topup' ? <Balance /> : words.text} />
      <OrderFacts order={order} />
      {link !== null && (
        <div className="card p-4">
          <CopyValue label="لینک اتصال" value={link} done="لینک اتصال کپی شد" />
        </div>
      )}
      {service !== null ? (
        <MainAction text="مشاهده سرویس" onClick={() => navigate(`/services/${service.id}`, { replace: true })} />
      ) : order.type === 'wallet_topup' ? (
        <MainAction text="کیف پول" onClick={() => navigate('/wallet', { replace: true })} />
      ) : (
        <MainAction text="بازگشت به خانه" onClick={() => navigate('/', { replace: true })} />
      )}
    </>
  )
}

/** The wallet's balance now, as a top-up delivered left it. */
function Balance() {
  const { data: account } = useAccount()

  return account === undefined ? null : `موجودی فعلی: ${formatBalance(account.customer.balance)}`
}

/** Paid, and being delivered: the screen reads the order again until it is. */
export function Underway({ order }: { order: StoreOrder }) {
  return (
    <>
      <Outcome tone="accent" icon={Turning} title="پرداخت انجام شد" text="سفارش در حال آماده شدن است؛ چند لحظه صبر کنید، این صفحه خودش به‌روز می‌شود." />
      <OrderFacts order={order} />
    </>
  )
}

/** Why a paid order was not delivered, in the customer's words (`failure`; the bot tells them the same). */
const FAILURES: Record<NonNullable<StoreOrder['failure']>, { tone: 'warning' | 'danger'; title: string; text: string }> = {
  delivery: {
    tone: 'warning',
    title: 'پرداخت انجام شد؛ تحویل با پشتیبانی',
    text: 'تحویل سفارش با مشکل روبه‌رو شد. مبلغ از دست نرفته است و پشتیبانی سفارش را تکمیل می‌کند؛ نیازی به پرداخت دوباره نیست.',
  },
  first_purchase_used: {
    tone: 'danger',
    title: 'این سفارش تحویل نمی‌شود',
    text: 'خرید دیگری از شما زودتر پرداخت شد و این سفارش دیگر خرید اول شما نیست. پشتیبانی درباره مبلغ پرداخت‌شده با شما هماهنگ می‌کند.',
  },
  service_changed_hands: {
    tone: 'danger',
    title: 'چیزی به سرویس اضافه نشد',
    text: 'این سرویس پیش از تحویل سفارش به حساب دیگری منتقل شد. پشتیبانی درباره مبلغ پرداخت‌شده با شما هماهنگ می‌کند.',
  },
}

/** Paid but not delivered: why, and support's door. */
export function Failed({ order }: { order: StoreOrder }) {
  const { data: shop } = useShop()
  const support = shop?.support?.url ?? null
  const { tone, title, text } = FAILURES[order.failure ?? 'delivery']

  return (
    <>
      <Outcome tone={tone} icon={tone === 'warning' ? TriangleAlert : PackageX} title={title} text={text} />
      <OrderFacts order={order} />
      <MainAction text={support === null ? null : 'پیام به پشتیبانی'} onClick={() => support !== null && openContact(support)} />
    </>
  )
}

/** Cancelled — unpaid, or by support — or refunded: the payment's last note, and the way to buy it again. */
export function Closed({ order }: { order: StoreOrder }) {
  const navigate = useNavigate()
  const note = order.payments[0]?.note ?? null

  if (order.status === 'refunded') {
    return (
      <>
        <Outcome tone="accent" icon={Undo2} title="مبلغ این سفارش برگشت داده شد" />
        {note !== null && <Notice>{note}</Notice>}
        <OrderFacts order={order} />
        <MainAction text="بازگشت به خانه" onClick={() => navigate('/', { replace: true })} />
      </>
    )
  }

  return (
    <>
      <Outcome tone="danger" icon={CircleX} title="این سفارش لغو شد" text="پرداختی برای آن کامل نشد، یا پشتیبانی آن را لغو کرد." />
      {note !== null && <Notice tone="warning">{note}</Notice>}
      <OrderFacts order={order} />
      <PayAgain order={order} label="خرید دوباره" />
    </>
  )
}
