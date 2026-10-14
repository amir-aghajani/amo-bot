import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { cardsDraft, cardsPayload, type CardDraft } from '@/components/payments/cards'
import { CardsEditor } from '@/components/payments/cards-editor'
import { PAYMENT_CARDS } from '@/lib/limits'
import { paymentCardRow } from '@/test/rows'

/*
 * CardsEditor (components/payments/cards-editor), a card method's cards: each named by its place, its number typed as
 * printed and kept as digits, switched off or on; a new card takes the focus on its number, a removed one hands it to
 * the card that takes its place — the last gone, to the add —; a card payments were shown has no remove and keeps its
 * number; full, adding is held and says why; and the list sent is the cards in their order, a kept one by its id.
 */

/** A list that keeps what it is handed, as a form would. */
function Kept({ start }: { start: CardDraft[] }) {
  const [cards, setCards] = useState(start)
  return (
    <>
      <CardsEditor cards={cards} onChange={setCards} idPrefix="test" />
      <output>{JSON.stringify(cardsPayload(cards))}</output>
    </>
  )
}

const sent = (): unknown => JSON.parse(screen.getByRole('status').textContent ?? '[]')
const add = () => screen.getByRole('button', { name: 'افزودن کارت' })

describe('a card method’s cards', () => {
  it('keep a number typed as printed as its digits, and a card switched off as off', () => {
    render(<Kept start={cardsDraft([paymentCardRow()])} />)

    fireEvent.change(screen.getByLabelText('شماره کارت'), { target: { value: '۵۸۹۲-۱۰۱۰ ۱۲۳۴ ۵۶۷۰' } })
    fireEvent.click(screen.getByRole('switch', { name: 'روشن بودن کارت ۱' }))

    expect(sent()).toEqual([{ id: 11, number: '5892101012345670', holder: 'امیر رضایی', bank: '', enabled: false, daily_cap: '' }])
    expect(screen.getByLabelText<HTMLInputElement>('شماره کارت').value).toBe('5892 1010 1234 5670')
  })

  it('add a blank card with the focus on its number; removed, hand the focus on — the last gone, to adding one', () => {
    render(<Kept start={cardsDraft([paymentCardRow()])} />)

    fireEvent.click(add())
    expect(document.activeElement).toBe(screen.getAllByLabelText('شماره کارت')[1])
    expect(sent()).toEqual([expect.objectContaining({ id: 11 }), { number: '', holder: '', bank: '', enabled: true, daily_cap: '' }])

    fireEvent.click(screen.getByRole('button', { name: 'حذف کارت ۱' }))
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'حذف کارت ۱' }))
    fireEvent.click(screen.getByRole('button', { name: 'حذف کارت ۱' }))
    expect(sent()).toEqual([])
    expect(document.activeElement).toBe(add())
    expect(screen.getByText('دست کم یک کارت لازم است.')).toBeTruthy()
  })

  it('keep a card payments were shown: no remove, its number as it is, what it took today against its cap', () => {
    render(<Kept start={cardsDraft([paymentCardRow({ payments: 2, daily_cap: '300000.00', today: '120000.00' })])} />)

    expect(screen.queryByRole('button', { name: 'حذف کارت ۱' })).toBeNull()
    expect(screen.getByLabelText('شماره کارت').hasAttribute('readonly')).toBe(true)
    expect(screen.getByLabelText<HTMLInputElement>(/^سقف روزانه/).value).toBe('300000')
    expect(screen.getByText('= ۳۰۰٬۰۰۰ تومان؛ امروز ۱۲۰٬۰۰۰ تومان از این سقف پر شده.')).toBeTruthy()

    fireEvent.change(screen.getByLabelText(/^سقف روزانه/), { target: { value: '' } })
    expect(screen.getByText('امروز ۱۲۰٬۰۰۰ تومان؛ خالی یعنی بدون سقف.')).toBeTruthy()
  })

  it('hold adding once the list is full, saying why', () => {
    render(<Kept start={cardsDraft(Array.from({ length: PAYMENT_CARDS.MOST }, (_, i) => paymentCardRow({ id: i + 1 })))} />)

    fireEvent.click(add())

    expect(add().getAttribute('aria-disabled')).toBe('true')
    expect(screen.getAllByLabelText('شماره کارت')).toHaveLength(PAYMENT_CARDS.MOST)
    expect(screen.getByText('حداکثر ۲۰ کارت؛ برای کارت تازه یکی را بردارید.')).toBeTruthy()
  })
})
