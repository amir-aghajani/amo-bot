import { onlineManager } from '@tanstack/react-query'
import { act, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ConnectionBanner } from '@/components/shell/connection-banner'

/*
 * The shell's strip while the panel cannot follow the shop (components/shell/connection-banner): the browser offline —
 * react-query's word, the one its reads and the live poll wait by —, or the server not answering the live poll. It goes
 * by itself once the panel can follow again; its region stays, empty, for assistive tech to hear the next one.
 */

// The browser's network state is react-query's, one for the page: back online for the next test.
afterEach(() => onlineManager.setOnline(true))

describe('the connection strip', () => {
  it('says nothing while the panel follows the shop', () => {
    render(<ConnectionBanner unreachable={false} />)

    expect(screen.getByRole('status').textContent).toBe('')
  })

  it('says the browser is offline, and goes when it is back', () => {
    render(<ConnectionBanner unreachable={false} />)

    act(() => onlineManager.setOnline(false))
    expect(screen.getByRole('status').textContent).toBe('اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها منتظر می‌مانند.')

    act(() => onlineManager.setOnline(true))
    expect(screen.getByRole('status').textContent).toBe('')
  })

  it('says the server does not answer — and the browser being offline before that', () => {
    const { rerender } = render(<ConnectionBanner unreachable />)
    expect(screen.getByRole('status').textContent).toBe('سرور پاسخ نمی‌دهد؛ صفحه‌ها تا برگشتن ارتباط به‌روز نمی‌شوند.')

    act(() => onlineManager.setOnline(false))
    rerender(<ConnectionBanner unreachable />)
    expect(screen.getByRole('status').textContent).toContain('اتصال اینترنت قطع است')
  })
})
