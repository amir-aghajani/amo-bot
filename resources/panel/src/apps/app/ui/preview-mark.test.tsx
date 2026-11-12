import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import * as app from '@/apps/app/test/app'
import { PreviewMark } from '@/apps/app/ui/preview-mark'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The «پیش‌نمایش» pill (ui/preview-mark): shown in a panel's preview alone — never in Telegram's own launch, nor in a
 * browser —, under Telegram's header, taking no tap.
 */

describe('the preview’s pill', () => {
  it('is shown in a panel’s preview, under Telegram’s header, taking no tap', () => {
    app.preview()
    render(<PreviewMark />)

    const pill = screen.getByText('پیش‌نمایش')
    expect(pill.className).toContain('pointer-events-none')
    expect(pill.className).toContain('top-[calc(var(--app-top)+2px)]')
  })

  it('is not shown in Telegram’s own launch, nor in a browser', () => {
    app.telegram()
    const { unmount } = render(<PreviewMark />)
    expect(screen.queryByText('پیش‌نمایش')).toBeNull()
    unmount()

    vi.stubGlobal('Telegram', undefined)
    render(<PreviewMark />)
    expect(screen.queryByText('پیش‌نمایش')).toBeNull()
  })
})
