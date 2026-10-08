import { MutationObserver, onlineManager, type MutationOptions } from '@tanstack/react-query'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api'
import type { ClientErrorRequest } from '@/lib/api-types'
import { startErrorReporting } from '@/lib/error-reporting'
import { FAILURE_KINDS } from '@/lib/failure'
import { createQueryClient, runMutation } from '@/lib/query-client'
import { queryKeys } from '@/lib/query-keys'

/*
 * The panels' cache (lib/query-client): a read that got no answer is asked again, twice — one the server answered stands,
 * and is kept half an hour once no screen shows it;
 * every mutation's failure is a toast in lib/failure's words — the API's own where they are the admin's — unless its
 * screen shows it itself; a failure that is no answer of the server's is the panel's bug, told to the log; and what a
 * mutation changes elsewhere, named on it, goes stale once it succeeds.
 */

beforeEach(() => {
  vi.spyOn(toast, 'error')
})

/** Run a mutation through the panels' cache, as a screen's useMutation does. */
function mutate(client: ReturnType<typeof createQueryClient>, options: MutationOptions<unknown, Error, void>) {
  return new MutationObserver(client, options).mutate().catch(() => undefined)
}

/** A run's reach by its answer, as a grant's run names it: the services once it is over, nothing when it was left. */
const reach = (over: unknown) => (over === true ? [queryKeys.subscriptions] : [])

describe('a read', () => {
  it('that got no answer is asked again twice, then fails', async () => {
    vi.useFakeTimers()
    const read = vi.fn(() => Promise.reject(new ApiError(0, 'ارتباط با سرور برقرار نشد.')))
    const outcome = createQueryClient()
      .fetchQuery({ queryKey: ['servers'], queryFn: read })
      .catch((error: unknown) => error)

    await vi.advanceTimersByTimeAsync(10_000)

    expect(await outcome).toMatchObject({ status: 0 })
    expect(read).toHaveBeenCalledTimes(3)
  })

  it('the server answered is not asked again: the screen shows the answer', async () => {
    const read = vi.fn(() => Promise.reject(new ApiError(500, 'خطای سرور')))

    await createQueryClient()
      .fetchQuery({ queryKey: ['servers'], queryFn: read })
      .catch(() => undefined)

    expect(read).toHaveBeenCalledTimes(1)
  })

  it('that failed for no answer of the server’s is the panel’s own bug, told to the console and the log', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const sent: ClientErrorRequest[] = []
    const stop = startErrorReporting((report) => Promise.resolve(sent.push(report)))
    const bug = new TypeError('x is undefined')

    await createQueryClient()
      .fetchQuery({ queryKey: ['servers'], queryFn: () => Promise.reject(bug) })
      .catch(() => undefined)
    await createQueryClient()
      .fetchQuery({ queryKey: ['plans'], queryFn: () => Promise.reject(new ApiError(404, 'پیدا نشد.')) })
      .catch(() => undefined)
    stop()

    expect(consoleError).toHaveBeenCalledTimes(1)
    expect(consoleError).toHaveBeenCalledWith('The panel failed:', bug)
    expect(sent.map((report) => [report.kind, report.message])).toEqual([['unhandled', 'TypeError: x is undefined']])
  })

  it('no screen shows is kept half an hour — a page opened again is drawn at once from it — and no longer', async () => {
    vi.useFakeTimers()
    const client = createQueryClient()
    await client.fetchQuery({ queryKey: ['orders'], queryFn: () => Promise.resolve({ orders: [] }) })

    vi.advanceTimersByTime(30 * 60_000 - 1_000)
    expect(client.getQueryData(['orders'])).toEqual({ orders: [] })

    vi.advanceTimersByTime(2_000)
    expect(client.getQueryData(['orders'])).toBeUndefined()
  })
})

