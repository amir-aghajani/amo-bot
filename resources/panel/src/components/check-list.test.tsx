import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CheckList } from '@/components/check-list'

/*
 * CheckList and the FieldGroup it is drawn in: tick boxes that are one field of a form — values come back in the options'
 * order, an «all» box stands for every option (null), and a refused group is marked invalid and can take the focus.
 */

const OPTIONS = [
  { value: 'a', label: 'الف' },
  { value: 'b', label: 'ب' },
  { value: 'c', label: 'ج' },
]

/** A list that keeps what it is handed, as a form would. */
function Kept({ all, error }: { all?: string; error?: string }) {
  const [value, setValue] = useState<string[] | null>(all ? null : [])
  return (
    <>
      {all ? (
        <CheckList label="حروف" all={all} options={OPTIONS} value={value} onChange={setValue} hint="راهنما" error={error} />
      ) : (
        <CheckList label="حروف" options={OPTIONS} value={value ?? []} onChange={setValue} hint="راهنما" error={error} />
      )}
      <output>{JSON.stringify(value)}</output>
    </>
  )
}

const tick = (label: string) => fireEvent.click(screen.getByRole('checkbox', { name: label }))
const kept = () => screen.getByRole('status').textContent

describe('a check list', () => {
  it('gives the ticked values back in the options’ order, whatever order they were ticked in', () => {
    render(<Kept />)

    tick('ج')
    tick('الف')
    expect(kept()).toBe('["a","c"]')
    tick('ج')
    expect(kept()).toBe('["a"]')
  })

  it('with «all» means every option until it is unticked, then shows the options', () => {
    render(<Kept all="همه حروف" />)

    expect(kept()).toBe('null')
    expect(screen.queryByRole('checkbox', { name: 'الف' })).toBeNull()

    tick('همه حروف')
    expect(kept()).toBe('[]')
    tick('ب')
    expect(kept()).toBe('["b"]')

    tick('همه حروف')
    expect(kept()).toBe('null')
  })

  it('refused, is one invalid field that can take the focus, the refusal in place of the hint', () => {
    render(<Kept error="دست‌کم یکی را انتخاب کنید." />)

    const group = screen.getByRole('group', { name: 'حروف' })
    expect(group.hasAttribute('data-invalid')).toBe(true)
    expect(group.getAttribute('tabindex')).toBe('-1')
    expect(screen.getByText('دست‌کم یکی را انتخاب کنید.')).toBeTruthy()
    expect(screen.queryByText('راهنما')).toBeNull()
  })
})
