import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FilterSelect } from '@/components/filter-select'

/*
 * A list's filter pill (components/filter-select): assistive tech hears what the eye reads on it — the filter and the
 * value it narrows the list to —, and the name follows each pick.
 */

const OPTIONS = [
  { value: '', label: 'همه' },
  { value: 'active', label: 'فعال' },
  { value: 'banned', label: 'مسدود' },
] as const

function StatusFilter() {
  const [status, setStatus] = useState<'' | 'active' | 'banned'>('')
  return <FilterSelect label="وضعیت" value={status} options={[...OPTIONS]} onChange={setStatus} />
}

describe('a filter pill', () => {
  it('is named by the filter and its value, and follows the pick', () => {
    render(<StatusFilter />)
    const pill = screen.getByRole('combobox', { name: 'وضعیت همه' })

    fireEvent.keyDown(pill, { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'مسدود' }))

    expect(screen.getByRole('combobox', { name: 'وضعیت مسدود' })).toBeTruthy()
  })
})
