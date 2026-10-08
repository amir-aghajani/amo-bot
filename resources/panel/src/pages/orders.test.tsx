import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { OrdersResponse } from '@/lib/api-types'
import { OrdersPage } from '@/pages/orders'
import { signedIn } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The orders screen (pages/orders) with nothing to list where it looks: an empty status tab says so in its own words —
 * «با این جستجو یا فیلتر…» only when a search or a filter narrows the list —, and the queue that waits on support says
 * what it waits for.
 */

const NOTHING: OrdersResponse = { orders: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1, sort: 'created', dir: 'desc', stuck: 0, sold: 0, sold_amount: '0.00' } }

/** The screen at `at`, the server finding no order there. */
function open(at: string) {
  server().on('GET', '/api/admin/orders', json(NOTHING))
  render(<OrdersPage />, { wrapper: signedIn({ at }).wrapper })
}

describe('an empty status tab', () => {
  it('says the tab is empty, in its own words', async () => {
    open('/orders?status=cancelled')

    expect(await screen.findByText('سفارشی در این وضعیت نیست.')).toBeTruthy()
    expect(screen.queryByText(/جستجو یا فیلتر/)).toBeNull()
  })

  it('— the queue that waits on support, what it waits for', async () => {
    open('/orders?status=stuck')

    expect(await screen.findByText('فعلا سفارشی نیست که پرداخت شده و تحویل نشده باشد.')).toBeTruthy()
  })

  it('says nothing matched when a search or a filter narrows it', async () => {
    open('/orders?status=stuck&search=amir')

    expect(await screen.findByText('با این جستجو یا فیلتر چیزی نیست.')).toBeTruthy()
  })
})
