import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { Banners } from '@/apps/app/screens/home/parts/banners'
import * as app from '@/apps/app/test/app'
import type { StoreSale, StoreSalesResponse } from '@/lib/api-types'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The home screen's banners (screens/home/parts/banners): a dot for each, tapped over 44px without taking its
 * neighbours' taps; the row moves on by itself every five seconds — never while focus is in it, nor while a finger is
 * on it.
 */

const SALE: StoreSale = { id: 4, name: 'فروش ویژه پاییز', percent: 20, ends_at: new Date(Date.now() + 2 * 86_400_000).toISOString(), plan_ids: null, order_types: null }

/** The banners of a shop that runs everything, a sale on: four of them, the clock in the test's hands. */
function open() {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
  const shop = app.shop()
  const tree = app.screen(<Banners shop={shop} />, { shop })
  queryClient.setQueryData<StoreSalesResponse>(keys.sales, { sales: [SALE] })
  render(tree)

  return screen.getByRole('region', { name: 'بنرها' })
}

const dots = (banners: HTMLElement) => within(banners).getAllByRole('button', { name: /^بنر / })

/** Which banner is in view, by its dot. */
const shown = (banners: HTMLElement) => dots(banners).findIndex((dot) => dot.getAttribute('aria-current') === 'true')

const wait = (ms: number) => act(() => vi.advanceTimersByTime(ms))

describe('the banners', () => {
  it('give each dot a 44px tap area of its own width, never over its neighbours', () => {
    const banners = open()

    expect(dots(banners)).toHaveLength(4)
    for (const dot of dots(banners)) {
      expect(dot.className).toContain('tap-row')
      expect(dot.className).toContain('relative')
      expect(dot.className).not.toContain('tap-target')
    }
  })

  it('move on every five seconds, but not while focus is in them', () => {
    const banners = open()
    expect(shown(banners)).toBe(0)
    wait(5_000)
    expect(shown(banners)).toBe(1)

    act(() => dots(banners)[3]?.focus())
    wait(15_000)
    expect(shown(banners)).toBe(1)

    // From one of their own to another: still in them.
    act(() => dots(banners)[0]?.focus())
    wait(15_000)
    expect(shown(banners)).toBe(1)

    act(() => (document.activeElement as HTMLElement).blur())
    wait(5_000)
    expect(shown(banners)).toBe(2)
  })

  it('hold still while a finger is on them', () => {
    const banners = open()
    const track = banners.firstElementChild as HTMLElement

    fireEvent.pointerDown(track)
    wait(15_000)
    expect(shown(banners)).toBe(0)

    fireEvent.pointerUp(track)
    wait(5_000)
    expect(shown(banners)).toBe(1)
  })
})
