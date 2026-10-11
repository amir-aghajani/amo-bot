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
 * What a paged list shows, as one plain object (and what its address carries): the settled search, the status tab, the
 * other filters by query parameter ('' = none), the order (null for a list that does not sort) and the page.
 */
interface PagedView<F extends string, S extends string> {
  search: string
  status: F
  params: Record<string, string>
  order: ListOrder<S> | null
  page: number
}

/** The view, and whether its next change to the address pushes a history entry or replaces the last one. */
interface PagedState<F extends string, S extends string> {
  view: PagedView<F, S>
  entry: 'push' | 'replace'
}

/** The query parameters a list reads besides its filters. */
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
  /** The values a filter may take when they are a closed set (an enum, the orders' `type`); any other value is no filter. */
  choices?: Readonly<Record<string, readonly string[]>>
  /**
   * The keys the column headers sort by on the server (the API's `sort`, SortableHead). The first is the list's own
   * order, read descending (newest or largest first).
   */
  sorts?: readonly S[]
  /**
   * The list's own address. While the address bar shows it, the view lives in the query string — only what differs from
   * the default — so a reload, back and forward, a shared link or a link from another screen («#12», the dashboard's
   * queue) shows the same view. Values the list does not have are ignored and corrected in place. A list mounted in a
   * hidden section leaves the address bar alone, and keeps its view when shown again at its bare address.
   */
  address?: string
}

/**
 * A searchable, filterable, sortable, server-paged list (users, payments): the view (the search once typing pauses, the
 * status tab, the filters, the order, the page) kept in step with the list's address, and the read, which keeps the
 * page on screen while the next one loads. A new tab, filter, order or search goes back to page 1. In a hidden section
 * the read ignores live updates until shown.
 */
// K comes first: TypeScript infers it from `list` before `read` (whose parameter this hook types) gives R.
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

  /** A filter's value from an address: '' (no filter) for one outside its `choices`. */
  const filterOf = (name: string, value: string | null): string => (value === null || (choices[name] !== undefined && !choices[name].includes(value)) ? '' : value)

  /** The view an address names; what it leaves out, or names wrongly, takes the default. */
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

  /** The list's parameters of an address as written, in the order `queryOf` writes them. */
  const written = (query: URLSearchParams): string => {
    const words = new URLSearchParams()
    for (const name of [...params, ...VIEW_PARAMS]) {
      const value = query.get(name)
      if (value !== null) words.set(name, value)
    }
    return words.toString()
  }

  /** A view as a query; the status is left out when it is `quiet` (the server's «همه», or the address's default tab). */
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
  // The search box's own text; the list reads what it settles to.
  const [typed, setTyped] = useState(view.search)
  /** A change made on the list's screen: a history entry of its own. */
  const change = (changes: Partial<View>) => setState((current) => ({ view: taken(current.view, changes), entry: 'push' }))

  // The address changed under the list (back or forward, a link to it): show what it names (adjusted during render).
  // Shown again at its bare address (its section back on screen), it keeps its view and the address takes that instead.
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

  // The address follows the view while it shows the list. An address that already names this view but spells it
  // differently (`?status=` of a missing tab, `?page=1`) is corrected in place, without a history entry.
  const shownQuery = here ? new URLSearchParams(location.search) : null
  const wanted = here ? queryOf(view, blank.status).toString() : null
  const addressed = shownQuery === null ? null : written(shownQuery)
  const settling = shownQuery !== null && sameView(view, named(shownQuery))
  useEffect(() => {
    if (address === undefined || wanted === null || wanted === addressed) return
    void navigate({ pathname: address, search: wanted }, { replace: entry === 'replace' || settling })
  }, [address, wanted, addressed, entry, settling, navigate])

  // Typing reaches the list once it pauses, replacing the address's last entry rather than adding one per keystroke.
  useEffect(() => {
    const settled = typed.trim()
    if (settled === view.search) return
    const timer = window.setTimeout(() => setState((current) => ({ view: taken(current.view, { search: settled }), entry: 'replace' })), SEARCH_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [typed, view.search])

  const narrowing = Object.fromEntries(Object.entries(view.params).filter(([, value]) => value !== ''))
  // What the server is sent of the order: nothing while it is the list's own.
  const sorted = view.order !== null && !sameOrder(view.order, own) ? view.order : null
  const query = queryOf(view, '')

  const result = useQuery({
    queryKey: [...queryKey, { search: view.search, status: view.status, page: view.page, ...narrowing, ...sorted }],
    queryFn: () => read(query),
    placeholderData: keepPreviousData,
    // A list in a hidden section passes the live updates by, and reads afresh once it is shown.
    subscribed: shown,
  })

  // The page on screen lost its last rows (deleted) while the list still has some: step back a page.
  if (result.data && !result.isPlaceholderData && result.data[list].length === 0 && result.data.meta.total > 0 && view.page > 1) {
    setState((current) => ({ view: { ...current.view, page: current.view.page - 1 }, entry: 'replace' }))
  }

  const rows: Row[] = result.data ? [...result.data[list]] : []

  /** Edit every cached page (of any filter and order) that holds row `id`; the live updates refetch the list later. */
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
    /** A search, a tab or a filter narrows the list: an empty result means "nothing matched", not "nothing yet". */
    filtered: view.search !== '' || view.status !== '' || Object.keys(narrowing).length > 0,
    /** A search or a filter narrows the list (the tab aside): an empty tab gets its own empty message, not the search's. */
    narrowed: view.search !== '' || Object.keys(narrowing).length > 0,
    /** A row the server handed back (an operation's answer), put in its place wherever it is cached. */
    replace: (row: Row) => rewrite(row.id, (current) => ({ ...current, [list]: current[list].map((r) => (r.id === row.id ? row : r)) }) as R),
    /** A deleted row: out of every cached page and its count. */
    remove: (id: number) => rewrite(id, (current) => ({ ...current, [list]: current[list].filter((r) => r.id !== id), meta: { ...current.meta, total: current.meta.total - 1 } }) as R),
  }
}

/** `view` with `changes` (filters merged), back on page 1 when they narrow or order the list differently. */
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
