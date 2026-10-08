import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ListView, SortableHead } from '@/components/list-view'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { ApiError } from '@/lib/api'
import type { PageMeta, SortDirection } from '@/lib/api-types'
import { FAILURE_KINDS } from '@/lib/failure'
import type { ListOrder } from '@/lib/use-paged-list'

/*
 * A list page's table in exactly one state (components/list-view): loading, its read's failure with a way to ask again,
 * nothing to list — or nothing matching what narrows it —, or the rows, named after what it lists; a paged one with its
 * pagination — first, previous, a page typed in, next, last —, dimmed while the next page loads, rows a later read
 * failed to refresh kept under the failure, and a page whose last rows were deleted saying nothing until it is read again.
 */

const ROW = { id: 1 }
const META: PageMeta = { page: 2, per_page: 20, total: 45, last_page: 3 }
const NONE: PageMeta = { page: 1, per_page: 20, total: 0, last_page: 1 }

function list(state: Partial<{ rows: readonly unknown[]; isPending: boolean; error: Error | null }> = {}) {
  return { rows: [], isPending: false, error: null, refetch: vi.fn(), ...state }
}

function paged(state: Partial<{ rows: readonly unknown[]; meta: PageMeta; filtered: boolean; isPlaceholderData: boolean }> = {}) {
  return { ...list(), meta: META, isPlaceholderData: false, setPage: vi.fn(), filtered: false, ...state }
}

function view(state: Parameters<typeof ListView>[0]['list']) {
  return render(
    <ListView list={state} noun="کاربران" unit="کاربر" empty={<p>هنوز کاربری نیست</p>} noMatch={<p>کاربری پیدا نشد</p>}>
      <table aria-label="جدول" />
    </ListView>,
  )
}

describe('a list', () => {
  it('shows its loading state while the first read runs', () => {
    view(list({ isPending: true }))

    expect(screen.getByLabelText('در حال بارگذاری کاربران').getAttribute('aria-busy')).toBe('true')
    expect(screen.queryByRole('table')).toBeNull()
  })

  it('whose read failed says so in lib/failure’s words, with a way to ask again', () => {
    const state = list({ error: new ApiError(500, 'خطای سرور') })
    view(state)

    expect(screen.getByRole('alert').textContent).toContain(`لیست کاربران بارگذاری نشد. ${FAILURE_KINDS.server.description}`)
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(state.refetch).toHaveBeenCalledTimes(1)
  })

  it('keeps the rows a later read failed to refresh, under the failure', () => {
    view(list({ rows: [ROW], error: new ApiError(0, 'ارتباط با سرور برقرار نشد.') }))

    expect(screen.getByRole('alert')).toBeTruthy()
    expect(screen.getByRole('table')).toBeTruthy()
  })

  it('asked again after its read failed keeps the failure on screen, its «تلاش دوباره» turning with the focus on it, until the read answers', () => {
    const failed = list({ error: new ApiError(500, 'خطای سرور') })
    const { rerender } = view(failed)
    const retry = screen.getByRole('button', { name: 'تلاش دوباره' })

    retry.focus()
    fireEvent.click(retry)
    expect(failed.refetch).toHaveBeenCalledTimes(1)
    // The read starts again as pending, its error gone (react-query's way with a read that has nothing to show).
    const asking = { ...failed, isPending: true, error: null }
    rerender(
      <ListView list={asking} noun="کاربران" unit="کاربر" empty={<p>هنوز کاربری نیست</p>}>
        <table aria-label="جدول" />
      </ListView>,
    )

    expect(screen.queryByLabelText('در حال بارگذاری کاربران')).toBeNull()
    expect(screen.getByRole('alert').textContent).toContain('لیست کاربران بارگذاری نشد.')
    expect(document.activeElement).toBe(retry)
    expect([retry.getAttribute('aria-busy'), retry.getAttribute('aria-disabled')]).toEqual(['true', 'true'])

    rerender(
      <ListView list={{ ...asking, isPending: false, rows: [ROW] }} noun="کاربران" unit="کاربر">
        <table aria-label="جدول" />
      </ListView>,
    )
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.getByRole('table')).toBeTruthy()
  })

  it('asked again after another view’s read failed keeps the failure and its focus — never the rows of the view before, which stand in while the read runs', () => {
    const failed = { ...paged({ rows: [] }), error: new ApiError(500, 'خطای سرور') }
    const { rerender } = view(failed)
    const retry = screen.getByRole('button', { name: 'تلاش دوباره' })

    retry.focus()
    fireEvent.click(retry)
    expect(failed.refetch).toHaveBeenCalledTimes(1)
    // A paged list keeps the previous view's rows as react-query's placeholder while this view's read runs again.
    const asking = { ...failed, error: null, rows: [ROW], isPlaceholderData: true }
    rerender(
      <ListView list={asking} noun="کاربران" unit="کاربر" empty={<p>هنوز کاربری نیست</p>}>
        <table aria-label="جدول" />
      </ListView>,
    )

    expect(screen.queryByRole('table')).toBeNull()
    expect(screen.getByRole('alert').textContent).toContain('لیست کاربران بارگذاری نشد.')
    expect(document.activeElement).toBe(retry)
    expect(retry.getAttribute('aria-busy')).toBe('true')

    // Failed again: the same failure, the focus where it was.
    rerender(
      <ListView list={{ ...asking, rows: [], isPlaceholderData: false, error: new ApiError(500, 'خطای سرور') }} noun="کاربران" unit="کاربر">
        <table aria-label="جدول" />
      </ListView>,
    )
    expect(screen.getByRole('alert')).toBeTruthy()
    expect(document.activeElement).toBe(retry)
    expect(retry.getAttribute('aria-busy')).toBeNull()

    // Answered: this view's own rows.
    rerender(
      <ListView list={{ ...asking, error: null, isPlaceholderData: false }} noun="کاربران" unit="کاربر">
        <table aria-label="جدول" />
      </ListView>,
    )
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.getByRole('table')).toBeTruthy()
  })

  it('with nothing in it says so — and that nothing matched when something narrows it', () => {
    const { unmount } = view(paged({ rows: [], meta: NONE }))
    expect(screen.getByText('هنوز کاربری نیست')).toBeTruthy()
    unmount()

    view(paged({ rows: [], meta: NONE, filtered: true }))
    expect(screen.getByText('کاربری پیدا نشد')).toBeTruthy()
  })

  it('names its table after what it lists, for assistive tech', () => {
    render(
      <ListView list={list({ rows: [ROW] })} noun="کاربران">
        <Table>
          <TableBody>
            <TableRow>
              <TableCell>امیر</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </ListView>,
    )

    expect(screen.getByRole('table', { name: 'کاربران' })).toBeTruthy()
  })
})

