import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SaleForm } from '@/components/discounts/sale-form'
import type { PlanOptions, PlansResponse, SaleRow } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { planRow, saleRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * A timed sale's form (components/discounts/sale-form): `dirty` reads it as the server would — its name trimmed, its
 * percent by its digits —, so a value typed back as it was is no change; the categories it is on, beside its plans; with
 * nothing ticked it asks for a category or a plan, the server's refusal said once in its place.
 */

/** What the form asks while nothing is ticked, in the server's words (DiscountForm::NO_PLAN_CHOSEN). */
const NOTHING_TICKED = 'دست‌کم یک دسته یا پلن انتخاب کنید؛ برای همه پلن‌ها، «همه پلن‌ها» را انتخاب کنید.'

const CATEGORIES: PlanOptions['categories'] = [
  { id: 7, name: 'ماهانه', is_active: true },
  { id: 8, name: 'هفتگی', is_active: true },
]

/** The form over `sale`; the shop's plan «یک‌ماهه» (1) and its categories «ماهانه» (7) and «هفتگی» (8). */
function show(sale: SaleRow) {
  server()
    .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 1, name: 'یک‌ماهه' })] } satisfies PlansResponse))
    .on('GET', '/api/admin/plans/options', json({ categories: CATEGORIES, servers: [] } satisfies PlanOptions))
  render(<SaleForm sale={sale} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

const save = () => screen.getByRole('button', { name: 'ذخیره تغییرات' })

/** The «پلن‌ها» field, as one group. */
const reach = () => screen.getByRole('group', { name: 'پلن‌ها' })

describe('a sale’s form', () => {
  it('is no change typed back as the server reads it: its name with a space after it, its percent in Persian digits', () => {
    show(saleRow())

    fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'فروش ویژه نوروز ' } })
    fireEvent.change(screen.getByLabelText('درصد تخفیف'), { target: { value: '۲۰' } })
    expect(save().getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(screen.getByLabelText('درصد تخفیف'), { target: { value: '25' } })
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })

  it('opens with the categories it is on ticked, and sends a category ticked beside the plans', async () => {
    show(saleRow({ plans: [{ id: 1, name: 'یک‌ماهه' }], categories: [{ id: 7, name: 'ماهانه' }] }))
    server().on('PUT', '/api/admin/sales/4', json({ sale: saleRow() }))

    const categories = screen.getByRole('group', { name: 'دسته‌ها' })
    expect((await within(categories).findByRole('checkbox', { name: 'ماهانه' })).getAttribute('aria-checked')).toBe('true')
    expect(within(categories).getByRole('checkbox', { name: 'هفتگی' }).getAttribute('aria-checked')).toBe('false')
    const plans = screen.getByRole('group', { name: 'پلن‌ها، جدا از دسته‌ها' })
    expect((await within(plans).findByRole('checkbox', { name: 'یک‌ماهه' })).getAttribute('aria-checked')).toBe('true')
    expect(save().getAttribute('aria-disabled')).toBe('true')

    fireEvent.click(within(categories).getByRole('checkbox', { name: 'هفتگی' }))
    fireEvent.click(save())

    await until(() => expect(server().sent('PUT', '/api/admin/sales/4')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/sales/4')[0]?.body).toMatchObject({ plan_ids: [1], category_ids: [7, 8] })
  })

  it('is a change unticked from every plan — both lists sent empty —, asking for a category or a plan, the server’s refusal in its place', async () => {
    show(saleRow())
    expect(screen.queryByText(NOTHING_TICKED)).toBeNull()

    fireEvent.click(screen.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    expect(save().getAttribute('aria-disabled')).toBeNull()
    expect(screen.getByText(NOTHING_TICKED)).toBeTruthy()
    expect(reach().hasAttribute('data-invalid')).toBe(false)

    server().on('PUT', '/api/admin/sales/4', refusal(422, NOTHING_TICKED, { plan_ids: [NOTHING_TICKED] }))
    fireEvent.click(save())

    await until(() => expect(reach().hasAttribute('data-invalid')).toBe(true))
    // Said once: the refusal in place of the field's own line.
    expect(screen.getAllByText(NOTHING_TICKED)).toHaveLength(1)
    expect(server().sent('PUT', '/api/admin/sales/4')[0]?.body).toMatchObject({ plan_ids: [], category_ids: [] })

    fireEvent.click(screen.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    expect(screen.queryByText(NOTHING_TICKED)).toBeNull()
  })

  it('says where categories are made while the shop has none, and a list it could not read as a failure', async () => {
    server()
      .on('GET', '/api/admin/plans', refusal(500, 'خطای سرور.'))
      .on('GET', '/api/admin/plans/options', json({ categories: [], servers: [] } satisfies PlanOptions))
    render(<SaleForm sale={saleRow({ plans: [], categories: [] })} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })

    expect(await within(screen.getByRole('group', { name: 'دسته‌ها' })).findByText('هنوز دسته‌ای ندارید؛ از بخش «دسته‌بندی‌ها» بسازید.')).toBeTruthy()
    const failed = await within(screen.getByRole('group', { name: 'پلن‌ها، جدا از دسته‌ها' })).findByText(/^لیست پلن‌ها خوانده نشد/)
    expect(failed.className).toContain('text-danger')
  })
})
