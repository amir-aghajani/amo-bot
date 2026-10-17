import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServicesCard } from '@/components/customer/services-card'
import { shopServerChoices } from '@/components/subscriptions/server-choices'
import type { SubscriptionsResponse, UserRef } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { subscriptionRow, userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A customer's latest services on their page (components/customer/services-card): each opens the services' own dialog;
 * a service handed to another customer from it is theirs now, and leaves this customer's card — its dialog following it.
 */

const CUSTOMER = userRow()
const SERVICE = subscriptionRow({ user: { id: CUSTOMER.id, name: CUSTOMER.name, username: CUSTOMER.username, telegram_id: CUSTOMER.telegram_id, email: null } })
const SARA: UserRef = { id: 20, name: 'سارا احمدی', username: 'sara', telegram_id: 7070, email: null }
const META = { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'created', dir: 'desc', expiring: 0, expiring_days: 3 } as const

/** The rows of the card's own table (the dialog over it has none). */
const cardRows = () => document.querySelectorAll('table tbody tr').length

describe('a customer’s services', () => {
  it('lose the one handed to another customer, its dialog showing whose it is now', async () => {
    server()
      .on('GET', '/api/admin/subscriptions', json({ subscriptions: [SERVICE], meta: META } satisfies SubscriptionsResponse))
      .on('GET', `/api/admin/subscriptions/${SERVICE.id}`, json({ subscription: SERVICE }))
      .on('GET', `/api/admin/subscriptions/${SERVICE.id}/transfer`, json({ recipient: SARA }))
      .on('POST', `/api/admin/subscriptions/${SERVICE.id}/transfer`, json({ subscription: { ...SERVICE, user: SARA } }))
    render(<ServicesCard customer={CUSTOMER} servers={shopServerChoices} />, { wrapper: signedIn().wrapper })

    fireEvent.click(await screen.findByRole('button', { name: SERVICE.name }))
    fireEvent.click(await screen.findByRole('button', { name: 'انتقال به مشتری دیگر' }))
    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام مشتری جدید'), { target: { value: '7070' } })
    await screen.findByText('سارا احمدی')
    expect(cardRows()).toBe(1)
    fireEvent.click(screen.getByRole('button', { name: 'انتقال به مشتری دیگر' }))

    await until(() => expect(cardRows()).toBe(0))
    expect(screen.getByRole('link', { name: 'سارا احمدی' }).getAttribute('href')).toBe('/users/20')
  })
})
