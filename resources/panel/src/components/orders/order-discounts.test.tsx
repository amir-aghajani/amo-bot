import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FactList } from '@/components/fact-list'
import { OrderAmount } from '@/components/orders/order-amount'
import { DiscountFact } from '@/components/orders/order-discounts'
import { orderRow } from '@/test/rows'

/*
 * What an order's discounts took off (components/orders/order-discounts, order-amount): under its amount in a list, the
 * Toman and the code; among a dialog's facts, each discount with its share — a customer's own percent in the panel's
 * words —; nothing for an order nothing was taken off.
 */

const DISCOUNTED = orderRow({
  amount: '76500.00',
  discount_amount: '23500.00',
  discounts: [
    { source: 'personal', label: 'تخفیف اختصاصی شما ۱۵٪', amount: '15000.00', percent: 15 },
    { source: 'code', label: 'کد NOWRUZ', amount: '8500.00', percent: 10 },
  ],
  discount_code: { id: 21, code: 'NOWRUZ' },
})

describe('an order’s discounts', () => {
  it('are shown under its amount: the Toman and the code', () => {
    render(<OrderAmount order={DISCOUNTED} />)

    expect(screen.getByText('۷۶٬۵۰۰ تومان')).toBeTruthy()
    expect(screen.getByText(/۲۳٬۵۰۰ تومان تخفیف/)).toBeTruthy()
    expect(screen.getByText('NOWRUZ')).toBeTruthy()
  })

  it('are listed among its facts, each with its share', () => {
    render(
      <FactList>
        <DiscountFact order={DISCOUNTED} />
      </FactList>,
    )

    expect(screen.getByText('۲۳٬۵۰۰ تومان')).toBeTruthy()
    expect(screen.getByText('تخفیف اختصاصی ۱۵٪')).toBeTruthy()
    expect(screen.getByText('کد NOWRUZ')).toBeTruthy()
  })

  it('say nothing of an order nothing was taken off', () => {
    const { container } = render(
      <FactList>
        <DiscountFact order={orderRow()} />
      </FactList>,
    )
    render(<OrderAmount order={orderRow()} />)

    expect(container.textContent).toBe('')
    expect(screen.queryByText(/تخفیف/)).toBeNull()
  })
})
