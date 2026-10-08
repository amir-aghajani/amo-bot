import { act, renderHook } from '@testing-library/react'
import { Ban, Check, Gift, X } from 'lucide-react'
import { describe, expect, it, vi } from 'vitest'
import { useOperations, type OperationSpec } from '@/components/operations'
import { ApiError } from '@/lib/api'
import { FAILURE_KINDS } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'

/*
 * A detail dialog's operations (components/operations): the ones the subject's state allows; a plain one runs at once,
 * a worded or destructive one waits on its strip for a second look, one that asks more than a note for the dialog's form
 * on its strip; one done has what the dialog says it changes beside the subject read again; a refusal is said in the
 * API's words — the list read again when the subject moved on, the refusal then the dialog's one word on it —, and an
 * operation waiting on its strip is withdrawn once the state no longer allows it, unless it is the admin's own in flight
 * or the dialog holds the operations with a question of its own. Each end — a run, a strip — says where the focus goes
 * (the section puts it there, components/payments and components/orders test it on screen).
 */

type Action = 'approve' | 'reject' | 'cancel'

const SPECS: Record<Action, OperationSpec> = {
  approve: { label: 'تایید', icon: Check, tone: 'primary', done: 'تایید شد' },
  reject: { label: 'رد', icon: X, tone: 'danger', done: 'رد شد', note: { label: 'دلیل رد', placeholder: '' } },
  cancel: { label: 'لغو', icon: Ban, tone: 'danger', done: 'لغو شد', confirm: 'سفارش لغو می‌شود.' },
}

const EVERYTHING: Record<Action, boolean> = { approve: true, reject: true, cancel: true }

interface Options {
  perform?: (run: { action: Action; note?: string; force?: boolean }) => Promise<unknown>
  onFailure?: (error: unknown) => boolean
  intercept?: (action: Action) => boolean
}

/** What these operations change beside their subject, as a dialog names it. */
const CHANGES = [queryKeys.dashboards]

/** A dialog's operations over a subject whose state allows `allowed` (a prop: the live updates may change it). */
function operations({ perform = () => Promise.resolve('fresh'), onFailure, intercept }: Options = {}) {
  const calls = { perform: vi.fn(perform), onDone: vi.fn(), onStale: vi.fn() }
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(
    ({ allowed, holding }) => useOperations({ specs: SPECS, allowed, perform: calls.perform, invalidates: CHANGES, onDone: calls.onDone, onStale: calls.onStale, onFailure, intercept, holding }),
    { initialProps: { allowed: EVERYTHING, holding: false }, wrapper },
  )
  return { ...hook, ...calls, invalidated }
}

describe('the operations', () => {
  it('are the ones the subject’s state allows, in the specs’ order', () => {
    const { result } = renderHook(
      () => useOperations({ specs: SPECS, allowed: { approve: true, reject: false, cancel: true }, perform: vi.fn(), invalidates: CHANGES, onDone: vi.fn(), onStale: vi.fn() }),
      {
        wrapper: providers().wrapper,
      },
    )

    expect(result.current.available).toEqual(['approve', 'cancel'])
  })

  it('run a plain one at once, and read again what it changes beside its subject', async () => {
    const { result, perform, onDone, invalidated } = operations()

    act(() => result.current.choose('approve'))

    await until(() => expect(onDone).toHaveBeenCalledTimes(1))
    expect(onDone.mock.calls[0]?.slice(0, 2)).toEqual(['fresh', { action: 'approve' }])
    expect(perform.mock.calls[0]?.[0]).toEqual({ action: 'approve' })
    expect(result.current.pending).toBeNull()
    expect(invalidated.mock.calls.map(([filters]) => filters?.queryKey)).toEqual(CHANGES)
  })

  it('hold a worded one on its strip, and run it with its note on the second look', async () => {
    const { result, perform } = operations()

    act(() => result.current.choose('reject'))
    expect(result.current.pending).toBe('reject')
    expect(perform).not.toHaveBeenCalled()

    act(() => result.current.setNote('رسید خوانا نیست'))
    act(() => result.current.confirm())

    await until(() => expect(perform).toHaveBeenCalledTimes(1))
    expect(perform.mock.calls[0]?.[0]).toEqual({ action: 'reject', note: 'رسید خوانا نیست' })
  })

  it('hold a destructive one for a second look, sent without a note', async () => {
    const { result, perform } = operations()

    act(() => result.current.choose('cancel'))
    act(() => result.current.confirm())

    await until(() => expect(perform).toHaveBeenCalledTimes(1))
    expect(perform.mock.calls[0]?.[0]).toEqual({ action: 'cancel', note: undefined })
  })

  it('bring the buttons back with the one whose strip closed remembered, the focus asked back to them', () => {
    const { result } = operations()

    act(() => result.current.choose('cancel'))
    act(() => result.current.close())

    expect(result.current.pending).toBeNull()
    expect(result.current.last).toBe('cancel')
    expect(result.current.focus).toMatchObject({ to: 'operations', insist: false })
  })

  it('remember the one run at once too, and ask the focus back to the operations once it went through', async () => {
    const { result, onDone } = operations()

    act(() => result.current.choose('approve'))
    expect(result.current.last).toBe('approve')

    await until(() => expect(onDone).toHaveBeenCalled())
    expect(result.current.focus).toMatchObject({ to: 'operations', insist: false })
  })

  it('hand one the dialog runs its own way to it', () => {
    const { result, perform } = operations({ intercept: (action) => action === 'approve' })

    act(() => result.current.choose('approve'))

    expect(perform).not.toHaveBeenCalled()
    expect(result.current.pending).toBeNull()
  })

  it('hold one that asks more than a note on its strip, for the dialog’s form there to run', () => {
    const specs: Record<'extend', OperationSpec> = { extend: { label: 'افزایش زمان و حجم', icon: Gift, tone: 'neutral', done: 'اضافه شد', form: true } }
    const perform = vi.fn()
    const { result } = renderHook(() => useOperations({ specs, allowed: { extend: true }, perform, invalidates: CHANGES, onDone: vi.fn(), onStale: vi.fn() }), { wrapper: providers().wrapper })

    act(() => result.current.choose('extend'))

    expect(result.current.pending).toBe('extend')
    expect(perform).not.toHaveBeenCalled()
  })
})

