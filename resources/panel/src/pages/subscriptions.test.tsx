import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { SubscriptionsResponse } from '@/lib/api-types'
import { SubscriptionsPage } from '@/pages/subscriptions'
import { signedIn, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The subscriptions screen (pages/subscriptions): its «نوع» filter narrows the list to the free test accounts or to
 * every other service — sold, renewed or given —, the pick sent as `kind`.
 */

const PAGE: SubscriptionsResponse = {
  subscriptions: [subscriptionRow()],
  meta: { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'created', dir: 'desc', expiring: 0, expiring_days: 3 },
}

/** The «نوع» filter, reading `current`, opened. */
function openKinds(current: string) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: `نوع ${current}` }), { key: 'Enter' })
}

/** The `kind` each read of the list sent, in order; null for none. */
const kinds = () =>
  server()
    .sent('GET', '/api/admin/subscriptions')
    .map((request) => request.query.get('kind'))

describe('the «نوع» filter', () => {
  it('offers the free tests and every other service, and sends the pick as `kind`', async () => {
    server().on('GET', '/api/admin/subscriptions', json(PAGE))
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper: signedIn({ at: '/subscriptions' }).wrapper })
    await screen.findByText('amir_1')
    expect(kinds()).toEqual([null])

    openKinds('همه')
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['همه', 'اکانت تست', 'سرویس عادی'])
    fireEvent.click(screen.getByRole('option', { name: 'اکانت تست' }))
    await until(() => expect(kinds().at(-1)).toBe('test'))

    openKinds('اکانت تست')
    fireEvent.click(screen.getByRole('option', { name: 'سرویس عادی' }))
    await until(() => expect(kinds().at(-1)).toBe('regular'))
  })
})
