import { render } from '@testing-library/react'
import type { RouteObject } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { lazyPage } from '@/lib/lazy-page'
import { preloadPage, startPrefetching } from '@/lib/prefetch'

/*
 * The page an address opens is asked for before its turn (lib/prefetch): the panel's first page as it starts, a link's
 * page as soon as the link is about to be followed — the pointer resting on it, the keyboard's focus, a press —, and the
 * page the sign-in leads to while its form waits.
 */

const pages = () => {
  const users = vi.fn(() => Promise.resolve({ Page: () => <h1>کاربران</h1> }))
  const plans = vi.fn(() => Promise.resolve({ Page: () => <h1>پلن‌ها</h1> }))
  const UsersPage = lazyPage(users, (module) => module.Page)
  const PlansPage = lazyPage(plans, (module) => module.Page)
  const routes: RouteObject[] = [
    {
      element: <div />,
      children: [
        { path: 'users', element: <UsersPage /> },
        { path: 'plans', element: <PlansPage /> },
        { path: 'plain', element: <p /> },
      ],
    },
  ]
  return { users, plans, routes }
}

/** Two of the panel's links — one with its words in a span —, and one out of it. */
const links = () =>
  render(
    <nav>
      <a href="/admin/users">
        <span>کاربران</span>
      </a>
      <a href="/admin/plans">پلن‌ها</a>
      <a href="https://t.me/someone">تلگرام</a>
    </nav>,
  )

let stop: (() => void) | undefined

afterEach(() => stop?.())

describe('the page an address opens', () => {
  it('is loaded as the panel starts on it, under the panel’s folder', () => {
    window.history.replaceState(null, '', '/shop/admin/users')
    const { users, plans, routes } = pages()

    stop = startPrefetching(routes, '/shop/admin')

    expect(users).toHaveBeenCalledTimes(1)
    expect(plans).not.toHaveBeenCalled()
  })

  it('is loaded by its router address — where the sign-in leads —, and nothing for one no page of its own draws', () => {
    const { users, plans, routes } = pages()
    stop = startPrefetching(routes, '/admin')

    preloadPage('/plans?status=active')
    preloadPage('/plain')
    preloadPage('/nowhere')

    expect(plans).toHaveBeenCalledTimes(1)
    expect(users).not.toHaveBeenCalled()
  })
})

describe('a link about to be followed', () => {
  it('loads its page once the pointer rests on it — not as the pointer passes over it', () => {
    vi.useFakeTimers()
    const { users, plans, routes } = pages()
    stop = startPrefetching(routes, '/admin')
    const { getByText } = links()

    getByText('پلن‌ها').dispatchEvent(new PointerEvent('pointerover', { bubbles: true }))
    getByText('پلن‌ها').dispatchEvent(new PointerEvent('pointerout', { bubbles: true }))
    getByText('کاربران').dispatchEvent(new PointerEvent('pointerover', { bubbles: true }))
    // A pointer resting a moment (50 ms) on a link: about to follow it.
    vi.advanceTimersByTime(50)

    expect(users).toHaveBeenCalledTimes(1)
    expect(plans).not.toHaveBeenCalled()
  })

  it('loads its page at a press, or when the keyboard’s focus reaches it', () => {
    const { users, plans, routes } = pages()
    stop = startPrefetching(routes, '/admin')
    const { getByText } = links()

    getByText('کاربران').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    getByText('پلن‌ها').focus()

    expect(users).toHaveBeenCalledTimes(1)
    expect(plans).toHaveBeenCalledTimes(1)
  })

  it('leaves alone a link out of the panel, and stops when told', () => {
    const { users, routes } = pages()
    stop = startPrefetching(routes, '/admin')
    const { getByText } = links()

    getByText('تلگرام').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    stop()
    getByText('کاربران').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))

    expect(users).not.toHaveBeenCalled()
  })
})
