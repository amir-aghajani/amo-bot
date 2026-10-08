import { queryOptions } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { giftLabel, outcome, progress, reached } from '@/components/grants/grant-format'
import { useGrants } from '@/components/grants/use-grants'
import { api } from '@/lib/api'
import type { ServerGrantRow, ServerGrantsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { json, offline, refusal, server, type Answer } from '@/test/server'

/*
 * A grants card (components/grants): while a grant runs and its card is open, the card works on it a request at a time —
 * the next a second after, half a minute while it waits for its panel, ten seconds after a request that got no answer —
 * until it is done, then reads again what it changed; a refusal (4xx) stops it.
 */

const GB = 1024 ** 3

function grant(overrides: Partial<ServerGrantRow> = {}): ServerGrantRow {
  return {
    id: 7,
    mass_grant_id: null,
    agents_only: false,
    days: 3,
    traffic_bytes: 10 * GB,
    reason: null,
    notify: true,
    include_unstarted: false,
    status: 'running',
    total: 3,
    granted: 0,
    skipped: 0,
    failed: 0,
    waiting_reason: null,
    last_failure: null,
    reviewer: 'owner',
    created_at: '2026-10-06T09:00:00Z',
    finished_at: null,
    ...overrides,
  }
}

const grantsQuery = queryOptions({ queryKey: queryKeys.serverGrants(4), queryFn: () => api.get<ServerGrantsResponse>('/servers/4/grants') })

/** Server 4's grants as its card works them: a grant's run and its stop. */
const writes = {
  run: (id: number) => api.post(`/servers/4/grants/${id}/run`),
  cancel: (id: number) => api.post(`/servers/4/grants/${id}/cancel`),
}

const RUN = '/api/admin/servers/4/grants/7/run'

/** Server 4's grants card open, its grant as `answer` leaves it after each run. */
async function card(answer: (latest: ServerGrantRow) => Answer | ServerGrantRow) {
  let latest = grant()
  server()
    .on('GET', '/api/admin/servers/4/grants', () => json({ grants: [latest], audience: { running: 3, unstarted: 0 } } satisfies ServerGrantsResponse))
    .on('POST', RUN, () => {
      const next = answer(latest)
      if (!('kind' in next)) {
        latest = next
        return json({ grant: next })
      }
      return next
    })
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(
    () =>
      useGrants({
        query: grantsQuery,
        ...writes,
        waiting: (row: ServerGrantRow) => row.waiting_reason !== null,
        invalidates: [queryKeys.subscriptions],
        noun: 'افزودن زمان و حجم',
      }),
    { wrapper },
  )
  await until(() => expect(runs()).toBe(1))
  return { ...hook, invalidated }
}

const runs = () => server().sent('POST', RUN).length

const wait = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms))

beforeEach(() => {
  vi.useFakeTimers()
  vi.spyOn(toast, 'success')
})

