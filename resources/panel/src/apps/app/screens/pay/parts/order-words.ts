import { openAddress } from '@/apps/app/telegram/webapp'
import type { OrderType, StoreOrder } from '@/lib/api-types'

/* An order in the customer's words: its number, what it was for, and where its kind is bought again. */

/**
 * An order's number as the bot, the shop's notices and its panels write it: «#52», in the digits support finds the
 * order by (a name, not an amount: no thousands separator).
 */
export function orderNumber(id: number): string {
  return `#${id}`
}

/** What each kind of order is, as a line under the payment's title. */
const ORDER_KINDS: Record<OrderType, string> = {
  purchase: 'خرید سرویس',
  renewal: 'تمدید سرویس',
  wallet_topup: 'شارژ کیف پول',
  traffic: 'حجم نمایندگی',
  extra_traffic: 'حجم اضافه',
  extra_time: 'زمان اضافه',
  extra_devices: 'کاربر اضافه',
}

/** What an order is about: its package's words, else its kind's. */
export function orderSubject(order: StoreOrder): string {
  return order.package_name ?? ORDER_KINDS[order.type]
}

/**
 * Where the customer orders the same again — a new attempt, which finds this very order while its price stands (and,
 * for a card, the same card) —: the plan's checkout, the builder, the service's renewal or extras, the top-up. Null for
 * a kind the app does not sell.
 */
export function orderAgain(order: StoreOrder): { to: string; state?: { serverId: number } } | null {
  switch (order.type) {
    case 'purchase':
      if (order.plan === null) return { to: '/buy/custom' }
      return { to: `/buy/plans/${order.plan.id}`, state: order.server === null ? undefined : { serverId: order.server.id } }
    case 'renewal':
      return order.subscription === null ? null : { to: `/services/${order.subscription.id}/renew` }
    case 'extra_traffic':
    case 'extra_time':
    case 'extra_devices':
      return order.subscription === null ? null : { to: `/services/${order.subscription.id}/extras` }
    case 'wallet_topup':
      return { to: '/wallet/top-up' }
    case 'traffic':
      return null
  }
}

/**
 * Open support's contact as the shop gave it — a Telegram chat inside Telegram, a web page in its browser, a phone's
 * dialler —, as every address the shop gives is opened (telegram/webapp's openAddress).
 */
export const openContact = openAddress
