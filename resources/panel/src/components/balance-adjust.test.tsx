import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TrafficModal } from '@/components/agency/agent-modal'
import { LeaveQuestion } from '@/components/leave-question'
import { WalletModal, WalletPanel } from '@/components/users/wallet-modal'
import type { AgentRow, UserWalletResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A balance set right by hand (components/balance-adjust) — a customer's wallet, an agent's traffic: the amount goes in
 * the API's form however it was typed — Toman whole, «۱۵۰٬۰۰۰» is "150000"; gigabytes with decimals too, «۱٫۵» or «1.5»
 * is "1.5", never 15 —, and one that is no such number (a fraction of a Toman, three decimals, two points, words) is the
 * form's to refuse, nothing sent. It makes a ledger's line — no revert: nothing saved to go back to —, and what it would
 * lose is what was typed: a direction picked alone is nothing to ask about.
 */

const CUSTOMER = userRow()

const AGENT: AgentRow = {
  id: 10,
  user: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
  status: 'active',
  level: { id: 1, name: 'طلایی', price_per_gb: '3000.00' },
  credit_limit: '0.00',
  balance: '0.00',
  bot: { id: 2, username: 'agent_shop_bot', title: 'فروشگاه نماینده', status: 'active', connected: true, problem: null, traffic_balance: 10 * 1024 ** 3, connected_at: '2026-09-02T10:00:00Z' },
  counts: { customers: 0, sold: 0, active: 0 },
}

/** A customer's wallet on their page, its ledger read. */
function wallet() {
  server()
    .on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions: [] } satisfies UserWalletResponse))
    .on('POST', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transaction: null, transactions: [] }))
  render(<WalletPanel user={CUSTOMER} onChanged={() => undefined} />, { wrapper: providers().wrapper })
}

/** Type `amount` in the field labelled `label` and send the form. */
function adjust(label: string, amount: string, submit: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value: amount } })
  fireEvent.click(screen.getByRole('button', { name: submit }))
}

const sent = (path: string) =>
  server()
    .sent('POST', path)
    .map((request) => request.body)

describe('a wallet’s dialog', () => {
  it('offers no revert, and leaves without a word with a direction picked and nothing typed — but asks once an amount is', async () => {
    server().on('GET', '/api/admin/users/10/wallet', json({ user: CUSTOMER, transactions: [] } satisfies UserWalletResponse))
    const onClose = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <WalletModal user={CUSTOMER} onClose={onClose} onChanged={() => undefined} />
      </>,
      { wrapper: providers().wrapper },
    )
    expect(screen.queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()

    fireEvent.click(screen.getByRole('radio', { name: 'کاهش' }))
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()

    fireEvent.change(screen.getByLabelText('مبلغ (تومان)'), { target: { value: '50000' } })
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))
    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(question).getByRole('button', { name: 'ماندن' }))
    await until(() => expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull())
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})

describe('a wallet’s amount', () => {
  it('goes as the API takes it, whole Toman typed in Persian or Latin digits, its thousands set apart', async () => {
    wallet()

    adjust('مبلغ (تومان)', '۱۵۰٬۰۰۰', 'افزایش موجودی')
    await until(() => expect(sent('/api/admin/users/10/wallet')).toHaveLength(1))
    adjust('مبلغ (تومان)', '150,000', 'افزایش موجودی')
    await until(() => expect(sent('/api/admin/users/10/wallet')).toHaveLength(2))

    expect(sent('/api/admin/users/10/wallet').map((body) => (body as { amount: string }).amount)).toEqual(['150000', '150000'])
  })

  it('that is no whole number of Toman is refused in the form’s words, and nothing is sent', () => {
    wallet()

    adjust('مبلغ (تومان)', '1.5', 'افزایش موجودی')

    expect(screen.getByText('یک عدد بدون اعشار بنویسید.')).toBeTruthy()
    expect(sent('/api/admin/users/10/wallet')).toEqual([])
  })
})

describe('an agent’s traffic', () => {
  it('is set right by the GB typed, a decimal kept — and taken away with a minus sign', async () => {
    server()
      .on('GET', '/api/admin/agency/agents/10/traffic', json({ lines: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }))
      .on('POST', '/api/admin/agency/agents/10/traffic', json({ agent: AGENT }))
    render(<TrafficModal agent={AGENT} onClose={() => undefined} onSaved={() => undefined} />, { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('radio', { name: 'کاهش' }))
    adjust('حجم (گیگابایت)', '۱٫۵', 'کاهش حجم')

    await until(() => expect(sent('/api/admin/agency/agents/10/traffic')).toEqual([{ gb: '-1.5', note: '' }]))
  })

  it('that is no such number is refused in the form’s words, and nothing is sent', () => {
    server().on('GET', '/api/admin/agency/agents/10/traffic', json({ lines: [], meta: { page: 1, per_page: 25, total: 0, last_page: 1 } }))
    render(<TrafficModal agent={AGENT} onClose={() => undefined} onSaved={() => undefined} />, { wrapper: providers().wrapper })

    adjust('حجم (گیگابایت)', '1.234', 'افزایش حجم')

    expect(screen.getByText('یک عدد بنویسید، با حداکثر دو رقم اعشار.')).toBeTruthy()
    expect(sent('/api/admin/agency/agents/10/traffic')).toEqual([])
  })
})
