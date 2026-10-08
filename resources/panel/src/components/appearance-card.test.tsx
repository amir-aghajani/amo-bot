import { fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { AppearanceCard } from '@/components/appearance-card'
import { STORAGE_KEYS } from '@/lib/storage'
import { ThemeProvider } from '@/lib/theme'

/*
 * The panel's look, «تنظیمات پنل» › «ظاهر» of either panel (components/appearance-card): dark or light, a choice put on
 * the page as it is picked and kept in this browser.
 */

afterEach(() => {
  document.documentElement.classList.remove('dark')
  document.documentElement.style.colorScheme = ''
})

/** The card's choices: the theme on the page is the one checked. */
function themes() {
  render(
    <ThemeProvider>
      <AppearanceCard />
    </ThemeProvider>,
  )
  const group = screen.getByRole('radiogroup', { name: 'تم پنل' })
  return { group, checked: () => within(group).getByRole('radio', { checked: true }).textContent }
}

describe('the theme’s card', () => {
  it('has the theme in use checked — dark, until light was chosen', () => {
    expect(themes().checked()).toBe('تیره')
  })

  it('puts the theme picked on the page at once, and keeps it for the next visit', () => {
    const { group, checked } = themes()

    fireEvent.click(within(group).getByRole('radio', { name: 'روشن' }))

    expect(checked()).toBe('روشن')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(localStorage.getItem(STORAGE_KEYS.theme)).toBe('light')
  })

  it('opens on the theme this browser keeps', () => {
    localStorage.setItem(STORAGE_KEYS.theme, 'light')

    expect(themes().checked()).toBe('روشن')
  })
})
