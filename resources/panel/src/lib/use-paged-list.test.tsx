import { act, renderHook } from '@testing-library/react'
import { useLocation, useNavigate, useNavigationType } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { SectionedPage } from '@/components/sectioned-page'
import { USER_SECTIONS } from '@/components/shell/nav'
import { api } from '@/lib/api'
import type { PageMeta } from '@/lib/api-types'
import { usePagedList } from '@/lib/use-paged-list'
import { providers, until } from '@/test/render'
import { json, server, type SentRequest } from '@/test/server'

/*
 * A searchable, filterable, sortable, paged list (lib/use-paged-list): its view lives in its address — a link from
 * another screen opens it narrowed or ordered, back and forward bring a view back, and what the admin does there is
 * written there —, typing reaches the server once it pauses, anything that narrows or orders the list starts again from
 * the first page, the page on screen stays while the next one loads, and an operation's answer changes the rows in place.
 */

interface Order {
  id: number
  name: string
}

interface OrdersPage {
  orders: Order[]
  meta: PageMeta
}

const STATUSES = ['', 'pending', 'failed'] as const
const SORTS = ['created', 'amount'] as const

/** The orders as the server pages them: the request's page, a row named after what was asked. */
function orders(request: SentRequest): OrdersPage {
  const page = Number(request.query.get('page') ?? 1)
  return { orders: [{ id: page, name: `page ${page} ${request.query.toString()}` }], meta: { page, per_page: 1, total: 5, last_page: 5 } }
}

/**
 * The orders list opened at `at` (the panel's address), the address it shows and how it got there, and a way to follow
 * another link meanwhile. Its `server` filter takes any value, its `type` filter the two `choices` alone.
 */
async function ordersList(at = '/orders', statuses: readonly string[] = STATUSES) {
  server().on('GET', '/api/admin/orders', (request) => json(orders(request)))
  const { wrapper } = providers({ at })
  const hook = renderHook(
    () => ({
      list: usePagedList({
        queryKey: ['orders'],
        read: (query) => api.get<OrdersPage>('/orders', query),
        list: 'orders',
        statuses,
        params: ['server', 'type'],
        choices: { type: ['purchase', 'renewal'] },
        sorts: SORTS,
        address: '/orders',
      }),
      location: useLocation(),
      navigation: useNavigationType(),
      navigate: useNavigate(),
    }),
    { wrapper },
  )
  await until(() => expect(hook.result.current.list.rows).toHaveLength(1))
  return hook.result
}

/** The address the list is at, as the address bar shows it. */
const at = (result: Awaited<ReturnType<typeof ordersList>>) => result.current.location.pathname + result.current.location.search

/** What the last read asked the server for. */
function lastQuery(): Record<string, string> {
  return Object.fromEntries(server().sent('GET', '/api/admin/orders').at(-1)?.query ?? [])
}

/** The search box typed into, a key at a time. */
function type(result: Awaited<ReturnType<typeof ordersList>>, text: string) {
  for (let end = 1; end <= text.length; end++) {
    act(() => result.current.list.setTyped(text.slice(0, end)))
  }
}

describe('a list opened', () => {
  it('reads its first page, narrowed by nothing', async () => {
    const result = await ordersList()

    expect(lastQuery()).toEqual({})
    expect(result.current.list.meta?.page).toBe(1)
    expect(result.current.list.meta?.last_page).toBe(5)
    expect(result.current.list.filtered).toBe(false)
  })

  it('takes in what a link to it carries: a search, a status tab, a filter', async () => {
    const result = await ordersList('/orders?search=%2312&status=failed&server=3')

    expect(lastQuery()).toEqual({ search: '#12', status: 'failed', server: '3' })
    expect(result.current.list.typed).toBe('#12')
    expect(result.current.list.filter).toBe('failed')
    expect(result.current.list.params).toEqual({ server: '3', type: '' })
    expect(result.current.list.filtered).toBe(true)
    expect(result.current.list.narrowed).toBe(true)
  })

  it('ignores a status it has no tab for — and takes it out of its address, in place', async () => {
    const result = await ordersList('/orders?status=refunded')

    expect(result.current.list.filter).toBe('')
    expect(lastQuery()).toEqual({})
    await until(() => expect(at(result)).toBe('/orders'))
    expect(result.current.navigation).toBe('REPLACE')
  })

  it('ignores a filter’s value outside its choices the same way, keeping the rest', async () => {
    const result = await ordersList('/orders?type=traffic&server=3')

    expect(result.current.list.params).toEqual({ server: '3', type: '' })
    expect(lastQuery()).toEqual({ server: '3' })
    await until(() => expect(at(result)).toBe('/orders?server=3'))
    expect(result.current.navigation).toBe('REPLACE')
  })

  it('takes a filter’s value among its choices', async () => {
    const result = await ordersList('/orders?type=renewal')

    expect(lastQuery()).toEqual({ type: 'renewal' })
    expect(at(result)).toBe('/orders?type=renewal')
  })

  it('narrows nothing by its status tab alone: an empty tab is no search that found nothing', async () => {
    const result = await ordersList('/orders?status=pending')

    expect(result.current.list.filtered).toBe(true)
    expect(result.current.list.narrowed).toBe(false)
  })

  it('takes in nothing from another screen’s address', async () => {
    const result = await ordersList('/payments?search=%2312&status=failed')

    expect(result.current.list.typed).toBe('')
    expect(lastQuery()).toEqual({})
  })

  it('shows what a link names when it lands on the open list again — the address is the whole view', async () => {
    const result = await ordersList('/orders?search=amir')

    act(() => void result.current.navigate('/orders?status=pending&server=4'))

    await until(() => expect(lastQuery()).toEqual({ status: 'pending', server: '4' }))
    expect(result.current.list.filter).toBe('pending')
    expect(result.current.list.typed).toBe('')

    act(() => void result.current.navigate('/orders?search=%2399'))
    await until(() => expect(lastQuery()).toEqual({ search: '#99' }))
    expect(result.current.list.typed).toBe('#99')
  })
})

