import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PaymentStatusCell } from '@/components/payments/payment-status-cell'
import { paymentRow } from '@/test/rows'

/*
 * A payment's status in a list's cell (components/payments/payment-status-cell), the payments screen's and a customer's
 * card's: its badge, whether it went to the wallet or is held for support — and, on the payments screen, who decided it.
 */

const text = (node: HTMLElement) => node.textContent?.replace(/\s+/g, ' ').trim()

describe('a payment’s status cell', () => {
  it('says where its money went and that it is held, words apart', () => {
    const { container } = render(<PaymentStatusCell payment={paymentRow({ status: 'paid', to_wallet: true, held: true, reviewer: 'root' })} />)

    expect(text(container)).toBe('پرداخت‌شده به کیف پول مشتری رفت نگه داشته شده برای پشتیبانی')
  })

  it('says who decided it where it is asked to: the reviewer, or the shop by itself', () => {
    const reviewed = render(<PaymentStatusCell payment={paymentRow({ status: 'failed', reviewer: 'root' })} decided />)
    expect(text(reviewed.container)).toMatch(/^ناموفق توسط /)

    const automatic = render(<PaymentStatusCell payment={paymentRow({ status: 'paid', auto_approved: true, reviewer: 'root' })} decided />)
    expect(text(automatic.container)).toBe('پرداخت‌شده تایید خودکار')
  })

  it('names one of the shop’s admins by their name where the server read it', () => {
    const { container } = render(<PaymentStatusCell payment={paymentRow({ status: 'failed', reviewer: 'tg:7101', reviewer_name: 'سارا احمدی' })} decided />)

    expect(text(container)).toBe('ناموفق توسط سارا احمدی')
  })
})
