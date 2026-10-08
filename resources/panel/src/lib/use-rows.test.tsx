import type { QueryKey } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SectionedPage } from '@/components/sectioned-page'
import { USER_SECTIONS } from '@/components/shell/nav'
import { api } from '@/lib/api'
import type { ActivationRequest, PlanRow, PlansResponse } from '@/lib/api-types'
import { plansQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'
import { useRows } from '@/lib/use-rows'
import { providers, until } from '@/test/render'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * An admin-ordered list (lib/use-rows): every change goes into the cached copy as a change of what is there now — at
 * once, before the server answers — so two quick ones never undo each other, and reaches the server after the one before
 * it; a refused one is put back and the list read again, and the admin is told why; one done has what else shows the
 * rows read again. In a section kept mounted and hidden the list reads nothing until it is shown.
 */

const plan = (id: number, name: string, overrides: Partial<PlanRow> = {}) => ({ id, name, is_active: true, ...overrides }) as PlanRow

const GOLD = plan(1, 'Gold')
const SILVER = plan(2, 'Silver')
const BRONZE = plan(3, 'Bronze')

/** The plans list as the plans page has it: its read, and its writes. */
function usePlans(invalidates?: readonly QueryKey[]) {
  return useRows({
    query: plansQuery,
    list: 'plans',
    reorder: (ids) => api.post('/plans/reorder', { ids }),
    remove: (row) => api.delete(`/plans/${row.id}`),
    patch: (row, changes: ActivationRequest) => api.patch(`/plans/${row.id}`, changes).then((answer) => answer.plan),
    invalidates,
  })
}

async function plansList(plans: PlanRow[] = [GOLD, SILVER, BRONZE]) {
  server().on('GET', '/api/admin/plans', json({ plans } satisfies PlansResponse))
  const { wrapper } = providers()
  const hook = renderHook(() => usePlans(), { wrapper })
  await until(() => expect(hook.result.current.rows).toHaveLength(plans.length))
  return hook.result
}

const names = (rows: readonly PlanRow[]) => rows.map((row) => row.name)

beforeEach(() => {
  vi.spyOn(toast, 'error')
})

describe('a field flipped in a row', () => {
  it('changes on screen at once, then takes the row the server answered', async () => {
    const rows = await plansList()
    const held = server().hold('PATCH', '/api/admin/plans/1')

    act(() => rows.current.patch.mutate({ row: GOLD, changes: { is_active: false } }))
    await until(() => expect(rows.current.rows[0]?.is_active).toBe(false))
    expect(server().sent('PATCH', '/api/admin/plans/1')[0]?.body).toEqual({ is_active: false })

    await act(async () => held.answer(json({ plan: { ...GOLD, is_active: false, sort: 9 } })))
    await until(() => expect(rows.current.rows[0]?.sort).toBe(9))
  })

  it('shows two quick changes at once, and sends them in the order they were made — each once the one before is answered', async () => {
    const rows = await plansList()
    const gold = server().hold('PATCH', '/api/admin/plans/1')
    const silver = server().hold('PATCH', '/api/admin/plans/2')

    act(() => rows.current.patch.mutate({ row: GOLD, changes: { is_active: false } }))
    act(() => rows.current.patch.mutate({ row: SILVER, changes: { is_active: false } }))
    await until(() => expect(gold.waiting).toBe(1))
    expect(rows.current.rows.map((row) => row.is_active)).toEqual([false, false, true])
    expect(silver.waiting).toBe(0)

    await act(async () => gold.answer(json({ plan: { ...GOLD, is_active: false } })))
    await until(() => expect(silver.waiting).toBe(1))
    await act(async () => silver.answer(json({ plan: { ...SILVER, is_active: false } })))

    await until(() => expect(rows.current.rows.map((row) => row.is_active)).toEqual([false, false, true]))
  })

  it('switched twice in a row ends on the server where it ends on the screen', async () => {
    const rows = await plansList()
    server().on('PATCH', '/api/admin/plans/1', (request) => json({ plan: { ...GOLD, ...(request.body as Partial<PlanRow>) } }))

    act(() => rows.current.patch.mutate({ row: GOLD, changes: { is_active: false } }))
    act(() => rows.current.patch.mutate({ row: { ...GOLD, is_active: false }, changes: { is_active: true } }))

    await until(() =>
      expect(
        server()
          .sent('PATCH', '/api/admin/plans/1')
          .map((request) => request.body),
      ).toEqual([{ is_active: false }, { is_active: true }]),
    )
    await until(() => expect(rows.current.rows[0]?.is_active).toBe(true))
  })

  it('is put back when the server refuses it, the list read again and the admin told why', async () => {
    const rows = await plansList()
    server().on('PATCH', '/api/admin/plans/1', refusal(422, 'این پلن فروش دارد.', { is_active: ['این پلن فروش دارد.'] }))

    act(() => rows.current.patch.mutate({ row: GOLD, changes: { is_active: false } }))

    await until(() => expect(server().sent('GET', '/api/admin/plans')).toHaveLength(2))
    expect(rows.current.rows[0]?.is_active).toBe(true)
    expect(toast.error).toHaveBeenCalledWith('این پلن فروش دارد.', { description: undefined })
  })
})

describe('the order', () => {
  it('swaps a row with its neighbour at once and takes the order the server answered', async () => {
    const rows = await plansList()
    const held = server().hold('POST', '/api/admin/plans/reorder')

    act(() => rows.current.move(0, 1))

    await until(() => expect(names(rows.current.rows)).toEqual(['Silver', 'Gold', 'Bronze']))
    expect(server().sent('POST', '/api/admin/plans/reorder')[0]?.body).toEqual({ ids: [2, 1, 3] })

    await act(async () => held.answer(json({ plans: [SILVER, GOLD, { ...BRONZE, name: 'Bronze (renamed)' }] })))
    await until(() => expect(names(rows.current.rows)).toEqual(['Silver', 'Gold', 'Bronze (renamed)']))
  })

  it('does not move the first row up or the last one down', async () => {
    const rows = await plansList()

    act(() => rows.current.move(0, -1))
    act(() => rows.current.move(2, 1))

    expect(server().sent('POST', '/api/admin/plans/reorder')).toEqual([])
    expect(names(rows.current.rows)).toEqual(['Gold', 'Silver', 'Bronze'])
  })

  it('keeps a row the new order does not name (added meanwhile) at the end', async () => {
    const rows = await plansList()
    server().hold('POST', '/api/admin/plans/reorder')

    act(() => rows.current.reorder.mutate([3, 1]))

    await until(() => expect(names(rows.current.rows)).toEqual(['Bronze', 'Gold', 'Silver']))
  })

  it('reads the list again when the server refuses the order', async () => {
    const rows = await plansList()
    server().on('POST', '/api/admin/plans/reorder', refusal(422, 'Refused.'))

    act(() => rows.current.move(1, -1))

    await until(() => expect(server().sent('GET', '/api/admin/plans')).toHaveLength(2))
    await until(() => expect(names(rows.current.rows)).toEqual(['Gold', 'Silver', 'Bronze']))
  })
})

describe('a row', () => {
  it('leaves the list once the server deleted it', async () => {
    const rows = await plansList()
    const held = server().hold('DELETE', '/api/admin/plans/2')

    act(() => rows.current.remove.mutate(SILVER))
    await until(() => expect(held.waiting).toBe(1))
    expect(names(rows.current.rows)).toEqual(['Gold', 'Silver', 'Bronze'])

    await act(async () => held.answer(noContent))
    await until(() => expect(names(rows.current.rows)).toEqual(['Gold', 'Bronze']))
  })

  it('saved by its form takes its place, or joins the list at the end when it is new', async () => {
    const rows = await plansList()

    act(() => rows.current.upsert({ ...SILVER, name: 'Silver+' }))
    act(() => rows.current.upsert(plan(4, 'Platinum')))

    await until(() => expect(names(rows.current.rows)).toEqual(['Gold', 'Silver+', 'Bronze', 'Platinum']))
  })
})

describe('what else shows the rows', () => {
  it('is read again after each of the list’s writes — not after one refused —, and handed back for its form’s save', async () => {
    server()
      .on('GET', '/api/admin/plans', json({ plans: [GOLD, SILVER] } satisfies PlansResponse))
      .on('PATCH', '/api/admin/plans/1', json({ plan: { ...GOLD, is_active: false } }))
      .on('POST', '/api/admin/plans/reorder', json({ plans: [SILVER, GOLD] }))
      .on('DELETE', '/api/admin/plans/2', refusal(409, 'این پلن فروش دارد.'))
    const { client, wrapper } = providers()
    const invalidated = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => usePlans([queryKeys.planOptions]), { wrapper })
    await until(() => expect(result.current.rows).toHaveLength(2))
    const elsewhere = () => invalidated.mock.calls.map(([filters]) => filters?.queryKey).filter((key) => key === queryKeys.planOptions).length

    await act(() => result.current.patch.mutateAsync({ row: GOLD, changes: { is_active: false } }))
    expect(elsewhere()).toBe(1)
    await act(() => result.current.reorder.mutateAsync([2, 1]))
    expect(elsewhere()).toBe(2)
    await act(() => result.current.remove.mutateAsync(SILVER).catch(() => undefined))
    expect(elsewhere()).toBe(2)

    expect(result.current.invalidates).toEqual([queryKeys.planOptions])
  })
})

describe('a list in a section kept mounted and hidden', () => {
  it('reads nothing until its section is on screen', async () => {
    server().on('GET', '/api/admin/plans', json({ plans: [GOLD] } satisfies PlansResponse))
    // The users page's two sections, the list in the second — opened on the first, where the list is hidden.
    const { wrapper: Providers } = providers({ at: '/users' })
    const hook = renderHook(() => ({ list: usePlans(), navigate: useNavigate() }), {
      wrapper: ({ children }) => (
        <Providers>
          <SectionedPage sections={USER_SECTIONS} header={(current) => ({ title: current.title })}>
            {{ users: null, groups: children }}
          </SectionedPage>
        </Providers>
      ),
    })
    expect(server().sent('GET', '/api/admin/plans')).toEqual([])

    act(() => void hook.result.current.navigate('/users/groups'))

    await until(() => expect(names(hook.result.current.list.rows)).toEqual(['Gold']))
  })
})
