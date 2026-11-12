import { CalendarPlus, Gauge, Package, RefreshCw, Smartphone, Wallet, Zap, type LucideIcon } from 'lucide-react'
import { orderNumber } from '@/apps/app/screens/pay/parts/order-words'
import type { Tone } from '@/apps/app/ui/visuals'
import type { OrderStatus, OrderType, PaymentStatus, StoreOrder, StorePayment } from '@/lib/api-types'
import { formatAmount, formatBytes, formatDate, formatDays, formatDevices, formatMoney, MONEY_UNIT } from '@/lib/format'
import { ORDER_PRICING, ORDER_STATUS, ORDER_TYPE, PAYMENT_STATUS, type Tone as StatusTone } from '@/lib/statuses'

/*
 * An order as the customer reads it: what it was for, where it stands (the panels' own words for each status, in the
 * app's tones), why a failed one failed, and the facts of its invoice — one list of facts the invoice screen shows and
 * its picture draws.
 */

/** The panels' colour families as the app's tones. */
const TONE: Record<StatusTone, Tone> = { neutral: 'hint', info: 'accent', success: 'success', warning: 'warning', danger: 'danger' }

export function orderStatus(status: OrderStatus): { label: string; tone: Tone } {
  return { label: ORDER_STATUS[status].label, tone: TONE[ORDER_STATUS[status].tone] }
}

export function paymentStatus(status: PaymentStatus): { label: string; tone: Tone } {
  return { label: PAYMENT_STATUS[status].label, tone: TONE[PAYMENT_STATUS[status].tone] }
}

/** Each kind of order's icon. */
export const ORDER_ICON: Record<OrderType, LucideIcon> = {
  purchase: Package,
  renewal: RefreshCw,
  extra_traffic: Gauge,
  extra_time: CalendarPlus,
  extra_devices: Smartphone,
  wallet_topup: Wallet,
  traffic: Zap,
}

/** What the order was, in a few words: a purchase's package, another kind's word and its package — else the kind alone. */
export function orderTitle(order: StoreOrder): string {
  const subject = order.package_name ?? order.plan?.name ?? null
  if (subject === null) return ORDER_TYPE[order.type]
  return order.type === 'purchase' ? subject : `${ORDER_TYPE[order.type]} · ${subject}`
}

/** Why a failed order failed, as the bot tells the customer. */
export const FAILURE: Record<NonNullable<StoreOrder['failure']>, string> = {
  delivery: 'پرداخت انجام شده و تحویل سفارش به پشتیبانی سپرده شد؛ پشتیبانی آن را کامل می‌کند و نیازی به پرداخت دوباره نیست.',
  first_purchase_used: 'پرداخت انجام شده، اما این سفارش دیگر خرید اول شما نیست و تحویل داده نمی‌شود؛ پشتیبانی درباره مبلغ آن با شما هماهنگ می‌کند.',
  service_changed_hands: 'پرداخت انجام شده، اما سرویس این سفارش به حساب دیگری منتقل شده و چیزی به آن اضافه نشد؛ پشتیبانی درباره مبلغ آن با شما هماهنگ می‌کند.',
}

/** The payment an invoice names: the one that paid it, else the latest. */
function invoicePayment(order: StoreOrder): StorePayment | null {
  return order.payments.find((payment) => payment.status === 'paid') ?? order.payments[0] ?? null
}

/** The invoice number: the order's number — what a card payment is found by, having no tracking code. */
export const invoiceNumber = (order: StoreOrder): string => orderNumber(order.id)

interface InvoiceRow {
  label: string
  value: string
  /** A value that reads left to right (a number, a service's name). */
  ltr?: boolean
  tone?: 'success' | 'hint'
  /** The total: larger, bold. */
  strong?: boolean
}

export interface InvoiceGroup {
  title: string
  rows: InvoiceRow[]
}

/** The invoice's facts under its header (its number, date and status), by group: what was bought, the amounts, its payment. */
export function invoiceGroups(order: StoreOrder): InvoiceGroup[] {
  const bought: InvoiceRow[] = [{ label: 'نوع سفارش', value: ORDER_TYPE[order.type] }]
  const subject = order.package_name ?? order.plan?.name ?? null
  if (subject !== null) bought.push({ label: 'بسته', value: subject })
  if (order.server !== null) bought.push({ label: 'موقعیت', value: order.server.name })
  if (order.subscription !== null) bought.push({ label: 'سرویس', value: order.subscription.name, ltr: true })
  if (order.traffic_bytes !== null) bought.push({ label: 'حجم', value: formatBytes(order.traffic_bytes) })
  if (order.days !== null) bought.push({ label: 'زمان', value: formatDays(order.days) })
  if (order.devices !== null) bought.push({ label: 'تعداد کاربر', value: formatDevices(order.devices) })
  if (order.fulfilled_at !== null) bought.push({ label: 'تاریخ تحویل', value: formatDate(order.fulfilled_at) })

  const amounts: InvoiceRow[] = []
  if (Number(order.discount_amount) > 0) {
    amounts.push({ label: 'مبلغ پایه', value: formatMoney(Number(order.amount) + Number(order.discount_amount)) })
    // The signed figure set apart left to right (LRI … PDI): its minus stays before its digits in a right-to-left line.
    for (const line of order.discounts) amounts.push({ label: line.label, value: `\u2066−${formatAmount(line.amount)}\u2069 ${MONEY_UNIT}`, tone: 'success' })
  }
  const pricing = ORDER_PRICING[order.pricing]
  if (pricing !== null) amounts.push({ label: 'نوع قیمت', value: pricing })
  amounts.push({ label: 'مبلغ کل', value: Number(order.amount) === 0 ? 'رایگان' : formatMoney(order.amount), strong: true })

  const groups: InvoiceGroup[] = [
    { title: 'شرح خرید', rows: bought },
    { title: 'مبلغ', rows: amounts },
  ]

  const payment = invoicePayment(order)
  if (payment !== null) {
    groups.push({
      title: 'پرداخت',
      rows: [
        { label: 'روش پرداخت', value: payment.method.label },
        // An online payment's tracking code at its gateway: what the customer quotes to the gateway or to support.
        ...(payment.reference === null ? [] : [{ label: 'کد رهگیری', value: payment.reference, ltr: true }]),
        { label: 'مبلغ پرداخت', value: formatMoney(payment.amount) },
        { label: 'وضعیت پرداخت', value: paymentStatus(payment.status).label },
        ...(payment.paid_at === null ? [] : [{ label: 'زمان پرداخت', value: formatDate(payment.paid_at) }]),
      ],
    })
  }

  return groups
}
