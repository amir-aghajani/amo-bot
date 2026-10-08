import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useLocation, useNavigate } from 'react-router'
import { useSectionShown } from '@/components/sectioned-page'
import type { PageMeta, SortDirection } from '@/lib/api-types'

/** How long typing pauses before the search reaches the list (and its address). */
const SEARCH_DELAY_MS = 300

/** The order a sorted list is read in: one of its API's `sort` keys, and which way. */
export interface ListOrder<S extends string = string> {
  sort: S
  dir: SortDirection
}

/**
 * What a paged list shows, as one plain object — what its address carries: the search as it settled, the status tab,
 * the other filters by query parameter ('' = not narrowed), the order (null for a list its headers do not sort) and the
 * page.
 */
interface PagedView<F extends string, S extends string> {
  search: string
  status: F
  params: Record<string, string>
  order: ListOrder<S> | null
  page: number
}

/** The view, and how the address takes its next change: a history entry of its own, or in place of the last one. */
interface PagedState<F extends string, S extends string> {
  view: PagedView<F, S>
  entry: 'push' | 'replace'
}

/** What an address names of a list: any of these is the list's own word. */
const VIEW_PARAMS = ['search', 'status', 'sort', 'dir', 'page']

interface UsePagedListOptions<R, K, F extends string, S extends string> {
  queryKey: QueryKey
  /** Reads one page; `query` holds what is set of the search, the status, the other filters, the order and the page. */
  read: (query: URLSearchParams) => Promise<R>
  /** Where the answer carries the rows ("orders"). */
  list: K
  /** The status tab's values ('' = all); the list starts on the first. */
  statuses?: readonly F[]
  /** The other filters the collection takes, by query parameter ('' = not narrowed): `['server']`. */
  params?: readonly string[]
  /** The values a filter of `params` may take, where they are a closed set (an enum's — the orders' `type`): anything else is no filter. */
  choices?: Readonly<Record<string, readonly string[]>>
  /**
   * The keys the list's column headers sort it by on the server (its API's `sort`, SortableHead); the list starts on the
   * first — its own order —, read descending: the newest, the largest first.
   */
  sorts?: readonly S[]
  /**
   * The list's own address. While the address bar shows it, the view is in its query string — only what differs from
   * the list's own: `search`, `status` (empty for «همه» when the list starts on another tab), one of `params`, `sort` and
   * `dir`, `page` —, so a reload, back and forward and a shared link keep the view, and a link from another screen
   * (its «#12», the dashboard's queue, a server's services) opens the list on it. What an address names that the list
   * does not have (a status it has no tab for, a value outside a filter's `choices`, an order it is not read in) is no
   * part of the view, and leaves the address in place. A list kept mounted elsewhere (a hidden section) leaves the
   * address bar alone; shown again at its bare address, it keeps the view it had.
   */
  address?: string
}

/**
 * The state of a searchable, filterable, sortable, paged list (users, payments) — what the admin types (the search once
 * it settles), the status tab, the other filters, the order its headers asked for and the page: one PagedView, in step
 * with the list's address (`address`) — and the read, which keeps the page on screen while the next one loads and, in a
 * section kept mounted and hidden, passes the live updates by until the section is on screen again. Another tab, filter
 * or order, or another search once it has settled, starts again from the first page.
 */
