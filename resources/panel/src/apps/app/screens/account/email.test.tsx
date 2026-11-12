import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import EmailScreen from '@/apps/app/screens/account/email'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse, StoreCodeSentResponse, StoreMeResponse, StoreMergeOfferResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «افزودن ایمیل» (screens/account/email): the address and its password, a code emailed to it and typed back — the
 * account then has it —; an address another account of the shop has, its merge offer shown and taken; a refusal under
 * its field.
 */

const WITH_EMAIL: StoreMeResponse = { customer: { ...app.account().customer, email: 'ali@example.com', has_password: true } }

const OFFER: StoreMergeOfferResponse = {
  merge: {
    token: '6a4ae800353b0c5c9a84b8a84d6caabce080931b4a069c6140425447d532f462',
    expires_in: 900,
    account: { name: 'علی رضایی', created_at: '2025-11-02T17:20:00+00:00', services: 1, orders: 4, balance: '15000.00', telegram: false, email: true, google: false, phone: false },
    keeps: 'other',
  },
}

/** The screen, the address and password typed and sent, the code typed. */
async function upToTheCode() {
  server().on('POST', `${API}/me/identities/email`, json({ expires_in: 600 } satisfies StoreCodeSentResponse, 202))
  render(app.screen(<EmailScreen />, { at: '/account/email' }))

  fireEvent.change(screen.getByLabelText('ایمیل'), { target: { value: ' ali@example.com ' } })
  fireEvent.change(screen.getByLabelText('رمز عبور'), { target: { value: 'kooh-e damavand' } })
  fireEvent.click(screen.getByRole('button', { name: 'ارسال کد' }))

  fireEvent.change(await screen.findByLabelText('کد تایید'), { target: { value: '۴۸۲۹۱۳' } })
  expect(server().sent('POST', `${API}/me/identities/email`)[0]?.body).toEqual({ email: 'ali@example.com', password: 'kooh-e damavand' })
}

describe('adding an email', () => {
  it('sends a code to the address, and the code typed back adds it to the account', async () => {
    server().on('POST', `${API}/me/identities/email/verify`, json(WITH_EMAIL))
    server().on('GET', `${API}/me`, json(app.account({ customer: WITH_EMAIL.customer })))
    await upToTheCode()

    expect(screen.getByText('ali@example.com')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'تایید ایمیل' }))

    await until(() => expect(screen.queryByLabelText('کد تایید')).toBeNull())
    expect(server().sent('POST', `${API}/me/identities/email/verify`)[0]?.body).toEqual({ email: 'ali@example.com', code: '482913' })
    expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.customer.email).toBe('ali@example.com')
  })

  it('shows the merge offer of an address another account has, says what merging does, and merges once confirmed', async () => {
    // Outside Telegram the browser's own confirm box asks.
    const confirm = vi.fn(() => true)
    vi.stubGlobal('confirm', confirm)
    server()
      .on('POST', `${API}/me/identities/email/verify`, json(OFFER, 202))
      .on('POST', `${API}/me/merge`, json(WITH_EMAIL))
      .on('GET', `${API}/me`, json(app.account({ customer: WITH_EMAIL.customer })))
    await upToTheCode()
    fireEvent.click(screen.getByRole('button', { name: 'تایید ایمیل' }))

    expect(await screen.findByText('این ایمیل مال حساب دیگری است')).toBeTruthy()
    expect(screen.getByText('علی رضایی')).toBeTruthy()
    expect(screen.getByText('۱۵٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText(/حساب دیگر که قدیمی‌تر است می‌ماند/)).toBeTruthy()
    expect(screen.getByText(/برگشت ندارد/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'یکی کردن دو حساب' }))
    await until(() => expect(server().sent('POST', `${API}/me/merge`)).toHaveLength(1))
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(server().sent('POST', `${API}/me/merge`)[0]?.body).toEqual({ token: OFFER.merge.token })
    await until(() => expect(screen.queryByText('این ایمیل مال حساب دیگری است')).toBeNull())
  })

  it("shows the shop's refusal under its field", async () => {
    server().on('POST', `${API}/me/identities/email`, refusal(422, 'ایمیل درست نیست.', { email: ['این ایمیل درست نیست.'] }))
    render(app.screen(<EmailScreen />, { at: '/account/email' }))

    fireEvent.change(screen.getByLabelText('ایمیل'), { target: { value: 'ali@' } })
    fireEvent.change(screen.getByLabelText('رمز عبور'), { target: { value: 'kooh-e damavand' } })
    fireEvent.click(screen.getByRole('button', { name: 'ارسال کد' }))

    expect(await screen.findByText('این ایمیل درست نیست.')).toBeTruthy()
    expect(screen.getByLabelText('ایمیل').getAttribute('aria-invalid')).toBe('true')
  })

  it('shows the email an account has already', () => {
    render(app.screen(<EmailScreen />, { at: '/account/email', account: app.account({ customer: WITH_EMAIL.customer }) }))

    expect(screen.getByText('ali@example.com')).toBeTruthy()
    expect(screen.queryByLabelText('ایمیل')).toBeNull()
  })
})
