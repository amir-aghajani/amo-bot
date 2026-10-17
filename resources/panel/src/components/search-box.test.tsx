import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SearchBox } from '@/components/search-box'

/*
 * A list's search field (components/search-box): empty, it reads right to left, so its Persian placeholder starts where
 * the page does — never at the far edge, cut at its start; typed into, it takes the direction of what is typed.
 */

function Search() {
  const [value, setValue] = useState('')
  return <SearchBox value={value} onChange={setValue} placeholder="نام، نام کاربری یا شناسه" aria-label="جستجوی کاربر" />
}

describe('a search box', () => {
  it('is right to left while empty, and follows what is typed after', () => {
    render(<Search />)
    const field = screen.getByRole('searchbox', { name: 'جستجوی کاربر' })
    expect(field.getAttribute('dir')).toBe('rtl')

    fireEvent.change(field, { target: { value: '@amir' } })
    expect(field.getAttribute('dir')).toBe('auto')

    fireEvent.change(field, { target: { value: '' } })
    expect(field.getAttribute('dir')).toBe('rtl')
  })

  it('checks no spelling: names, handles and numbers are what it takes', () => {
    render(<Search />)

    expect(screen.getByRole('searchbox', { name: 'جستجوی کاربر' }).getAttribute('spellcheck')).toBe('false')
  })
})