describe('the address', () => {
  it('takes what narrows, orders and pages the list — a history entry each — and nothing of its own view', async () => {
    const result = await ordersList()

    act(() => result.current.list.setFilter('pending'))
    await until(() => expect(at(result)).toBe('/orders?status=pending'))
    act(() => result.current.list.setOrder({ sort: 'amount', dir: 'asc' }))
    await until(() => expect(at(result)).toBe('/orders?status=pending&sort=amount&dir=asc'))
    act(() => result.current.list.setPage(3))
    await until(() => expect(at(result)).toBe('/orders?status=pending&sort=amount&dir=asc&page=3'))

    act(() => result.current.list.setFilter(''))
    act(() => result.current.list.setOrder({ sort: 'created', dir: 'desc' }))
    await until(() => expect(at(result)).toBe('/orders'))
  })

  it('takes the search in place of its last once typing settles', async () => {
    const result = await ordersList()
    act(() => result.current.list.setParam('server', '7'))
    await until(() => expect(at(result)).toBe('/orders?server=7'))
    vi.useFakeTimers()

    type(result, ' amir ')
    expect(at(result)).toBe('/orders?server=7')
    await act(() => vi.advanceTimersByTimeAsync(300))
    vi.useRealTimers()
    await until(() => expect(at(result)).toBe('/orders?server=7&search=amir'))

    // The settled search took the filter's entry's place: back goes to before the filter.
    act(() => void result.current.navigate(-1))
    await until(() => expect(at(result)).toBe('/orders'))
    expect(result.current.list.params).toEqual({ server: '', type: '' })
    expect(result.current.list.typed).toBe('')
  })

  it('brings a view back with back and forward', async () => {
    const result = await ordersList()
    act(() => result.current.list.setFilter('failed'))
    await until(() => expect(at(result)).toBe('/orders?status=failed'))
    act(() => result.current.list.setPage(2))
    await until(() => expect(lastQuery()).toEqual({ status: 'failed', page: '2' }))

    act(() => void result.current.navigate(-1))
    await until(() => expect(result.current.list.meta?.page).toBe(1))
    expect(at(result)).toBe('/orders?status=failed')
    expect(result.current.list.filter).toBe('failed')

    act(() => void result.current.navigate(1))
    await until(() => expect(lastQuery()).toEqual({ status: 'failed', page: '2' }))
  })

  it('names «همه» for a list that starts on another tab, and leaves that tab out', async () => {
    const result = await ordersList('/orders', ['pending', 'failed', ''])
    expect(lastQuery()).toEqual({ status: 'pending' })

    act(() => result.current.list.setFilter(''))
    await until(() => expect(at(result)).toBe('/orders?status='))
    expect(lastQuery()).toEqual({})

    act(() => result.current.list.setFilter('pending'))
    await until(() => expect(at(result)).toBe('/orders'))
  })

  it('lets go of what a link brought once the admin moves on — a reload keeps the view on screen', async () => {
    const result = await ordersList('/orders?status=pending')

    act(() => result.current.list.setFilter(''))

    await until(() => expect(at(result)).toBe('/orders'))
    expect(lastQuery()).toEqual({})
  })

  it('is left alone while it shows another screen', async () => {
    const result = await ordersList('/payments')

    act(() => result.current.list.setFilter('failed'))

    await until(() => expect(lastQuery()).toEqual({ status: 'failed' }))
    expect(at(result)).toBe('/payments')
  })

  it('takes the view a list kept while another section was on screen, when it comes back at its bare address', async () => {
    const result = await ordersList()
    act(() => result.current.list.setFilter('failed'))
    await until(() => expect(at(result)).toBe('/orders?status=failed'))

    act(() => void result.current.navigate('/orders/archive'))
    await until(() => expect(at(result)).toBe('/orders/archive'))
    act(() => void result.current.navigate('/orders'))

    await until(() => expect(at(result)).toBe('/orders?status=failed'))
    expect(result.current.list.filter).toBe('failed')
  })
})

