import type { StoreCheckoutResponse, StoreOrder } from '@/lib/api-types'

/* What the services' screens' tests are answered with besides the shared rows (apps/app/test/rows): an order's checkout. */

/** Order 51 of `type`, waiting for its card transfer of `amount`. */
export function checkout(type: StoreOrder['type'], amount: string): StoreCheckoutResponse {
  return {
    checkout: {
      outcome: 'transfer',
      order: {
        id: 51,
        type,
        status: 'pending',
        failure: null,
        amount,
        pricing: 'regular',
        discount_amount: '0.00',
        discounts: [],
        discount_code: null,
        created_at: '2026-10-20T10:00:00+00:00',
        fulfilled_at: null,
        plan: { id: 3, name: 'یک ماهه ۱۰۰ گیگ' },
        package_name: null,
        server: { id: 2, name: 'هلند' },
        subscription: { id: 9, name: 'ali_1' },
        traffic_bytes: null,
        days: null,
        devices: null,
        payments: [],
      },
      transfer: { payment_id: 70, amount, amount_rial: `${Number(amount) * 10}`, card: '6037997700001119', holder: 'علی رضایی', bank: 'ملت', instructions: null, contact: null },
      link: null,
      subscription: null,
    },
  }
}
