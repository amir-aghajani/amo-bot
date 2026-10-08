import { useState } from 'react'
import { useIsFetching } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { api } from '@/lib/api'
import type { PageMeta } from '@/lib/api-types'
import { useNextRow } from '@/lib/use-next-row'
import { usePagedList } from '@/lib/use-paged-list'
import { providers, until } from '@/test/render'
import { json, server, type SentRequest } from '@/test/server'

/*
 * «بعدی» of a list's dialog (lib/use-next-row): the row after the open one in the list's own order — the next page's
 * first at the end of a page —, nothing after the last; when a decision took the open row out of the list, the row that
 * came to its place; and a press while the list is being read again waits for it.
 */

interface Row {
  id: number
}

/** A queue's page as the payments list answers it — the rows reduced to what «بعدی» reads of them. */
interface RowsPage {
  payments: Row[]
  meta: PageMeta
}

const PER_PAGE = 3

/** The queue the server holds now, newest first, paged three at a time. */
let queue: number[] = []

function page(request: SentRequest): RowsPage {
  const number = Number(request.query.get('page') ?? 1)
  const payments = queue.slice((number - 1) * PER_PAGE, number * PER_PAGE).map((id) => ({ id }))
  return { payments, meta: { page: number, per_page: PER_PAGE, total: queue.length, last_page: Math.max(1, Math.ceil(queue.length / PER_PAGE)) } }
}

/** A list and its dialog: the row open, and «بعدی». */
async function opened(ids: number[]) {
  queue = ids
  server().on('GET', '/api/admin/payments', (request) => json(page(request)))
  const { wrapper } = providers()
  const hook = renderHook(
    () => {
      const list = usePagedList({ queryKey: ['queue'], read: (query) => api.get<RowsPage>('/payments', query), list: 'payments' })
      const [open, setOpen] = useState<Row | null>(null)
      // What the screen shows of a read under way: the list being read again.
      const reading = useIsFetching({ queryKey: ['queue'] }) > 0
      return { list, open, setOpen, reading, next: useNextRow({ queryKey: ['queue'], list, open, onOpen: setOpen }) }
    },
    { wrapper },
  )
  await until(() => expect(hook.result.current.list.rows.length).toBeGreaterThan(0))
  return hook.result
}

type Hook = Awaited<ReturnType<typeof opened>>

const open = (result: Hook, id: number) => act(() => result.current.setOpen({ id }))
const press = (result: Hook) => act(() => result.current.next.go())

describe('the next row', () => {
  it('is the one after in the list, then the next page’s first, and none after the last', async () => {
    const result = await opened([9, 8, 7, 6, 5])
    open(result, 8)

    press(result)
    expect(result.current.open?.id).toBe(7)

    press(result)
    expect(result.current.next.busy).toBe(true)
    await until(() => expect(result.current.open?.id).toBe(6))
    expect(result.current.list.meta?.page).toBe(2)
    expect(result.current.next.busy).toBe(false)

    open(result, 5)
    expect(result.current.next.available).toBe(false)
  })

  it('is, once a decision took the open row out of the list, the row that came to its place', async () => {
    const result = await opened([9, 8, 7, 6])
    open(result, 8)
    expect(result.current.next.available).toBe(true)

    // Reviewed: out of the review queue.
    queue = [9, 7, 6]
    act(() => result.current.list.refetch())
    await until(() => expect(result.current.list.rows.map((row) => row.id)).toEqual([9, 7, 6]))

    press(result)
    expect(result.current.open?.id).toBe(7)
  })

  it('waits, pressed while the list is read again, for the list as it is now', async () => {
    const result = await opened([9, 8, 7, 6])
    open(result, 9)
    const held = server().hold('GET', '/api/admin/payments')
    queue = [8, 7, 6]
    act(() => result.current.list.refetch())
    await until(() => expect([held.waiting, result.current.reading]).toEqual([1, true]))

    press(result)
    expect(result.current.next.busy).toBe(true)
    expect(result.current.open?.id).toBe(9)

    held.answer(json({ payments: [{ id: 8 }, { id: 7 }, { id: 6 }], meta: { page: 1, per_page: PER_PAGE, total: 3, last_page: 1 } }))
    await until(() => expect(result.current.open?.id).toBe(8))
    expect(result.current.next.busy).toBe(false)
  })
})
