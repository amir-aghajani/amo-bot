import type { FormEvent } from 'react'
import { act, renderHook, type RenderHookOptions } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api'
import { FAILURE_KINDS } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import { asAmount, asSet, asWhole, useForm } from '@/lib/use-form'
import { providers } from '@/test/render'

/**
 * A form talking to the API (lib/use-form): what is unsaved, what the server refused under which field, and what a save
 * becomes — a mutation of the panels' cache, its failure the form's own, what it changes elsewhere read again.
 */

const PLAN = { name: 'Gold', price: '120000', servers: [] as number[] }

/** A form as a screen holds one: in the panels' cache, which its save is a mutation of. */
function renderForm<Result, Props>(render: (props: Props) => Result, options: RenderHookOptions<Props> = {}) {
  return renderHook(render, { wrapper: providers().wrapper, ...options })
}

/** A request the test settles itself. */
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((settle, fail) => {
    resolve = settle
    reject = fail
  })
  return { promise, resolve, reject }
}

describe('a draft', () => {
  it('is unsaved by value: typing a change back undoes it', () => {
    const { result } = renderForm(() => useForm(PLAN))
    expect(result.current.dirty).toBe(false)

    act(() => result.current.set('name', 'Silver'))
    expect(result.current.dirty).toBe(true)

    act(() => result.current.set('name', 'Gold'))
    expect(result.current.dirty).toBe(false)
  })

  it('goes back to where it started on revert, its errors gone', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('price', '1'))
    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { price: ['Too low.'] }))))

    act(() => result.current.revert())

    expect(result.current.values).toEqual(PLAN)
    expect(result.current.dirty).toBe(false)
    expect(result.current.errors).toEqual({})
    expect(result.current.formError).toBeNull()
  })

  it('starts again from what reset() hands it', () => {
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('name', 'Silver'))

    act(() => result.current.reset({ ...PLAN, name: 'Bronze' }))

    expect(result.current.values.name).toBe('Bronze')
    expect(result.current.dirty).toBe(false)
  })

  it('takes its first values from a function, once', () => {
    const start = vi.fn(() => PLAN)
    const { result, rerender } = renderForm(() => useForm(start))
    rerender()

    expect(result.current.values).toEqual(PLAN)
    expect(start).toHaveBeenCalledTimes(1)
  })
})

describe('what is unsaved', () => {
  it('is what the server would read otherwise: a text trimmed, nothing as nothing, a number whatever its digits’ script', () => {
    const { result } = renderForm(() =>
      useForm<{ name: string; note: string | null; days: number | string; price: string; servers: number[] }>({ name: 'Gold', note: null, days: 30, price: '120000', servers: [1, 2] }),
    )

    act(() => result.current.patch({ name: ' Gold ', note: '', days: '30', price: '۱۲۰۰۰۰' }))
    expect(result.current.dirty).toBe(false)

    // Leading zeros are no script: «0120000» is another text, as a phone number's are.
    act(() => result.current.set('price', '0120000'))
    expect(result.current.dirty).toBe(true)
    act(() => result.current.patch({ price: '120000', servers: [2, 1] }))
    expect(result.current.dirty).toBe(true)
  })

  it('is measured by the form’s own reading where its request reads the values otherwise — a list kept as a set', () => {
    const { result } = renderForm(() => useForm({ group_ids: [3, 1] }, { reads: ({ group_ids }) => asSet(group_ids) }))

    act(() => result.current.set('group_ids', [1, 3]))
    expect(result.current.dirty).toBe(false)

    act(() => result.current.set('group_ids', [1]))
    expect(result.current.dirty).toBe(true)
  })

  it('is an amount’s or a count’s number however it is typed — a unit, separators, Persian digits —, a blank one none', () => {
    const { result } = renderForm(() =>
      useForm({ price: '50000', cap: '', uses: '100' }, { reads: (values) => ({ price: asAmount(values.price), cap: asAmount(values.cap), uses: asWhole(values.uses) }) }),
    )

    act(() => result.current.patch({ price: '۵۰٬۰۰۰ تومان', cap: '  ', uses: '۱۰۰' }))
    expect(result.current.dirty).toBe(false)

    act(() => result.current.patch({ price: '50000.50', uses: '1,000' }))
    expect(result.current.dirty).toBe(true)
    expect([asAmount(' 50,000.00 '), asAmount('سی هزار'), asWhole(' ۱۰٬۰۰۰ '), asWhole('ده')]).toEqual([50000, 'سی هزار', 10000, 'ده'])
  })

  it('tells a file picked by itself, not by what it holds', () => {
    const { result } = renderForm(() => useForm<{ body: string; file: File | null }>({ body: '', file: null }))

    act(() => result.current.set('file', new File(['x'], 'receipt.png')))
    expect(result.current.dirty).toBe(true)

    act(() => result.current.set('file', null))
    expect(result.current.dirty).toBe(false)
  })

  it('lets a refusal go once the values read as where they started — typed back with a space at the end', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('price', '1'))
    await act(() => result.current.submit(refuse({ price: ['Too low.'], connection: ['Not now.'] })))

    act(() => result.current.set('price', `${PLAN.price} `))

    expect(result.current.dirty).toBe(false)
    expect(result.current.errors).toEqual({})
    expect(result.current.formError).toBeNull()
  })
})

