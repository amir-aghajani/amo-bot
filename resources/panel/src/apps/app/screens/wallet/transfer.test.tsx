import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import TransferScreen from '@/apps/app/screens/wallet/transfer'
import * as app from '@/apps/app/test/app'
import { API, walletRow } from '@/apps/app/test/rows'
import type { StoreReauthenticatedResponse, StoreTransferRecipientResponse, StoreWalletResponse, StoreWalletTransferResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server, withHeaders, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «انتقال موجودی» (screens/wallet/transfer): the recipient looked up by their Telegram id and shown by name, the amount
 * within what the wallet may send, the transfer confirmed and sent with its key — a session whose sign-in is old proves
 * it again with the launch data, and the same transfer goes once more with the same key —, and the wallet after it.
 */

const SENT: StoreWalletTransferResponse = {
  transfer: { id: 9, amount: '50000.00', recipient: { name: 'Sara', username: 'sara' }, created_at: '2026-10-27T12:00:00+00:00' },
  wallet: walletRow({ balance: '100000.00', spendable: '100000.00' }),
}

function open(wallet: StoreWalletResponse = walletRow()) {
  const telegram = app.telegram()
  server().on('GET', `${API}/wallet`, json(wallet))
  render(app.screen(<TransferScreen />, { at: '/wallet/transfer' }))
  return telegram
}

describe('a wallet transfer', () => {
  it('finds the recipient, confirms them with the amount, and proves a recent sign-in to send it', async () => {
    const { mainButton } = open()
    server().on('GET', `${API}/wallet/transfer`, json({ recipient: { name: 'Sara', username: 'sara' } } satisfies StoreTransferRecipientResponse))
    const answers: Answer[] = [withHeaders(refusal(403, 'برای این کار دوباره وارد شوید.'), { 'WWW-Authenticate': 'Bearer error="insufficient_user_authentication", max_age=900' }), json(SENT)]
    server().on('POST', `${API}/wallet/transfer`, () => answers.shift() ?? json(SENT))
    server().on('POST', `${API}/me/reauthenticate`, json({ expires_in: 900 } satisfies StoreReauthenticatedResponse))

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '۷۰۷۰' } })
    await until(() => expect(mainButton.params).toMatchObject({ text: 'ادامه', is_active: true }))
    mainButton.press()

    expect(await screen.findByText('Sara')).toBeTruthy()
    expect(server().sent('GET', `${API}/wallet/transfer`)[0]?.query.get('telegram_id')).toBe('۷۰۷۰')
    fireEvent.change(screen.getByLabelText('مبلغ انتقال'), { target: { value: '50000' } })
    await until(() => expect(mainButton.params).toMatchObject({ text: 'انتقال ۵۰٬۰۰۰ تومان', is_active: true }))
    mainButton.press()

    await until(() => expect(server().sent('POST', `${API}/wallet/transfer`)).toHaveLength(2))
    expect(server().sent('POST', `${API}/me/reauthenticate`)[0]?.body).toEqual({ method: 'mini_app', init_data: expect.stringContaining('auth_date=') })
    const [refused, again] = server().sent('POST', `${API}/wallet/transfer`)
    expect(refused?.body).toEqual({ telegram_id: '۷۰۷۰', amount: '50000' })
    expect(again?.headers['idempotency-key']).toBe(refused?.headers['idempotency-key'])
    await until(() => expect(screen.queryByText('Sara')).toBeNull())
  })

  it("shows the shop's refusal of the recipient under the id", async () => {
    const { mainButton } = open()
    server().on('GET', `${API}/wallet/transfer`, refusal(422, 'مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.', { telegram_id: ['مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.'] }))

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '1234' } })
    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()

    expect((await screen.findByRole('alert')).textContent).toBe('مشتری‌ای با این شناسه تلگرام در این فروشگاه نیست.')
  })

  it('holds an amount outside what the wallet may send now', async () => {
    const { mainButton } = open()
    server().on('GET', `${API}/wallet/transfer`, json({ recipient: { name: null, username: 'sara' } } satisfies StoreTransferRecipientResponse))

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })
    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()
    fireEvent.change(await screen.findByLabelText('مبلغ انتقال'), { target: { value: '200000' } })

    expect(screen.getByText('@sara')).toBeTruthy()
    expect(screen.getByRole('alert').textContent).toBe('بیشترین مبلغی که الان می‌توانید منتقل کنید ۱۵۰٬۰۰۰ تومان است.')
    await until(() => expect(mainButton.params).toMatchObject({ is_active: false }))
  })

  it('says why nothing can be sent now, instead of the form', async () => {
    open(walletRow({ balance: '5000.00', spendable: '5000.00', transfer: { min: '10000.00', max: '5000000.00', daily_cap: null, left_today: null, most: '5000.00' } }))

    expect(await screen.findByText('فعلا انتقالی ممکن نیست')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'شارژ کیف پول' })).toBeTruthy()
    expect(screen.queryByLabelText('شناسه عددی تلگرام گیرنده')).toBeNull()
  })

  it('says so while the shop offers no transfers', async () => {
    open(walletRow({ transfer: null }))

    expect(await screen.findByText('انتقال موجودی در این فروشگاه فعال نیست.')).toBeTruthy()
  })

  it('forgets a refused amount once a chip puts another', async () => {
    const { mainButton } = open()
    const words = 'موجودی کیف پول برای این انتقال کافی نیست.'
    server().on('GET', `${API}/wallet/transfer`, json({ recipient: { name: 'Sara', username: 'sara' } } satisfies StoreTransferRecipientResponse))
    server().on('POST', `${API}/wallet/transfer`, refusal(422, words, { amount: [words] }))

    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })
    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()
    fireEvent.change(await screen.findByLabelText('مبلغ انتقال'), { target: { value: '50000' } })
    await until(() => expect(mainButton.params).toMatchObject({ text: 'انتقال ۵۰٬۰۰۰ تومان', is_active: true }))
    mainButton.press()
    await screen.findByText(words)

    fireEvent.click(screen.getByRole('button', { name: /کمترین/ }))
    expect(screen.queryByText(words)).toBeNull()
  })

  it('keeps the form and what was typed when the wallet read again fails', async () => {
    open()
    fireEvent.change(await screen.findByLabelText('شناسه عددی تلگرام گیرنده'), { target: { value: '7070' } })

    server().on('GET', `${API}/wallet`, refusal(500, 'خطای سرور'))
    await queryClient.refetchQueries({ queryKey: keys.wallet }).catch(() => undefined)

    expect((screen.getByLabelText('شناسه عددی تلگرام گیرنده') as HTMLInputElement).value).toBe('7070')
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
