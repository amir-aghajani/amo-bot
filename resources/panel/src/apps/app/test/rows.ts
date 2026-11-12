import { storeBase } from '@/apps/app/test/config'
import type { StoreCheckout, StoreOrder, StorePayment, StorePaymentMethod, StoreSubscription, StoreWalletResponse } from '@/lib/api-types'

/*
 * What more than one screen folder's tests are answered with, each as the Store API writes it (docs/Store-API-Buying.md's
 * examples), a test changing what it is about: the Store API's address, a customer's service, the wallet and the ways to
 * pay, and order 52 in each way a checkout answers. A folder's own rows stay in its test-rows.ts.
 */

/** The Store API under the test's store key (apps/app/test/config). */
export const API = storeBase

export const GB = 1024 ** 3

/**
 * Service 9, «ali_1» on «هلند», running: 40 of its 100 GB used, ten of its thirty days left, two devices; renewable on
 * its plan, extras on sale, its link rotatable, «تمدید خودکار» offered and off.
 */
export function service(overrides: Partial<StoreSubscription> = {}): StoreSubscription {
  const now = Date.now()

  return {
    id: 9,
    name: 'ali_1',
    label: null,
    status: 'active',
    is_test: false,
    claimed_at: null,
    plan: { id: 3, name: 'یک ماهه ۱۰۰ گیگ' },
    server: { id: 2, name: 'هلند' },
    link: 'https://sub.example.net/sub/3d9e6b1f0a72',
    traffic: { limit_bytes: 100 * GB, used_bytes: 40 * GB, remaining_bytes: 60 * GB },
    term: {
      duration_days: 30,
      duration_hours: 720,
      starts_at: new Date(now - 20 * 86_400_000).toISOString(),
      expires_at: new Date(now + 10 * 86_400_000 - 60_000).toISOString(),
      awaits_first_use: false,
    },
    ended_at: null,
    removes_at: null,
    auto_renew: { on: false, offered: true, days_before: 2 },
    renewable: true,
    picks_plan: false,
    renewal_window: { open: true, opens_at: null, days_before: null, traffic_percent: null },
    devices: 2,
    extras: { traffic: true, time: true, devices: true },
    link_rotation: true,
    next_period: null,
    next_devices: null,
    presence: null,
    synced_at: new Date(now - 5 * 60_000).toISOString(),
    created_at: new Date(now - 20 * 86_400_000).toISOString(),
    ...overrides,
  }
}

/** Service 17, «ali_2» in Germany: 50 GB, a month from its first connection — just bought. */
export function storeSubscription(overrides: Partial<StoreSubscription> = {}): StoreSubscription {
  return {
    id: 17,
    name: 'ali_2',
    label: null,
    status: 'active',
    is_test: false,
    claimed_at: null,
    plan: { id: 3, name: 'یک ماهه ۵۰ گیگ' },
    server: { id: 1, name: 'آلمان' },
    link: 'https://sub.example.net/sub/8f1c2e7a9b0d',
    traffic: { limit_bytes: 53687091200, used_bytes: 0, remaining_bytes: 53687091200 },
    term: { duration_days: 30, duration_hours: 720, starts_at: null, expires_at: null, awaits_first_use: true },
    ended_at: null,
    removes_at: null,
    auto_renew: { on: false, offered: true, days_before: 2 },
    renewable: true,
    picks_plan: false,
    renewal_window: { open: true, opens_at: null, days_before: null, traffic_percent: null },
    devices: 2,
    extras: { traffic: true, time: true, devices: true },
    link_rotation: true,
    next_period: null,
    next_devices: null,
    presence: null,
    synced_at: '2026-10-07T12:00:03+00:00',
    created_at: '2026-10-07T12:00:03+00:00',
    ...overrides,
  }
}

/** 150٬000 Toman, no credit; top-ups of 10٬000 to 50٬000٬000 with three presets; transfers of 10٬000 up to the balance. */
export function walletRow(overrides: Partial<StoreWalletResponse> = {}): StoreWalletResponse {
  return {
    balance: '150000.00',
    credit: '0.00',
    spendable: '150000.00',
    top_up: { min: '10000.00', max: '50000000.00', presets: ['50000.00', '100000.00', '200000.00'], random: [] },
    transfer: { min: '10000.00', max: '5000000.00', daily_cap: null, left_today: null, most: '150000.00' },
    ...overrides,
  }
}

export const WALLET: StorePaymentMethod = { id: 1, label: 'کیف پول', kind: 'instant', min_amount: null, max_amount: '5000000.00' }
export const CARD: StorePaymentMethod = { id: 2, label: 'کارت به کارت (ملت)', kind: 'manual', min_amount: '10000.00', max_amount: null }
export const GATEWAY: StorePaymentMethod = { id: 3, label: 'پرداخت آنلاین', kind: 'online', min_amount: null, max_amount: null }

/** A payment of order 52: by card and waiting for its receipt, unless told otherwise. */
export function storePayment(overrides: Partial<StorePayment> = {}): StorePayment {
  return {
    id: 89,
    method: CARD,
    status: 'pending',
    amount: '120000.00',
    created_at: '2026-10-07T12:10:00+00:00',
    paid_at: null,
    receipt: null,
    note: null,
    reference: null,
    held: false,
    link: null,
    ...overrides,
  }
}

/** Order 52: plan 3 bought in Germany, pending its card payment, unless told otherwise. */
export function storeOrder(overrides: Partial<StoreOrder> = {}): StoreOrder {
  return {
    id: 52,
    type: 'purchase',
    status: 'pending',
    failure: null,
    amount: '120000.00',
    pricing: 'regular',
    discount_amount: '0.00',
    discounts: [],
    discount_code: null,
    created_at: '2026-10-07T12:10:00+00:00',
    fulfilled_at: null,
    plan: { id: 3, name: 'یک ماهه ۵۰ گیگ' },
    package_name: 'یک ماهه ۵۰ گیگ',
    server: { id: 1, name: 'آلمان' },
    subscription: null,
    traffic_bytes: null,
    days: null,
    devices: null,
    payments: [storePayment()],
    ...overrides,
  }
}

/** The card to pay order 52 to: Mellat's, 1,200,000 Rial. */
const TRANSFER: NonNullable<StoreCheckout['transfer']> = {
  payment_id: 89,
  amount: '120000.00',
  amount_rial: '1200000',
  card: '6037997700001119',
  holder: 'محمد کریمی',
  bank: 'ملت',
  instructions: 'شماره پیگیری را نگه دارید.',
  contact: null,
}

/** What a checkout of order 52 answered: a card to pay, unless told otherwise. */
export function storeCheckout(overrides: Partial<StoreCheckout> = {}): StoreCheckout {
  return { outcome: 'transfer', order: storeOrder(), transfer: TRANSFER, link: null, subscription: null, ...overrides }
}