/** The pagination's field for a page's number. */
const pageField = () => screen.getByRole('textbox', { name: 'برو به صفحه' }) as HTMLInputElement

describe('a paged list', () => {
  it('counts its rows and pages under them, and turns the page — the next, the previous, the first, the last', () => {
    const state = paged({ rows: [ROW] })
    view(state)

    expect(screen.getByText('۴۵ کاربر')).toBeTruthy()
    expect(pageField().value).toBe('۲')
    expect(screen.getByText('از ۳')).toBeTruthy()
    for (const name of ['صفحه بعد', 'صفحه قبل', 'صفحه اول', 'صفحه آخر']) fireEvent.click(screen.getByRole('button', { name }))
    expect(state.setPage.mock.calls).toEqual([[3], [1], [1], [3]])
  })

  it('goes to a page typed in — Persian digits too — on Enter or as the field is left, held to the list’s pages', () => {
    const state = paged({ rows: [ROW] })
    view(state)

    fireEvent.change(pageField(), { target: { value: '۳' } })
    fireEvent.keyDown(pageField(), { key: 'Enter' })
    fireEvent.change(pageField(), { target: { value: '99' } })
    fireEvent.blur(pageField())
    expect(state.setPage.mock.calls).toEqual([[3], [3]])

    // Nothing to go to: the field shows the page on screen again.
    fireEvent.change(pageField(), { target: { value: 'صفر' } })
    fireEvent.keyDown(pageField(), { key: 'Enter' })
    fireEvent.change(pageField(), { target: { value: '2' } })
    fireEvent.blur(pageField())
    expect(state.setPage).toHaveBeenCalledTimes(2)
    expect(pageField().value).toBe('۲')

    fireEvent.change(pageField(), { target: { value: '1' } })
    fireEvent.keyDown(pageField(), { key: 'Escape' })
    expect(pageField().value).toBe('۲')
  })

  it('keeps the focus in the page’s field as the page typed in comes', () => {
    const state = paged({ rows: [ROW] })
    const { rerender } = view(state)
    const field = pageField()

    field.focus()
    fireEvent.change(field, { target: { value: '3' } })
    fireEvent.keyDown(field, { key: 'Enter' })
    expect(state.setPage).toHaveBeenCalledWith(3)
    rerender(
      <ListView list={{ ...state, meta: { ...META, page: 3 } }} noun="کاربران" unit="کاربر">
        <table aria-label="جدول" />
      </ListView>,
    )

    expect(pageField()).toBe(field)
    expect(document.activeElement).toBe(field)
    expect(field.value).toBe('۳')
  })

  it('makes every control a finger’s size on a phone, and leaves the desktop’s as they are', () => {
    view(paged({ rows: [ROW] }))

    expect(screen.getByRole('button', { name: 'صفحه بعد' }).className).toMatch(/(^| )size-7( |$).*max-md:size-11|max-md:size-11.*(^| )size-7( |$)/)
    expect(pageField().className).toContain('max-md:h-11')
  })

  it('keeps the focus on a button with nowhere to go, which does nothing', () => {
    const state = paged({ rows: [ROW], meta: { ...META, page: 3 } })
    view(state)
    const last = screen.getByRole('button', { name: 'صفحه آخر' })

    last.focus()
    fireEvent.click(last)

    expect(last.getAttribute('aria-disabled')).toBe('true')
    expect(document.activeElement).toBe(last)
    expect(state.setPage).not.toHaveBeenCalled()
  })

  it('dims the page on screen while the next one loads, and holds the pagination meanwhile', () => {
    const state = paged({ rows: [ROW], isPlaceholderData: true })
    view(state)

    expect(screen.getByRole('table').parentElement?.className).toContain('opacity-60')
    fireEvent.click(screen.getByRole('button', { name: 'صفحه بعد' }))
    expect(screen.getByRole('button', { name: 'صفحه بعد' }).getAttribute('aria-disabled')).toBe('true')
    expect(state.setPage).not.toHaveBeenCalled()
  })

  it('says nothing of a page whose last rows were deleted while it has rows elsewhere, until it is read again', () => {
    view(paged({ rows: [] }))

    expect(screen.getByLabelText('در حال بارگذاری کاربران').getAttribute('aria-busy')).toBe('true')
    expect(screen.queryByText('هنوز کاربری نیست')).toBeNull()
  })
})

