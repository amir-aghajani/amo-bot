import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { CodeForm } from '@/components/discounts/code-form'
import type { DiscountCodeRow, PlanOptions, PlansResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { discountCodeRow, planRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A discount code's form (components/discounts/code-form): `dirty` reads it as the server would — its text trimmed, its
 * amounts and counts by their digits, the plans and servers it applies to as sets —, so a value typed back as it was is no
 * change and there is nothing to save.
 */

/** The form over `code`; the shop's plans «برنزی» (1), «طلایی» (3) and «نقره‌ای» (5), in their order. */
function show(code: DiscountCodeRow) {
  server()
    .on('GET', '/api/admin/plans/options', json({ categories: [], servers: [] } satisfies PlanOptions))
    .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 1, name: 'برنزی' }), planRow({ id: 3, name: 'طلایی' }), planRow({ id: 5, name: 'نقره‌ای' })] } satisfies PlansResponse))
  render(<CodeForm code={code} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

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
})
