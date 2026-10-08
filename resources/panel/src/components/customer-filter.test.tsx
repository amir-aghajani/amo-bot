import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { CustomerFilter } from '@/components/customer-filter'
import type { PaymentsResponse, UserDetailResponse } from '@/lib/api-types'
import { PaymentsPage } from '@/pages/payments'
import { providers, testQueryClient, until } from '@/test/render'
import { customerAccount, paymentRow, userRow } from '@/test/rows'
import { json, server, type SentRequest } from '@/test/server'

/*
 * A list narrowed to one customer — their page's «همه …» — says so beside its other filters (components/customer-filter):
 * «مشتری» and the customer's name, read with the customer, leading back to their page, and a ✕ that lets go of the
 * filter. It rides in the list's address like the others: the list reads it, and the address drops it once let go.
 */

const DETAIL: UserDetailResponse = { user: userRow({ id: 12 }), referral: { referrer: null, referrals: 0, earned: '0.00' }, agency: null, account: customerAccount() }

describe('the pill', () => {
  it('names the customer once their read is in, and leads to their page', async () => {
    const read = server().hold('GET', '/api/admin/users/12')
    render(<CustomerFilter value="12" onClear={() => undefined} />, { wrapper: providers().wrapper })

    const pill = screen.getByRole('group', { name: 'فیلتر مشتری' })
    expect(within(pill).getByRole('link').textContent).toBe('#12')

    read.answer(json(DETAIL))
    await until(() => expect(within(pill).getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/12'))
  })

  it('lets go of the filter with its ✕, in the words of what the customer is to the list', () => {
    server().on('GET', '/api/admin/users/12', json(DETAIL))
    const onClear = vi.fn()
    render(<CustomerFilter value="12" label="معرف" onClear={onClear} />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'برداشتن فیلتر معرف' }))

    expect(onClear).toHaveBeenCalledOnce()
  })

  it('is not there while the list names no customer — nor something that is no customer’s number', () => {
    const { container, rerender } = render(<CustomerFilter value="" onClear={() => undefined} />, { wrapper: providers().wrapper })
    expect(container.textContent).toBe('')

    rerender(<CustomerFilter value="ali" onClear={() => undefined} />)
    expect(container.textContent).toBe('')
    expect(server().requests).toEqual([])
  })
})

/** The payments, as the server pages them for what was asked. */
const payments = (request: SentRequest) =>
  json({
    payments: [paymentRow({ id: request.query.has('user') ? 7 : 8 })],
    meta: { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'created', dir: 'desc', awaiting_review: 0, paid: 0, paid_amount: '0.00' },
  } satisfies PaymentsResponse)

describe('a list narrowed to a customer', () => {
  it('reads their rows alone, says whose they are, and reads them all once the filter is let go — the address with it', async () => {
    server().on('GET', '/api/admin/payments', payments).on('GET', '/api/admin/users/12', json(DETAIL))
    const router = createMemoryRouter([{ path: 'payments', element: <PaymentsPage /> }], { initialEntries: ['/payments?user=12'] })
    render(
      <QueryClientProvider client={testQueryClient()}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    )

    await screen.findByRole('button', { name: '#7' })
    expect(server().sent('GET', '/api/admin/payments').at(-1)?.query.get('user')).toBe('12')
    await screen.findByRole('link', { name: 'امیر' })

    fireEvent.click(screen.getByRole('button', { name: 'برداشتن فیلتر مشتری' }))

    await screen.findByRole('button', { name: '#8' })
    expect(server().sent('GET', '/api/admin/payments').at(-1)?.query.has('user')).toBe(false)
    await until(() => expect(router.state.location.search).toBe(''))
    expect(screen.queryByRole('group', { name: 'فیلتر مشتری' })).toBeNull()
  })
})