describe('a save', () => {
  it('reads again what it says it changes elsewhere once it went through — its failure reads nothing, and is the form’s alone', async () => {
    vi.spyOn(toast, 'error')
    const { client, wrapper } = providers()
    const invalidated = vi.spyOn(client, 'invalidateQueries')
    const { result } = renderHook(() => useForm(PLAN), { wrapper })

    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { name: ['Taken.'] })), { invalidates: [queryKeys.plans] }))
    expect(invalidated).not.toHaveBeenCalled()
    expect(toast.error).not.toHaveBeenCalled()
    expect(result.current.error('name')).toBe('Taken.')

    await act(() => result.current.submit(() => Promise.resolve(null), { invalidates: [queryKeys.plans, queryKeys.planOptions] }))
    expect(invalidated.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([queryKeys.plans, queryKeys.planOptions])
  })

  it('is busy while it runs, and what it sent is saved afterwards', async () => {
    const request = deferred<{ ok: true }>()
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('name', 'Silver'))

    let saved: Promise<unknown> = Promise.resolve()
    act(() => {
      saved = result.current.submit(() => request.promise)
    })
    expect(result.current.busy).toBe(true)

    await act(async () => {
      request.resolve({ ok: true })
      await expect(saved).resolves.toEqual({ ok: true })
    })
    expect(result.current.busy).toBe(false)
    expect(result.current.dirty).toBe(false)

    act(() => result.current.revert())
    expect(result.current.values.name).toBe('Silver')
  })

  it('keeps what was typed while it ran as unsaved', async () => {
    const request = deferred<null>()
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('name', 'Silver'))
    let saved: Promise<unknown> = Promise.resolve()
    act(() => {
      saved = result.current.submit(() => request.promise)
    })

    act(() => result.current.set('name', 'Platinum'))
    await act(async () => {
      request.resolve(null)
      await saved
    })

    expect(result.current.values.name).toBe('Platinum')
    expect(result.current.dirty).toBe(true)
    act(() => result.current.revert())
    expect(result.current.values.name).toBe('Silver')
  })

  it('puts a 422’s messages under their fields, and one about no field of the form above it', async () => {
    const onInvalid = vi.fn()
    const errors = { name: ['Taken.', 'Second.'], 'servers.0.server_id': ['Gone.'], connection: ['The panel did not answer.'] }
    const { result } = renderForm(() => useForm(PLAN))

    const outcome = await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', errors)), { onInvalid }))

    expect(outcome).toBeUndefined()
    expect(result.current.error('name')).toBe('Taken.')
    expect(result.current.error('servers.0.server_id')).toBe('Gone.')
    expect(result.current.formError).toBe('The panel did not answer.')
    expect(onInvalid).toHaveBeenCalledWith(errors)
  })

  it('leaves a refused draft unsaved', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('name', 'Silver'))

    await act(() => result.current.submit(() => Promise.reject(new ApiError(500, 'Down.'))))

    expect(result.current.dirty).toBe(true)
  })

  it('shows a refusal that names no field with the server’s message', async () => {
    const onInvalid = vi.fn()
    const { result } = renderForm(() => useForm(PLAN))

    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Email does not go out yet.')), { onInvalid }))

    expect(result.current.formError).toBe('Email does not go out yet.')
    expect(result.current.errors).toEqual({})
    expect(onInvalid).toHaveBeenCalledWith({})
  })

  it('has no form error when every refusal is about one of its fields', async () => {
    const { result } = renderForm(() => useForm(PLAN))

    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { 'servers.0.server_id': ['Gone.'] }))))

    expect(result.current.formError).toBeNull()
  })

  it('shows any other failure above the form as lib/failure describes it (the API’s message when meant for the admin), and keeps it', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)
    const { result } = renderForm(() => useForm(PLAN))

    const held = new ApiError(409, 'This plan has sales.')
    await act(() => result.current.submit(() => Promise.reject(held)))
    expect(result.current.formError).toBe('This plan has sales.')
    expect(result.current.failure).toBe(held)
    expect(result.current.errors).toEqual({})

    await act(() => result.current.submit(() => Promise.reject(new ApiError(500, 'x', {}, { requestId: '3f9a1c2b7d4e5f60' }))))
    expect(result.current.formError).toBe(`${FAILURE_KINDS.server.description} کد پیگیری: 3f9a1c2b7d4e5f60`)

    await act(() => result.current.submit(() => Promise.reject(new TypeError('x is undefined'))))
    expect(result.current.formError).toBe(FAILURE_KINDS.crash.description)

    act(() => result.current.setFormError('اتصال برقرار نشد.'))
    expect([result.current.formError, result.current.failure]).toEqual(['اتصال برقرار نشد.', null])
  })

  it('clears the last refusal when it starts again', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { name: ['Taken.'], file: ['Too big.'] }))))

    await act(() => result.current.submit(() => Promise.resolve(null)))

    expect(result.current.errors).toEqual({})
    expect(result.current.formError).toBeNull()
  })
})

