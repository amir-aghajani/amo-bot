import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ledgerRow } from '@/apps/app/screens/wallet/test-rows'
import WalletScreen from '@/apps/app/screens/wallet/wallet'
import * as app from '@/apps/app/test/app'
import { API, walletRow } from '@/apps/app/test/rows'
import { Toaster } from '@/apps/app/ui/toaster'
import type { StoreGiftCodeResponse, StoreWalletResponse, StoreWalletTransactionsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «کیف پول» (screens/wallet/wallet): the balance on its card — an agent's debt worded as the bot words it —, the actions
 * the shop's programs allow, the ledger's latest lines, and a gift code redeemed in its sheet.
 */

function open({
  wallet = walletRow(),
  shop = app.shop(),
  lines = [ledgerRow()],
}: { wallet?: StoreWalletResponse; shop?: ReturnType<typeof app.shop>; lines?: StoreWalletTransactionsResponse['transactions'] } = {}) {
  server().on('GET', `${API}/wallet`, json(wallet))
  server().on('GET', `${API}/wallet/transactions`, json({ transactions: lines, meta: { page: 1, per_page: 25, total: lines.length, last_page: 1 } } satisfies StoreWalletTransactionsResponse))
  render(
    <>
      {app.screen(<WalletScreen />, { at: '/wallet', shop })}
      <Toaster />
    </>,
  )
}

describe('the wallet', () => {
  it('shows the balance on its card, every action the programs allow and the latest lines of the ledger', async () => {
    open()

    const card = await screen.findByRole('region', { name: 'موجودی کیف پول' })
    expect(card.textContent).toContain('۱۵۰٬۰۰۰')
    expect(card.textContent).toContain('علی')
    const actions = within(screen.getByRole('navigation', { name: 'کارهای کیف پول' }))
    for (const name of ['شارژ', 'انتقال', 'کد هدیه', 'تاریخچه']) expect(actions.getByText(name)).toBeTruthy()
    expect(await screen.findByText('شارژ کیف پول (سفارش #50)')).toBeTruthy()
    expect(screen.getByText('+۵۰٬۰۰۰')).toBeTruthy()
  })

  it("words an agent's debt as the bot does, with the credit and what the wallet can spend", async () => {
    open({ wallet: walletRow({ balance: '-20000.00', credit: '100000.00', spendable: '80000.00' }) })

    await until(() => expect(screen.getByRole('region', { name: 'موجودی کیف پول' }).textContent).toContain('تومان بدهی'))
    const card = screen.getByRole('region', { name: 'موجودی کیف پول' })
    expect(card.textContent).toContain('۲۰٬۰۰۰')
    expect(card.textContent).toContain('قابل خرج: ۸۰٬۰۰۰ تومان')
  })

  it('offers no transfer nor gift code while the shop runs neither, and says when the ledger is empty', async () => {
    const shop = app.shop()
    shop.shop.programs = { ...shop.shop.programs, wallet_transfers: false, gift_codes: false, loyalty: false, cashback: false }
    open({ shop, lines: [] })

    expect(await screen.findByText('هنوز تراکنشی در کیف پول شما ثبت نشده است.')).toBeTruthy()
    const actions = within(screen.getByRole('navigation', { name: 'کارهای کیف پول' }))
    expect(actions.queryByText('انتقال')).toBeNull()
    expect(actions.queryByText('کد هدیه')).toBeNull()
    expect(screen.queryByText('باشگاه مشتریان')).toBeNull()
  })

  it('shows the way to the club with how far the next loyalty tier is', async () => {
    server().on('GET', `${API}/wallet`, json(walletRow()))
    server().on('GET', `${API}/wallet/transactions`, json({ transactions: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } } satisfies StoreWalletTransactionsResponse))
    const account = app.account({
      loyalty: { tier: { id: 1, name: 'نقره‌ای', discount_percent: 5, cashback_percent: 2 }, spent: '300000.00', next: { name: 'طلایی', min_spent: '500000.00', missing: '200000.00' } },
    })
    render(app.screen(<WalletScreen />, { at: '/wallet', account }))

    expect(await screen.findByText('سطح نقره‌ای')).toBeTruthy()
    expect(screen.getByText('۲۰۰٬۰۰۰ تومان خرید دیگر تا سطح طلایی')).toBeTruthy()
  })

  it('redeems a gift code: the shop says what it gave, and the balance is read again', async () => {
    open()
    server().on(
      'POST',
      `${API}/gift-codes/redeem`,
      json({ gift: { kind: 'wallet', words: '۵۰٬۰۰۰ تومان شارژ کیف پول', balance: '200000.00', order: null, subscription: null } } satisfies StoreGiftCodeResponse),
    )

    fireEvent.click(await screen.findByText('کد هدیه'))
    const sheet = within(await screen.findByRole('dialog'))
    fireEvent.change(sheet.getByLabelText('کد هدیه'), { target: { value: 'nowruz-7k3m ۹qx' } })
    server().on('GET', `${API}/wallet`, json(walletRow({ balance: '200000.00', spendable: '200000.00' })))
    fireEvent.click(sheet.getByRole('button', { name: 'ثبت کد' }))

    await until(() => expect(server().sent('POST', `${API}/gift-codes/redeem`)[0]?.body).toEqual({ code: 'nowruz-7k3m ۹qx' }))
    expect(await screen.findByText('۵۰٬۰۰۰ تومان شارژ کیف پول')).toBeTruthy()
    await until(() => expect(screen.getByRole('region', { name: 'موجودی کیف پول' }).textContent).toContain('۲۰۰٬۰۰۰'))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('shows a refused code under its field and keeps the sheet open', async () => {
    open()
    server().on('POST', `${API}/gift-codes/redeem`, refusal(422, 'این کد هدیه قبلا استفاده شده است.', { code: ['این کد هدیه قبلا استفاده شده است.'] }))

    fireEvent.click(await screen.findByText('کد هدیه'))
    const sheet = within(await screen.findByRole('dialog'))
    fireEvent.change(sheet.getByLabelText('کد هدیه'), { target: { value: 'USED1234' } })
    fireEvent.click(sheet.getByRole('button', { name: 'ثبت کد' }))

    expect(await sheet.findByRole('alert')).toBeTruthy()
    expect(sheet.getByRole('alert').textContent).toBe('این کد هدیه قبلا استفاده شده است.')
  })

  it('says the latest lines are loading as the rest of the app does', () => {
    server().on('GET', `${API}/wallet`, json(walletRow()))
    server().hold('GET', `${API}/wallet/transactions`)
    render(app.screen(<WalletScreen />, { at: '/wallet' }))

    expect(screen.getByRole('status', { name: 'در حال بارگذاری' })).toBeTruthy()
  })
})
