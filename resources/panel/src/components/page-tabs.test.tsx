import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PageTabs } from '@/components/page-tabs'

/*
 * The one tab/switch control (components/page-tabs): a row wider than its place (a phone's) scrolls sideways, and its
 * ends that hide tabs fade — the tab cut there reads as more to see, not as a broken word —, nothing while every tab is
 * in sight.
 */

const STATUSES = [
  { value: 'all', label: 'همه' },
  { value: 'stuck', label: 'نیازمند رسیدگی' },
  { value: 'pending', label: 'در انتظار پرداخت' },
  { value: 'fulfilled', label: 'تکمیل‌شده' },
]

/** The row laid out `wide` px for `room` px of place, scrolled `scrolled` px from its start (right to left: below zero). */
function lay(row: HTMLElement, wide: number, room: number, scrolled: number) {
  Object.defineProperties(row, {
    scrollWidth: { value: wide, configurable: true },
    clientWidth: { value: room, configurable: true },
    scrollLeft: { value: scrolled, configurable: true },
  })
  fireEvent.scroll(row)
}

// The panel's page reads right to left (its index.html): a row's end is on its left.
beforeEach(() => {
  document.documentElement.dir = 'rtl'
})

afterEach(() => {
  document.documentElement.removeAttribute('dir')
})

describe('a row of tabs', () => {
  it('fades the ends that hide tabs as it scrolls, and nothing once every tab is in sight', () => {
    render(<PageTabs as="choice" value="all" tabs={STATUSES} onChange={vi.fn()} aria-label="وضعیت" />)
    const row = screen.getByRole('radiogroup', { name: 'وضعیت' })
    expect(row.style.maskImage).toBe('')

    lay(row, 520, 320, 0)
    expect(row.style.maskImage).toBe('linear-gradient(to left, black, black calc(100% - 2rem), transparent)')

    lay(row, 520, 320, -100)
    expect(row.style.maskImage).toBe('linear-gradient(to left, transparent, black 2rem, black calc(100% - 2rem), transparent)')

    lay(row, 520, 320, -200)
    expect(row.style.maskImage).toBe('linear-gradient(to left, transparent, black 2rem, black)')

    lay(row, 300, 320, 0)
    expect(row.style.maskImage).toBe('')
  })
})
