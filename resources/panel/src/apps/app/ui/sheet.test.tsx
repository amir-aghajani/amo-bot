import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import * as app from '@/apps/app/test/app'
import { MainAction } from '@/apps/app/ui/button'
import { Sheet } from '@/apps/app/ui/sheet'
import { until } from '@/test/render'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The sheet (ui/sheet): named by its title, it takes focus as it opens — once: the customer typing in it, its caller
 * drawn again with a new onClose at each letter, keeps the field focused (and the phone's keyboard up) —, Tab goes round
 * inside it, Escape closes it, and focus goes back to what opened it. Telegram's main button, outside the page, is put
 * away while any sheet is open and shown again as it was once the last one closes.
 */

/** A service's name in a sheet, its words kept by the caller — which hands the sheet a new onClose each time it draws. */
function Rename({ onClose = () => {}, onRenew = () => {} }: { onClose?: () => void; onRenew?: () => void }) {
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        تغییر نام
      </button>
      <MainAction text="تمدید سرویس" onClick={onRenew} />
      <Sheet
        open={open}
        onClose={() => {
          onClose()
          setOpen(false)
        }}
        title="نام سرویس"
      >
        <input aria-label="نام" value={name} onChange={(event) => setName(event.target.value)} />
        <button type="button">ذخیره</button>
      </Sheet>
    </>
  )
}

/** Open the sheet from its button, focused first as a tap leaves it. */
function openSheet(): HTMLElement {
  const opener = screen.getByRole('button', { name: 'تغییر نام' })
  opener.focus()
  fireEvent.click(opener)

  return opener
}

describe('a sheet', () => {
  it('keeps the field focused while the customer types, its caller drawn again at each letter', () => {
    render(<Rename />)
    openSheet()

    const field = screen.getByLabelText('نام') as HTMLInputElement
    field.focus()
    fireEvent.change(field, { target: { value: 'خ' } })
    fireEvent.change(field, { target: { value: 'خانه' } })

    expect(field.value).toBe('خانه')
    expect(document.activeElement).toBe(field)
  })

  it('takes focus as it opens, named by its title, and gives it back to what opened it as it closes', () => {
    const closed = vi.fn()
    render(<Rename onClose={closed} />)
    const opener = openSheet()

    const sheet = screen.getByRole('dialog', { name: 'نام سرویس' })
    expect(document.activeElement).toBe(sheet)
    // A letter typed draws the caller again; Escape still closes, by the latest onClose.
    fireEvent.change(screen.getByLabelText('نام'), { target: { value: 'خانه' } })
    fireEvent.keyDown(document.activeElement ?? window, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(closed).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(opener)
  })

  it('keeps Tab inside it', () => {
    render(<Rename />)
    openSheet()
    const field = screen.getByLabelText('نام')
    const save = screen.getByRole('button', { name: 'ذخیره' })

    save.focus()
    fireEvent.keyDown(save, { key: 'Tab' })
    expect(document.activeElement).toBe(field)

    fireEvent.keyDown(field, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(save)

    // From the sheet itself, as it opened, back goes round to its last stop too.
    screen.getByRole('dialog').focus()
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(save)
  })

  it('puts Telegram’s main button away while it is open, and shows it again as it was after', async () => {
    const { mainButton } = app.telegram()
    const renew = vi.fn()
    render(<Rename onRenew={renew} />)
    await until(() => expect(mainButton.params).toMatchObject({ text: 'تمدید سرویس', is_visible: true, is_active: true }))

    openSheet()
    expect(mainButton.isVisible).toBe(false)
    mainButton.press()
    expect(renew).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'بستن' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(mainButton.params).toMatchObject({ text: 'تمدید سرویس', is_visible: true, is_active: true })
    mainButton.press()
    expect(renew).toHaveBeenCalledOnce()
  })

  it('keeps the main button away until the last open sheet closes', async () => {
    const { mainButton } = app.telegram()
    const { rerender } = render(<TwoSheets first={false} second={false} />)
    await until(() => expect(mainButton.params).toMatchObject({ text: 'پرداخت', is_visible: true }))

    rerender(<TwoSheets first second />)
    expect(mainButton.isVisible).toBe(false)
    rerender(<TwoSheets first={false} second />)
    expect(mainButton.isVisible).toBe(false)
    rerender(<TwoSheets first={false} second={false} />)
    expect(mainButton.isVisible).toBe(true)
  })
})

/** A screen's main button under two sheets of its own. */
function TwoSheets({ first, second }: { first: boolean; second: boolean }) {
  return (
    <>
      <MainAction text="پرداخت" onClick={() => {}} />
      <Sheet open={first} onClose={() => {}} title="یک">
        <p>یک</p>
      </Sheet>
      <Sheet open={second} onClose={() => {}} title="دو">
        <p>دو</p>
      </Sheet>
    </>
  )
}
