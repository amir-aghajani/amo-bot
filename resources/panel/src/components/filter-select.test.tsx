import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { FilterSelect } from '@/components/filter-select'

/*
 * A list's filter pill (components/filter-select): assistive tech hears what the eye reads on it — the filter and the
 * value it narrows the list to —, the name follows each pick, and a list narrowed by a value its choices lack is never
 * said to be whole.
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

  it('never says «همه» of a list it narrows: a value its choices do not name yet — or any more — is said as it is', () => {
    const servers = [{ value: '', label: 'همه' }]
    const { rerender } = render(<FilterSelect label="سرور" value="4" options={servers} onChange={() => undefined} />)
    expect(screen.getByRole('combobox', { name: 'سرور 4' })).toBeTruthy()

    rerender(<FilterSelect label="سرور" value="4" options={[...servers, { value: '4', label: 'آلمان' }]} onChange={() => undefined} />)
    expect(screen.getByRole('combobox', { name: 'سرور آلمان' })).toBeTruthy()
  })

  it('lights up under the hand and while open in the dark theme too — dark’s own fill never wins over them', () => {
    render(<StatusFilter />)
    const looks = screen.getByRole('combobox', { name: 'وضعیت همه' }).className.split(' ')

    expect(looks).toEqual(expect.arrayContaining(['border-field-line', 'dark:bg-transparent', 'dark:hover:bg-fill', 'dark:data-[state=open]:bg-fill']))
    expect(looks.filter((look) => look.startsWith('dark:border-'))).toEqual([])
  })

  it('presses as a finger’s 44px on a phone, drawn as the 32px pill', () => {
    render(<StatusFilter />)

    expect(screen.getByRole('combobox', { name: 'وضعیت همه' }).className.split(' ')).toEqual(expect.arrayContaining(['h-8', 'touch-target']))
  })
})
