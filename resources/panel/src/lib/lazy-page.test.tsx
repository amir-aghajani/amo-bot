import { setImmediate } from 'node:timers'
import { Suspense, type ReactElement } from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { lazyPage } from '@/lib/lazy-page'
import { until } from '@/test/render'

/*
 * A page of its own chunk (lib/lazy-page): loaded when it is first drawn — or before, when it is asked for ahead (the
 * page the panel opens on, a link about to be followed) —, and then drawn at once, without the wait of a fallback.
 */

type UsersModule = { Users: (props: { title: string }) => ReactElement }

const usersModule: UsersModule = { Users: ({ title }) => <h1>{title}</h1> }

/** The event loop turns once: what a load settled has been taken in. */
const settled = () => new Promise<void>((resolve) => setImmediate(resolve))

const drawn = (Page: (props: { title: string }) => ReactElement) =>
  render(
    <Suspense fallback={<p>در حال بارگذاری</p>}>
      <Page title="کاربران" />
    </Suspense>,
  )

describe('a page of its own chunk', () => {
  it('waits for its chunk under the fallback when nothing asked for it before', async () => {
    const load = vi.fn(() => Promise.resolve(usersModule))

    drawn(lazyPage(load, (module) => module.Users))

    expect(screen.getByText('در حال بارگذاری')).toBeTruthy()
    await until(() => expect(screen.getByRole('heading', { name: 'کاربران' })).toBeTruthy())
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('is drawn at once, with its props, when it was loaded ahead — and loaded once', async () => {
    const load = vi.fn(() => Promise.resolve(usersModule))
    const Page = lazyPage(load, (module) => module.Users)

    Page.preload()
    Page.preload()
    await settled()
    drawn(Page)

    expect(screen.getByRole('heading', { name: 'کاربران' })).toBeTruthy()
    expect(screen.queryByText('در حال بارگذاری')).toBeNull()
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('says nothing of a load ahead that failed, and loads again when drawn', async () => {
    const load = vi.fn<() => Promise<UsersModule>>().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValue(usersModule)
    const Page = lazyPage(load, (module) => module.Users)
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)

    Page.preload()
    await settled()
    drawn(Page)

    await until(() => expect(screen.getByRole('heading', { name: 'کاربران' })).toBeTruthy())
    expect(load).toHaveBeenCalledTimes(2)
    expect(reload).not.toHaveBeenCalled()
  })

  it('reloads once for a new build when the chunk it is drawn from is gone', async () => {
    const load = vi.fn(() => Promise.reject<UsersModule>(new TypeError('Failed to fetch dynamically imported module: /admin/assets/users-Bx1.js')))
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)

    drawn(lazyPage(load, (module) => module.Users))

    await until(() => expect(reload).toHaveBeenCalledTimes(1))
    expect(screen.getByText('در حال بارگذاری')).toBeTruthy()
  })

  it('waits for the reload, drawing no failure, when the chunk came back as nothing — the preload error was taken over for it', async () => {
    // What Vite's import answers once root.tsx's `vite:preloadError` listener prevented the error to reload the tab.
    const load = vi.fn(() => Promise.resolve(undefined as unknown as UsersModule))
    const pick = vi.fn((module: UsersModule) => module.Users)

    drawn(lazyPage(load, pick))
    await settled()
    await settled()

    expect(screen.getByText('در حال بارگذاری')).toBeTruthy()
    expect(pick).not.toHaveBeenCalled()
  })
})
