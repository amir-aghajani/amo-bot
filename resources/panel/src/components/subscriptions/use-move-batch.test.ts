import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useMoveBatch } from '@/components/subscriptions/use-move-batch'
import type { NamedRef, SubscriptionRow } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { subscriptionRow } from '@/test/rows'
import { json, refusal, server, type Answer, type SentRequest } from '@/test/server'

/*
 * Services moved to another server in a batch (components/subscriptions/use-move-batch): one request each, in order; a
 * previous server that fails pauses the batch on a question — skip it for the rest of its services, or cancel there —,
 * and a refusal that would refuse the next one too (the target's, or the server's refusal to leave a client behind)
 * stops the batch, saying why; while it runs, closing the tab asks first, and its page going away stops it; once it is
 * over, what it moved beyond its services is read again.
 */

const GERMANY: NamedRef = { id: 1, name: 'آلمان' }
const FRANCE: NamedRef = { id: 2, name: 'فرانسه' }
const NETHERLANDS: NamedRef = { id: 3, name: 'هلند' }

const PREVIOUS_DOWN = 'پنل «آلمان»: پاسخ نداد.'
const LEAVE_REFUSED = 'پنل سرور «آلمان» در دسترس است؛ کلاینت این سرویس فقط وقتی روی پنل می‌ماند که پنل جواب ندهد.'

const service = (id: number, on: NamedRef = GERMANY, overrides: Partial<SubscriptionRow> = {}) => subscriptionRow({ id, name: `amir_${id}`, server: on, ...overrides })

/** The service as the target took it. */
const arrived = (moving: SubscriptionRow): Answer => json({ subscription: { ...moving, server: NETHERLANDS } })

const leaves = (request: SentRequest) => (request.body as { leave_previous: boolean }).leave_previous

/** What a service's move request answers, by whether it leaves the previous server out. */
function answers(moving: SubscriptionRow, answer: Answer | ((request: SentRequest) => Answer) = arrived(moving)) {
  server().on('POST', `/api/admin/subscriptions/${moving.id}/move`, answer)
}

/** The previous server fails the move; carrying on without it works. */
const previousDown = (moving: SubscriptionRow) => (request: SentRequest) => (leaves(request) ? arrived(moving) : refusal(502, PREVIOUS_DOWN, { previous: [PREVIOUS_DOWN] }))

/** The move dialog's batch over `services`, the target picked. */
function batch(services: SubscriptionRow[]) {
  const onMoved = vi.fn()
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(({ list }) => useMoveBatch(list, onMoved), { initialProps: { list: services }, wrapper })
  act(() => hook.result.current.setTarget(String(NETHERLANDS.id)))
  return { ...hook, onMoved, invalidated }
}

/** The moves sent, in order: which service, and whether it left its previous server out. */
const sent = () => server().requests.map((request) => [Number(request.path.split('/')[4]), leaves(request)])

/** Whether the tab, closing or reloading, was asked to stay. */
function unload(): boolean {
  const event = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(event)
  return event.defaultPrevented
}

describe('a batch', () => {
  it('moves every active service not on the target already, one request each, in order', async () => {
    const services = [service(1), service(2, FRANCE), service(3, GERMANY, { status: 'disabled', actions: { ...service(3).actions, move: false } }), service(4, NETHERLANDS)]
    services.forEach((moving) => answers(moving))
    const { result, onMoved } = batch(services)

    expect(result.current.movable.map((moving) => moving.id)).toEqual([1, 2, 4])
    expect(result.current.queue.map((moving) => moving.id)).toEqual([1, 2])

    await act(async () => result.current.start())
    await until(() => expect(result.current.running).toBe(false))

    expect(sent()).toEqual([
      [1, false],
      [2, false],
    ])
    expect(result.current.outcomes[1]).toEqual({ state: 'moved', server: 'هلند', left: false })
    expect(result.current.moved).toBe(2)
    expect(onMoved).toHaveBeenCalledTimes(2)
    expect(result.current.stopped).toBeNull()
  })

  it('has what it moved beyond its services read again once it is over: the list, the servers’ counts, the dashboard', async () => {
    const services = [service(1), service(2)]
    services.forEach((moving) => answers(moving))
    const { result, invalidated } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.moved).toBe(2))

    await until(() => expect(invalidated.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([queryKeys.subscriptions, queryKeys.serverChoices, queryKeys.dashboards]))
  })

  it('stops after the service under way when asked', async () => {
    const services = [service(1), service(2)]
    const first = server().hold('POST', '/api/admin/subscriptions/1/move')
    answers(services[1] as SubscriptionRow)
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(first.waiting).toBe(1))
    act(() => result.current.stopAfterThis())
    await act(async () => first.answer(arrived(services[0] as SubscriptionRow)))

    await until(() => expect(result.current.running).toBe(false))
    expect(sent()).toEqual([[1, false]])
    expect(result.current.outcomes[2]).toBeUndefined()
  })

  it('asks before the tab closes while it runs, and not otherwise', async () => {
    const services = [service(1)]
    const first = server().hold('POST', '/api/admin/subscriptions/1/move')
    const { result } = batch(services)
    expect(unload()).toBe(false)

    await act(async () => result.current.start())
    await until(() => expect(first.waiting).toBe(1))
    expect(unload()).toBe(true)

    await act(async () => first.answer(arrived(services[0] as SubscriptionRow)))
    await until(() => expect(result.current.running).toBe(false))
    expect(unload()).toBe(false)
  })

  it('stops after the service under way when its page goes away — a question it waits on answered «لغو»', async () => {
    const services = [service(1), service(2), service(3)]
    answers(services[0] as SubscriptionRow, previousDown(services[0] as SubscriptionRow))
    const { result, unmount } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.question).not.toBeNull())
    unmount()

    await until(() => expect(sent()).toEqual([[1, false]]))
    expect(unload()).toBe(false)
  })

  it('starts afresh at every opening', async () => {
    const first = service(1)
    answers(first)
    const { result, rerender } = batch([first])
    await act(async () => result.current.start())
    await until(() => expect(result.current.moved).toBe(1))

    rerender({ list: [service(9)] })

    expect(result.current.outcomes).toEqual({})
    expect(result.current.target).toBe('')
    expect(result.current.started).toBe(false)
  })
})

