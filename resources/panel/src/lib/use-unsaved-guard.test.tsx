import { createRef, useImperativeHandle, type Ref } from 'react'
import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { createBrowserRouter, createMemoryRouter, Link, Outlet, RouterProvider, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { LeaveQuestion } from '@/components/leave-question'
import { confirmLeave, NavigationGuard, UnsavedScope, useUnloadGuard, useUnsavedGuard, useUnsavedScope } from '@/lib/use-unsaved-guard'
import { until } from '@/test/render'

/*
 * Changes nobody saved are not lost without a word: while an edit surface is dirty, closing the tab, reloading and every
 * navigation to another page of the panel — a link, back and forward — ask first; a page's own sections keep their drafts
 * mounted, so moving between them asks nothing; an action that drops every draft by its own doing asks before it acts;
 * a modal asks before dropping a draft of its own, not one behind it; and with nothing unsaved the router is not held.
 * Inside the panel the question is its own dialog (components/leave-question), staying the default; a tab closing alone
 * asks the browser's way.
 */

function Draft({ dirty }: { dirty: boolean }) {
  useUnsavedGuard(dirty)
  return null
}

function Work({ running }: { running: boolean }) {
  useUnloadGuard(running)
  return null
}

/** The panels' root route as root.tsx has it: the guard's hold on the router and its question, around every page. */
function Root() {
  return (
    <>
      <NavigationGuard />
      <LeaveQuestion />
      <Outlet />
    </>
  )
}

function Address() {
  return <p data-testid="address">{useLocation().pathname}</p>
}

/** The owner's panel (under /admin) on the users page: links to a section of it, to another page, and to a new tab. */
function router(entries: string[] = ['/admin/users']) {
  return createMemoryRouter(
    [
      {
        element: <Root />,
        children: [
          {
            path: '*',
            element: (
              <>
                <Link to="/users/groups">گروه‌ها</Link>
                <Link to="/plans">پلن‌ها</Link>
                <Link to="/orders" target="_blank">
                  سفارش‌ها
                </Link>
                <Address />
              </>
            ),
          },
        ],
      },
    ],
    { basename: '/admin', initialEntries: entries, initialIndex: entries.length - 1 },
  )
}

/** The panel with a draft — dirty or not — beside it (a surface the guard counts wherever it is mounted). */
function panel(dirty: boolean, at?: string[]) {
  const routes = router(at)
  const view = render(
    <>
      <Draft dirty={dirty} />
      <RouterProvider router={routes} />
    </>,
  )
  return {
    routes,
    save: () =>
      view.rerender(
        <>
          <Draft dirty={false} />
          <RouterProvider router={routes} />
        </>,
      ),
  }
}

interface Discard {
  confirmDiscard: (discard: () => void) => void
}

/** A modal with a draft of its own — its scope — and its question before it drops that draft. */
function Dialog({ dirty, handle }: { dirty: boolean; handle: Ref<Discard> }) {
  const { scope, confirmDiscard } = useUnsavedScope()
  useImperativeHandle(handle, () => ({ confirmDiscard }))
  return (
    <UnsavedScope value={scope}>
      <Draft dirty={dirty} />
    </UnsavedScope>
  )
}

const address = () => screen.getByTestId('address').textContent

/** The panel's question, while it is on screen. */
const question = () => screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })

/** The admin's answer to the question on screen: drop the changes, or stay. */
async function answer(leave: boolean) {
  const dialog = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
  fireEvent.click(within(dialog).getByRole('button', { name: leave ? 'رها کردن تغییرات' : 'ماندن' }))
  await until(() => expect(question()).toBeNull())
}

/** What the browser itself would do with a click — open a tab — is not the tests' business. */
const browser = (event: Event) => event.preventDefault()

/** Whether the tab, closing or reloading, was asked to stay. */
function unload(): boolean {
  const event = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(event)
  return event.defaultPrevented
}

describe('a navigation while a draft is unsaved', () => {
  it('asks the panel’s own question before going to another page — staying the default —, and the page stays when the admin says so', async () => {
    panel(true)

    fireEvent.click(screen.getByText('پلن‌ها'))

    const dialog = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    expect(within(dialog).getByText('تغییراتی که ذخیره نکرده‌اید از بین می‌روند.')).toBeTruthy()
    expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'ماندن' }))
    await answer(false)
    expect(address()).toBe('/users')
  })

  it('goes on when the admin agrees to drop the changes', async () => {
    panel(true)

    fireEvent.click(screen.getByText('پلن‌ها'))
    await answer(true)

    await until(() => expect(address()).toBe('/plans'))
  })

  it('moves to another section of the same page without asking: its drafts stay mounted', async () => {
    panel(true)

    fireEvent.click(screen.getByText('گروه‌ها'))

    await until(() => expect(address()).toBe('/users/groups'))
    expect(question()).toBeNull()
  })

  it('asks before leaving one subject’s page (a customer’s) for its list: nothing of the one stays mounted on the other', async () => {
    panel(true, ['/admin/users/12'])

    fireEvent.click(screen.getByText('گروه‌ها'))
    await answer(false)

    expect(address()).toBe('/users/12')
  })

  it('asks nothing once the draft is saved', async () => {
    panel(true).save()

    fireEvent.click(screen.getByText('پلن‌ها'))

    await until(() => expect(address()).toBe('/plans'))
    expect(question()).toBeNull()
  })

  it('asks before the browser’s back takes the page away, and stays or goes as the admin answers', async () => {
    const { routes } = panel(true, ['/admin/plans', '/admin/users'])

    await act(() => routes.navigate(-1))
    await answer(false)
    expect(address()).toBe('/users')

    await act(() => routes.navigate(-1))
    await answer(true)
    await until(() => expect(address()).toBe('/plans'))
  })

  it('asks nothing when the address only moves to a fragment of the same page', async () => {
    const { routes } = panel(true)

    await act(() => routes.navigate('/users#groups'))

    expect(routes.state.location.hash).toBe('#groups')
    expect(question()).toBeNull()
  })

  it('leaves alone a link that opens a new tab', () => {
    window.addEventListener('click', browser)
    try {
      panel(true)

      fireEvent.click(screen.getByText('سفارش‌ها'))
      fireEvent.click(screen.getByText('پلن‌ها'), { ctrlKey: true })

      expect(question()).toBeNull()
      expect(address()).toBe('/users')
    } finally {
      window.removeEventListener('click', browser)
    }
  })
})

