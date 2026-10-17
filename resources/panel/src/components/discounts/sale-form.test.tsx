import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SaleForm } from '@/components/discounts/sale-form'
import type { PlansResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { planRow, saleRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A timed sale's form (components/discounts/sale-form): `dirty` reads it as the server would — its name trimmed, its
 * percent by its digits —, so a value typed back as it was is no change.
 */

describe('a sale’s form', () => {
  it('is no change typed back as the server reads it: its name with a space after it, its percent in Persian digits', () => {
    server().on('GET', '/api/admin/plans', json({ plans: [planRow()] } satisfies PlansResponse))
    render(<SaleForm sale={saleRow()} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
    const save = screen.getByRole('button', { name: 'ذخیره تغییرات' })

    fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'فروش ویژه نوروز ' } })
    fireEvent.change(screen.getByLabelText('درصد تخفیف'), { target: { value: '۲۰' } })
    expect(save.getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(screen.getByLabelText('درصد تخفیف'), { target: { value: '25' } })
    expect(save.getAttribute('aria-disabled')).toBeNull()
  })
})