/** A save the server refuses with these fields' messages. */
const refuse = (fields: Record<string, string[]>) => () => Promise.reject(new ApiError(422, 'Refused.', fields))

describe('fixing what was refused', () => {
  it('clears a field’s error as it is edited, and only that one', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    await act(() => result.current.submit(refuse({ name: ['Taken.'], price: ['Too low.'] })))

    act(() => result.current.set('name', 'Silver'))

    expect(result.current.error('name')).toBeUndefined()
    expect(result.current.error('price')).toBe('Too low.')
  })

  it('clears the errors of every field a patch changes', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    await act(() => result.current.submit(refuse({ name: ['Taken.'], price: ['Too low.'], servers: ['Pick one.'] })))

    act(() => result.current.patch({ name: 'Silver', price: '150000' }))

    expect(result.current.values).toEqual({ ...PLAN, name: 'Silver', price: '150000' })
    expect(result.current.errors).toEqual({ servers: ['Pick one.'] })
  })

  it('drops the whole refusal once the values come back where the form started — it was about the values sent', async () => {
    // A minimum the server refused under another field (the wallet's top-up presets), then typed back.
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.set('price', '60000'))
    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { servers: ['Below the minimum.'], connection: ['Not now.'] }))))
    expect(result.current.error('servers')).toBe('Below the minimum.')

    act(() => result.current.set('price', PLAN.price))

    expect(result.current.dirty).toBe(false)
    expect(result.current.errors).toEqual({})
    expect(result.current.formError).toBeNull()
  })

  it('— a failure to send too', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    act(() => result.current.patch({ name: 'Silver' }))
    await act(() => result.current.submit(() => Promise.reject(new ApiError(0, 'ارتباط با سرور برقرار نشد.'))))
    expect(result.current.formError).not.toBeNull()

    act(() => result.current.patch({ name: PLAN.name }))

    expect(result.current.formError).toBeNull()
  })

  it('keeps the refusal of a form sent as it started, back there: it is still about what is on screen', async () => {
    const { result } = renderForm(() => useForm(PLAN))
    await act(() => result.current.submit(refuse({ name: ['Taken.'], price: ['Too low.'] })))

    act(() => result.current.set('name', 'Silver'))
    act(() => result.current.set('name', PLAN.name))

    expect(result.current.error('name')).toBeUndefined()
    expect(result.current.error('price')).toBe('Too low.')
  })
})

