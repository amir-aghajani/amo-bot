import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { DiscountCodeRow, DiscountCodesResponse } from '@/lib/api-types'
import { DiscountCodesPage } from '@/pages/discount-codes'
import { providers, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, discountCodeRow, planRow } from '@/test/rows'
import { json, noContent, refusal, server, type Answer } from '@/test/server'

/*
 * The discount codes (pages/discount-codes): every code — the code, what it takes off and what it holds for, its uses of
 * its cap, how it stands and its switch —; a code made with every field the form has, its window on the shop's clock; a
 * code nobody used deleted, one with uses only switched off; the form's plans and servers while they are read, or could
 * not be; and how long an unpaid order made with a code holds its use (a group of the bot settings).
 */

function codes(rows: DiscountCodeRow[] = [discountCodeRow()]): Answer {
  return json({ codes: rows, meta: { page: 1, per_page: 25, total: rows.length, last_page: 1 } } satisfies DiscountCodesResponse)
}

function open(at = '/discounts', answer: Answer = codes()) {
  server()
    .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ hold_minutes: 60 }))))
    .on('GET', '/api/admin/discount-codes', answer)
    .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 3, name: 'یک‌ماهه' })] }))
    .on('GET', '/api/admin/plans/options', json({ categories: [], servers: [] }))
  render(<DiscountCodesPage />, { wrapper: providers({ at }).wrapper })
}

const table = () => screen.findByRole('table', { name: 'کدهای تخفیف' })

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the codes', () => {
  it('list each code — what it takes off and holds for, its uses of its cap, how it stands', async () => {
    const limited = discountCodeRow({
      id: 22,
      code: 'BERLIN',
      type: 'fixed',
      value: '15000.00',
      order_types: ['purchase', 'renewal'],
      plans: [{ id: 3, name: 'یک‌ماهه' }],
      first_purchase_only: true,
      max_uses: null,
      uses: 4,
      enabled: false,
      state: 'off',
    })
    open('/discounts', codes([discountCodeRow({ max_discount: '50000.00' }), limited]))

    const [, percent, fixed] = within(await table()).getAllByRole('row')
    if (!percent || !fixed) throw new Error('Two codes are listed.')
    expect(within(percent).getByText('NOWRUZ')).toBeTruthy()
    expect(within(percent).getByText('۲۰٪ تا ۵۰٬۰۰۰ تومان')).toBeTruthy()
    expect(within(percent).getByText('همه سفارش‌ها')).toBeTruthy()
    expect(within(percent).getByText('۱ از ۱۰۰')).toBeTruthy()
    expect(within(percent).getByText('فعال')).toBeTruthy()

    expect(within(fixed).getByText('۱۵٬۰۰۰ تومان')).toBeTruthy()
    expect(within(fixed).getByText('خرید، تمدید · پلن «یک‌ماهه» · خرید اول')).toBeTruthy()
    expect(within(fixed).getByText('۴')).toBeTruthy()
    expect(within(fixed).getByText('غیرفعال')).toBeTruthy()
  })

  it('say there is none yet, with the screen’s own empty text', async () => {
    open('/discounts', codes([]))

    expect(await screen.findByText('هنوز کد تخفیفی نساخته‌اید')).toBeTruthy()
  })

  it('switch on and off at once', async () => {
    open()
    server().on('PATCH', '/api/admin/discount-codes/21', json({ code: discountCodeRow({ enabled: false, state: 'off' }) }))

    fireEvent.click(await screen.findByRole('switch', { name: 'فعال بودن NOWRUZ' }))

    await until(() => expect(server().sent('PATCH', '/api/admin/discount-codes/21')[0]?.body).toEqual({ enabled: false }))
  })
})

