import { createElement } from 'react'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react'
import { Ban, Check, Gift, X } from 'lucide-react'
import { describe, expect, it, vi } from 'vitest'
import { OperationsSection, useOperations, type OperationAlternative, type OperationSpec } from '@/components/operations'
import { ApiError } from '@/lib/api'
import { FAILURE_KINDS } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'

/*
 * A detail dialog's operations (components/operations): which run at once and which wait on a strip or a form, what is
 * read again after one, how a refusal is shown (and the list read again when the subject moved on), withdrawing an
 * operation the new state no longer allows, where the focus goes when each ends, and a strip's alternative while it runs.
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

  it('hold one that takes a note on its strip, and run it with the note on confirmation', async () => {
    const { result, perform } = operations()

    act(() => result.current.choose('reject'))
    expect(result.current.pending).toBe('reject')
    expect(perform).not.toHaveBeenCalled()

    act(() => result.current.setNote('رسید خوانا نیست'))
    act(() => result.current.confirm())

    await until(() => expect(perform).toHaveBeenCalledTimes(1))
    expect(perform.mock.calls[0]?.[0]).toEqual({ action: 'reject', note: 'رسید خوانا نیست' })
  })

  it('hold a destructive one for confirmation, sent without a note', async () => {
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
  it('withdraws the operation waiting on its strip, and says so', () => {
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
  it('on `status` shows the API’s message, reads the list again, and moves the focus to the message', async () => {
    const { result, onStale } = operations({ perform: () => Promise.reject(new ApiError(422, 'Refused.', { status: ['این پرداخت همین الان تایید شد.'] })) })

    act(() => result.current.choose('approve'))

    await until(() => expect(result.current.error).toBe('این پرداخت همین الان تایید شد.'))
    expect(onStale).toHaveBeenCalledTimes(1)
    expect(result.current.movedOn).toBe(true)
    expect(result.current.focus).toMatchObject({ to: 'word', insist: true })
  })

  it('on the note shows its message and reads nothing again', async () => {
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

  it('that the dialog handles itself is left to the dialog', async () => {
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

/** A second way through the cancel's strip, as a subject may offer one. */
const ALTERNATIVE: OperationAlternative = { label: 'لغو بدون بازگشت پول', hint: 'پول مشتری برنمی‌گردد.' }

/** The operations as a dialog draws them (OperationsSection), the cancel's strip offering ALTERNATIVE. */
function Section({ perform }: { perform: NonNullable<Options['perform']> }) {
  const ops = useOperations({ specs: SPECS, allowed: EVERYTHING, perform, invalidates: CHANGES, onDone: vi.fn(), onStale: vi.fn() })
  return createElement(OperationsSection<Action>, { ops, specs: SPECS, subject: 'این سفارش', alternative: (action) => (action === 'cancel' ? ALTERNATIVE : null) })
}

describe('a press', () => {
  it('on one that runs at once, twice before the buttons show it running — a double click —, runs it once', async () => {
    let finish!: (value: unknown) => void
    const perform = vi.fn<NonNullable<Options['perform']>>(() => new Promise((resolve) => (finish = resolve)))
    render(createElement(Section, { perform }), { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'تایید' }))
    fireEvent.click(screen.getByRole('button', { name: 'تایید' }))
    fireEvent.click(screen.getByRole('button', { name: 'لغو' }))

    await until(() => expect(perform).toHaveBeenCalled())
    expect(perform).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('سفارش لغو می‌شود.')).toBeNull()
    await act(async () => finish('fresh'))
    await until(() => expect(screen.getByRole('button', { name: 'تایید' }).getAttribute('aria-busy')).toBeNull())

    fireEvent.click(screen.getByRole('button', { name: 'تایید' }))
    await until(() => expect(perform).toHaveBeenCalledTimes(2))
    await act(async () => finish('fresh'))
  })
})

describe('a strip’s alternative', () => {
  it('runs the operation forced, with nothing failed before it, its hint read with its button', async () => {
    const perform = vi.fn<NonNullable<Options['perform']>>(() => Promise.resolve('fresh'))
    render(createElement(Section, { perform }), { wrapper: providers().wrapper })

    fireEvent.click(screen.getByRole('button', { name: 'لغو' }))
    fireEvent.click(screen.getByRole('button', { name: ALTERNATIVE.label, description: 'پول مشتری برنمی‌گردد.' }))

    await until(() => expect(perform).toHaveBeenCalledTimes(1))
    expect(perform.mock.calls[0]?.[0]).toEqual({ action: 'cancel', note: undefined, force: true })
  })

  it('spins alone while its run is in flight: the confirm held without a spinner, back disabled', async () => {
    let finish!: (value: unknown) => void
    render(createElement(Section, { perform: () => new Promise((resolve) => (finish = resolve)) }), { wrapper: providers().wrapper })
    fireEvent.click(screen.getByRole('button', { name: 'لغو' }))
    const alternative = screen.getByRole('button', { name: ALTERNATIVE.label })

    fireEvent.click(alternative)

    await until(() => expect(alternative.getAttribute('aria-busy')).toBe('true'))
    expect(alternative.getAttribute('aria-disabled')).toBe('true')
    const confirm = screen.getByRole('button', { name: 'لغو' })
    expect([confirm.getAttribute('aria-busy'), confirm.getAttribute('aria-disabled')]).toEqual([null, 'true'])
    expect(screen.getByRole('button', { name: 'برگشت' }).hasAttribute('disabled')).toBe(true)
    await act(async () => finish('fresh'))
  })
})
