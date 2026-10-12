import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HeaderActions } from '@/components/shell/header-actions'
import { ThemeProvider } from '@/lib/theme'

describe('the header’s actions', () => {
  it('switch to the other theme and back', () => {
    render(<HeaderActions />, { wrapper: ThemeProvider })

    fireEvent.click(screen.getByRole('button', { name: 'تم روشن' }))
    expect(document.documentElement.classList.contains('dark')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'تم تاریک' }))
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('open the project’s page beside the panel', () => {
    render(<HeaderActions />, { wrapper: ThemeProvider })

    const link = screen.getByRole('link', { name: 'کد پروژه' })
    expect(link.getAttribute('href')).toMatch(/^https:\/\//)
    expect([link.getAttribute('target'), link.getAttribute('rel')]).toEqual(['_blank', 'noreferrer'])
  })
})
