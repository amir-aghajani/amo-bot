import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { ProfileCard } from '@/apps/app/screens/account/parts/profile-card'
import * as app from '@/apps/app/test/app'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The customer's card at the head of «حساب من» (screens/account/parts/profile-card): their Telegram picture from t.me —
 * the initials once it does not load (a page policy without t.me, t.me out of reach), never asked for again in the visit
 * —, and its pills tapped over 44px.
 */

/** The launch of Telegram customer 42 with `photo` as their picture. */
function launchedWith(photo: string) {
  app.telegram({ initDataUnsafe: { user: { id: 42, first_name: 'علی', photo_url: photo }, auth_date: 1790000000 } })
}

function draw(tier: string | null = null) {
  return render(
    <MemoryRouter>
      <ProfileCard customer={app.account().customer} tier={tier} />
    </MemoryRouter>,
  )
}

const picture = (container: HTMLElement) => container.querySelector('img')

describe('the profile card', () => {
  it('shows the customer’s Telegram picture', () => {
    launchedWith('https://t.me/i/userpic/320/shown.jpg')
    const { container } = draw()

    expect(picture(container)?.getAttribute('src')).toBe('https://t.me/i/userpic/320/shown.jpg')
  })

  it('falls back to the initials when the picture does not load, and does not ask for it again in the visit', () => {
    launchedWith('https://t.me/i/userpic/320/blocked.jpg')
    const first = draw()
    fireEvent.error(picture(first.container)!)

    expect(picture(first.container)).toBeNull()
    expect(first.container.textContent).toContain('ع')

    first.unmount()
    const again = draw()
    expect(picture(again.container)).toBeNull()
    expect(again.container.textContent).toContain('ع')
  })

  it('loads no picture from anywhere but t.me, nor another account’s', () => {
    launchedWith('https://example.com/ali.jpg')
    expect(picture(draw().container)).toBeNull()
    cleanup()

    app.telegram({ initDataUnsafe: { user: { id: 99, photo_url: 'https://t.me/i/userpic/320/other.jpg' }, auth_date: 1790000000 } })
    expect(picture(draw().container)).toBeNull()
  })

  it('draws its pills small and taps them over 44px', () => {
    launchedWith('https://t.me/i/userpic/320/pills.jpg')
    draw('طلایی')

    for (const pill of [screen.getByRole('button', { name: 'کپی شناسه تلگرام 42' }), screen.getByRole('link', { name: /سطح طلایی/ })]) {
      expect(pill.className).toContain('h-8')
      expect(pill.className).toContain('tap-target')
      expect(pill.className).toContain('relative')
    }
  })
})