/** The list's own order: the newest first. */
const OWN: ListOrder = { sort: 'created', dir: 'desc' }

/** A column's sortable header over a list read in `order`; what a press asks for lands in `setOrder`. */
function header(order: ListOrder, props: { by?: string; first?: SortDirection; label?: string; words?: string } = {}) {
  const setOrder = vi.fn()
  const { by = 'amount', first, label, words = 'مبلغ' } = props
  render(
    <table>
      <thead>
        <tr>
          <SortableHead list={{ order, ownOrder: OWN, setOrder }} by={by} first={first} label={label}>
            {words}
          </SortableHead>
        </tr>
      </thead>
    </table>,
  )
  return { setOrder, th: screen.getByRole('columnheader'), button: screen.getByRole('button') }
}

describe('a sortable header', () => {
  it('says nothing of a column the list is not sorted by, and a first press reads the list by it, the largest first', () => {
    const { setOrder, th, button } = header(OWN)

    expect(th.hasAttribute('aria-sort')).toBe(false)
    expect(button.getAttribute('aria-label')).toBe('مرتب‌سازی بر اساس مبلغ')
    fireEvent.click(button)
    expect(setOrder).toHaveBeenCalledWith({ sort: 'amount', dir: 'desc' })
  })

  it('reads soonest first on a first press when the column says so (an end)', () => {
    const { setOrder, button } = header(OWN, { by: 'expires', first: 'asc', words: 'پایان' })

    fireEvent.click(button)
    expect(setOrder).toHaveBeenCalledWith({ sort: 'expires', dir: 'asc' })
  })

  it('says which way the list runs; the next press turns it around, the one after gives the list its own order back', () => {
    const descending = header({ sort: 'amount', dir: 'desc' })
    expect(descending.th.getAttribute('aria-sort')).toBe('descending')
    fireEvent.click(descending.button)
    expect(descending.setOrder).toHaveBeenCalledWith({ sort: 'amount', dir: 'asc' })
    cleanup()

    const ascending = header({ sort: 'amount', dir: 'asc' })
    expect(ascending.th.getAttribute('aria-sort')).toBe('ascending')
    fireEvent.click(ascending.button)
    expect(ascending.setOrder).toHaveBeenCalledWith(OWN)
  })

  it('turns the list’s own order around and back again, never off', () => {
    const own = header(OWN, { by: 'created', words: 'زمان' })
    expect(own.th.getAttribute('aria-sort')).toBe('descending')
    fireEvent.click(own.button)
    expect(own.setOrder).toHaveBeenCalledWith({ sort: 'created', dir: 'asc' })
    cleanup()

    const reversed = header({ sort: 'created', dir: 'asc' }, { by: 'created', words: 'زمان' })
    fireEvent.click(reversed.button)
    expect(reversed.setOrder).toHaveBeenCalledWith(OWN)
  })

  it('names what it sorts by when its words do not, and is a button the keyboard reaches', () => {
    const { button } = header(OWN, { by: 'traffic', label: 'حجم ربات', words: 'ربات' })

    expect(button.getAttribute('aria-label')).toBe('مرتب‌سازی بر اساس حجم ربات')
    expect(button.textContent).toBe('ربات')
    expect(button.getAttribute('type')).toBe('button')
    button.focus()
    expect(document.activeElement).toBe(button)
  })
})
