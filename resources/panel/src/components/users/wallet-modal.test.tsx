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
 * nobody on a line the shop wrote itself.
 */

const CUSTOMER = userRow()

/** A ledger line: a credit by hand unless `overrides` say otherwise. */
function line(overrides: Partial<WalletTransactionRow>): WalletTransactionRow {
  return { id: 1, type: 'credit', amount: '50000.00', balance_after: '50000.00', description: 'شارژ دستی', reviewer: null, created_at: '2026-09-01T10:00:00Z', ...overrides }
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
})
