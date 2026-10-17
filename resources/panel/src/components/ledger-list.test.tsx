import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { LedgerList } from '@/components/ledger-list'

/*
 * A ledger's lines (components/ledger-list) — a customer's wallet, an agent's traffic: in or out said in words besides
 * its colour and sign, and a line's note read whole — wrapped, never cut off at the row's end.
 */

/** A support correction's note, as long as one may be. */
const NOTE = 'اصلاح حجم بعد از بررسی سرویس‌هایی که روی سرور آلمان ساخته نشدند و حجمشان به ربات برنگشت'

describe('a ledger', () => {
  it('shows a line’s note whole, wrapped rather than cut off', () => {
    render(<LedgerList entries={[{ id: 1, credit: true, title: <>اصلاح پشتیبانی · {NOTE}</>, detail: '۱ مهر · مانده ۱۰ گیگابایت', amount: '۱۰ گیگابایت' }]} />)

    const title = screen.getByText(new RegExp(NOTE))
    expect(title.className).not.toMatch(/(^| )truncate( |$)/)
    expect(title.className).toContain('wrap-break-word')
  })

  it('says in words whether each line came in or went out', () => {
    render(
      <LedgerList
        entries={[
          { id: 1, credit: true, title: 'خرید حجم', detail: '۱ مهر', amount: '۵۰ گیگابایت' },
          { id: 2, credit: false, title: 'فروش', detail: '۲ مهر', amount: '۱۰ گیگابایت' },
        ]}
      />,
    )

    const [bought, sold] = screen.getAllByRole('listitem')
    expect(bought?.textContent).toContain('افزایش +۵۰ گیگابایت')
    expect(sold?.textContent).toContain('کاهش −۱۰ گیگابایت')
  })
})
