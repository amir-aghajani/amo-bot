import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PricedRowsEditor, type PricedRowDraft } from '@/components/priced-rows-editor'

/*
 * PricedRowsEditor (components/priced-rows-editor), the rows of amounts and prices the extras' amounts offered and the
 * custom builder's tiers are typed in: each row named by its place, its amount kept under the list's own field name, a
 * blank price's placeholder; a new row takes the focus on its amount, a removed one hands it to the row that takes its
 * place — the last gone, to the add —; full, adding is held (keeping the focus) and says why; empty, it may say what that
 * means.
 */

type Row = PricedRowDraft<'from'>

const row = (id: number, from: string, price = ''): Row => ({ id, from, price })

/** A list that keeps what it is handed, as a form would — tiers of «روز», three at most. */
function Kept({ start = [], empty }: { start?: Row[]; empty?: string }) {
  const [rows, setRows] = useState(start)
  return (
    <>
      <PricedRowsEditor
        label="قیمت هر روز"
        description="هر پله: از چند روز به بالا."
        rows={rows}
        onChange={setRows}
        amount="from"
        headings={{ amount: 'از (روز)', price: 'قیمت (تومان)' }}
        names={(place) => ({ row: `پله ${place}`, amount: `پله ${place}: از`, price: `پله ${place}: قیمت` })}
        example="30"
        pricePlaceholder={(typed) => (typed.from === '' ? undefined : `${typed.from}000`)}
        noun="پله"
        most={3}
        empty={empty}
      />
      <output>{JSON.stringify(rows)}</output>
    </>
  )
}

const kept = (): Row[] => JSON.parse(screen.getByRole('status').textContent ?? '[]') as Row[]
const add = () => screen.getByRole('button', { name: 'افزودن پله' })

describe('a list of priced rows', () => {
  it('names each row by its place, keeps its amount under the list’s field, and words a blank price', () => {
    render(<Kept start={[row(0, '1', '2000')]} />)

    expect(screen.getByRole('group', { name: 'قیمت هر روز' })).toBeTruthy()
    fireEvent.change(screen.getByRole('textbox', { name: 'پله ۱: از' }), { target: { value: '7' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'پله ۱: قیمت' }), { target: { value: '' } })

    expect(kept()).toEqual([row(0, '7', '')])
    expect(screen.getByRole('textbox', { name: 'پله ۱: از' }).getAttribute('placeholder')).toBe('30')
    expect(screen.getByRole('textbox', { name: 'پله ۱: قیمت' }).getAttribute('placeholder')).toBe('7000')
  })

  it('adds a blank row with the focus on its amount', () => {
    render(<Kept start={[row(4, '1', '2000')]} />)

    fireEvent.click(add())

    expect(kept()).toEqual([row(4, '1', '2000'), row(5, '')])
    expect(document.activeElement).toBe(screen.getByRole('textbox', { name: 'پله ۲: از' }))
  })

  it('removed, hands the focus to the row that takes the place, then the one before — and, the last gone, to adding one', () => {
    render(<Kept start={[row(0, '1'), row(1, '30'), row(2, '60')]} />)

    fireEvent.click(screen.getByRole('button', { name: 'حذف پله ۱' }))
    expect(screen.getByRole<HTMLInputElement>('textbox', { name: 'پله ۱: از' }).value).toBe('30')
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'حذف پله ۱' }))

    fireEvent.click(screen.getByRole('button', { name: 'حذف پله ۲' }))
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'حذف پله ۱' }))

    fireEvent.click(screen.getByRole('button', { name: 'حذف پله ۱' }))
    expect(kept()).toEqual([])
    expect(document.activeElement).toBe(add())
  })

  it('full, holds adding — keeping the focus — and says why', () => {
    render(<Kept start={[row(0, '1'), row(1, '30'), row(2, '60')]} />)

    add().focus()
    fireEvent.click(add())

    expect(kept()).toHaveLength(3)
    expect(add().getAttribute('aria-disabled')).toBe('true')
    expect(document.activeElement).toBe(add())
    expect(screen.getByText('حداکثر ۳ پله؛ برای پله تازه یکی را بردارید.')).toBeTruthy()
  })

  it('empty, says what that means where the list has a word for it', () => {
    const { unmount } = render(<Kept empty="بدون پله فروخته نمی‌شود." />)
    expect(screen.getByText('بدون پله فروخته نمی‌شود.')).toBeTruthy()
    expect(screen.queryByRole('list')).toBeNull()
    unmount()

    render(<Kept start={[row(0, '1')]} empty="بدون پله فروخته نمی‌شود." />)
    expect(screen.queryByText('بدون پله فروخته نمی‌شود.')).toBeNull()
  })
})
