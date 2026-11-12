import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { dayLabel, dayOf } from '@/apps/app/screens/support/parts/thread'
import HistoryScreen from '@/apps/app/screens/wallet/history'
import { ledgerRow, orderRow, pageMeta } from '@/apps/app/screens/wallet/test-rows'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreOrdersResponse, StoreWalletTransactionsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «تاریخچه» (screens/wallet/history): the ledger a page at a time, set apart by day, credits and debits signed; the
 * orders in their own segment, each with its status, leading to its invoice.
 */

/** A day's heading, as a conversation's day is said (support/parts/thread): the year only when it is not this year's. */
const day = (moment: string) => dayLabel(dayOf(moment))

describe('the history', () => {
  it('reads the ledger a page at a time, by day', async () => {
    const pages: Record<string, StoreWalletTransactionsResponse> = {
      '1': {
        transactions: [
          ledgerRow({ id: 31, type: 'debit', amount: '120000.00', balance_after: '30000.00', description: 'پرداخت سفارش #51 (خرید)', created_at: '2026-10-20T12:00:00+00:00' }),
          ledgerRow({ id: 30, created_at: '2026-10-18T09:00:00+00:00' }),
        ],
        meta: pageMeta(1, 2, 3),
      },
      '2': { transactions: [ledgerRow({ id: 29, description: 'کش‌بک سفارش #49', amount: '5000.00', created_at: '2026-10-15T09:00:00+00:00' })], meta: pageMeta(2, 2, 3) },
    }
    server().on('GET', `${API}/wallet/transactions`, (request) => json(pages[request.query.get('page') ?? '1']))
    render(app.screen(<HistoryScreen />, { at: '/wallet/history' }))

    expect(await screen.findByText('پرداخت سفارش #51 (خرید)')).toBeTruthy()
    expect(screen.getByText('−۱۲۰٬۰۰۰')).toBeTruthy()
    expect(screen.getByRole('heading', { name: day('2026-10-20T12:00:00+00:00') })).toBeTruthy()
    expect(screen.getByRole('heading', { name: day('2026-10-18T09:00:00+00:00') })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'بیشتر' }))

    expect(await screen.findByText('کش‌بک سفارش #49')).toBeTruthy()
    expect(
      server()
        .sent('GET', `${API}/wallet/transactions`)
        .map((request) => request.query.get('page')),
    ).toEqual(['1', '2'])
    expect(screen.queryByRole('button', { name: 'بیشتر' })).toBeNull()
  })

  it('lists the orders in their segment, each leading to its invoice', async () => {
    server().on('GET', `${API}/wallet/transactions`, json({ transactions: [], meta: pageMeta(1, 1, 0) } satisfies StoreWalletTransactionsResponse))
    server().on(
      'GET',
      `${API}/orders`,
      json({
        orders: [orderRow(), orderRow({ id: 50, type: 'renewal', status: 'pending', amount: '90000.00', fulfilled_at: null, payments: [] })],
        meta: pageMeta(1, 1, 2),
      } satisfies StoreOrdersResponse),
    )
    render(app.screen(<HistoryScreen />, { at: '/wallet/history' }))

    expect(await screen.findByText('هنوز تراکنشی نیست')).toBeTruthy()
    fireEvent.click(screen.getByRole('radio', { name: 'سفارش‌ها' }))

    const purchase = (await screen.findByText('یک ماهه ۵۰ گیگ')).closest('a')
    expect(purchase?.getAttribute('href')).toBe('/orders/51')
    expect(purchase?.textContent).toContain('تکمیل‌شده')
    expect(purchase?.textContent).toContain('۱۲۰٬۰۰۰')
    const renewal = screen.getByText('تمدید · یک ماهه ۵۰ گیگ').closest('a')
    expect(renewal?.textContent).toContain('در انتظار پرداخت')
    await until(() => expect(server().sent('GET', `${API}/orders`)[0]?.query.get('page')).toBe('1'))
  })

  it('heads today’s and yesterday’s lines with «امروز» and «دیروز», as a conversation does', async () => {
    const now = Date.now()
    const lines = [ledgerRow({ id: 31, created_at: new Date(now).toISOString() }), ledgerRow({ id: 30, description: 'دیروزی', created_at: new Date(now - 86_400_000).toISOString() })]
    server().on('GET', `${API}/wallet/transactions`, json({ transactions: lines, meta: pageMeta(1, 1, 2) } satisfies StoreWalletTransactionsResponse))
    render(app.screen(<HistoryScreen />, { at: '/wallet/history' }))

    expect(await screen.findByRole('heading', { name: 'امروز' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'دیروز' })).toBeTruthy()
  })

  it('says it is loading as the rest of the app does', () => {
    server().hold('GET', `${API}/wallet/transactions`)
    render(app.screen(<HistoryScreen />, { at: '/wallet/history' }))

    expect(screen.getByRole('status', { name: 'در حال بارگذاری' })).toBeTruthy()
  })

  it('keeps the lines when the next page fails, and says why', async () => {
    server().on('GET', `${API}/wallet/transactions`, (request) =>
      request.query.get('page') === '2' ? refusal(500, 'خطای سرور') : json({ transactions: [ledgerRow({ id: 31 })], meta: pageMeta(1, 2, 2) } satisfies StoreWalletTransactionsResponse),
    )
    render(app.screen(<HistoryScreen />, { at: '/wallet/history' }))

    fireEvent.click(await screen.findByRole('button', { name: 'بیشتر' }))

    await screen.findByText(/خطای سرور/)
    expect(screen.getAllByRole('heading').length).toBeGreaterThan(1)
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
