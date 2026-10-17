import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { GiftCodeBatchesResponse, GiftCodeRow, GiftCodesResponse } from '@/lib/api-types'
import { GiftCodesPage } from '@/pages/gift-codes'
import { providers, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, giftCodeRow, planRow } from '@/test/rows'
import { json, noContent, server, type Answer } from '@/test/server'

/*
 * The gift codes screen (pages/gift-codes): whether customers may redeem codes (a group of the bot settings); every code
 * — the code, what it is worth, its batch, where it stands, who took it and the order a plan's service was given by —;
 * a batch made, its codes shown to copy; a batch picked, its counts with its unused codes copied or deleted — one the
 * address names said and undone once it is gone —; a code nobody took deleted, asked first.
 */

function codes(rows: GiftCodeRow[] = [giftCodeRow()]): Answer {
  return json({ codes: rows, meta: { page: 1, per_page: 25, total: rows.length, last_page: 1 } } satisfies GiftCodesResponse)
}

const BATCHES: GiftCodeBatchesResponse = {
  batches: [{ batch: 'نوروز', total: 10, redeemed: 3, expired: 1, unused: 6, created_at: '2026-10-06T09:00:00Z' }],
}

/** The screen at `at`, gift codes on unless said otherwise. */
function open(at = '/gift-codes', answer: Answer = codes(), enabled = true) {
  server()
    .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ gift_codes_enabled: enabled }))))
    .on('GET', '/api/admin/keyboards', json({ keyboards: [], actions: [], styles: [], limits: { rows: 10, per_row: 8, label: 64 } }))
    .on('GET', '/api/admin/gift-codes/batches', json(BATCHES))
    .on('GET', '/api/admin/gift-codes', answer)
  render(<GiftCodesPage />, { wrapper: providers({ at }).wrapper })
}

const table = () => screen.findByRole('table', { name: 'کدهای هدیه' })

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

beforeEach(() => {
  vi.spyOn(toast, 'success')
  Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn(() => Promise.resolve()) }, configurable: true })
})

describe('the gift codes', () => {
  it('list each code — what it is worth, its batch, where it stands, who took it and its order', async () => {
    const taken = giftCodeRow({
      id: 32,
      code: 'PLANGIFT2345',
      kind: 'plan',
      amount: null,
      plan: { id: 3, name: 'یک‌ماهه' },
      status: 'redeemed',
      redeemed_by: { id: 10, name: 'امیر', username: 'amir', telegram_id: 123456789, email: null },
      redeemed_at: '2026-10-06T10:00:00Z',
      order: { id: 77, status: 'fulfilled' },
    })
    open('/gift-codes', codes([giftCodeRow(), taken]))

    const [, free, used] = within(await table()).getAllByRole('row')
    if (!free || !used) throw new Error('Two codes are listed.')
    expect(within(free).getByText('NOWRUZ7K3M9QXAPT')).toBeTruthy()
    expect(within(free).getByText('۵۰٬۰۰۰ تومان')).toBeTruthy()
    expect(within(free).getByText('استفاده‌نشده')).toBeTruthy()

    expect(within(used).getByText('یک‌ماهه')).toBeTruthy()
    expect(within(used).getByText('سرویس رایگان')).toBeTruthy()
    expect(within(used).getByText('استفاده‌شده')).toBeTruthy()
    expect(within(used).getByRole('link', { name: 'امیر' }).getAttribute('href')).toBe('/users/10')
    expect(within(used).getByRole('link', { name: /سفارش/ }).getAttribute('href')).toBe('/orders?search=%2377')
  })

  it('say there is none yet, with the screen’s own empty text', async () => {
    open('/gift-codes', codes([]))

    expect(await screen.findByText('هنوز کد هدیه‌ای نساخته‌اید')).toBeTruthy()
  })
})

