import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import type { SubscriptionResponse, SubscriptionRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useOpenRow } from '@/lib/use-open-row'
import { providers, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The row a list page has open in its modal (lib/use-open-row): the list's copy at once, then the row's own read — which
 * the live updates reload —, so the modal follows its row even after it left the list; an operation's answer goes into
 * both at once; a refusal because it moved on reads both again; and a row deleted meanwhile is `gone`.
 */

const LISTED = subscriptionRow({ id: 4, status: 'active' })

function opened() {
  const list = { replace: vi.fn<(row: SubscriptionRow) => void>(), refetch: vi.fn<() => void>() }
  const { client, wrapper } = providers()
  const hook = renderHook(
    () =>
      useOpenRow({
        queryKey: queryKeys.subscription,
        read: (id) => api.get<SubscriptionResponse>(`/subscriptions/${id}`).then((data) => data.subscription),
        list,
      }),
    { wrapper },
  )
  return { ...hook, list, client }
}

describe('an open row', () => {
  it('is the list’s copy until its own read comes, then the read', async () => {
    const read = server().hold('GET', '/api/admin/subscriptions/4')
    const { result } = opened()
    expect(result.current.row).toBeNull()

    act(() => result.current.open(LISTED))
    expect(result.current.row).toBe(LISTED)

    await until(() => expect(read.waiting).toBe(1))
    await act(async () => read.answer(json({ subscription: { ...LISTED, status: 'disabled' } })))
    await until(() => expect(result.current.row?.status).toBe('disabled'))
  })

  it('takes an operation’s answer at once, into the list too', async () => {
    server().on('GET', '/api/admin/subscriptions/4', json({ subscription: LISTED }))
    const { result, list } = opened()
    act(() => result.current.open(LISTED))
    await until(() => expect(server().sent('GET', '/api/admin/subscriptions/4')).toHaveLength(1))

    const synced = { ...LISTED, traffic: { limit: 0, used: 1 } }
    act(() => result.current.apply(synced))

    expect(result.current.row).toEqual(synced)
    expect(list.replace).toHaveBeenCalledWith(synced)
  })

  it('leaves the modal alone when the answer is another row’s, and closes on null', async () => {
    server().on('GET', '/api/admin/subscriptions/4', json({ subscription: LISTED }))
    const { result, list } = opened()
    act(() => result.current.open(LISTED))
    await until(() => expect(server().sent('GET', '/api/admin/subscriptions/4')).toHaveLength(1))

    act(() => result.current.apply(subscriptionRow({ id: 9 })))
    expect(result.current.row?.id).toBe(4)
    expect(list.replace).toHaveBeenCalledTimes(1)

    act(() => result.current.open(null))
    expect(result.current.row).toBeNull()
  })

  it('is gone once its own read says it was deleted meanwhile — the modal keeps what it last showed', async () => {
    server().on('GET', '/api/admin/subscriptions/4', refusal(404, 'پیدا نشد.'))
    const { result } = opened()

    act(() => result.current.open(LISTED))

    await until(() => expect(result.current.gone).toBe(true))
    expect(result.current.row).toBe(LISTED)
  })

  it('reads itself and its list again when the server refused because it moved on', async () => {
    server().on('GET', '/api/admin/subscriptions/4', json({ subscription: LISTED }))
    const { result, list } = opened()
    act(() => result.current.open(LISTED))
    // Its own read is in: the modal shows that copy, not the list's.
    await until(() => expect(result.current.row).not.toBe(LISTED))

    server().on('GET', '/api/admin/subscriptions/4', json({ subscription: { ...LISTED, status: 'disabled' } }))
    act(() => result.current.refresh())

    await until(() => expect(result.current.row?.status).toBe('disabled'))
    expect(list.refetch).toHaveBeenCalledTimes(1)
    expect(result.current.gone).toBe(false)
  })
})
