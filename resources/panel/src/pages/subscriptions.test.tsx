import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { SubscriptionsResponse } from '@/lib/api-types'
import { SubscriptionsPage } from '@/pages/subscriptions'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { claimedServiceRow, subscriptionRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The subscriptions screen (pages/subscriptions): its «نوع» filter narrows the list to the free test accounts, to every
 * other service — sold, renewed or given —, or to those customers added by their link (the main shop's alone), the pick
 * sent as `kind`; a service added by its link is marked so in the list, with no plan until a renewal gives it one.
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
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['همه', 'اکانت تست', 'سرویس عادی', 'افزوده‌شده با لینک'])
    fireEvent.click(screen.getByRole('option', { name: 'اکانت تست' }))
    await until(() => expect(kinds().at(-1)).toBe('test'))

    openKinds('اکانت تست')
    fireEvent.click(screen.getByRole('option', { name: 'سرویس عادی' }))
    await until(() => expect(kinds().at(-1)).toBe('regular'))

    openKinds('سرویس عادی')
    fireEvent.click(screen.getByRole('option', { name: 'افزوده‌شده با لینک' }))
    await until(() => expect(kinds().at(-1)).toBe('claimed'))
  })

  it('has no services added by their link in an agent’s shop, which takes none', async () => {
    server().on('GET', '/api/admin/subscriptions', json(PAGE))
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper: signedIn({ session: AGENT_SHOP, at: '/subscriptions' }).wrapper })
    await screen.findByText('amir_1')

    openKinds('همه')
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['همه', 'اکانت تست', 'سرویس عادی'])
    expect(screen.queryByText(/افزوده‌شده با لینک/)).toBeNull()
  })
})

describe('a service added by its link', () => {
  it('is marked so in the list, its plan to be picked when it is renewed — never «پلن حذف شده»', async () => {
    const claimed = claimedServiceRow({ id: 2, name: 'hand_made' })
    server().on('GET', '/api/admin/subscriptions', json({ ...PAGE, subscriptions: [claimed] }))
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper: signedIn({ at: '/subscriptions?kind=claimed' }).wrapper })

    expect(await screen.findByText('hand_made')).toBeTruthy()
    expect(screen.getByText('با لینک').getAttribute('title')).toBe('افزوده‌شده با لینک')
    expect(screen.getByText('بدون پلن')).toBeTruthy()
    expect(screen.queryByText('پلن حذف شده')).toBeNull()
    expect(kinds()).toEqual(['claimed'])
    expect(screen.getByText(/افزوده‌شده با لینک مشتری/)).toBeTruthy()
  })
})