describe('searching', () => {
  it('asks the server once typing pauses, from the first page, the search trimmed', async () => {
    const result = await ordersList()
    act(() => result.current.list.setPage(3))
    await until(() => expect(lastQuery()).toEqual({ page: '3' }))
    vi.useFakeTimers()

    type(result, ' amir ')
    await act(() => vi.advanceTimersByTimeAsync(299))
    expect(at(result)).toBe('/orders?page=3')
    expect(server().sent('GET', '/api/admin/orders')).toHaveLength(2)

    await act(() => vi.advanceTimersByTimeAsync(1))
    await until(() => expect(lastQuery()).toEqual({ search: 'amir' }))
    await until(() => expect(at(result)).toBe('/orders?search=amir'))
    expect(result.current.list.filtered).toBe(true)
    expect(server().sent('GET', '/api/admin/orders')).toHaveLength(3)
  })

  it('keeps the page on screen, dimmed, while the next one loads', async () => {
    const result = await ordersList()
    const next = server().hold('GET', '/api/admin/orders')

    act(() => result.current.list.setPage(2))

    await until(() => expect(next.waiting).toBe(1))
    expect(result.current.list.rows[0]?.id).toBe(1)
    expect(result.current.list.isPlaceholderData).toBe(true)

    await act(async () => next.answer(json(orders(server().requests.at(-1) as SentRequest))))
    await until(() => expect(result.current.list.rows[0]?.id).toBe(2))
    expect(result.current.list.isPlaceholderData).toBe(false)
  })
})

/** What the server was asked for the rows on screen. */
const shown = (result: Awaited<ReturnType<typeof ordersList>>) => result.current.list.rows[0]?.name

describe('narrowing', () => {
  it('goes back to the first page on another status tab or filter', async () => {
    const result = await ordersList()
    act(() => result.current.list.setPage(4))
    await until(() => expect(shown(result)).toBe('page 4 page=4'))

    act(() => result.current.list.setFilter('pending'))
    await until(() => expect(shown(result)).toBe('page 1 status=pending'))

    act(() => result.current.list.setPage(2))
    act(() => result.current.list.setParam('server', '7'))
    await until(() => expect(shown(result)).toBe('page 1 server=7&status=pending'))
  })

  it('goes back to the first page when a filter or a search is cleared, not to the page it left', async () => {
    const result = await ordersList()
    act(() => result.current.list.setPage(3))
    await until(() => expect(shown(result)).toBe('page 3 page=3'))

    act(() => result.current.list.setParam('server', '7'))
    await until(() => expect(shown(result)).toBe('page 1 server=7'))
    act(() => result.current.list.setParam('server', ''))
    await until(() => expect(shown(result)).toBe('page 1 '))
    expect(result.current.list.filtered).toBe(false)

    act(() => result.current.list.setPage(2))
    vi.useFakeTimers()
    type(result, 'amir')
    await act(() => vi.advanceTimersByTimeAsync(300))
    await until(() => expect(shown(result)).toBe('page 1 search=amir'))
    act(() => result.current.list.setTyped(''))
    await act(() => vi.advanceTimersByTimeAsync(300))
    await until(() => expect(shown(result)).toBe('page 1 '))
  })
})

