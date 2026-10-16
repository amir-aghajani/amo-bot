import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WalletPanel } from '@/components/users/wallet-modal'
import type { UserWalletResponse, WalletTransactionRow } from '@/lib/api-types'
import { providers } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A customer's wallet (components/users/wallet-modal): its ledger names who set a line right by hand — a panel's login,
 * one of the shop's admins on its website, as the server words them for this reader —, set apart left to right, and
 * nobody on a line the shop wrote itself — an online payment's that came after its order closed among them —; a wallet
 * transfer's line links the other customer.
 */

const CUSTOMER = userRow()

/** A ledger line: a credit by hand unless `overrides` say otherwise. */
function line(overrides: Partial<WalletTransactionRow>): WalletTransactionRow {
  return { id: 1, type: 'credit', amount: '50000.00', balance_after: '50000.00', description: 'شارژ دستی', reviewer: null, counterpart: null, created_at: '2026-09-01T10:00:00Z', ...overrides }
}

describe('a wallet’s ledger', () => {
  it('names who set a line right by hand, and nobody on the shop’s own', async () => {
    const transactions = [line({ id: 3, reviewer: '@sara', description: 'جبران قطعی' }), line({ id: 2, reviewer: 'root' }), line({ id: 1, type: 'debit', description: 'خرید سرویس' })]
    server().on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions } satisfies UserWalletResponse))
    render(<WalletPanel user={CUSTOMER} onChanged={() => undefined} />, { wrapper: providers().wrapper })

    const staff = await screen.findByText('@sara')
    expect(staff.getAttribute('dir')).toBe('ltr')
    expect(screen.getByText('root').getAttribute('dir')).toBe('ltr')
    expect(screen.getByText('خرید سرویس').closest('li')?.querySelector('bdi')).toBeNull()
  })

  it('shows an online payment that came after its order closed as the shop wrote it: money in, by nobody’s hand', async () => {
    const transactions = [line({ id: 4, description: 'پرداخت آنلاین #12 پس از بسته شدن سفارش #7', balance_after: '100000.00' })]
    server().on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions } satisfies UserWalletResponse))
    render(<WalletPanel user={CUSTOMER} onChanged={() => undefined} />, { wrapper: providers().wrapper })

    const entry = (await screen.findByText('پرداخت آنلاین #12 پس از بسته شدن سفارش #7')).closest('li')
    expect(entry?.textContent).toContain('افزایش')
    expect(entry?.querySelector('a, bdi')).toBeNull()
  })

  it('links a wallet transfer’s line to the other customer: whom it went to, or came from', async () => {
    const sara = { id: 12, name: 'Sara Ahmadi', username: 'sara', telegram_id: 7070, email: null }
    const reza = { id: 13, name: null, username: 'reza', telegram_id: 7272, email: null }
    const transactions = [
      line({ id: 3, type: 'debit', description: 'انتقال موجودی به Sara Ahmadi (@sara)', counterpart: sara }),
      line({ id: 2, description: 'دریافت موجودی از @reza', counterpart: reza }),
      line({ id: 1, description: 'شارژ کیف پول (سفارش #4)' }),
    ]
    server().on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions } satisfies UserWalletResponse))
    render(<WalletPanel user={CUSTOMER} onChanged={() => undefined} />, { wrapper: providers().wrapper })

    const to = await screen.findByRole('link', { name: 'Sara Ahmadi' })
    expect(to.getAttribute('href')).toBe('/users/12')
    expect(to.closest('div')?.textContent).toContain(' · به Sara Ahmadi')
    const from = screen.getByRole('link', { name: '@reza' })
    expect(from.getAttribute('href')).toBe('/users/13')
    expect(from.closest('div')?.textContent).toContain(' · از @reza')
    expect(screen.getByText('شارژ کیف پول (سفارش #4)').closest('li')?.querySelector('a')).toBeNull()
  })
})
