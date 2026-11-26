import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { Banners } from '@/apps/app/screens/home/parts/banners'
import * as app from '@/apps/app/test/app'
import type { StoreSale, StoreSalesResponse } from '@/lib/api-types'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * The home screen's banners (screens/home/parts/banners): a dot for each, tapped over 44px without taking its
 * neighbours' taps; the row moves on by itself every five seconds — never while focus is in it, nor while a finger is
 * on it. A sale limited to some plans never says it is on every purchase; one none of whose plans is on sale now is
 * left out on purchases alone, and on renewals or extras leads to the services, its words naming no purchase. No wheel or
 * draw for a customer the perks do not reach.
 */

const SALE: StoreSale = { id: 4, name: 'فروش ویژه پاییز', percent: 20, ends_at: new Date(Date.now() + 2 * 86_400_000).toISOString(), plan_ids: null, category_ids: null, order_types: null }

/** Where a tap on a banner went. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

/** The banners of a shop that runs everything, a sale on: four of them, the clock in the test's hands; any other screen a stand-in that says its address. */
function open({ perks = true, sales = [SALE] }: { perks?: boolean; sales?: StoreSale[] } = {}) {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] })
  const shop = app.shop()
  const tree = app.screen(
    <Routes>
      <Route path="/" element={<Banners shop={shop} perks={perks} />} />
      <Route path="*" element={<Where />} />
    </Routes>,
    { shop, route: '*' },
  )
  queryClient.setQueryData<StoreSalesResponse>(keys.sales, { sales })
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

  it('word a sale on every purchase as such, and one limited to some plans as on some plans', () => {
    const banners = open({
      sales: [SALE, { ...SALE, id: 5, plan_ids: [1, 3], category_ids: [7] }, { ...SALE, id: 6, plan_ids: [3], order_types: ['renewal'] }, { ...SALE, id: 7, order_types: ['purchase', 'renewal'] }],
    })

    expect(within(banners).getByText('۲۰٪ تخفیف روی همه خریدها')).toBeTruthy()
    expect(within(banners).getByText('۲۰٪ تخفیف روی برخی پلن‌ها')).toBeTruthy()
    expect(within(banners).getByText('۲۰٪ تخفیف روی تمدید، برای برخی پلن‌ها')).toBeTruthy()
    expect(within(banners).getByText('۲۰٪ تخفیف روی خرید سرویس و تمدید')).toBeTruthy()
    expect(within(banners).getAllByText(/روی همه خریدها/)).toHaveLength(1)
  })

  it('lead a sale on purchases to the plans to buy', () => {
    const banners = open({ sales: [{ ...SALE, plan_ids: [1, 3], order_types: ['purchase'] }] })

    fireEvent.click(within(banners).getByRole('button', { name: /۲۰٪ تخفیف روی خرید سرویس، برای برخی پلن‌ها/ }))
    expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/buy')
  })

  it('leave out a sale on purchases alone none of whose plans is on sale, and lead one on renewals too to the services', () => {
    const banners = open({
      sales: [
        { ...SALE, id: 8, plan_ids: [], category_ids: [7], order_types: ['purchase'] },
        { ...SALE, id: 9, plan_ids: [], category_ids: [7] },
      ],
    })

    expect(dots(banners)).toHaveLength(4)
    expect(within(banners).queryByText('۲۰٪ تخفیف روی خرید سرویس، برای برخی پلن‌ها')).toBeNull()
    fireEvent.click(within(banners).getByRole('button', { name: /۲۰٪ تخفیف روی برخی پلن‌ها/ }))
    expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/services')
  })

  it('leave purchases out of the words of a sale none of whose plans is on sale, and lead it to the services', () => {
    const banners = open({
      sales: [
        { ...SALE, id: 10, plan_ids: [], category_ids: [7], order_types: ['purchase', 'renewal'] },
        { ...SALE, id: 11, plan_ids: [1, 3], order_types: ['purchase', 'renewal'] },
      ],
    })

    expect(within(banners).getByText('۲۰٪ تخفیف روی خرید سرویس و تمدید، برای برخی پلن‌ها')).toBeTruthy()
    expect(within(banners).getAllByText(/خرید سرویس/)).toHaveLength(1)
    fireEvent.click(within(banners).getByRole('button', { name: /۲۰٪ تخفیف روی تمدید، برای برخی پلن‌ها/ }))
    expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/services')
  })

  it('leave the wheel and the draw out for a customer the perks do not reach', () => {
    // GET /sales lists no sale for them either.
    const banners = open({ perks: false, sales: [] })

    expect(within(banners).getByText('دعوت از دوستان')).toBeTruthy()
    expect(within(banners).queryByText('گردونه شانس')).toBeNull()
    expect(within(banners).queryByText('قرعه‌کشی شبانه')).toBeNull()
  })
})