describe('a mutation', () => {
  it('that fails is told in a toast, in the API’s words', async () => {
    await mutate(createQueryClient(), { mutationFn: () => Promise.reject(new ApiError(422, 'نام پلن تکراری است.')) })

    expect(toast.error).toHaveBeenCalledWith('نام پلن تکراری است.', { description: undefined })
  })

  it('that got no answer is told in the words of what happened', async () => {
    await mutate(createQueryClient(), { mutationFn: () => Promise.reject(new ApiError(0, 'No answer', {}, { foreign: true, timedOut: true })) })

    expect(toast.error).toHaveBeenCalledWith(FAILURE_KINDS.timeout.title, { description: FAILURE_KINDS.timeout.description })
  })

  it('whose screen shows its failure itself makes no toast', async () => {
    await mutate(createQueryClient(), { mutationFn: () => Promise.reject(new ApiError(422, 'نام پلن تکراری است.')), meta: { quiet: true } })

    expect(toast.error).not.toHaveBeenCalled()
  })

  it('that succeeds makes stale what it names, and nothing else', async () => {
    const client = createQueryClient()
    client.setQueryData(queryKeys.plans, { plans: [] })
    client.setQueryData(queryKeys.orders, { orders: [] })

    await mutate(client, { mutationFn: () => Promise.resolve(null), meta: { invalidates: [queryKeys.plans] } })

    expect(client.getQueryState(queryKeys.plans)?.isInvalidated).toBe(true)
    expect(client.getQueryState(queryKeys.orders)?.isInvalidated).toBe(false)
  })

  it('whose reach is known once it ends names it from its answer', async () => {
    const client = createQueryClient()
    client.setQueryData(queryKeys.subscriptions, { subscriptions: [] })

    await mutate(client, { mutationFn: () => Promise.resolve(false), meta: { invalidates: reach } })
    expect(client.getQueryState(queryKeys.subscriptions)?.isInvalidated).toBe(false)

    await mutate(client, { mutationFn: () => Promise.resolve(true), meta: { invalidates: reach } })
    expect(client.getQueryState(queryKeys.subscriptions)?.isInvalidated).toBe(true)
  })

  it('that fails makes nothing stale', async () => {
    const client = createQueryClient()
    client.setQueryData(queryKeys.plans, { plans: [] })

    await mutate(client, { mutationFn: () => Promise.reject(new ApiError(422, 'نام پلن تکراری است.')), meta: { invalidates: [queryKeys.plans] } })

    expect(client.getQueryState(queryKeys.plans)?.isInvalidated).toBe(false)
  })
})

describe('one request run as a mutation (a form’s save)', () => {
  it('hands its answer back, and is heeded as any mutation: what it names made stale', async () => {
    const client = createQueryClient()
    client.setQueryData(queryKeys.plans, { plans: [] })

    await expect(runMutation(client, () => Promise.resolve({ plan: 7 }), { invalidates: [queryKeys.plans] })).resolves.toEqual({ plan: 7 })

    expect(client.getQueryState(queryKeys.plans)?.isInvalidated).toBe(true)
  })

  it('throws its failure to the one who ran it — no toast when it is quiet —, and leaves the cache in its own time', async () => {
    vi.useFakeTimers()
    const client = createQueryClient()
    const refused = new ApiError(422, 'نام پلن تکراری است.')

    await expect(runMutation(client, () => Promise.reject(refused), { quiet: true })).rejects.toBe(refused)
    expect(toast.error).not.toHaveBeenCalled()

    // Nobody watches it once it is over: it is not kept for good.
    await vi.advanceTimersByTimeAsync(5 * 60_000)
    expect(client.getMutationCache().getAll()).toEqual([])
  })

  it('goes at once while the browser says it is offline — its form says when no answer came', async () => {
    const client = createQueryClient()
    onlineManager.setOnline(false)
    try {
      await expect(runMutation(client, () => Promise.resolve('sent'), { quiet: true })).resolves.toBe('sent')
    } finally {
      onlineManager.setOnline(true)
    }
  })
})
