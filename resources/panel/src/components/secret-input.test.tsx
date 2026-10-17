import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SecretInput } from '@/components/secret-input'

/*
 * A password or token field (components/secret-input): hidden as typed, its reveal a toggle that keeps one name — its
 * pressed state says whether the value shows —, and not the browser's login to fill in.
 */

describe('a secret’s field', () => {
  it('shows what was typed while its toggle is pressed, the toggle keeping its name', () => {
    render(<SecretInput aria-label="توکن ربات" value="123:abc" onChange={() => undefined} />)
    const field = screen.getByLabelText('توکن ربات')
    const toggle = screen.getByRole('button', { name: 'نمایش' })

    expect([field.getAttribute('type'), toggle.getAttribute('aria-pressed')]).toEqual(['password', 'false'])

    fireEvent.click(toggle)

    expect([field.getAttribute('type'), screen.getByRole('button', { name: 'نمایش' }).getAttribute('aria-pressed')]).toEqual(['text', 'true'])
  })

  it('is no login the browser fills in, unless a sign-in says it is', () => {
    const { rerender } = render(<SecretInput aria-label="رمز دیتابیس" value="" onChange={() => undefined} />)
    expect(screen.getByLabelText('رمز دیتابیس').getAttribute('autocomplete')).toBe('new-password')

    rerender(<SecretInput aria-label="رمز دیتابیس" value="" onChange={() => undefined} autoComplete="current-password" />)
    expect(screen.getByLabelText('رمز دیتابیس').getAttribute('autocomplete')).toBe('current-password')
  })
})