describe('the router, with nothing unsaved', () => {
  it('is not held: a fragment the browser moves to on its own goes by without a word', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    window.history.replaceState(null, '', '/agent/login')
    const routes = createBrowserRouter([{ element: <Root />, children: [{ path: '*', element: <Address /> }] }], { basename: '/agent' })
    render(<RouterProvider router={routes} />)
    try {
      // An agent's sign-in link opened in a tab already at its page: the browser moves to the fragment itself.
      window.history.pushState(null, '', '/agent/login#code=abc')
      window.dispatchEvent(new PopStateEvent('popstate', { state: null }))

      await until(() => expect(routes.state.location.hash).toBe('#code=abc'))
      expect(warn).not.toHaveBeenCalled()
      expect(question()).toBeNull()
    } finally {
      routes.dispose()
    }
  })

  it('is held again as soon as a draft is', async () => {
    const { routes } = panel(false)
    const view = render(<Draft dirty />)

    fireEvent.click(screen.getByText('پلن‌ها'))
    await answer(false)
    expect(address()).toBe('/users')

    view.unmount()
    await act(() => routes.navigate('/plans'))
    expect(address()).toBe('/plans')
    expect(question()).toBeNull()
  })
})

describe('an action that drops every draft by its own doing (signing out, another shop)', () => {
  it('asks first, and does nothing when the admin would rather stay', async () => {
    panel(true)

    const leaving = confirmLeave()
    await answer(false)

    expect(await leaving).toBeNull()
  })

  it('agreed, makes its navigation — and lets the tab go — without asking again', async () => {
    const { routes } = panel(true)

    const leaving = confirmLeave()
    await answer(true)
    expect(await leaving).not.toBeNull()
    expect(unload()).toBe(false)
    await act(() => routes.navigate('/plans'))

    expect(address()).toBe('/plans')
    expect(question()).toBeNull()
  })

  it('failed, gives the drafts their hold back', async () => {
    const { routes } = panel(true)

    const leaving = confirmLeave()
    await answer(true)
    const release = await leaving
    act(() => release?.keep())
    await act(() => routes.navigate('/plans'))
    await answer(false)

    expect(address()).toBe('/users')
  })

  it('asks nothing when nothing is unsaved', async () => {
    panel(false)

    expect(await confirmLeave()).not.toBeNull()
    expect(question()).toBeNull()
  })
})

describe('closing or reloading the tab', () => {
  it('asks (the browser’s own question) while a draft is unsaved, and not otherwise', () => {
    const { save } = panel(true)
    expect(unload()).toBe(true)

    save()
    expect(unload()).toBe(false)
  })

  it('asks while work is under way that the page carries on, which holds no navigation back', async () => {
    const routes = router()
    const view = render(
      <>
        <Work running />
        <RouterProvider router={routes} />
      </>,
    )
    expect(unload()).toBe(true)

    fireEvent.click(screen.getByText('پلن‌ها'))
    await until(() => expect(address()).toBe('/plans'))
    expect(question()).toBeNull()

    view.rerender(<Work running={false} />)
    expect(unload()).toBe(false)
  })
})

describe('a modal', () => {
  it('asks before dropping a draft of its own, and closes as the admin answers', async () => {
    const handle = createRef<Discard>()
    const close = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <Dialog dirty handle={handle} />
      </>,
    )

    act(() => handle.current?.confirmDiscard(close))
    await answer(false)
    expect(close).not.toHaveBeenCalled()

    act(() => handle.current?.confirmDiscard(close))
    await answer(true)
    await until(() => expect(close).toHaveBeenCalledTimes(1))
  })

  it('closes without a word when only a card behind it is dirty', () => {
    const handle = createRef<Discard>()
    const close = vi.fn()
    render(
      <>
        <LeaveQuestion />
        <Draft dirty />
        <Dialog dirty={false} handle={handle} />
      </>,
    )

    handle.current?.confirmDiscard(close)

    expect(close).toHaveBeenCalledTimes(1)
    expect(question()).toBeNull()
  })
})
