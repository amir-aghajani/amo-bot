import type { PageMeta, StoreOrder, StorePayment, StoreWalletTransaction } from '@/lib/api-types'

/*
 * What the wallet's and the orders' tests read from the fake server besides the shared rows (apps/app/test/rows): a
 * ledger line, an order and its payment, and a page's meta — each as the Store API answers it, a test changing what it is about.
 */

export function ledgerRow(overrides: Partial<StoreWalletTransaction> = {}): StoreWalletTransaction {
  return { id: 30, type: 'credit', amount: '50000.00', balance_after: '150000.00', description: 'شارژ کیف پول (سفارش #50)', created_at: '2026-10-20T11:40:00+00:00', ...overrides }
}

/** A card payment of 120٬000 Toman, paid. */
export function paymentRow(overrides: Partial<StorePayment> = {}): StorePayment {
  return {
    id: 88,
    method: { id: 2, label: 'کارت به کارت (ملت)', kind: 'manual', min_amount: '10000.00', max_amount: null },
    status: 'paid',
    amount: '120000.00',
    created_at: '2026-10-20T12:00:00+00:00',
    paid_at: '2026-10-20T12:05:00+00:00',
    receipt: { sent_at: '2026-10-20T12:02:00+00:00' },
    note: null,
    reference: null,
    held: false,
    link: null,
    ...overrides,
  }
}

/** Order 51: a purchase of «یک ماهه ۵۰ گیگ» in Germany, 120٬000 Toman after a 10٪ code, paid by card and delivered. */
export function orderRow(overrides: Partial<StoreOrder> = {}): StoreOrder {
  return {
    id: 51,
    type: 'purchase',
    status: 'fulfilled',
    failure: null,
    amount: '120000.00',
    pricing: 'regular',
    discount_amount: '13334.00',
    discounts: [{ source: 'code', label: 'کد NOWRUZ', amount: '13334.00', percent: 10 }],
    discount_code: { id: 4, code: 'NOWRUZ' },
    created_at: '2026-10-20T12:00:00+00:00',
    fulfilled_at: '2026-10-20T12:06:00+00:00',
    plan: { id: 3, name: 'یک ماهه ۵۰ گیگ' },
    package_name: 'یک ماهه ۵۰ گیگ',
    server: { id: 1, name: 'آلمان' },
    subscription: { id: 17, name: 'ali_2' },
    traffic_bytes: null,
    days: null,
    devices: null,
    payments: [paymentRow()],
    ...overrides,
  }
}

export function pageMeta(page: number, lastPage: number, total = lastPage * 25): PageMeta {
  return { page, per_page: 25, total, last_page: lastPage }
}
