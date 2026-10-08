import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { STORAGE_KEYS } from '@/lib/storage'
import { ThemeProvider, useTheme, useThemeSwitch } from '@/lib/theme'

/** The panels' theme (lib/theme): dark unless the admin chose light — there is no "system" theme —, kept for the next visit. */

afterEach(() => {
  document.documentElement.classList.remove('dark')
  document.documentElement.style.colorScheme = ''
})

const themed = () => renderHook(() => useThemeSwitch(), { wrapper: ThemeProvider })

describe('the theme', () => {
  it('is dark until the admin chooses light', () => {
    const { result } = themed()

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.style.colorScheme).toBe('dark')
    expect(result.current.label).toBe('تم روشن')
  })

  it('is the one the admin chose last time', () => {
    localStorage.setItem(STORAGE_KEYS.theme, 'light')

    const { result } = themed()

    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(result.current.label).toBe('تم تاریک')
  })

  it('switches in one tap, and is remembered', () => {
    const { result } = themed()

    act(() => result.current.toggle())

    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(document.documentElement.style.colorScheme).toBe('light')
    expect(localStorage.getItem(STORAGE_KEYS.theme)).toBe('light')

    act(() => result.current.toggle())
    expect(localStorage.getItem(STORAGE_KEYS.theme)).toBe('dark')
  })

  it('is the one chosen in the settings, chosen again or not', () => {
    const { result } = renderHook(() => useTheme(), { wrapper: ThemeProvider })

    act(() => result.current.choose('light'))
    act(() => result.current.choose('light'))

    expect(result.current.theme).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
    expect(localStorage.getItem(STORAGE_KEYS.theme)).toBe('light')

    act(() => result.current.choose('dark'))
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem(STORAGE_KEYS.theme)).toBe('dark')
  })
})
