import { act, renderHook } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useKeyboardDraft } from '@/components/keyboards/use-keyboard-draft'
import type { KeyboardButtonSpec, KeyboardLayoutData, KeyboardResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * A keyboard's draft in the editor (components/keyboards/use-keyboard-draft): its rows and buttons moved, added and
 * removed within the API's limits — a refused edit says why in a toast and changes nothing —, a row the same row
 * wherever it moves (its id), a button dropped past the last row on a row of its own, every change dropping the last
 * save's errors (they are about positions), and its save — the rows as the keyboard has them, no ids.
 */

const button = (label: string): KeyboardButtonSpec => ({ action: label, label, style: null, icon: null })

const START: KeyboardLayoutData = { name: 'start', title: 'منوی اصلی', is_default: true, type: 'reply', rows: [[button('a'), button('b')], [button('c')]] }

const LIMITS = { rows: 3, per_row: 2, label: 32 }

function editor(keyboard = START) {
  const onSaved = vi.fn()
  const hook = renderHook(({ shown }) => useKeyboardDraft(shown, LIMITS, onSaved), { initialProps: { shown: keyboard }, wrapper: providers().wrapper })
  return { ...hook, onSaved }
}

type Draft = ReturnType<typeof editor>['result']

const labels = (result: Draft) => result.current.buttons.map((row) => row.map((spec) => spec.label))

/** The rows' ids, in their order. */
const ids = (result: Draft) => result.current.form.values.rows.map((row) => row.id)

beforeEach(() => {
  vi.spyOn(toast, 'error')
  vi.spyOn(toast, 'success')
})

describe('moving a button', () => {
  it('puts it before the button it is dropped on, or at the end of the row, and says where it landed', () => {
    const { result } = editor()

    let landed: unknown
    act(() => {
      landed = result.current.moveButton({ row: 0, index: 0 }, { row: 0, index: 2 })
    })
    expect(labels(result)).toEqual([['b', 'a'], ['c']])
    expect(landed).toEqual({ row: 0, index: 1 })

    act(() => {
      landed = result.current.moveButton({ row: 0, index: 1 }, { row: 1, index: 0 })
    })
    expect(labels(result)).toEqual([['b'], ['a', 'c']])
    expect(landed).toEqual({ row: 1, index: 0 })
  })

  it('does nothing when dropped where it already is', () => {
    const { result } = editor()

    let landed: unknown = 'moved'
    act(() => {
      landed = result.current.moveButton({ row: 0, index: 0 }, { row: 0, index: 1 })
    })

    expect(landed).toBeNull()
    expect(result.current.form.dirty).toBe(false)
  })

  it('makes a row of its own for one dropped past the last row, while the keyboard has room for one', () => {
    const { result } = editor()

    let landed: unknown
    act(() => {
      landed = result.current.moveButton({ row: 0, index: 1 }, { row: 2, index: 0 })
    })
    expect(labels(result)).toEqual([['a'], ['c'], ['b']])
    expect(landed).toEqual({ row: 2, index: 0 })

    act(() => {
      landed = result.current.moveButton({ row: 0, index: 0 }, { row: 3, index: 0 })
    })
    expect(landed).toBeNull()
    expect(labels(result)).toEqual([['a'], ['c'], ['b']])
    expect(toast.error).toHaveBeenCalledWith('کیبورد حداکثر ۳ ردیف می‌گیرد.')
  })

  it('refuses a row that is full, saying how many a row takes', () => {
    const { result } = editor()

    let landed: unknown = 'moved'
    act(() => {
      landed = result.current.moveButton({ row: 1, index: 0 }, { row: 0, index: 0 })
    })

    expect(landed).toBeNull()
    expect(labels(result)).toEqual([['a', 'b'], ['c']])
    expect(toast.error).toHaveBeenCalledWith('هر ردیف حداکثر ۲ دکمه می‌گیرد.')
  })
})

describe('rows and buttons', () => {
  it('are added within the limits, a refusal saying why', () => {
    const { result } = editor()

    act(() => result.current.addButton(0, button('d')))
    expect(toast.error).toHaveBeenCalledWith('هر ردیف حداکثر ۲ دکمه می‌گیرد.')
    act(() => result.current.addButton(1, button('d')))
    act(() => result.current.addRow())
    act(() => result.current.addRow())

    expect(labels(result)).toEqual([['a', 'b'], ['c', 'd'], []])
    expect(toast.error).toHaveBeenLastCalledWith('کیبورد حداکثر ۳ ردیف می‌گیرد.')
  })

  it('swap a row with its neighbour, and not past either end — each row the same row in its new place', () => {
    const { result } = editor()
    expect(ids(result)).toEqual([0, 1])

    act(() => result.current.moveRow(0, 1))
    expect(labels(result)).toEqual([['c'], ['a', 'b']])
    expect(ids(result)).toEqual([1, 0])
    act(() => result.current.moveRow(0, -1))
    act(() => result.current.moveRow(1, 1))
    expect(labels(result)).toEqual([['c'], ['a', 'b']])

    act(() => result.current.moveRow(1, -1))
    expect(result.current.form.dirty).toBe(false)
  })

  it('give a new row an id no row has, and a button changed keeps its row', () => {
    const { result } = editor()

    act(() => result.current.moveRow(0, 1))
    act(() => result.current.addRow())
    expect(ids(result)).toEqual([1, 0, 2])

    act(() => result.current.setButton({ row: 1, index: 0 }, button('A')))
    expect(ids(result)).toEqual([1, 0, 2])
  })

  it('are removed, and a button set in its place', () => {
    const { result } = editor()

    act(() => result.current.setButton({ row: 0, index: 1 }, button('B')))
    act(() => result.current.removeButton({ row: 0, index: 0 }))
    act(() => result.current.removeRow(1))

    expect(labels(result)).toEqual([['B']])
  })

  it('back as the keyboard had them, are no change — whatever rows it took: a row’s id is the draft’s own', () => {
    const { result } = editor()

    act(() => result.current.removeRow(0))
    act(() => result.current.addRow())
    act(() => result.current.addButton(1, button('a')))
    act(() => result.current.addButton(1, button('b')))
    expect(result.current.form.dirty).toBe(true)
    act(() => result.current.moveRow(1, -1))

    expect(labels(result)).toEqual([['a', 'b'], ['c']])
    expect(ids(result)).not.toEqual([0, 1])
    expect(result.current.form.dirty).toBe(false)
  })
})

describe('saving', () => {
  it('sends the draft and follows what the server kept', async () => {
    const kept: KeyboardLayoutData = { ...START, is_default: false, rows: [[button('b'), button('a')], [button('c')]] }
    server().on('PUT', '/api/admin/keyboards/start', json({ keyboard: kept } satisfies KeyboardResponse))
    const { result, rerender, onSaved } = editor()
    act(() => void result.current.moveButton({ row: 0, index: 0 }, { row: 0, index: 2 }))

    await act(() => result.current.save())

    expect(server().sent('PUT', '/api/admin/keyboards/start')[0]?.body).toEqual({ type: 'reply', rows: kept.rows })
    expect(onSaved).toHaveBeenCalledWith(kept)
    expect(toast.success).toHaveBeenCalled()
    rerender({ shown: kept })
    expect(result.current.form.dirty).toBe(false)
  })

  it('puts a refusal under the row it names, and drops it at the next change: positions move', async () => {
    server().on('PUT', '/api/admin/keyboards/start', refusal(422, 'Refused.', { 'rows.0.1': ['این برچسب تکراری است.'] }))
    const { result } = editor()

    await act(() => result.current.save())
    expect(result.current.form.error('rows.0.1')).toBe('این برچسب تکراری است.')
    expect(toast.error).toHaveBeenCalledWith('چیدمان ذخیره نشد؛ خطاها را زیر ردیف‌ها ببینید.')

    act(() => result.current.moveRow(0, 1))
    await until(() => expect(result.current.form.errors).toEqual({}))
  })
})
