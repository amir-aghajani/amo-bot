import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { LevelForm } from '@/components/agency/level-form'
import { providers } from '@/test/render'
import { levelRow } from '@/test/rows'

/*
 * An agency level's form (components/agency/level-form): its price per GB is whole Toman, read as the server reads it —
 * typed again with its thousands set apart or in Persian digits it is no change, and a fraction the server would refuse
 * is worked into no price —, typed on a phone's number pad.
 */

const price = () => screen.getByLabelText<HTMLInputElement>('قیمت هر گیگابایت (تومان)')
const save = () => screen.getByRole('button', { name: 'ذخیره تغییرات' })

function show() {
  // Level 1 «طلایی», 3٬000 Toman a GB.
  render(<LevelForm level={levelRow()} invalidates={[]} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: providers().wrapper })
}

describe('a level’s price per GB', () => {
  it('is no change typed again with its thousands set apart or in Persian digits — another price is', () => {
    show()

    for (const same of ['3,000', '۳٬۰۰۰', ' 3000 ']) {
      fireEvent.change(price(), { target: { value: same } })
      expect(save().getAttribute('aria-disabled')).toBe('true')
    }
    fireEvent.change(price(), { target: { value: '3500' } })
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })

  it('says what 100 GB come to only of a price the server takes: whole Toman', () => {
    show()

    fireEvent.change(price(), { target: { value: '۲٬۵۰۰' } })
    expect(screen.getByText('۱۰۰ گیگابایت برای نماینده‌های این سطح ۲۵۰٬۰۰۰ تومان است.')).toBeTruthy()
    fireEvent.change(price(), { target: { value: '2500.5' } })
    expect(screen.getByText('نماینده‌ها حجمی را که رباتشان می‌فروشد با این قیمت از شما می‌خرند.')).toBeTruthy()
  })

  it('is typed on a phone’s number pad', () => {
    show()
    expect(price().getAttribute('inputmode')).toBe('numeric')
  })
})
