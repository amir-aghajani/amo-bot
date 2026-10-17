import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BulkPricesModal } from '@/components/plans/bulk-prices-modal'
import { providers } from '@/test/render'
import { planRow } from '@/test/rows'

/*
 * The bulk price change's value (components/plans/bulk-prices-modal): a percent may have decimals, so a phone offers its
 * decimal pad; an amount is whole Toman, so it offers the number pad, without the point the server refuses. (Its
 * preview and its apply are pages/plans.test's.)
 */

describe('the change’s value', () => {
  it('is typed on a decimal pad as a percent, and on the number pad as an amount of Toman', async () => {
    render(<BulkPricesModal open plans={[planRow()]} invalidates={[]} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
    const dialog = within(await screen.findByRole('dialog', { name: 'تغییر گروهی قیمت' }))

    expect(dialog.getByLabelText('درصد تغییر').getAttribute('inputmode')).toBe('decimal')
    fireEvent.click(dialog.getByRole('radio', { name: 'مبلغ ثابت' }))
    expect(dialog.getByLabelText('مبلغ تغییر (تومان)').getAttribute('inputmode')).toBe('numeric')
  })
})
