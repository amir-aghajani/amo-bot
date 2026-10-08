import { act, render } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PremiumEmoji } from '@/components/bot-texts/premium-emoji'
import type { CustomEmojisResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * A premium emoji as Telegram draws it (components/bot-texts/premium-emoji): a video one fetches nothing until it comes
 * on screen — its still picture the poster meanwhile — and plays only while it is there, as the Lottie ones do: a
 * picker of a hundred plays the dozen in sight. What it is drawn from is the panel's API's, in the shop the tab shows.
 */

/** Tells the emoji it came on screen, or left it — what the browser's IntersectionObserver would say. */
let seen: (visible: boolean) => void = () => undefined

class ScreenWatch {
  constructor(private readonly changed: IntersectionObserverCallback) {}

  observe() {
    seen = (visible) => this.changed([{ isIntersecting: visible } as IntersectionObserverEntry], this as unknown as IntersectionObserver)
  }

  disconnect() {}
}

beforeEach(() => {
  vi.stubGlobal('IntersectionObserver', ScreenWatch)
  vi.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('probably')
  server().on(
    'GET',
    '/api/admin/bot/custom-emojis',
    json({ emojis: [{ id: '42', emoji: '🔥', format: 'video', repaint: false, updated_at: '2026-10-06T09:00:00Z' }], status: null } satisfies CustomEmojisResponse),
  )
})

describe('a video premium emoji', () => {
  it('fetches nothing until it is on screen, and plays only while it is', async () => {
    const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue()
    const pause = vi.spyOn(HTMLMediaElement.prototype, 'pause').mockReturnValue()
    const { container } = render(<PremiumEmoji id="42" fallback="🔥" />, { wrapper: providers().wrapper })

    await until(() => expect(container.querySelector('video')).not.toBeNull())
    const video = container.querySelector('video')
    expect([video?.getAttribute('src'), video?.getAttribute('poster')]).toEqual(['/api/admin/bot/custom-emojis/42/animation?shop=1', '/api/admin/bot/custom-emojis/42/image?shop=1'])
    expect(video?.getAttribute('preload')).toBe('none')
    expect(video?.hasAttribute('autoplay')).toBe(false)
    expect(play).not.toHaveBeenCalled()

    act(() => seen(true))
    expect(play).toHaveBeenCalledTimes(1)

    act(() => seen(false))
    expect(pause).toHaveBeenCalledTimes(1)
  })
})

describe('an animated premium emoji', () => {
  it('asks for its animation through the panel’s API once on screen — in the shop the tab shows —, its still picture standing meanwhile', async () => {
    server()
      .on(
        'GET',
        '/api/admin/bot/custom-emojis',
        json({ emojis: [{ id: '43', emoji: '🎁', format: 'animated', repaint: false, updated_at: '2026-10-06T09:00:00Z' }], status: null } satisfies CustomEmojisResponse),
      )
      .on('GET', '/api/admin/bot/custom-emojis/43/animation', refusal(404, 'این ایموجی تصویر متحرکی ندارد.'))
    const { container } = render(<PremiumEmoji id="43" fallback="🎁" />, { wrapper: providers().wrapper })

    // Drawn as it moves once the kept list says so: its box, the still picture in it.
    await until(() => expect(container.querySelector('span[role="img"]')).not.toBeNull())
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/api/admin/bot/custom-emojis/43/image?shop=1')
    expect(server().sent('GET', '/api/admin/bot/custom-emojis/43/animation')).toEqual([])
    act(() => seen(true))

    await until(() => expect(server().sent('GET', '/api/admin/bot/custom-emojis/43/animation')).toHaveLength(1))
    expect(server().sent('GET', '/api/admin/bot/custom-emojis/43/animation')[0]?.headers['x-shop']).toBe('1')
    expect(container.querySelector('img')).not.toBeNull()
  })
})