describe('ordering', () => {
  it('reads the list in its own order without a word of it, and another from the first page', async () => {
    const result = await ordersList()
    expect(result.current.list.order).toEqual({ sort: 'created', dir: 'desc' })
    expect(result.current.list.ownOrder).toEqual({ sort: 'created', dir: 'desc' })
    act(() => result.current.list.setPage(3))
    await until(() => expect(shown(result)).toBe('page 3 page=3'))

    act(() => result.current.list.setOrder({ sort: 'amount', dir: 'desc' }))
    await until(() => expect(shown(result)).toBe('page 1 sort=amount&dir=desc'))
    expect(result.current.list.filtered).toBe(false)

    act(() => result.current.list.setOrder({ sort: 'created', dir: 'asc' }))
    await until(() => expect(shown(result)).toBe('page 1 sort=created&dir=asc'))

    act(() => result.current.list.setOrder({ sort: 'created', dir: 'desc' }))
    await until(() => expect(shown(result)).toBe('page 1 '))
  })

  it('keeps its order through a tab, a filter and a search, and turns pages in it', async () => {
    const result = await ordersList()
    act(() => result.current.list.setOrder({ sort: 'amount', dir: 'asc' }))
    act(() => result.current.list.setFilter('pending'))
    act(() => result.current.list.setParam('server', '7'))
    await until(() => expect(lastQuery()).toEqual({ server: '7', status: 'pending', sort: 'amount', dir: 'asc' }))

    act(() => result.current.list.setPage(2))
    await until(() => expect(lastQuery()).toEqual({ server: '7', status: 'pending', sort: 'amount', dir: 'asc', page: '2' }))

    vi.useFakeTimers()
    type(result, 'amir')
    await act(() => vi.advanceTimersByTimeAsync(300))
    await until(() => expect(lastQuery()).toEqual({ server: '7', search: 'amir', status: 'pending', sort: 'amount', dir: 'asc' }))
    expect(result.current.list.order).toEqual({ sort: 'amount', dir: 'asc' })
  })

  it('takes in the order a link to it carries — one of its keys, descending unless it says otherwise', async () => {
    const result = await ordersList('/orders?sort=amount&dir=asc')

    expect(lastQuery()).toEqual({ sort: 'amount', dir: 'asc' })
    expect(result.current.list.order).toEqual({ sort: 'amount', dir: 'asc' })

    act(() => void result.current.navigate('/orders?sort=amount'))
    await until(() => expect(lastQuery()).toEqual({ sort: 'amount', dir: 'desc' }))

    act(() => void result.current.navigate('/orders?sort=nonsense&dir=asc&status=failed'))
    await until(() => expect(lastQuery()).toEqual({ status: 'failed' }))
    expect(result.current.list.order).toEqual({ sort: 'created', dir: 'desc' })
  })

  it('reads its own order when a link names one it does not have — the address put right in place', async () => {
    const result = await ordersList('/orders?sort=nonsense&dir=asc')

    expect(lastQuery()).toEqual({})
    expect(result.current.list.order).toEqual({ sort: 'created', dir: 'desc' })
    await until(() => expect(at(result)).toBe('/orders'))
    expect(result.current.navigation).toBe('REPLACE')
  })
})

describe('a row an operation handed back', () => {
  it('takes its place wherever it is shown — the live updates read the list again, not the operation', async () => {
    const result = await ordersList()

    act(() => result.current.list.replace({ id: 1, name: 'paid' }))

    await until(() => expect(result.current.list.rows[0]?.name).toBe('paid'))
    expect(server().sent('GET', '/api/admin/orders')).toHaveLength(1)
  })

  it('that is gone leaves every page, and its count', async () => {
    const result = await ordersList()

    act(() => result.current.list.remove(1))

    await until(() => expect(result.current.list.rows).toEqual([]))
    expect(result.current.list.meta?.total).toBe(4)
    expect(server().sent('GET', '/api/admin/orders')).toHaveLength(1)
  })

  it('that was the last of a later page takes the list to the page before', async () => {
    const result = await ordersList('/orders?page=5')
    expect(lastQuery()).toEqual({ page: '5' })

    act(() => result.current.list.remove(5))

    await until(() => expect(lastQuery()).toEqual({ page: '4' }))
    await until(() => expect(at(result)).toBe('/orders?page=4'))
  })
})

/** How many times the list was read. */
const reads = () => server().sent('GET', '/api/admin/orders').length

describe('a list in a section kept mounted and hidden', () => {
  it('passes the live updates by, and reads afresh once its section is shown', async () => {
    server().on('GET', '/api/admin/orders', (request) => json(orders(request)))
    // The users page's two sections, the list in the first — opened on the second, where the list is hidden.
    const { wrapper: Providers, client } = providers({ at: '/users/groups' })
    const hook = renderHook(
      () => ({ list: usePagedList({ queryKey: ['orders'], read: (query) => api.get<OrdersPage>('/orders', query), list: 'orders', address: '/users' }), navigate: useNavigate() }),
      {
        wrapper: ({ children }) => (
          <Providers>
            <SectionedPage sections={USER_SECTIONS} header={(current) => ({ title: current.title })}>
              {{ users: children, groups: null }}
            </SectionedPage>
          </Providers>
        ),
      },
    )
    expect(reads()).toBe(0)

    act(() => void hook.result.current.navigate('/users'))
    await until(() => expect(hook.result.current.list.rows).toHaveLength(1))

    act(() => void hook.result.current.navigate('/users/groups'))
    await act(() => client.invalidateQueries({ queryKey: ['orders'] }))
    expect(reads()).toBe(1)

    act(() => void hook.result.current.navigate('/users'))
    await until(() => expect(reads()).toBe(2))
  })
})
