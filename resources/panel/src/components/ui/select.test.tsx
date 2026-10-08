import { useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

/*
 * An option with a `hint` (components/ui/select): the hint — what the option carries, a server's reason it cannot sell —
 * is under the option's words in the list, and stays out of its name and out of the field once it is picked.
 */

const REASON = 'سرور هنوز بررسی نشده است؛ تا معلوم نشود لینک اشتراک می‌دهد، به مشتری نشان داده نمی‌شود.'

function ServerPicker() {
  const [server, setServer] = useState('')
  return (
    <Select value={server} onValueChange={setServer}>
      <SelectTrigger aria-label="سرور">
        <SelectValue placeholder="انتخاب سرور" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="1" hint={REASON}>
          آلمان ۲
        </SelectItem>
        <SelectItem value="2">هلند</SelectItem>
      </SelectContent>
    </Select>
  )
}

describe('an option with a hint', () => {
  it('shows it under its words, out of its name and of the field once picked', () => {
    render(<ServerPicker />)
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'سرور' }), { key: 'Enter' })

    const option = screen.getByRole('option', { name: 'آلمان ۲' })
    expect(within(option).getByText(REASON)).toBeTruthy()

    fireEvent.click(option)

    expect(screen.getByRole('combobox', { name: 'سرور' }).textContent).toBe('آلمان ۲')
  })
})