describe('a running grant', () => {
  it('is worked on a second after each step until it is done, then what it changed is read again', async () => {
    const { invalidated } = await card((latest) => (latest.granted < 2 ? grant({ granted: latest.granted + 1 }) : grant({ status: 'done', granted: 3 })))

    await wait(999)
    expect(runs()).toBe(1)
    await wait(1)
    await until(() => expect(runs()).toBe(2))
    await wait(1_000)
    await until(() => expect(runs()).toBe(3))

    await until(() => expect(toast.success).toHaveBeenCalledWith('۳ روز و ۱۰ گیگابایت به ۳ سرویس اضافه شد'))
    const read = invalidated.mock.calls.map(([filters]) => filters?.queryKey)
    expect(read).toContainEqual(queryKeys.serverGrants(4))
    expect(read).toContainEqual(queryKeys.subscriptions)
    await wait(60_000)
    expect(runs()).toBe(3)
  })

  it('that waits for its panel is asked again in half a minute', async () => {
    await card(() => grant({ waiting_reason: 'پنل «آلمان» در دسترس نیست.' }))

    await wait(29_999)
    expect(runs()).toBe(1)
    await wait(1)
    await until(() => expect(runs()).toBe(2))
  })

  it('is asked again in ten seconds when a request got no answer', async () => {
    await card(() => offline)

    await wait(9_999)
    expect(runs()).toBe(1)
    await wait(1)
    await until(() => expect(runs()).toBe(2))
  })

  it('is not asked again once the server refuses it, and the list is read again', async () => {
    const { invalidated } = await card(() => refusal(404, 'این مورد پیدا نشد.'))

    await until(() => expect(invalidated).toHaveBeenCalled())
    await wait(60_000)
    expect(runs()).toBe(1)
  })

  it('that gave nobody anything says so when it ends', async () => {
    await card(() => grant({ status: 'done', granted: 0, skipped: 3 }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('افزودن زمان و حجم تمام شد؛ سرویسی شامل نشد'))
  })

  it('is left unfinished when its card goes — nothing more asked, nothing read again: the scheduler carries on', async () => {
    const { invalidated, unmount } = await card((latest) => grant({ granted: latest.granted + 1 }))

    unmount()
    await wait(60_000)

    expect(runs()).toBe(1)
    expect(invalidated).not.toHaveBeenCalled()
  })
})

describe('stopping a grant', () => {
  it('puts the stopped grant in its place and stops working on it', async () => {
    let latest = grant()
    const step = server().hold('POST', RUN)
    server()
      .on('GET', '/api/admin/servers/4/grants', () => json({ grants: [latest], audience: { running: 3, unstarted: 0 } }))
      .on('POST', '/api/admin/servers/4/grants/7/cancel', () => {
        latest = grant({ status: 'cancelled', granted: 1 })
        return json({ grant: latest })
      })
    const { client, wrapper } = providers()
    const invalidated = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useGrants({ query: grantsQuery, ...writes, waiting: () => false, invalidates: [queryKeys.subscriptions], noun: 'افزودن زمان و حجم' }), {
      wrapper,
    })
    await until(() => expect(step.waiting).toBe(1))

    act(() => result.current.cancel.mutate(grant()))

    await until(() => expect(toast.success).toHaveBeenCalledWith('افزودن زمان و حجم متوقف شد'))
    expect(client.getQueryData<ServerGrantsResponse>(queryKeys.serverGrants(4))?.grants[0]?.status).toBe('cancelled')
    expect(result.current.running).toBeNull()
    // What it gave so far is read again — by the stop; the run the card left reads nothing.
    expect(invalidated.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([queryKeys.serverGrants(4), queryKeys.subscriptions])
  })
})

describe('a grant in words', () => {
  it('says what it gives each service as the bot does', () => {
    expect(giftLabel({ days: 3, traffic_bytes: 10 * GB })).toBe('۳ روز و ۱۰ گیگابایت')
    expect(giftLabel({ days: 0, traffic_bytes: 1.5 * GB })).toBe('۱٫۵ گیگابایت')
    expect(giftLabel({ days: 7, traffic_bytes: 0 })).toBe('۷ روز')
  })

  it('counts the services it has been through, never past its total', () => {
    expect(reached({ total: 10, granted: 4, skipped: 2, failed: 1 })).toBe(7)
    expect(reached({ total: 3, granted: 3, skipped: 1, failed: 0 })).toBe(3)
  })

  it('says how it went', () => {
    expect(outcome({ total: 16, granted: 12, skipped: 3, failed: 1 })).toBe('به ۱۲ سرویس اضافه شد · ۳ سرویس شامل نشد · ۱ ناموفق')
    expect(outcome({ total: 2, granted: 0, skipped: 2, failed: 0 })).toBe('به سرویسی اضافه نشد · ۲ سرویس شامل نشد')
  })

  it('names its numbers while it runs: the active services it goes through, those checked, and what came of them', () => {
    expect(progress({ total: 3, granted: 0, skipped: 0, failed: 0 })).toBe('۰ از ۳ سرویس فعال بررسی شد · به ۰ سرویس اضافه شد')
    expect(progress({ total: 12, granted: 3, skipped: 2, failed: 1 })).toBe('۶ از ۱۲ سرویس فعال بررسی شد · به ۳ سرویس اضافه شد · ۲ سرویس شامل نشد · ۱ ناموفق')
  })
})
