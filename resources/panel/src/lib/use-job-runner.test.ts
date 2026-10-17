import { act, renderHook } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api'
import { useJobRunner } from '@/lib/use-job-runner'
import { providers, until } from '@/test/render'

/*
 * The runner a card works a background job with (lib/use-job-runner) — a grant, a move of a server's services: a turn at
 * a time while the card is on screen — the next a second after, half a minute while the job waits for a panel, ten
 * seconds after a turn that got no answer — until a turn no longer works it (then how it ended is said, and what it
 * changed read again) or it is refused; a card that goes leaves the run, and another job starts its own.
 */

interface Job {
  id: number
  status: 'running' | 'done'
  waiting: boolean
}

const CHANGED = ['services'] as const

/** A card running job `id`, each turn answered by `turn` (the turn's number, from 1): the job, or a failure thrown. */
function card(turn: (n: number) => Job | Error, id = 7) {
  const asked: number[] = []
  const put = vi.fn()
  const ended = vi.fn()
  const run = async (job: number) => {
    asked.push(job)
    const answer = turn(asked.length)
    if (answer instanceof Error) throw answer
    return answer
  }
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(
    ({ job }: { job: number | null }) => useJobRunner<Job>({ id: job, run, workable: (row) => row.status === 'running', waiting: (row) => row.waiting, put, ended, invalidates: [CHANGED] }),
    { wrapper, initialProps: { job: id as number | null } },
  )
  /** The queries read again so far. */
  const read = () => invalidated.mock.calls.map(([filters]) => filters?.queryKey)
  return { ...hook, asked, put, ended, invalidated, read }
}

const job = (overrides: Partial<Job> = {}): Job => ({ id: 7, status: 'running', waiting: false, ...overrides })

const wait = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms))

beforeEach(() => {
  vi.useFakeTimers()
})

describe('a job under way on a card', () => {
  it('is worked a second after each turn until a turn no longer works it, then says how it ended and reads again what it changed', async () => {
    const { asked, put, ended, read } = card((n) => (n < 3 ? job() : job({ status: 'done' })))
    await until(() => expect(asked).toHaveLength(1))

    await wait(999)
    expect(asked).toHaveLength(1)
    await wait(1)
    await until(() => expect(asked).toHaveLength(2))
    await wait(1_000)
    await until(() => expect(ended).toHaveBeenCalledWith(job({ status: 'done' })))

    expect(put).toHaveBeenCalledTimes(3)
    expect(read()).toContainEqual(CHANGED)
    await wait(60_000)
    expect(asked).toHaveLength(3)
  })

  it('is asked again in half a minute while it waits for a panel, and in ten seconds after a turn that got no answer', async () => {
    const { asked } = card((n) => (n === 1 ? job({ waiting: true }) : n === 2 ? new ApiError(0, 'no answer') : job()))
    await until(() => expect(asked).toHaveLength(1))

    await wait(29_999)
    expect(asked).toHaveLength(1)
    await wait(1)
    await until(() => expect(asked).toHaveLength(2))
    await wait(9_999)
    expect(asked).toHaveLength(2)
    await wait(1)
    await until(() => expect(asked).toHaveLength(3))
  })

  it('stops once a turn is refused, and reads again what it changed', async () => {
    const { asked, ended, read } = card(() => new ApiError(404, 'این مورد پیدا نشد.'))

    await until(() => expect(read()).toContainEqual(CHANGED))
    await wait(60_000)
    expect(asked).toHaveLength(1)
    expect(ended).not.toHaveBeenCalled()
  })

  it('stops at a failure of the panel’s own code — said and reported, never asked again in silence', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
    vi.spyOn(toast, 'error')
    const bug = new TypeError('x is undefined')
    const { asked, read } = card(() => bug)

    await until(() => expect(consoleError).toHaveBeenCalledWith('The panel failed:', bug))
    expect(toast.error).toHaveBeenCalled()
    await wait(60_000)
    expect(asked).toHaveLength(1)
    expect(read()).not.toContainEqual(CHANGED)
  })

  it('is left unfinished when the card goes — nothing more asked, nothing read again —, and another job starts its own run', async () => {
    const left = card(() => job())
    await until(() => expect(left.asked).toHaveLength(1))
    left.unmount()
    await wait(60_000)
    expect(left.asked).toHaveLength(1)
    expect(left.invalidated).not.toHaveBeenCalled()

    const other = card(() => job())
    await until(() => expect(other.asked).toEqual([7]))
    other.rerender({ job: 8 })
    await until(() => expect(other.asked.at(-1)).toBe(8))
    await wait(5_000)
    expect(other.asked.filter((id) => id === 7)).toHaveLength(1)
  })
})
