import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { SubscriptionsResponse, UserRef } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { SubscriptionsPage } from '@/pages/subscriptions'
import { AGENT_SHOP, signedIn, until } from '@/test/render'
import { claimedServiceRow, subscriptionRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The subscriptions screen (pages/subscriptions): its «نوع» filter narrows the list to the free test accounts, to every
 * other service — sold, renewed or given —, or to those customers added by their link (the main shop's alone), the pick
 * sent as `kind`; a service added by its link is marked so in the list, with no plan until a renewal gives it one; the
 * services ticked are moved as the list last read them.
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

describe('the services ticked', () => {
  it('are moved as the list last read them: one that ended meanwhile is said not to move', async () => {
    let shown = [subscriptionRow(), subscriptionRow({ id: 2, name: 'amir_2' })]
    server().on('GET', '/api/admin/subscriptions', () => json({ ...PAGE, subscriptions: shown, meta: { ...PAGE.meta, total: shown.length } }))
    const { client, wrapper } = signedIn({ at: '/subscriptions' })
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper })
    fireEvent.click(await screen.findByRole('checkbox', { name: 'انتخاب همه سرویس‌های این صفحه' }))

    // amir_2 ran out meanwhile; the live updates read the list again.
    shown = [subscriptionRow(), subscriptionRow({ id: 2, name: 'amir_2', status: 'expired', actions: { ...subscriptionRow().actions, move: false } })]
    await act(() => client.invalidateQueries({ queryKey: queryKeys.subscriptions }))
    await until(() => expect(within(screen.getByRole('table', { name: 'اشتراک‌ها' })).getByText('منقضی‌شده')).toBeTruthy())

    fireEvent.click(within(screen.getByRole('toolbar', { name: 'سرویس‌های انتخاب‌شده' })).getByRole('button', { name: 'انتقال به سرور دیگر' }))
    const dialog = await screen.findByRole('dialog', { name: 'انتقال به سرور دیگر' })
    expect(within(dialog).getByText('۱ سرویس انتخاب‌شده فعال نیست و منتقل نمی‌شود.')).toBeTruthy()
  })
})

/** The rows of the list's own table (the dialog over it has none). */
const listRows = () => [...document.querySelectorAll('table tbody tr')]

describe('a customer’s services (?user=)', () => {
  it('lose the one handed to another customer, unticked, its dialog showing whose it is now', async () => {
    const service = subscriptionRow()
    const sara: UserRef = { id: 20, name: 'سارا احمدی', username: 'sara', telegram_id: 7070, email: null }
    server()
      .on('GET', '/api/admin/subscriptions', json(PAGE))
      .on('GET', `/api/admin/users/${service.user.id}`, refusal(404, 'not found'))
      .on('GET', `/api/admin/subscriptions/${service.id}`, json({ subscription: service }))
      .on('GET', `/api/admin/subscriptions/${service.id}/transfer`, json({ recipient: sara }))
      .on('POST', `/api/admin/subscriptions/${service.id}/transfer`, json({ subscription: { ...service, user: sara } }))
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper: signedIn({ at: `/subscriptions?user=${service.user.id}` }).wrapper })
    fireEvent.click(await screen.findByRole('checkbox', { name: 'انتخاب همه سرویس‌های این صفحه' }))
    expect(screen.getByRole('toolbar', { name: 'سرویس‌های انتخاب‌شده' })).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: service.name }))
    fireEvent.click(await screen.findByRole('button', { name: 'انتقال به مشتری دیگر' }))
    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام مشتری جدید'), { target: { value: '7070' } })
    await screen.findByText('سارا احمدی')
    fireEvent.click(screen.getByRole('button', { name: 'انتقال به مشتری دیگر' }))

    await until(() => expect(screen.getByRole('link', { name: 'سارا احمدی' }).getAttribute('href')).toBe('/users/20'))
    expect(listRows()).toHaveLength(0)
    expect(screen.queryByRole('toolbar', { name: 'سرویس‌های انتخاب‌شده' })).toBeNull()
  })

  it('keep a service changed for the same customer, still ticked', async () => {
    const service = subscriptionRow()
    server()
      .on('GET', '/api/admin/subscriptions', json(PAGE))
      .on('GET', `/api/admin/users/${service.user.id}`, refusal(404, 'not found'))
      .on('GET', `/api/admin/subscriptions/${service.id}`, json({ subscription: service }))
      .on('POST', `/api/admin/subscriptions/${service.id}/sync`, json({ subscription: { ...service, status: 'disabled' } }))
    render(<SubscriptionsPage servers={() => Promise.resolve([])} />, { wrapper: signedIn({ at: `/subscriptions?user=${service.user.id}` }).wrapper })
    fireEvent.click(await screen.findByRole('checkbox', { name: 'انتخاب همه سرویس‌های این صفحه' }))
    fireEvent.click(screen.getByRole('button', { name: service.name }))
    fireEvent.click(await screen.findByRole('button', { name: 'به‌روزرسانی از پنل' }))

    await until(() => expect(listRows()[0]?.textContent).toContain('غیرفعال'))
    expect(listRows()).toHaveLength(1)
    expect(screen.getByRole('toolbar', { name: 'سرویس‌های انتخاب‌شده' })).toBeTruthy()
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
