import { act, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useAppActive } from '@/apps/app/telegram/active'
import * as app from '@/apps/app/test/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * Whether the app is in front (telegram/active): not while the mini app is minimised in Telegram (its `deactivated`
 * event, `activated` back), nor while the page is hidden.
 */

function Probe() {
  return <output aria-label="در جلو">{String(useAppActive())}</output>
}

describe('whether the app is in front', () => {
  it('is not while the mini app is minimised in Telegram, and is again once it comes back', () => {
    const { app: telegram } = app.telegram()
    render(<Probe />)
    const shown = () => screen.getByRole('status', { name: 'در جلو' }).textContent

    expect(shown()).toBe('true')
    app.tell(telegram, 'deactivated')
    expect(shown()).toBe('false')
    app.tell(telegram, 'activated')
    expect(shown()).toBe('true')
  })

  it('is not while the page is hidden', () => {
    app.telegram()
    render(<Probe />)
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')

    act(() => void document.dispatchEvent(new Event('visibilitychange')))
    expect(screen.getByRole('status', { name: 'در جلو' }).textContent).toBe('false')
    visibility.mockReturnValue('visible')
    act(() => void document.dispatchEvent(new Event('visibilitychange')))
    expect(screen.getByRole('status', { name: 'در جلو' }).textContent).toBe('true')
  })
})