describe('a form that follows the server’s copy', () => {
  it('with nothing typed starts again when the server’s values change', () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })

    rerender({ initial: { ...PLAN, price: '150000' } })

    expect(result.current.values).toEqual({ ...PLAN, price: '150000' })
    expect([result.current.dirty, result.current.elsewhere]).toEqual([false, false])
  })

  it('never replaces what is typed: the draft stays, saying the copy changed elsewhere, until the admin takes the new copy', () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    act(() => result.current.set('name', 'Silver'))

    // Another tab saved the same card.
    rerender({ initial: { ...PLAN, price: '150000' } })

    expect(result.current.values).toEqual({ ...PLAN, name: 'Silver' })
    expect([result.current.dirty, result.current.elsewhere]).toEqual([true, true])

    act(() => result.current.revert())
    expect(result.current.values).toEqual({ ...PLAN, price: '150000' })
    expect([result.current.dirty, result.current.elsewhere]).toEqual([false, false])
  })

  it('typed back to where it started, takes the new copy it held', () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    act(() => result.current.set('name', 'Silver'))
    rerender({ initial: { ...PLAN, price: '150000' } })

    act(() => result.current.set('name', 'Gold'))

    expect(result.current.values).toEqual({ ...PLAN, price: '150000' })
    expect(result.current.elsewhere).toBe(false)
  })

  it('saved over the new copy, says no more of it — its own save’s answer is no change elsewhere', async () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    act(() => result.current.set('name', 'Silver'))
    rerender({ initial: { ...PLAN, price: '150000' } })

    await act(() => result.current.submit(() => Promise.resolve('saved')))
    expect(result.current.elsewhere).toBe(false)
    rerender({ initial: { ...PLAN, name: 'Silver' } })

    expect(result.current.values).toEqual({ ...PLAN, name: 'Silver' })
    expect([result.current.dirty, result.current.elsewhere]).toEqual([false, false])
  })

  it('typed on while its own save ran, keeps that, the save’s answer its new start', async () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    act(() => result.current.set('name', 'Silver'))
    const request = deferred<string>()
    let saving!: Promise<unknown>
    act(() => {
      saving = result.current.submit(() => request.promise)
    })
    act(() => result.current.set('price', '99000'))
    await act(async () => {
      request.resolve('saved')
      await saving
    })

    rerender({ initial: { ...PLAN, name: 'Silver' } })

    expect(result.current.values).toEqual({ ...PLAN, name: 'Silver', price: '99000' })
    expect([result.current.dirty, result.current.elsewhere]).toEqual([true, false])
  })

  it('keeps the draft when the server hands the same values again (another card’s save)', () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    act(() => result.current.set('name', 'Silver'))

    rerender({ initial: { ...PLAN, servers: [] } })

    expect(result.current.values.name).toBe('Silver')
    expect(result.current.dirty).toBe(true)
  })

  it('drops the last refusal along with the old values', async () => {
    const { result, rerender } = renderForm(({ initial }) => useForm(initial, { follow: true }), { initialProps: { initial: PLAN } })
    await act(() => result.current.submit(() => Promise.reject(new ApiError(422, 'Refused.', { name: ['Taken.'], file: ['Too big.'] }))))

    rerender({ initial: { ...PLAN, name: 'Renamed elsewhere' } })

    expect(result.current.errors).toEqual({})
    expect(result.current.formError).toBeNull()
  })
})

describe('a <form>', () => {
  it('runs its save instead of the browser’s own submit', () => {
    const run = vi.fn()
    const { result } = renderForm(() => useForm(PLAN))
    const event = { preventDefault: vi.fn() } as unknown as FormEvent & { preventDefault: ReturnType<typeof vi.fn> }

    result.current.handleSubmit(run)(event)

    expect(event.preventDefault).toHaveBeenCalled()
    expect(run).toHaveBeenCalledTimes(1)
  })
})
