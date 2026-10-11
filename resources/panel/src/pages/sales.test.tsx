import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SaleRow, SalesResponse } from '@/lib/api-types'
import { SalesPage } from '@/pages/sales'
import { providers, until } from '@/test/render'
import { planRow, saleRow } from '@/test/rows'
import { json, server, type Answer } from '@/test/server'

/*
 * The timed sales (pages/sales): every sale — its percent, what it is for, how it stands and its switch —, and one made
 * with its plans and its window on the shop's clock.
 */

function sales(rows: SaleRow[] = [saleRow()]): Answer {
  return json({ sales: rows } satisfies SalesResponse)
}

function open(answer: Answer = sales()) {
  server()
    .on('GET', '/api/admin/sales', answer)
    .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 3, name: 'یک‌ماهه' }), planRow({ id: 4, name: 'سه‌ماهه' })] }))
  render(<SalesPage />, { wrapper: providers({ at: '/sales' }).wrapper })
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the sales', () => {
  it('list each sale — its percent, what it is for, how it stands', async () => {
    open(sales([saleRow(), saleRow({ id: 5, name: 'تمدید ارزان', percent: 50, order_types: ['renewal'], plans: [{ id: 3, name: 'یک‌ماهه' }], enabled: false, state: 'off' })]))

    const [, first, second] = within(await screen.findByRole('table', { name: 'فروش‌های ویژه' })).getAllByRole('row')
    if (!first || !second) throw new Error('Two sales are listed.')
    expect(within(first).getByText('۲۰٪')).toBeTruthy()
    expect(within(first).getByText('همه سفارش‌ها · همه پلن‌ها')).toBeTruthy()
    expect(within(second).getByText('تمدید · پلن «یک‌ماهه»')).toBeTruthy()
    expect(within(second).getByText('غیرفعال')).toBeTruthy()
  })

  it('say there is none yet, with the screen’s own empty text', async () => {
    open(sales([]))

    expect(await screen.findByText('هنوز فروش ویژه‌ای نساخته‌اید')).toBeTruthy()
  })
})

describe('a sale', () => {
  it('is made with its plans and its window', async () => {
    open()
    server().on('POST', '/api/admin/sales', json({ sale: saleRow({ id: 9, name: 'یلدا' }) }, 201))
    await screen.findByRole('table', { name: 'فروش‌های ویژه' })

    fireEvent.click(screen.getByRole('button', { name: 'ساخت فروش ویژه' }))
    type('نام', 'یلدا')
    type('درصد تخفیف', '15')
    fireEvent.click(screen.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    fireEvent.click(await screen.findByRole('checkbox', { name: 'سه‌ماهه' }))
    type('شروع', '1405/09/30')
    type('پایان', '1405/10/01')
    fireEvent.submit(screen.getByLabelText('نام').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/sales')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/sales')[0]?.body).toEqual({
      name: 'یلدا',
      percent: '15',
      order_types: null,
      plan_ids: [4],
      starts_at: '2026-12-21T00:00',
      ends_at: '2026-12-22T23:00',
      enabled: true,
    })
    expect(toast.success).toHaveBeenCalledWith('فروش ویژه ساخته شد')
  })
})
