import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { DepositRow, DepositsResponse } from '@/lib/api-types'
import { PaymentsPage } from '@/pages/payments'
import { providers, until } from '@/test/render'
import { depositRow, depositSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «واریزهای پیامکی», the payments page's second section (components/payments/deposits): the bank SMS ForApp forwarded
 * and the deposits Rikhtam told, each with its sum in Toman and Rial, its bank and sender, and what came of it — the
 * payment it approved, linked with its order and its customer, or why none —; a tab a status, asked of the server; a row
 * opened with the SMS as it came (Rikhtam's: what it sent); and the section saying so while «تایید خودکار واریز» is off.
 */

const LIST = '/api/admin/deposits'

/** The section at `at`, listing `deposits`, the shop's auto-verifier on unless said. */
function open(deposits: DepositRow[], { at = '/payments/deposits', enabled = true }: { at?: string; enabled?: boolean } = {}) {
  server()
    .on('GET', '/api/admin/deposits/settings', json(depositSettingsRow(enabled ? {} : { enabled: false, key: null })))
    .on('GET', LIST, json({ deposits, meta: { page: 1, per_page: 25, total: deposits.length, last_page: 1 } } satisfies DepositsResponse))
  render(<PaymentsPage />, { wrapper: providers({ at }).wrapper })
}

const rowsOf = async () =>
  within(await screen.findByRole('table', { name: 'واریزها' }))
    .getAllByRole('row')
    .slice(1)

describe('the bank SMS', () => {
  it('list each one’s sum in Toman and Rial, its bank, its status, and what came of it', async () => {
    open([depositRow(), depositRow({ id: 32, status: 'unmatched', amount_rial: 990000, amount: '99000.00', payment: null, note: 'هیچ پرداخت بازی این مبلغ را نمی‌خواهد.' })])

    const [matched, unmatched] = await rowsOf()
    expect(matched?.textContent).toContain('۱۵۰٬۳۷۰ تومان')
    expect(matched?.textContent).toContain('۱٬۵۰۳٬۷۰۰ ریال')
    expect(matched?.textContent).toContain('ملت')
    expect(matched?.textContent).toContain('+98700717')
    expect(matched?.textContent).toContain('تایید شده')
    expect(unmatched?.textContent).toContain('۹۹٬۰۰۰ تومان')
    expect(unmatched?.textContent).toContain('بدون پرداخت')
    expect(unmatched?.textContent).toContain('هیچ پرداخت بازی این مبلغ را نمی‌خواهد.')
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('واریزهای پیامکی')
  })

  it('link the payment one approved, its order and its customer', async () => {
    open([depositRow()])

    const [matched] = await rowsOf()
    const row = within(matched as HTMLElement)
    expect(row.getByRole('link', { name: 'پرداخت #7' }).getAttribute('href')).toBe('/payments?search=%237')
    expect(row.getByRole('link', { name: 'سفارش #12' }).getAttribute('href')).toBe('/orders?search=%2312')
    expect(row.getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
  })

  it('say a sum the SMS did not name', async () => {
    open([depositRow({ status: 'ignored', amount_rial: null, amount: null, payment: null, bank: null, sender: null })])

    const [row] = await rowsOf()
    expect(row?.textContent).toContain('بدون مبلغ')
    expect(row?.textContent).toContain('نادیده')
  })

  it('open with the SMS as it came', async () => {
    open([depositRow()])

    fireEvent.click(within((await rowsOf())[0] as HTMLElement).getByRole('button', { name: 'پیامک' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/واریز: 1,503,700 ریال/).textContent).toBe('بانک ملت\nواریز: 1,503,700 ریال\nمانده: 9,000,000')
    expect(within(dialog).getByRole('link', { name: 'پرداخت #7' })).toBeTruthy()
    expect(within(dialog).getByText('پیامک واریز بانک، همان‌طور که فوراپ فرستاد.')).toBeTruthy()
  })

  it('of Rikhtam’s open with what Rikhtam sent, never as an SMS ForApp forwarded', async () => {
    const line = 'واریز ۱٬۵۰۳٬۷۰۰ ریال · به حساب …1119 · 1405/08/24 12:30'
    open([depositRow({ source: 'rikhtam', bank: null, sender: 'ریختم', body: line })])

    fireEvent.click(within((await rowsOf())[0] as HTMLElement).getByRole('button', { name: 'جزئیات' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/ریختم از پیامک بانک خبر داد/)).toBeTruthy()
    expect(within(dialog).getByText('آنچه ریختم فرستاد')).toBeTruthy()
    expect(within(dialog).getByText(line)).toBeTruthy()
    expect(within(dialog).queryByText(/فوراپ/)).toBeNull()
    expect(within(dialog).queryByText('متن پیامک')).toBeNull()
  })
})

describe('the tabs', () => {
  it('ask the server for the status picked', async () => {
    open([depositRow()])
    await rowsOf()
    expect(server().sent('GET', LIST)[0]?.query.get('status')).toBeNull()

    fireEvent.click(screen.getByRole('tab', { name: 'بدون پرداخت' }))
    await until(() => expect(server().sent('GET', LIST).at(-1)?.query.get('status')).toBe('unmatched'))

    fireEvent.click(screen.getByRole('tab', { name: 'آزمایشی' }))
    await until(() => expect(server().sent('GET', LIST).at(-1)?.query.get('status')).toBe('test'))
  })

  it('follow the address a link names — the setup card’s, to the deposits that approved nothing', async () => {
    open([], { at: '/payments/deposits?status=unmatched' })

    expect(await screen.findByText('فعلا واریزی بدون پرداخت نیست.')).toBeTruthy()
    expect(server().sent('GET', LIST)[0]?.query.get('status')).toBe('unmatched')
    expect(screen.getByRole('tab', { name: 'بدون پرداخت' }).getAttribute('aria-selected')).toBe('true')
  })

  it('are all, in order, after «همه»', async () => {
    open([depositRow()])
    await rowsOf()

    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['همه', 'بدون پرداخت', 'چند پرداخت', 'تایید شده', 'نادیده', 'آزمایشی'])
  })
})

describe('the section', () => {
  it('says nothing has come yet while nothing has', async () => {
    open([])

    expect(await screen.findByText('هنوز پیامکی نرسیده است')).toBeTruthy()
    expect(screen.getByText(/فوراپ یا ریختم وصل باشد/)).toBeTruthy()
  })

  it('says so while the auto-verifier is off, with the way to its switch', async () => {
    open([], { enabled: false })

    expect(await screen.findByText(/تایید خودکار واریز خاموش است/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'روشن کردن در روش‌های پرداخت' }).getAttribute('href')).toBe('/payment-methods')
  })

  it('reads none of the payments while it is the one shown', async () => {
    open([depositRow()])
    await rowsOf()

    expect(server().sent('GET', '/api/admin/payments')).toEqual([])
  })
})
