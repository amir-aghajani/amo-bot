import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useImportRunner } from '@/components/imports/use-import-runner'
import type { ImportState } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { importState } from '@/test/rows'
import { json, offline, refusal, server, type Answer } from '@/test/server'

/*
 * The steps of an import under way (components/imports/use-import-runner): `POST continue` a second apart, each answer
 * handed on, until the import no longer reads or writes — a few seconds apart while another step holds it —; a step
 * with no answer is tried again after a wait that doubles, until a few in a row failed and the retry is the owner's
 * («تلاش دوباره»), as at once for a refused one, which reads the imports again too; a gone import ends the run; leaving
 * the screen leaves it.
 */

const CONTINUE = '/api/admin/imports/7/continue'

/** A step's answer: the import as the step left it, and whether it was taken. */
const step = (overrides: Partial<ImportState>, stepped = true): Answer => json({ import: importState(overrides), stepped })

/** A reading import `done` bytes in. */
const reading = (done: number, stepped = true) => step({ phase: 'reading', progress: { stage: null, stage_label: null, done, total: 1000 } }, stepped)

/** The runner on import 7, answering each step with the next of `answers` (the last one again once they run out). */
function run(answers: Answer[]) {
  let asked = 0
  server().on('POST', CONTINUE, () => answers[Math.min(asked++, answers.length - 1)] ?? reading(0))
  const put = vi.fn()
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(() => useImportRunner(7, put), { wrapper })
  const steps = () => server().sent('POST', CONTINUE).length
  return { ...hook, put, steps, read: () => invalidated.mock.calls.map(([filters]) => filters?.queryKey) }
}

const wait = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms))

beforeEach(() => {
  vi.useFakeTimers()
})

describe('an import under way', () => {
  it('is stepped on a second apart, each answer handed on, until it no longer reads — then the imports are read again', async () => {
    const { put, steps, read } = run([reading(100), reading(400), step({ phase: 'review' })])

    await until(() => expect(steps()).toBe(1))
    await wait(999)
    expect(steps()).toBe(1)
    await wait(1)
    await until(() => expect(steps()).toBe(2))
    await wait(1_000)
    await until(() => expect(put).toHaveBeenCalledTimes(3))
    expect(put.mock.calls.map((call) => (call[0] as ImportState).phase)).toEqual(['reading', 'reading', 'review'])
    await until(() => expect(read()).toContainEqual(queryKeys.imports))
    await wait(60_000)
    expect(steps()).toBe(3)
  })

  it('waits a few seconds after a step not taken — another step (another tab) holds the import —, not asking back to back', async () => {
    const { steps } = run([reading(100, false), reading(180, false), step({ phase: 'review' })])

    await until(() => expect(steps()).toBe(1))
    await wait(2_999)
    expect(steps()).toBe(1)
    await wait(1)
    await until(() => expect(steps()).toBe(2))
    await wait(3_000)
    await until(() => expect(steps()).toBe(3))
  })

  it('tries a step with no answer again after a wait that doubles, and after a few in a row leaves the retry to the owner', async () => {
    const { result, steps, put } = run([offline, refusal(500, 'خطای داخلی'), offline, refusal(500, 'خطای داخلی'), step({ phase: 'review' })])

    await until(() => expect(result.current.trouble?.again).toBe(true))
    await wait(1_999)
    expect(steps()).toBe(1)
    await wait(1)
    await until(() => expect(steps()).toBe(2))
    await wait(4_000)
    await until(() => expect(steps()).toBe(3))
    await wait(8_000)
    await until(() => expect(steps()).toBe(4))

    // The fourth in a row: said, and no longer asked on its own.
    await until(() => expect(result.current.trouble?.again).toBe(false))
    expect(result.current.trouble?.error.status).toBe(500)
    await wait(10 * 60_000)
    expect(steps()).toBe(4)

    act(() => result.current.retry())
    await until(() => expect(steps()).toBe(5))
    await until(() => expect(put).toHaveBeenCalledTimes(1))
    expect(result.current.trouble).toBeNull()
  })

  it('stops at a refused step — said, and the imports read again in case it moved on — until the owner retries', async () => {
    const { result, steps, read, put } = run([refusal(422, 'اطلاعات واردشده معتبر نیست.', { import: ['این انتقال در مرحله خواندن یا نوشتن نیست.'] }), step({ phase: 'review' })])

    await until(() => expect(result.current.trouble?.again).toBe(false))
    expect(result.current.trouble?.error.field('import')).toBe('این انتقال در مرحله خواندن یا نوشتن نیست.')
    expect(read()).toContainEqual(queryKeys.imports)
    await wait(60_000)
    expect(steps()).toBe(1)

    act(() => result.current.retry())
    await until(() => expect(put).toHaveBeenCalledTimes(1))
    expect(result.current.trouble).toBeNull()
  })

  it('ends at an import that is gone, and reads the imports again', async () => {
    const { result, steps, read, put } = run([refusal(404, 'مورد درخواستی پیدا نشد؛ ممکن است حذف شده باشد.')])

    await until(() => expect(read()).toContainEqual(queryKeys.imports))
    await wait(60_000)
    expect(steps()).toBe(1)
    expect(put).not.toHaveBeenCalled()
    expect(result.current.trouble).toBeNull()
  })

  it('is left when the screen goes — nothing more asked —, and none is run with no import under way', async () => {
    const held = server().hold('POST', CONTINUE)
    const put = vi.fn()
    const { wrapper } = providers()
    const hook = renderHook(() => useImportRunner(7, put), { wrapper })
    await until(() => expect(held.waiting).toBe(1))

    hook.unmount()
    held.answer(json({ import: importState({ phase: 'reading' }), stepped: true }))
    await wait(60_000)
    expect(put).not.toHaveBeenCalled()
    expect(server().sent('POST', CONTINUE)).toHaveLength(1)

    renderHook(() => useImportRunner(null, put), { wrapper })
    await wait(60_000)
    expect(server().sent('POST', CONTINUE)).toHaveLength(1)
  })
})