describe('the subject moving on', () => {
  it('withdraws the operation waiting on its strip, with a word', () => {
    const { result, rerender } = operations()
    act(() => result.current.choose('reject'))

    rerender({ allowed: { ...EVERYTHING, reject: false }, holding: false })

    expect(result.current.withdrawn).toBe('reject')
    expect(result.current.pending).toBeNull()
  })

  it('withdraws nothing while the admin’s own operation is in flight — it moves the state itself', async () => {
    let finish!: (value: unknown) => void
    const { result, rerender } = operations({ perform: () => new Promise((resolve) => (finish = resolve)) })
    act(() => result.current.choose('cancel'))
    act(() => result.current.confirm())
    await until(() => expect(result.current.running).toBe('cancel'))

    rerender({ allowed: { ...EVERYTHING, cancel: false }, holding: false })

    expect(result.current.withdrawn).toBeNull()
    await act(async () => finish('fresh'))
  })

  it('withdraws nothing while the dialog holds the operations with a question of its own', () => {
    const { result, rerender } = operations()
    act(() => result.current.choose('cancel'))

    rerender({ allowed: { ...EVERYTHING, cancel: false }, holding: true })

    expect(result.current.withdrawn).toBeNull()
    expect(result.current.pending).toBe('cancel')
  })
})

describe('a refusal', () => {
  it('is said in the words the API put under the state, and the list behind is read again — the dialog’s one word on it, the focus asked there', async () => {
    const { result, onStale } = operations({ perform: () => Promise.reject(new ApiError(422, 'Refused.', { status: ['این پرداخت همین الان تایید شد.'] })) })

    act(() => result.current.choose('approve'))

    await until(() => expect(result.current.error).toBe('این پرداخت همین الان تایید شد.'))
    expect(onStale).toHaveBeenCalledTimes(1)
    expect(result.current.movedOn).toBe(true)
    expect(result.current.focus).toMatchObject({ to: 'word', insist: true })
  })

  it('of the note is said in its words, the list left alone — and nothing else read again', async () => {
    const { result, onStale, invalidated } = operations({ perform: () => Promise.reject(new ApiError(422, 'Refused.', { note: ['حداکثر ۳۰۰ کاراکتر.'] })) })

    act(() => result.current.choose('reject'))
    act(() => result.current.confirm())

    await until(() => expect(result.current.error).toBe('حداکثر ۳۰۰ کاراکتر.'))
    expect(onStale).not.toHaveBeenCalled()
    expect(invalidated).not.toHaveBeenCalled()
    expect(result.current.movedOn).toBe(false)
    expect(result.current.focus).toMatchObject({ to: 'word', insist: false })
  })

  it('goes with the next try, while it runs', async () => {
    let attempt = 0
    let finish!: (value: unknown) => void
    const { result } = operations({
      perform: () => (++attempt === 1 ? Promise.reject(new ApiError(422, 'Refused.', { note: ['حداکثر ۳۰۰ کاراکتر.'] })) : new Promise((resolve) => (finish = resolve))),
    })
    act(() => result.current.choose('reject'))
    act(() => result.current.confirm())
    await until(() => expect(result.current.error).toBe('حداکثر ۳۰۰ کاراکتر.'))

    act(() => result.current.confirm())

    await until(() => expect(result.current.running).toBe('reject'))
    expect(result.current.error).toBeNull()
    await act(async () => finish('fresh'))
  })

  it('the dialog words its own way is left to it', async () => {
    const onFailure = vi.fn(() => true)
    const { result } = operations({ perform: () => Promise.reject(new ApiError(502, 'پنل پاسخ نداد.')), onFailure })

    act(() => result.current.choose('approve'))

    await until(() => expect(onFailure).toHaveBeenCalled())
    expect(result.current.error).toBeNull()
  })

  it('goes when the admin chooses again', async () => {
    const { result } = operations({ perform: () => Promise.reject(new ApiError(500, 'خطای سرور')) })
    act(() => result.current.choose('approve'))
    await until(() => expect(result.current.error).toBe(FAILURE_KINDS.server.description))

    act(() => result.current.choose('cancel'))

    expect(result.current.error).toBeNull()
  })
})