describe('the switch', () => {
  it('is saved as the bot settings’ `gift_codes` group', async () => {
    open('/gift-codes', codes(), false)
    server().on('PUT', '/api/admin/bot/settings/gift_codes', json({ settings: botSettingsRow({ gift_codes_enabled: true }) }))

    fireEvent.click(await screen.findByRole('switch', { name: /استفاده از کد هدیه فعال باشد/ }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/gift_codes')[0]?.body).toEqual({ gift_codes_enabled: true }))
  })
})

describe('a batch', () => {
  it('is made from the form and its codes shown to copy', async () => {
    open()
    server().on('POST', '/api/admin/gift-codes', json({ batch: 'یلدا', codes: ['YALDA2345678AB', 'YALDA2345678CD'] }, 201))
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کدها' }))
    type('مبلغ هر کد (تومان)', '۲۰٬۰۰۰')
    type('تعداد کدها', '2')
    type('نام سری', 'یلدا')
    type(/^پیشوند/, 'yalda')
    fireEvent.submit(screen.getByLabelText('نام سری').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/gift-codes')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/gift-codes')[0]?.body).toEqual({ count: '2', kind: 'wallet', batch: 'یلدا', amount: '۲۰٬۰۰۰', prefix: 'yalda' })
    const made = (await screen.findByRole('textbox', { name: 'کدهای ساخته‌شده' })) as HTMLTextAreaElement
    expect(made.value).toBe('YALDA2345678AB\nYALDA2345678CD')

    fireEvent.click(screen.getByRole('button', { name: 'کپی همه کدها' }))
    await until(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('YALDA2345678AB\nYALDA2345678CD'))
  })

  it('of a plan names the plan', async () => {
    open()
    server()
      .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 3, name: 'یک‌ماهه' }), planRow({ id: 4, name: 'هدیه', is_active: false })] }))
      .on('POST', '/api/admin/gift-codes', json({ batch: 'هدیه', codes: ['GIFT2345678AB'] }, 201))
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کدها' }))
    fireEvent.click(screen.getByRole('radio', { name: 'سرویس رایگان' }))
    await until(() => expect(server().sent('GET', '/api/admin/plans')).toHaveLength(1))
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'پلن' }), { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: /^هدیه/ }))
    type('نام سری', 'هدیه')
    fireEvent.submit(screen.getByLabelText('نام سری').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/gift-codes')[0]?.body).toEqual({ count: '10', kind: 'plan', batch: 'هدیه', plan_id: 4 }))
  })

  it('picked shows its counts — its unused codes copied, or deleted once asked', async () => {
    open('/gift-codes?batch=%D9%86%D9%88%D8%B1%D9%88%D8%B2')
    server()
      .on('GET', '/api/admin/gift-codes/unused', json({ codes: ['AAAA2345', 'BBBB2345'] }))
      .on('POST', '/api/admin/gift-codes/discard', json({ deleted: 7 }))

    expect(await screen.findByText(/سری «نوروز»: ۱۰ کد/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'کپی کدهای استفاده‌نشده' }))
    await until(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('AAAA2345\nBBBB2345'))
    expect(Object.fromEntries(server().sent('GET', '/api/admin/gift-codes/unused')[0]?.query ?? [])).toEqual({ batch: 'نوروز' })

    fireEvent.click(screen.getByRole('button', { name: 'حذف کدهای استفاده‌نشده' }))
    const dialog = await screen.findByRole('dialog', { name: 'حذف کدهای استفاده‌نشده' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('POST', '/api/admin/gift-codes/discard')[0]?.body).toEqual({ batch: 'نوروز' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith('۷ کد استفاده‌نشده حذف شد'))
  })
})

describe('a batch the address names', () => {
  it('is said and can be undone once it is gone — its codes deleted, no batch left to list', async () => {
    server()
      .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ gift_codes_enabled: true }))))
      .on('GET', '/api/admin/keyboards', json({ keyboards: [], actions: [], styles: [], limits: { rows: 10, per_row: 8, label: 64 } }))
      .on('GET', '/api/admin/gift-codes/batches', json({ batches: [] } satisfies GiftCodeBatchesResponse))
      .on('GET', '/api/admin/gift-codes', codes([]))
    render(<GiftCodesPage />, { wrapper: providers({ at: '/gift-codes?batch=yalda' }).wrapper })

    expect(await screen.findByText('با این جستجو یا فیلتر کدی نیست.')).toBeTruthy()
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'سری yalda' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'همه' }))

    await until(() => expect(server().sent('GET', '/api/admin/gift-codes').at(-1)?.query.has('batch')).toBe(false))
  })
})

describe('a code nobody took', () => {
  it('is deleted once asked', async () => {
    open()
    server().on('DELETE', '/api/admin/gift-codes/31', noContent)
    await table()

    fireEvent.pointerDown(screen.getByRole('button', { name: 'عملیات کد NOWRUZ7K3M9QXAPT' }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
    const dialog = await screen.findByRole('dialog', { name: 'حذف کد هدیه' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('DELETE', '/api/admin/gift-codes/31')).toHaveLength(1))
    await until(() => expect(screen.queryByText('NOWRUZ7K3M9QXAPT')).toBeNull())
  })
})