// K comes first and stands alone: it is read off `list` before `read` (an arrow whose parameter is typed by this hook) gives R.
export function usePagedList<K extends string, R extends { meta: PageMeta } & Record<K, readonly { id: number }[]>, F extends string = '', S extends string = never>({
  queryKey,
  read,
  list,
  statuses = ['' as F],
  params = [],
  choices = {},
  sorts = [],
  address,
}: UsePagedListOptions<R, K, F, S>) {
  type Row = R[K][number]
  type View = PagedView<F, S>
  const queryClient = useQueryClient()
  const location = useLocation()
  const navigate = useNavigate()
  const shown = useSectionShown()
  // The list the address bar shows: its view is the address's.
  const here = address !== undefined && location.pathname === address
  const [first] = sorts
  const own: ListOrder<S> | null = first === undefined ? null : { sort: first, dir: 'desc' }
  const blank: View = { search: '', status: statuses[0] as F, params: Object.fromEntries(params.map((name) => [name, ''])), order: own, page: 1 }

  /** A filter's value an address names: '' (no filter) for one outside its `choices`. */
  const filterOf = (name: string, value: string | null): string => (value === null || (choices[name] !== undefined && !choices[name].includes(value)) ? '' : value)

  /** The view an address names: what it leaves out — or names that the list does not have — is the list's own. */
  const named = (query: URLSearchParams): View => {
    const status = query.get('status')
    const sort = sorts.find((key) => key === query.get('sort'))
    const page = Number(query.get('page'))
    return {
      search: query.get('search')?.trim() ?? '',
      status: statuses.find((value) => value === status) ?? blank.status,
      params: Object.fromEntries(params.map((name) => [name, filterOf(name, query.get(name))])),
      order: sort === undefined ? own : { sort, dir: query.get('dir') === 'asc' ? 'asc' : 'desc' },
      page: Number.isSafeInteger(page) && page > 1 ? page : 1,
    }
  }

  /** The list's own words of an address, as written — in the order the list writes them (queryOf). */
  const written = (query: URLSearchParams): string => {
    const words = new URLSearchParams()
    for (const name of [...params, ...VIEW_PARAMS]) {
      const value = query.get(name)
      if (value !== null) words.set(name, value)
    }
    return words.toString()
  }

  /** A view as a query: what narrows, orders and pages it — the status unless it is `quiet` (the server's «همه», the address's first tab). */
  const queryOf = (view: View, quiet: string): URLSearchParams => {
    const query = new URLSearchParams()
    for (const name of params) {
      const value = view.params[name]
      if (value) query.set(name, value)
    }
    if (view.search !== '') query.set('search', view.search)
    if (view.status !== quiet) query.set('status', view.status)
    if (view.order !== null && !sameOrder(view.order, own)) {
      query.set('sort', view.order.sort)
      query.set('dir', view.order.dir)
    }
    if (view.page > 1) query.set('page', String(view.page))
    return query
  }

  const [state, setState] = useState<PagedState<F, S>>(() => ({ view: here ? named(new URLSearchParams(location.search)) : blank, entry: 'push' }))
  const { view, entry } = state
  // The search box's own text: the list reads what it settles to.
  const [typed, setTyped] = useState(view.search)
  /** A change made on the list's screen: a history entry of its own. */
  const change = (changes: Partial<View>) => setState((current) => ({ view: taken(current.view, changes), entry: 'push' }))

  // The address changed under a list it shows — back or forward, a link to it —: the list shows what the address names
  // (state adjusted during render). Shown again at its bare address (a section of a page back on screen), it keeps the
  // view it had instead, and the address takes that in place.
  const [seen, setSeen] = useState({ key: location.key, here })
  if (seen.key !== location.key) {
    setSeen({ key: location.key, here })
    if (here) {
      const query = new URLSearchParams(location.search)
      const linked = named(query)
      if (!seen.here && ![...VIEW_PARAMS, ...params].some((name) => query.has(name))) {
        setState((current) => ({ ...current, entry: 'replace' }))
      } else if (!sameView(linked, view)) {
        setState({ view: linked, entry: 'replace' })
        setTyped(linked.search)
      }
    }
  }

  // The address follows the view while it shows the list: only what differs from the list's own. An address that names
  // the view on screen in words the list does not take, or writes otherwise (`?status=` of a tab it has not, `?page=1`),
  // is put right in place: no history entry of its own.
  const shownQuery = here ? new URLSearchParams(location.search) : null
  const wanted = here ? queryOf(view, blank.status).toString() : null
  const addressed = shownQuery === null ? null : written(shownQuery)
  const settling = shownQuery !== null && sameView(view, named(shownQuery))
  useEffect(() => {
    if (address === undefined || wanted === null || wanted === addressed) return
    void navigate({ pathname: address, search: wanted }, { replace: entry === 'replace' || settling })
  }, [address, wanted, addressed, entry, settling, navigate])

  // Typing reaches the list once it pauses: the search settles — from the first page, in place of the address's last.
  useEffect(() => {
    const settled = typed.trim()
    if (settled === view.search) return
    const timer = window.setTimeout(() => setState((current) => ({ view: taken(current.view, { search: settled }), entry: 'replace' })), SEARCH_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [typed, view.search])

  const narrowing = Object.fromEntries(Object.entries(view.params).filter(([, value]) => value !== ''))
  // What the server is told of the order: nothing while it is the list's own.
  const sorted = view.order !== null && !sameOrder(view.order, own) ? view.order : null
  const query = queryOf(view, '')

  const result = useQuery({
    queryKey: [...queryKey, { search: view.search, status: view.status, page: view.page, ...narrowing, ...sorted }],
    queryFn: () => read(query),
    placeholderData: keepPreviousData,
    // A list in a hidden section passes the live updates by, and reads afresh once it is shown.
    subscribed: shown,
  })

  // The last rows of the page on screen are gone (deleted) while the list still has some: the page before it (state
  // adjusted during render).
  if (result.data && !result.isPlaceholderData && result.data[list].length === 0 && result.data.meta.total > 0 && view.page > 1) {
    setState((current) => ({ view: { ...current.view, page: current.view.page - 1 }, entry: 'replace' }))
  }

  const rows: Row[] = result.data ? [...result.data[list]] : []

  /** Edit the cached pages — of every filter and order — that hold row `id`; the live updates read the list again. */
  const rewrite = (id: number, edit: (current: R) => R) => queryClient.setQueriesData<R>({ queryKey }, (current) => (current?.[list].some((row) => row.id === id) ? edit(current) : current))

  return {
    rows,
    meta: result.data?.meta as R['meta'] | undefined,
    isPending: result.isPending,
    isPlaceholderData: result.isPlaceholderData,
    error: result.error,
    refetch: () => void queryClient.invalidateQueries({ queryKey }),
    typed,
    setTyped,
    filter: view.status,
    setFilter: (status: F) => change({ status }),
    params: view.params,
    setParam: (name: string, value: string) => change({ params: { [name]: value } }),
    /** The order the list is read in now, and its own; null for a list its headers do not sort. */
    order: view.order,
    ownOrder: own,
    setOrder: (order: ListOrder<S>) => change({ order }),
    setPage: (page: number) => change({ page }),
    /** True once a search, a tab or a filter narrows the list (an empty result then means "nothing matched", not "nothing yet"). */
    filtered: view.search !== '' || view.status !== '' || Object.keys(narrowing).length > 0,
    /** True once a search or a filter narrows the list, its status tab aside: an empty tab says so in its own words, not the search's. */
    narrowed: view.search !== '' || Object.keys(narrowing).length > 0,
    /** A row the server handed back (an operation's answer): in its place wherever it is shown. */
    replace: (row: Row) => rewrite(row.id, (current) => ({ ...current, [list]: current[list].map((r) => (r.id === row.id ? row : r)) }) as R),
    /** A row that is gone (deleted): out of every page, and out of its count. */
    remove: (id: number) => rewrite(id, (current) => ({ ...current, [list]: current[list].filter((r) => r.id !== id), meta: { ...current.meta, total: current.meta.total - 1 } }) as R),
  }
}

/** `view` with `changes` in it — the other filters merged —, from the first page when they narrow or order the list another way. */
function taken<F extends string, S extends string>(view: PagedView<F, S>, changes: Partial<PagedView<F, S>>): PagedView<F, S> {
  const next = { ...view, ...changes, params: { ...view.params, ...changes.params } }
  const moved = next.search !== view.search || next.status !== view.status || !sameParams(next.params, view.params) || !sameOrder(next.order, view.order)

  return moved ? { ...next, page: 1 } : next
}

function sameView<F extends string, S extends string>(a: PagedView<F, S>, b: PagedView<F, S>): boolean {
  return a.search === b.search && a.status === b.status && sameParams(a.params, b.params) && sameOrder(a.order, b.order) && a.page === b.page
}

function sameParams(a: Record<string, string>, b: Record<string, string>): boolean {
  return Object.keys({ ...a, ...b }).every((name) => (a[name] ?? '') === (b[name] ?? ''))
}

function sameOrder(a: ListOrder | null, b: ListOrder | null): boolean {
  return a?.sort === b?.sort && a?.dir === b?.dir
}
