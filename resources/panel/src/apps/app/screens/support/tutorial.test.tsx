import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import TutorialScreen from '@/apps/app/screens/support/tutorial'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «آموزش» (screens/support/tutorial): the shop's own tutorial from `GET /tutorial`, rendered safely, then the connection
 * guides — each opened to its steps.
 */

describe('the tutorial', () => {
  it('shows the shop’s text, and each app’s steps once opened', async () => {
    server().on('GET', `${API}/tutorial`, json({ text: '<b>به فروشگاه خوش آمدید</b>\nبرای اتصال <a href="https://t.me/sample_support">پشتیبانی</a><script>alert(1)</script>' }))
    const { container } = render(app.screen(<TutorialScreen />, { at: '/tutorial' }))

    expect((await screen.findByText('به فروشگاه خوش آمدید')).tagName).toBe('STRONG')
    expect(container.querySelector('script')).toBeNull()
    expect(screen.getByRole('link', { name: 'پشتیبانی' }).getAttribute('href')).toBe('https://t.me/sample_support')

    const guide = screen.getByRole('button', { name: /v2rayNG/ })
    expect(guide.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(guide)
    expect(guide.getAttribute('aria-expanded')).toBe('true')
    expect(screen.getByText(/Update subscription/)).toBeTruthy()
  })

  it('a read that failed says why and asks again; the guides stay', async () => {
    let asked = 0
    server().on('GET', `${API}/tutorial`, () => (++asked === 1 ? refusal(500, 'خطای سرور') : json({ text: 'متن آموزش' })))
    render(app.screen(<TutorialScreen />, { at: '/tutorial' }))

    fireEvent.click(await screen.findByRole('button', { name: 'تلاش دوباره' }))
    expect(screen.getByRole('button', { name: /Happ/ })).toBeTruthy()
    await screen.findByText('متن آموزش')
  })

  it('says where each app runs as «اتصال سریع» says it', async () => {
    server().on('GET', `${API}/tutorial`, json({ text: 'متن آموزش' }))
    render(app.screen(<TutorialScreen />, { at: '/tutorial' }))

    await screen.findByText('متن آموزش')
    expect(screen.getByRole('button', { name: /Happ/ }).textContent).toContain('اندروید، iOS و کامپیوتر')
    expect(screen.getByRole('button', { name: /Streisand/ }).textContent).toContain('iOS و مک')
  })
})
