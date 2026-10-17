import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TrafficLedger } from '@/components/agency/traffic-ledger'
import type { TrafficLine, TrafficLinesResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { providers } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * An agent's traffic ledger (components/agency/traffic-ledger), as the owner's agent dialog and the agent's account
 * page list it: who changed a line by hand is named as their account has it where the server read it.
 */

const GB = 1024 ** 3

/** Line 3: 5 GB given to the agent's service reza_1, by nobody's hand. */
function line(overrides: Partial<TrafficLine> = {}): TrafficLine {
  return {
    id: 3,
    type: 'extension',
    bytes: -5 * GB,
    balance_after: 15 * GB,
    description: 'سرویس reza_1',
    order_id: null,
    reviewer: null,
    reviewer_name: null,
    created_at: '2026-10-06T09:00:00Z',
    ...overrides,
  }
}

describe('an agent’s traffic ledger', () => {
  it('names an admin of the agent’s website by their name, the owner as support', async () => {
    const lines = [line({ id: 3, reviewer: 'user#12', reviewer_name: 'نیما رضایی' }), line({ id: 2, reviewer: 'پشتیبانی' })]
    server().on('GET', '/api/admin/agency/agents/10/traffic', json({ lines, meta: { page: 1, per_page: 25, total: 2, last_page: 1 } } satisfies TrafficLinesResponse))
    render(<TrafficLedger queryKey={['agency', 'agents', 10, 'traffic']} url="/agency/agents/10/traffic" />, { wrapper: providers().wrapper })

    expect(await screen.findByText('نیما رضایی')).toBeTruthy()
    expect(screen.queryByText(/کاربر/)).toBeNull()
    expect(screen.getByText('پشتیبانی')).toBeTruthy()
  })

  it('keeps the order a line names whole, «#33» right to left as written', async () => {
    const lines = [line({ id: 4, type: 'sale', bytes: -10 * GB, description: 'فروش · سفارش #33', order_id: 33 })]
    server().on('GET', '/api/admin/agency/agents/10/traffic', json({ lines, meta: { page: 1, per_page: 25, total: 1, last_page: 1 } } satisfies TrafficLinesResponse))
    render(<TrafficLedger queryKey={['agency', 'agents', 10, 'traffic']} url="/agency/agents/10/traffic" />, { wrapper: providers().wrapper })

    expect(await screen.findByText(`· فروش · سفارش ${idLabel(33)}`)).toBeTruthy()
  })
})
