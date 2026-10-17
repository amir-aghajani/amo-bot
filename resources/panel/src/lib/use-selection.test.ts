import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useSelection } from '@/lib/use-selection'

/*
 * A paged list's ticks (lib/use-selection): kept across its pages, cleared by another scope, and — as the list reads its
 * rows again — following them: a ticked row on screen held as it is now, one that may no longer be ticked unticked, a
 * ticked row on another page left as it was.
 */

interface Receipt {
  id: number
  status: 'waiting' | 'paid'
}

const waiting = (row: Receipt) => row.status === 'waiting'

/** A list's render: its selection, following the rows it shows now. */
function list(initial: { rows: Receipt[]; scope?: string }) {
  return renderHook(
    ({ rows, scope = 'all' }: { rows: Receipt[]; scope?: string }) => {
      const selection = useSelection<Receipt>(scope)
      selection.follow(rows, waiting)
      return selection
    },
    { initialProps: initial },
  )
}

describe('a list’s ticks', () => {
  it('follow the rows read again: one on screen held as it is now, one no longer tickable unticked, one on another page kept', () => {
    const first = { id: 1, status: 'waiting' } as const
    const second = { id: 2, status: 'waiting' } as const
    const elsewhere = { id: 9, status: 'waiting' } as const
    const { result, rerender } = list({ rows: [first, second] })
    act(() => result.current.set([first, second, elsewhere], true))

    const fresh = { id: 1, status: 'waiting' } as const
    rerender({ rows: [fresh, { id: 2, status: 'paid' }] })

    expect(result.current.size).toBe(2)
    expect(result.current.rows()).toEqual([fresh, elsewhere])
    expect(result.current.rows()[0]).toBe(fresh)
    expect(result.current.has(2)).toBe(false)
  })

  it('go with another scope, and follow nothing then', () => {
    const row = { id: 1, status: 'waiting' } as const
    const { result, rerender } = list({ rows: [row] })
    act(() => result.current.set([row], true))

    rerender({ rows: [{ id: 1, status: 'paid' }], scope: 'search' })

    expect(result.current.size).toBe(0)
  })
})
