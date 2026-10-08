import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { QrBackgroundCard } from '@/components/bot/qr-background-card'
import type { QrBackgroundInfo } from '@/lib/api-types'
import { providers } from '@/test/render'

/*
 * The QR code's background (components/bot/qr-background-card): the largest upload it takes said before one is refused —
 * the server's own limit —, and a picture's size in pixels written as a measure of the file: Latin digits, no separators.
 */

const SHIPPED: QrBackgroundInfo = { custom: false, mime: 'image/jpeg', width: 1024, height: 1024, size: 182_000, updated_at: 1_759_000_000, max_bytes: 5 * 1024 * 1024 }

describe('the QR background card', () => {
  it('says the largest upload it takes, and the picture’s size in Latin digits', () => {
    render(<QrBackgroundCard background={SHIPPED} />, { wrapper: providers().wrapper })

    expect(screen.getByText(/حداکثر ۵ مگابایت/)).toBeTruthy()
    expect(screen.getAllByText('1024×1024').length).toBe(2)
    expect(document.body.textContent).not.toContain('۱٬۰۲۴')
  })

  it('draws the picture in use from its address — a new one when it changes, in the shop the tab shows', () => {
    render(<QrBackgroundCard background={SHIPPED} />, { wrapper: providers().wrapper })

    expect(screen.getByRole('img', { name: 'پس‌زمینه فعلی کد QR' }).getAttribute('src')).toBe('/api/admin/bot/qr-background?v=1759000000&shop=1')
  })
})
