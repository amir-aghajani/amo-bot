import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { CodeForm } from '@/components/discounts/code-form'
import type { DiscountCodeRow, PlanOptions, PlansResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { discountCodeRow, planRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * A discount code's form (components/discounts/code-form): `dirty` reads it as the server would — its text trimmed, its
 * amounts and counts by their digits, the plans, categories and servers it applies to as sets —, so a value typed back as
 * it was is no change and there is nothing to save; and the categories a code holds for, beside its plans.
 */

/**
 * The form over `code`; the shop's plans «برنزی» (1), «طلایی» (3) and «نقره‌ای» (5), in their order, and its categories
 * «ماهانه» (7) and «هفتگی» (8, switched off).
 */
function show(code: DiscountCodeRow) {
  server()
    .on(
      'GET',
      '/api/admin/plans/options',
      json({
        categories: [
          { id: 7, name: 'ماهانه', is_active: true },
          { id: 8, name: 'هفتگی', is_active: false },
        ],
        servers: [],
      } satisfies PlanOptions),
    )
    .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 1, name: 'برنزی' }), planRow({ id: 3, name: 'طلایی' }), planRow({ id: 5, name: 'نقره‌ای' })] } satisfies PlansResponse))
  render(<CodeForm code={code} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

/** What the form asks while nothing is ticked, in the server's words (DiscountForm::NO_PLAN_CHOSEN). */
const NOTHING_TICKED = 'دست‌کم یک دسته یا پلن انتخاب کنید؛ برای همه پلن‌ها، «همه پلن‌ها» را انتخاب کنید.'

const save = () => screen.getByRole('button', { name: 'ذخیره تغییرات' })
const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('a code’s form', () => {
  it('is no change typed back as the server reads it: its cap with its thousands set apart, a count in Persian digits, its note with a space after it', () => {
    show(discountCodeRow({ max_discount: '50000.00', per_user: 1, note: 'کمپین نوروز' }))
    // The code's bounds in Latin digits, as it is typed.
    expect(screen.getByText(/^همان چیزی که مشتری می‌نویسد؛ 3 تا 32 حرف/)).toBeTruthy()

    type(/^سقف تخفیف/, '۵۰٬۰۰۰')
    type(/^سقف استفاده هر مشتری/, '۱')
    type(/^یادداشت/, 'کمپین نوروز ')
    expect(save().getAttribute('aria-disabled')).toBe('true')

    type(/^سقف تخفیف/, '60000')
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })

  it('is no change while the plans it applies to are ticked back in another order', async () => {
    show(
      discountCodeRow({
        plans: [
          { id: 3, name: 'طلایی' },
          { id: 1, name: 'برنزی' },
        ],
      }),
    )
    const other = await screen.findByRole('checkbox', { name: 'نقره‌ای' })

    fireEvent.click(other)
    expect(save().getAttribute('aria-disabled')).toBeNull()
    fireEvent.click(other)
    expect(save().getAttribute('aria-disabled')).toBe('true')
  })

  it('opens with the categories it holds for ticked, and sends a category ticked beside the plans', async () => {
    show(discountCodeRow({ categories: [{ id: 7, name: 'ماهانه' }], plans: null }))
    server().on('PUT', '/api/admin/discount-codes/21', json({ code: discountCodeRow() }))

    expect((screen.getByRole('checkbox', { name: 'همه پلن‌ها' }) as HTMLButtonElement).getAttribute('aria-checked')).toBe('false')
    expect((await screen.findByRole('checkbox', { name: 'ماهانه' })).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByText('(غیرفعال)')).toBeTruthy()
    expect(save().getAttribute('aria-disabled')).toBe('true')

    fireEvent.click(screen.getByRole('checkbox', { name: /^هفتگی/ }))
    fireEvent.click(await screen.findByRole('checkbox', { name: 'طلایی' }))
    fireEvent.click(save())

    await until(() => expect(server().sent('PUT', '/api/admin/discount-codes/21')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/discount-codes/21')[0]?.body).toMatchObject({ category_ids: [7, 8], plan_ids: [3] })
  })

  it('asks for a category or a plan while nothing is ticked — a code whose category is gone too —, the server’s refusal said once in its place', async () => {
    show(discountCodeRow({ categories: [], plans: null }))
    const reach = () => screen.getByRole('group', { name: 'پلن‌ها' })

    expect((screen.getByRole('checkbox', { name: 'همه پلن‌ها' }) as HTMLButtonElement).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText(NOTHING_TICKED)).toBeTruthy()
    expect(reach().hasAttribute('data-invalid')).toBe(false)

    server().on('PUT', '/api/admin/discount-codes/21', refusal(422, NOTHING_TICKED, { plan_ids: [NOTHING_TICKED] }))
    type(/^سقف تخفیف/, '60000')
    fireEvent.click(save())
    await until(() => expect(reach().hasAttribute('data-invalid')).toBe(true))
    expect(screen.getAllByText(NOTHING_TICKED)).toHaveLength(1)
    expect(server().sent('PUT', '/api/admin/discount-codes/21')[0]?.body).toMatchObject({ plan_ids: [], category_ids: [] })

    fireEvent.click(screen.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    expect(screen.queryByText(NOTHING_TICKED)).toBeNull()
  })
})
