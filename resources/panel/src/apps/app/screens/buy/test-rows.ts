import type { StorePlan } from '@/lib/api-types'

/* What the buying screens' tests are answered with besides the shared rows (apps/app/test/rows): the plan they sell. */

/** Plan 3: 50 GB for a month on two devices, 120,000 Toman, sold in Germany (1) and the Netherlands (2). */
export function storePlan(overrides: Partial<StorePlan> = {}): StorePlan {
  return {
    id: 3,
    name: 'یک ماهه ۵۰ گیگ',
    description: 'مناسب استفاده روزانه',
    badge: null,
    price: '120000.00',
    first_purchase_price: null,
    agent_price: null,
    offer_price: '120000.00',
    discount: null,
    checkout_note: null,
    traffic_gb: 50,
    duration_days: 30,
    duration_hours: 720,
    devices: 2,
    category_id: 1,
    locations: [
      { id: 1, name: 'آلمان' },
      { id: 2, name: 'هلند' },
    ],
    ...overrides,
  }
}