describe('a previous server that fails', () => {
  it('pauses the batch on a question; the skip moves the rest of its services without asking again', async () => {
    const services = [service(1), service(2), service(3, FRANCE)]
    answers(services[0] as SubscriptionRow, previousDown(services[0] as SubscriptionRow))
    answers(services[1] as SubscriptionRow, previousDown(services[1] as SubscriptionRow))
    answers(services[2] as SubscriptionRow)
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.question).not.toBeNull())
    expect(result.current.question).toMatchObject({ subscription: { id: 1 }, reason: PREVIOUS_DOWN, others: 1 })
    expect(result.current.outcomes[1]).toEqual({ state: 'asking' })

    act(() => result.current.reply(true))
    await until(() => expect(result.current.running).toBe(false))

    expect(sent()).toEqual([
      [1, false],
      [1, true],
      [2, true],
      [3, false],
    ])
    expect(result.current.outcomes[1]).toEqual({ state: 'moved', server: 'هلند', left: true })
    expect(result.current.question).toBeNull()
  })

  it('stops the batch there when the admin cancels: what moved stays moved, the rest stays put', async () => {
    const services = [service(5, FRANCE), service(1), service(2)]
    answers(services[0] as SubscriptionRow)
    answers(services[1] as SubscriptionRow, previousDown(services[1] as SubscriptionRow))
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.question).not.toBeNull())
    act(() => result.current.reply(false))
    await until(() => expect(result.current.running).toBe(false))

    expect(sent()).toEqual([
      [5, false],
      [1, false],
    ])
    expect(result.current.outcomes[1]).toEqual({ state: 'cancelled' })
    expect(result.current.outcomes[2]).toBeUndefined()
    expect(result.current.moved).toBe(1)
    expect(result.current.cancelled).toBe(true)
  })

  it('stops the batch in the server’s words when it will not leave the client there (an agent’s shop, the panel answering)', async () => {
    const services = [service(1), service(2)]
    answers(services[0] as SubscriptionRow, (request) => (leaves(request) ? refusal(422, LEAVE_REFUSED, { status: [LEAVE_REFUSED] }) : refusal(502, PREVIOUS_DOWN, { previous: [PREVIOUS_DOWN] })))
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.question).not.toBeNull())
    act(() => result.current.reply(true))
    await until(() => expect(result.current.running).toBe(false))

    expect(result.current.stopped?.id).toBe(1)
    expect(result.current.outcomes[1]).toEqual({ state: 'failed', message: LEAVE_REFUSED })
    expect(result.current.outcomes[2]).toBeUndefined()
    expect(sent()).toEqual([
      [1, false],
      [1, true],
    ])
  })
})

describe('a refusal', () => {
  it('of the target stops the batch, saying why: the next service would be refused too', async () => {
    const full = '«هلند»: ظرفیت سرور پر است.'
    const services = [service(1), service(2)]
    answers(services[0] as SubscriptionRow, refusal(422, full, { server_id: [full] }))
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.running).toBe(false))

    expect(result.current.stopped?.id).toBe(1)
    expect(result.current.outcomes[1]).toEqual({ state: 'failed', message: full })
    expect(sent()).toEqual([[1, false]])
  })

  it('when the target does not answer stops the batch as well', async () => {
    const down = 'پنل «هلند»: پاسخ نداد.'
    const services = [service(1), service(2)]
    answers(services[0] as SubscriptionRow, refusal(502, down, { target: [down] }))
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.running).toBe(false))

    expect(result.current.stopped?.id).toBe(1)
    expect(result.current.outcomes[1]).toEqual({ state: 'failed', message: down })
    expect(sent()).toEqual([[1, false]])
  })

  it('about one service alone fails that one and the batch goes on', async () => {
    const inactive = 'این سرویس فعال نیست.'
    const services = [service(1), service(2)]
    answers(services[0] as SubscriptionRow, refusal(422, inactive, { status: [inactive] }))
    answers(services[1] as SubscriptionRow)
    const { result } = batch(services)

    await act(async () => result.current.start())
    await until(() => expect(result.current.running).toBe(false))

    expect(result.current.outcomes[1]).toEqual({ state: 'failed', message: inactive })
    expect(result.current.outcomes[2]).toMatchObject({ state: 'moved' })
    expect(result.current.failed).toBe(1)
    expect(result.current.stopped).toBeNull()
  })
})
