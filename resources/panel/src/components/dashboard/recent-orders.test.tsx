import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { RecentOrders } from '@/components/dashboard/recent-orders'
import type { OrderType, RecentOrder } from '@/lib/api-types'
import { providers } from '@/test/render'

/*
 * The overview's latest orders (components/dashboard/recent-orders): each says what it was on — a purchase its plan, a
 * service's extra traffic or time the extra before its service's plan, a top-up what it was for.
 */

/** Order `id` of customer 10, delivered. */
function order(id: number, type: OrderType, plan: string | null): RecentOrder {
  return { id, type, status: 'fulfilled', amount: '30000.00', plan, user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null }, created_at: '2026-10-06T09:00:00Z' }
}

describe('the latest orders', () => {
  it('say what each was on: a plan, a service’s extra on its plan, or what it was for', () => {
    render(<RecentOrders orders={[order(45, 'extra_traffic', 'طلایی'), order(44, 'extra_time', 'طلایی'), order(43, 'purchase', 'طلایی'), order(42, 'wallet_topup', null)]} />, {
      wrapper: providers().wrapper,
    })

    expect(screen.getByText('حجم اضافه · طلایی')).toBeTruthy()
    expect(screen.getByText('زمان اضافه · طلایی')).toBeTruthy()
    expect(screen.getByText('طلایی')).toBeTruthy()
    expect(screen.getByText('شارژ کیف پول')).toBeTruthy()
  })
})