describe('a code', () => {
  it('is made with every field, its window on the shop’s clock', async () => {
    open()
    server().on('POST', '/api/admin/discount-codes', json({ code: discountCodeRow({ id: 30, code: 'YALDA' }) }, 201))
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کد تخفیف' }))
    type('کد', 'yalda')
    type('درصد تخفیف', '۳۰')
    type(/^سقف تخفیف/, '50000')
    fireEvent.click(screen.getByRole('checkbox', { name: 'همه: خرید، تمدید، حجم، زمان و کاربر اضافه' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'تمدید' }))
    type('پایان', '1405/07/30')
    type(/^سقف استفاده هر مشتری/, '1')
    fireEvent.submit(screen.getByLabelText('کد').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/discount-codes')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/discount-codes')[0]?.body).toEqual({
      code: 'YALDA',
      note: '',
      type: 'percent',
      value: '۳۰',
      max_discount: '50000',
      min_amount: '',
      order_types: ['renewal'],
      plan_ids: null,
      server_ids: null,
      first_purchase_only: false,
      starts_at: null,
      ends_at: '2026-10-22T23:00',
      max_uses: '',
      per_user: '1',
      enabled: true,
    })
    expect(toast.success).toHaveBeenCalledWith('کد تخفیف ساخته شد')
  })

  it('says while its plans and servers are read, and why they could not be — never that there is nothing to pick', async () => {
    server()
      .on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ hold_minutes: 60 }))))
      .on('GET', '/api/admin/discount-codes', codes())
    const plans = server().hold('GET', '/api/admin/plans')
    const options = server().hold('GET', '/api/admin/plans/options')
    render(<DiscountCodesPage />, { wrapper: providers({ at: '/discounts' }).wrapper })
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کد تخفیف' }))
    fireEvent.click(screen.getByRole('button', { name: 'محدودیت‌های بیشتر' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'همه سرورها' }))

    expect(screen.getByText('در حال خواندن پلن‌ها…')).toBeTruthy()
    expect(screen.getByText('در حال خواندن سرورها…')).toBeTruthy()
    expect(screen.queryByText('چیزی برای انتخاب نیست.')).toBeNull()

    plans.answer(refusal(403, 'دسترسی ندارید.'))
    options.answer(refusal(403, 'دسترسی ندارید.'))
    expect(await screen.findByText('لیست پلن‌ها خوانده نشد: دسترسی ندارید.')).toBeTruthy()
    expect(await screen.findByText('لیست سرورها خوانده نشد: دسترسی ندارید.')).toBeTruthy()
    expect(screen.queryByText('چیزی برای انتخاب نیست.')).toBeNull()
  })

  it('a random one is made by the form', async () => {
    open()
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کد تخفیف' }))
    fireEvent.click(screen.getByRole('button', { name: 'ساخت کد تصادفی' }))

    expect((screen.getByLabelText('کد') as HTMLInputElement).value).toMatch(/^[A-Z2-9]{8}$/)
  })

  it('with uses is not deleted — only switched off', async () => {
    open()

    fireEvent.pointerDown(await screen.findByRole('button', { name: 'عملیات کد NOWRUZ' }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))

    expect(await screen.findByText(/برای سابقه می‌ماند؛ به جای حذف، آن را غیرفعال کنید/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'حذف' })).toBeNull()
  })

  it('nobody used is deleted once asked', async () => {
    open('/discounts', codes([discountCodeRow({ uses: 0 })]))
    server().on('DELETE', '/api/admin/discount-codes/21', noContent)

    fireEvent.pointerDown(await screen.findByRole('button', { name: 'عملیات کد NOWRUZ' }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
    fireEvent.click(await screen.findByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('DELETE', '/api/admin/discount-codes/21')).toHaveLength(1))
    await until(() => expect(screen.queryByText('NOWRUZ')).toBeNull())
  })
})

describe('the settings', () => {
  it('save how long an unpaid order made with a code holds its use', async () => {
    open('/discounts/settings')
    server().on('PUT', '/api/admin/bot/settings/discounts', json({ settings: botSettingsRow({ hold_minutes: 30 }) }))

    const field = await screen.findByLabelText('مهلت (دقیقه)')
    fireEvent.change(field, { target: { value: '30' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/discounts')[0]?.body).toEqual({ hold_minutes: '30' }))
  })
})
